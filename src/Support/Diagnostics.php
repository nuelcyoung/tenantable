<?php

/**
 * This file is part of the Tenantable.
 *
 * (c) Nuel Young Chukwunalu <nuelmega@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace nuelcyoung\tenantable\Support;

use nuelcyoung\tenantable\Bootstrap\Systems\QueueSystem;
use nuelcyoung\tenantable\Config\Tenantable as PackageConfig;
use nuelcyoung\tenantable\Jobs\ProvisionTenantJob;
use nuelcyoung\tenantable\Models\TenantModel;

/**
 * Every "will this deployment actually work" check, in one place. Rendered
 * by tenants:doctor; kept here so the checks are testable and match what
 * SharedInfrastructure::assertSafe() enforces at boot.
 *
 * @phpstan-type Finding array{id: string, severity: string, title: string, detail: string, remedy: string}
 */
final class Diagnostics
{
    public const OK = 'ok';

    /**
     * Every finding, criticals first, then warnings, then what passed.
     *
     * @return list<Finding>
     */
    public static function run(?object $tenantable = null): array
    {
        $config = $tenantable instanceof PackageConfig ? $tenantable : TenantableConfig::get();

        $findings = array_merge(
            SharedInfrastructure::inspect(null, null, $config),
            self::inspectQueue($config),
            self::inspectProvisioning($config),
            self::inspectWritablePaths(),
            self::inspectTenantScale($config),
            self::inspectFramework()
        );

        $rank = [
            SharedInfrastructure::CRITICAL => 0,
            SharedInfrastructure::WARNING  => 1,
            self::OK                       => 2,
        ];

        usort(
            $findings,
            static fn (array $a, array $b): int => ($rank[$a['severity']] ?? 3) <=> ($rank[$b['severity']] ?? 3)
        );

        return $findings;
    }

    /**
     * @param list<Finding> $findings
     */
    public static function hasCritical(array $findings): bool
    {
        return SharedInfrastructure::critical($findings) !== [];
    }

    /**
     * @return list<Finding>
     */
    private static function inspectQueue(PackageConfig $config): array
    {
        $queueConfig = self::queueConfig();

        if ($queueConfig === null) {
            return [];
        }

        $group = QueueSystem::sharedTenantGroup($queueConfig, $config);

        if ($group === null) {
            return [[
                'id'       => 'queue-group',
                'severity' => self::OK,
                'title'    => 'The queue does not follow tenancy',
                'detail'   => 'Jobs are written to a connection that tenant switching does not repoint.',
                'remedy'   => '',
            ]];
        }

        return [[
            'id'       => 'queue-shares-tenant-group',
            'severity' => SharedInfrastructure::CRITICAL,
            'title'    => 'The queue writes into tenant databases',
            'detail'   => "Config\\Queue uses the database handler on group '{$group}', the group "
                . 'repointed at each tenant database in database isolation mode. Jobs pushed while a '
                . 'tenant is active land in that tenant database, where no worker looks for them. '
                . 'Nothing errors — the job is simply lost.',
            'remedy'   => "Add a central connection group in Config\\Database and point "
                . "Config\\Queue::\$database['dbGroup'] at it.",
        ]];
    }

    /**
     * @return list<Finding>
     */
    private static function inspectProvisioning(PackageConfig $config): array
    {
        if (! $config->provisionAsync) {
            return [];
        }

        $findings = [];

        if (self::queueConfig() === null) {
            $findings[] = [
                'id'       => 'async-provisioning-without-queue',
                'severity' => SharedInfrastructure::CRITICAL,
                'title'    => 'Async provisioning has no queue behind it',
                'detail'   => '$provisionAsync is on but no Config\\Queue exists, so every tenant '
                    . 'falls back to being provisioned inline — the slow signup this setting was '
                    . 'meant to avoid.',
                'remedy'   => 'composer require codeigniter4/queue, then publish and configure it.',
            ];
        } elseif (! self::provisionJobRegistered()) {
            $findings[] = [
                'id'       => 'provision-job-not-registered',
                'severity' => SharedInfrastructure::CRITICAL,
                'title'    => 'The provisioning job is not registered',
                'detail'   => "Config\\Queue::\$jobHandlers has no '" . ProvisionTenantJob::NAME
                    . "' entry, so a worker cannot run the job. Tenants are queued and stay "
                    . 'in the provisioning state, unable to serve traffic.',
                'remedy'   => "Add '" . ProvisionTenantJob::NAME . "' => \\"
                    . ProvisionTenantJob::class . '::class to $jobHandlers, and run '
                    . '`php spark queue:work ' . ProvisionTenantJob::QUEUE . '`.',
            ];
        }

        $statusColumn = self::tenantsTableHasStatus($config);

        if ($statusColumn === false) {
            $findings[] = [
                'id'       => 'tenants-status-column-missing',
                'severity' => SharedInfrastructure::CRITICAL,
                'title'    => 'The tenants table has no status column',
                'detail'   => 'Async provisioning tracks readiness in a `status` column that this '
                    . 'database does not have, so a tenant would resolve while its storage was '
                    . 'still being created.',
                'remedy'   => 'Run `php spark migrate --all` to apply the bundled migration.',
            ];
        } elseif ($statusColumn === true) {
            $findings[] = [
                'id'       => 'tenants-status-column',
                'severity' => self::OK,
                'title'    => 'Tenant provisioning status is tracked',
                'detail'   => 'The tenants table has its status column.',
                'remedy'   => '',
            ];
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private static function inspectWritablePaths(): array
    {
        $findings = [];

        $paths = [
            'writable root'   => WRITEPATH,
            'fan-out reports' => dirname(FanOutReport::defaultPath()),
            'tenant uploads'  => WRITEPATH . 'uploads',
        ];

        $unwritable = [];

        foreach ($paths as $label => $path) {
            if (is_dir($path)) {
                if (! is_writable($path)) {
                    $unwritable[] = "{$label} ({$path})";
                }

                continue;
            }

            // Not yet created is fine as long as the parent allows it.
            $parent = dirname($path);

            if (! is_dir($parent) || ! is_writable($parent)) {
                $unwritable[] = "{$label} ({$path}, cannot be created)";
            }
        }

        if ($unwritable !== []) {
            $findings[] = [
                'id'       => 'writable-paths',
                'severity' => SharedInfrastructure::CRITICAL,
                'title'    => 'Writable paths are not writable',
                'detail'   => 'Tenantable writes tenant uploads and fan-out reports below WRITEPATH: '
                    . implode(', ', $unwritable) . '.',
                'remedy'   => 'Give the web server and CLI user write access to those directories.',
            ];
        } else {
            $findings[] = [
                'id'       => 'writable-paths',
                'severity' => self::OK,
                'title'    => 'Writable paths are writable',
                'detail'   => 'Uploads and fan-out reports have somewhere to go.',
                'remedy'   => '',
            ];
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private static function inspectTenantScale(PackageConfig $config): array
    {
        if ($config->resolvedIsolationMode() !== 'prefix') {
            return [];
        }

        $threshold = $config->prefixTenantWarningThreshold;
        $count     = self::tenantCount();

        if ($count === null) {
            return [];
        }

        if ($threshold > 0 && $count > $threshold) {
            return [[
                'id'       => 'prefix-mode-tenant-count',
                'severity' => SharedInfrastructure::WARNING,
                'title'    => "Prefix mode is carrying {$count} tenants",
                'detail'   => 'Every tenant multiplies the table count in one database. Past a few '
                    . 'hundred tenants, schema changes, backups and the information_schema queries '
                    . 'the framework runs all slow down together.',
                'remedy'   => 'Move to database-per-tenant isolation, or raise '
                    . "\$prefixTenantWarningThreshold (currently {$threshold}) if this is expected.",
            ]];
        }

        return [[
            'id'       => 'prefix-mode-tenant-count',
            'severity' => self::OK,
            'title'    => "Prefix mode is carrying {$count} tenant(s)",
            'detail'   => "Below the configured threshold of {$threshold}.",
            'remedy'   => '',
        ]];
    }

    /**
     * @return list<Finding>
     */
    private static function inspectFramework(): array
    {
        if (FrameworkState::isTestedCiVersion()) {
            return [[
                'id'       => 'framework-version',
                'severity' => self::OK,
                'title'    => 'CodeIgniter ' . FrameworkState::ciVersion() . ' is a tested version',
                'detail'   => 'Tenantable reaches into framework-internal state to swap connections '
                    . 'and services; this build is inside the window those internals are verified against.',
                'remedy'   => '',
            ]];
        }

        return [[
            'id'       => 'framework-version',
            'severity' => SharedInfrastructure::WARNING,
            'title'    => 'CodeIgniter ' . FrameworkState::ciVersion() . ' is outside the tested window',
            'detail'   => 'Tenant switching evicts shared connections and services through framework '
                . 'internals verified against ' . FrameworkState::MIN_CI_VERSION . '–'
                . FrameworkState::MAX_CI_VERSION_TESTED . '. Outside it the package still works, but a '
                . 'moved internal store would surface as a FrameworkCompatibilityException.',
            'remedy'   => 'Upgrade the tenantable package, or pin the framework inside the window.',
        ]];
    }

    private static function queueConfig(): ?object
    {
        if (! class_exists('Config\\Queue')) {
            return null;
        }

        $config = config('Queue');

        return is_object($config) ? $config : null;
    }

    private static function provisionJobRegistered(): bool
    {
        $handlers = self::queueConfig()->jobHandlers ?? null;

        return is_array($handlers) && isset($handlers[ProvisionTenantJob::NAME]);
    }

    /** Null when the table could not be inspected at all. */
    private static function tenantsTableHasStatus(PackageConfig $config): ?bool
    {
        try {
            $db    = \Config\Database::connect();
            $table = $config->tenantsTable;

            if (! $db->tableExists($table)) {
                return null;
            }

            return in_array('status', $db->getFieldNames($table), true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function tenantCount(): ?int
    {
        try {
            return (new TenantModel())->countAllResults();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
