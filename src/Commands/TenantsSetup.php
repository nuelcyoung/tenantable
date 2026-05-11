<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Database;
use Config\Services;
use nuelcyoung\tenantable\Config\Tenantable as TenantableConfig;
use nuelcyoung\tenantable\Models\TenantModel;
use nuelcyoung\tenantable\Services\TenantTableManager;

/**
 * tenants:setup — provision database schema for the configured isolation mode.
 *
 * Modes:
 *   row       Shared DB with tenant_id column. Only creates the central tenants table.
 *   prefix    Shared DB with per-tenant table prefixes. Runs tenant migrations once
 *             per tenant with TenantTableManager seeded so table names resolve to
 *             tenant_{id}_*. Migration authors must reference tables via
 *             TenantTableManager::getInstance()->getTable('foo').
 *   database  Database-per-tenant. Optionally creates the DB, then runs tenant
 *             migrations against that connection.
 *
 * Usage:
 *   php spark tenants:setup
 *   php spark tenants:setup --mode=database --create-db
 *   php spark tenants:setup --tenants=1,3
 */
class TenantsSetup extends BaseCommand
{
    protected $group       = 'Tenantable';
    protected $name        = 'tenants:setup';
    protected $description = 'Provision tenant storage for the configured isolation mode.';
    protected $usage       = 'tenants:setup [--mode=row|prefix|database] [--create-db] [--tenants=1,2]';
    protected $options     = [
        '--mode'      => 'Override the configured isolation mode (row|prefix|database).',
        '--create-db' => 'For database mode: CREATE DATABASE IF NOT EXISTS per tenant.',
        '--tenants'   => 'Comma-separated tenant IDs (default: all active).',
    ];

    public function run(array $params): void
    {
        /** @var TenantableConfig $config */
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
                'Config\\Tenantable::$tenantMigrationsNamespace is not set. ' .
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
            $this->setupPrefixMode($tenants, $namespace);
            return;
        }

        $this->setupDatabaseMode($tenants, $namespace);
    }

    private function resolveMode(TenantableConfig $config): ?string
    {
        $mode = CLI::getOption('mode') ?: $config->isolationMode;

        if ($mode === null) {
            $mode = $config->separateDatabasePerTenant ? 'database' : 'row';
        }

        if (!in_array($mode, ['row', 'prefix', 'database'], true)) {
            CLI::error("Invalid mode '{$mode}'. Allowed: row, prefix, database.");
            return null;
        }

        return $mode;
    }

    private function migrateCentral(): bool
    {
        CLI::write('Migrating central tenants table...', 'yellow');

        try {
            $runner = Services::migrations();
            $runner->setNamespace('nuelcyoung\\tenantable')->latest();
        } catch (\Throwable $e) {
            CLI::error('  Failed: ' . $e->getMessage());
            return false;
        }

        CLI::write(CLI::color('  tenants table ready', 'green'));
        return true;
    }

    private function setupPrefixMode(array $tenants, string $namespace): void
    {
        CLI::write('');
        CLI::write('Note: tenant migrations must reference tables via', 'light_gray');
        CLI::write('TenantTableManager::getInstance()->getTable(\'foo\') for prefixes to apply.', 'light_gray');

        $tableManager = TenantTableManager::getInstance();
        $runner       = Services::migrations();

        $success = 0;
        $failed  = 0;

        foreach ($tenants as $tenant) {
            $id   = (int) $tenant['id'];
            $name = $tenant['name'] ?? $tenant['subdomain'] ?? "tenant #{$id}";

            CLI::write('');
            CLI::write(CLI::color("[{$id}] {$name}", 'yellow'));

            try {
                $tableManager->setTenant($id, $tenant['subdomain'] ?? null);
                $runner->setNamespace($namespace)->latest();
                CLI::write(CLI::color('  migrations applied', 'green'));
                $success++;
            } catch (\Throwable $e) {
                CLI::write(CLI::color('  failed: ' . $e->getMessage(), 'red'));
                $failed++;
            }
        }

        $tableManager->clear();

        CLI::write('');
        CLI::write("  {$success} succeeded" . ($failed > 0 ? ", {$failed} failed" : '') . '.');
        CLI::write('');
    }

    private function setupDatabaseMode(array $tenants, string $namespace): void
    {
        $createDb = (bool) CLI::getOption('create-db');
        $runner   = Services::migrations();

        $success = 0;
        $failed  = 0;
        $skipped = 0;

        foreach ($tenants as $tenant) {
            $id   = (int) $tenant['id'];
            $name = $tenant['name'] ?? $tenant['subdomain'] ?? "tenant #{$id}";
            $db   = $tenant['database_name'] ?? null;

            CLI::write('');
            CLI::write(CLI::color("[{$id}] {$name}", 'yellow'));

            if (empty($db)) {
                CLI::write(CLI::color('  skipped: tenant has no database_name', 'yellow'));
                $skipped++;
                continue;
            }

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
            ($failed > 0 ? ", {$failed} failed" : '') .
            ($skipped > 0 ? ", {$skipped} skipped" : '') . '.');
        CLI::write('');
    }

    private function createDatabase(array $tenant): bool
    {
        $db   = $tenant['database_name'];
        $host = $tenant['database_host']     ?? 'localhost';
        $user = $tenant['database_username'] ?? '';
        $pass = $tenant['database_password'] ?? '';
        $port = (int) ($tenant['database_port'] ?? 3306);

        try {
            $admin = Database::connect([
                'DBDriver' => 'MySQLi',
                'hostname' => $host,
                'username' => $user,
                'password' => $pass,
                'database' => '',
                'port'     => $port,
                'DBPrefix' => '',
            ], false);

            $escaped = str_replace('`', '``', $db);
            $admin->query("CREATE DATABASE IF NOT EXISTS `{$escaped}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            CLI::write(CLI::color("  database {$db} ensured", 'green'));
            return true;
        } catch (\Throwable $e) {
            CLI::write(CLI::color("  CREATE DATABASE failed: {$e->getMessage()}", 'red'));
            return false;
        }
    }

    private function registerTenantGroup(array $tenant): string
    {
        $alias = $tenant['subdomain'] ?? ('tenant_' . $tenant['id']);

        $dbConfig         = config('Database');
        $dbConfig->$alias = [
            'DBDriver' => 'MySQLi',
            'DBPrefix' => '',
            'hostname' => $tenant['database_host']     ?? 'localhost',
            'username' => $tenant['database_username'] ?? '',
            'password' => $tenant['database_password'] ?? '',
            'database' => $tenant['database_name'],
            'port'     => (int) ($tenant['database_port'] ?? 3306),
        ];

        return $alias;
    }

    private function resolveTenants(): array
    {
        $model     = new TenantModel();
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
