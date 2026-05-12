<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use nuelcyoung\tenantable\Models\TenantModel;
use nuelcyoung\tenantable\Config\Tenantable as TenantableConfig;
use nuelcyoung\tenantable\Services\TenantDatabaseManager;

/**
 * tenants:run — execute a Spark command in the context of each tenant.
 *
 * In database-per-tenant mode, migration commands run directly against
 * each tenant's database. Other commands run as subprocesses with the
 * TENANTABLE_TENANT_ID env variable set.
 *
 * Usage:
 *   php spark tenants:run migrate
 *   php spark tenants:run db:seed --seeder=TenantSeeder --tenants=1,3,5
 */
class TenantsRun extends BaseCommand
{
    protected $group       = 'Tenantable';
    protected $name        = 'tenants:run';
    protected $description = 'Run a Spark command for each (or specific) tenant(s).';
    protected $usage       = 'tenants:run <command> [args] [--tenants=1,2,3]';
    protected $arguments   = [
        'command' => 'The Spark command to run (e.g. migrate, db:seed).',
    ];
    protected $options = [
        '--tenants' => 'Comma-separated list of tenant IDs (default: all active).',
    ];

    public function run(array $params): void
    {
        if (empty($params)) {
            CLI::error('Usage: php spark tenants:run <command> [args]');
            return;
        }

        $command   = array_shift($params);
        $extraArgs = implode(' ', array_map('escapeshellarg', $params));

        $model   = new TenantModel();
        $tenants = $this->resolveTenants($model);

        if (empty($tenants)) {
            CLI::write('No tenants found to run command for.', 'yellow');
            return;
        }

        /** @var TenantableConfig $config */
        $config = config(TenantableConfig::class);
        $mode   = $config->isolationMode
            ?? ($config->separateDatabasePerTenant ? 'database' : 'row');

        $isMigration = $mode === 'database' && $this->isMigrationCommand($command);

        CLI::write('');
        CLI::write(CLI::color("  Running: php spark {$command} {$extraArgs}", 'cyan'));
        CLI::write(CLI::color("  Mode:    {$mode}", 'cyan'));
        CLI::write(CLI::color("  Tenants: " . count($tenants), 'cyan'));
        CLI::write('');

        $success = 0;
        $failed  = 0;

        foreach ($tenants as $tenant) {
            $id   = (int) $tenant['id'];
            $name = $tenant['name'] ?? $tenant['subdomain'] ?? "tenant #{$id}";

            CLI::write(CLI::color("  ► [{$id}] {$name}", 'yellow'));

            if ($isMigration) {
                $exitCode = $this->runMigrations($tenant, $config);
            } else {
                $exitCode = $this->runAsSubprocess($id, $command, $extraArgs);
            }

            if ($exitCode === 0) {
                CLI::write(CLI::color("    ✔ Done", 'green'));
                $success++;
            } else {
                CLI::write(CLI::color("    ✘ Failed (exit {$exitCode})", 'red'));
                $failed++;
            }

            CLI::write('');
        }

        CLI::write("  Ran on {$success} tenant(s) successfully." . ($failed > 0 ? " {$failed} failed." : ''));
        CLI::write('');
    }

    /**
     * Run tenant migrations directly against the tenant's database.
     */
    protected function runMigrations(array $tenant, TenantableConfig $config): int
    {
        try {
            $manager = new TenantDatabaseManager(
                $config->separateDatabasePerTenant,
                $config->defaultDatabaseGroup,
            );

            $namespaces = [];
            if (! empty($config->tenantMigrationsNamespace)) {
                $namespaces[] = $config->tenantMigrationsNamespace;
            }
            foreach ($config->tenantMigrationsNamespaces as $ns) {
                if (! empty($ns) && ! in_array($ns, $namespaces, true)) {
                    $namespaces[] = $ns;
                }
            }

            if (empty($namespaces)) {
                CLI::write('    No tenant migration namespaces configured.', 'yellow');
                return 0;
            }

            foreach ($namespaces as $ns) {
                CLI::write("    Migrating: {$ns}", 'light_gray');
                if (! $manager->migrateTenant($tenant, $ns)) {
                    return 1;
                }
            }

            return 0;
        } catch (\Throwable $e) {
            CLI::write(CLI::color("    Error: {$e->getMessage()}", 'red'));
            return 1;
        }
    }

    /**
     * Run a command as a shell subprocess with the tenant ID in env.
     */
    protected function runAsSubprocess(int $tenantId, string $command, string $extraArgs): int
    {
        $exitCode  = 0;
        $sparkPath = ROOTPATH . 'spark';
        $php       = PHP_BINARY;
        $cmd       = "{$php} {$sparkPath} " . escapeshellarg($command) . " " . escapeshellarg($extraArgs) . " 2>&1";

        putenv("TENANTABLE_TENANT_ID={$tenantId}");
        passthru($cmd, $exitCode);
        putenv("TENANTABLE_TENANT_ID=");

        return $exitCode;
    }

    protected function isMigrationCommand(string $command): bool
    {
        return in_array($command, [
            'migrate',
            'migrate:rollback',
            'migrate:refresh',
            'migrate:status',
        ], true);
    }

    /**
     * @return array<int, array>
     */
    protected function resolveTenants(TenantModel $model): array
    {
        $idsOption = CLI::getOption('tenants');

        if (!empty($idsOption)) {
            $ids = array_filter(array_map('intval', explode(',', $idsOption)));
            if (empty($ids)) {
                CLI::error('--tenants must be a comma-separated list of integer IDs.');
                return [];
            }
            return $model->whereIn('id', $ids)->findAll();
        }

        return $model->where('is_active', 1)->findAll();
    }
}
