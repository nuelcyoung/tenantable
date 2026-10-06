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

namespace nuelcyoung\tenantable\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;
use nuelcyoung\tenantable\Config\Tenantable as TenantableConfig;
use nuelcyoung\tenantable\Models\TenantModel;
use nuelcyoung\tenantable\Traits\NormalizesCliOptions;
use nuelcyoung\tenantable\Traits\ReportsInfrastructure;

class TenantsSetup extends BaseCommand
{
    use NormalizesCliOptions;
    use ReportsInfrastructure;

    protected $group       = 'Tenantable';
    protected $name        = 'tenants:setup';
    protected $description = 'Provision tenant storage for the configured isolation mode.';
    protected $usage       = 'tenants:setup [--mode=row|prefix|database] [--create-db] [--tenants=1,2]';
    protected $options     = [
        '--mode'      => 'Override the configured isolation mode (row|prefix|database).',
        '--create-db' => 'For database mode: create a database for each tenant when missing.',
        '--tenants'   => 'Comma-separated tenant IDs (default: all active).',
    ];

    public function run(array $params): void
    {
        $config = config(TenantableConfig::class);

        $mode = $this->resolveMode($config);
        if ($mode === null) {
            return;
        }

        CLI::write('');
        CLI::write(CLI::color('  Tenantable Setup  ', 'white', 'blue'));
        CLI::write('  mode: ' . CLI::color($mode, 'cyan'));
        CLI::write('');

        if (!$this->migrateCentral()) {
            return;
        }

        $this->reportInfrastructure();

        if ($mode === 'row') {
            CLI::write('');
            CLI::write(CLI::color('  Setup complete.', 'green'));
            CLI::write('  Next: run your app migrations with `php spark migrate`.');
            CLI::write('');
            return;
        }

        $namespace = $config->tenantMigrationsNamespace;
        if (empty($namespace)) {
            CLI::error(
                'Config\Tenantable::$tenantMigrationsNamespace is not set. ' .
                'Set it to the PSR-4 namespace that holds your per-tenant migrations.'
            );
            return;
        }

        $tenants = $this->resolveTenants();
        if (empty($tenants)) {
            CLI::write('No tenants to set up.', 'yellow');
            return;
        }

        if ($mode === 'prefix') {
            $this->setupPrefixMode($tenants, $config);
            return;
        }

        $this->setupDatabaseMode($tenants, $namespace);
    }

    private function resolveMode(TenantableConfig $config): ?string
    {
        $override = $this->cliOption('mode');

        if (!empty($override)) {
            if (!in_array($override, ['row', 'prefix', 'database'], true)) {
                CLI::error("Invalid mode '{$override}'. Allowed: row, prefix, database.");
                return null;
            }
            return $override;
        }

        return $config->resolvedIsolationMode();
    }

    private function migrateCentral(): bool
    {
        $config    = config(TenantableConfig::class);
        $tableName = $config->tenantsTable;

        CLI::write('Migrating central tenants table...', 'yellow');

        try {
            $runner = Services::migrations();
            $runner->setNamespace('nuelcyoung\tenantable')->latest();
        } catch (\Throwable $e) {
            CLI::error('  Failed: ' . $e->getMessage());
            return false;
        }

        $db = \Config\Database::connect();
        if (! $db->tableExists($tableName)) {
            CLI::error("  Migration reported success but the '{$tableName}' table does not exist.");
            CLI::write('  This usually means a previous migration run is recorded in the', 'yellow');
            CLI::write('  `migrations` table but the actual table was dropped manually.', 'yellow');
            CLI::write('', 'yellow');
            CLI::write('  Fix: clear the stale migration record and re-run:', 'yellow');
            CLI::write("    DELETE FROM migrations WHERE namespace = 'nuelcyoung\\tenantable';", 'light_gray');
            CLI::write('    php spark tenants:setup', 'light_gray');
            return false;
        }

        CLI::write(CLI::color('  tenants table ready', 'green'));
        return true;
    }

    /**
     * Provision prefix-mode tenants using the in-process migrator. CI4's
     * MigrationRunner tracks by namespace only and would skip tenants 2..N.
     */
    private function setupPrefixMode(array $tenants, TenantableConfig $config): void
    {
        CLI::write('');
        CLI::write('Note: tenant migrations must reference tables via', 'light_gray');
        CLI::write('TenantTableManager::getInstance()->getTable(\'foo\') for prefixes to apply.', 'light_gray');

        $namespaces = $config->tenantMigrationNamespaces();
        $manager    = $this->getDatabaseManager();

        $success = 0;
        $failed  = 0;

        foreach ($tenants as $tenant) {
            $id   = (int) $tenant['id'];
            $name = $tenant['name'] ?? $tenant['subdomain'] ?? "tenant #{$id}";

            CLI::write('');
            CLI::write(CLI::color("[{$id}] {$name}", 'yellow'));

            $tenantFailed = false;

            foreach ($namespaces as $ns) {
                $applied = $manager->migrateTenantTables($tenant, $ns);

                if ($applied === null) {
                    CLI::write(CLI::color("  failed: {$ns}", 'red'));
                    $tenantFailed = true;
                    break;
                }

                if ($applied === 0 && $manager->findTenantMigrationFiles($ns) === []) {
                    CLI::write(CLI::color("  warning: no migration files found for {$ns}", 'yellow'));
                    continue;
                }

                CLI::write(CLI::color(
                    "  {$ns}: " . ($applied > 0 ? "{$applied} migration(s) applied" : 'up to date'),
                    'green'
                ));
            }

            if ($tenantFailed) {
                $failed++;
            } else {
                $success++;
            }
        }

        CLI::write('');
        CLI::write("  {$success} succeeded" . ($failed > 0 ? ", {$failed} failed" : '') . '.');
        CLI::write('');
    }

    private function setupDatabaseMode(array $tenants, string $namespace): void
    {
        $createDb = $this->hasCliOption('create-db');
        $runner   = Services::migrations();

        $success = 0;
        $failed  = 0;

        foreach ($tenants as $tenant) {
            $id   = (int) $tenant['id'];
            $name = $tenant['name'] ?? $tenant['subdomain'] ?? "tenant #{$id}";
            $db   = TenantModel::getDatabaseName($tenant);

            CLI::write('');
            CLI::write(CLI::color("[{$id}] {$name}", 'yellow'));

            try {
                if ($createDb && !$this->createDatabase($tenant)) {
                    $failed++;
                    continue;
                }

                $alias = $this->registerTenantGroup($tenant);
                $runner->setNamespace($namespace)->setGroup($alias)->latest();
                CLI::write(CLI::color("  migrations applied to {$db}", 'green'));
                $success++;
            } catch (\Throwable $e) {
                CLI::write(CLI::color('  failed: ' . $e->getMessage(), 'red'));
                $failed++;
            }
        }

        CLI::write('');
        CLI::write("  {$success} succeeded" .
            ($failed > 0 ? ", {$failed} failed" : '') . '.');
        CLI::write('');
    }

    private function createDatabase(array $tenant): bool
    {
        $db      = TenantModel::getDatabaseName($tenant);
        $manager = $this->getDatabaseManager();

        if ($manager->createDatabase($db)) {
            CLI::write(CLI::color("  database {$db} ensured", 'green'));
            return true;
        }

        CLI::write(CLI::color("  CREATE DATABASE failed for {$db}", 'red'));
        return false;
    }

    private function registerTenantGroup(array $tenant): string
    {
        $alias = $tenant['subdomain'] ?? ('tenant_' . $tenant['id']);

        $config             = $this->getDatabaseManager()->getDefaultGroupConfig();
        $config['database'] = TenantModel::getDatabaseName($tenant);

        $dbConfig         = config('Database');
        $dbConfig->$alias = $config;

        return $alias;
    }

    private function getDatabaseManager(): \nuelcyoung\tenantable\Services\TenantDatabaseManager
    {
        $config = config(TenantableConfig::class);
        return new \nuelcyoung\tenantable\Services\TenantDatabaseManager(
            $config->isDatabaseIsolation(),
            $config->defaultDatabaseGroup,
        );
    }

    private function resolveTenants(): array
    {
        $model     = new TenantModel();
        $idsOption = $this->cliOption('tenants');

        try {
            if (!empty($idsOption)) {
                $ids = array_filter(array_map('intval', explode(',', $idsOption)));
                if (empty($ids)) {
                    CLI::error('--tenants must be a comma-separated list of integer IDs.');
                    return [];
                }
                return $model->whereIn('id', $ids)->findAll();
            }

            return $model->where('is_active', 1)->findAll();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
