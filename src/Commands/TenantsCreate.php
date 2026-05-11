<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use nuelcyoung\tenantable\Models\TenantModel;

/**
 * tenants:create — create a new tenant record.
 *
 * Inserts a tenant via TenantModel, which triggers auto-provisioning
 * (CREATE DATABASE + migrations) when database-per-tenant mode is active.
 *
 * Usage:
 *   php spark tenants:create foodblog "Food Blog"
 *   php spark tenants:create acme "Acme Corp" --domain=acme.com
 *   php spark tenants:create demo "Demo Tenant" --inactive
 */
class TenantsCreate extends BaseCommand
{
    protected $group       = 'Tenantable';
    protected $name        = 'tenants:create';
    protected $description = 'Create a new tenant.';
    protected $usage       = 'tenants:create <subdomain> <name> [--domain=] [--inactive]';
    protected $arguments   = [
        'subdomain' => 'Unique subdomain identifier (e.g. foodblog, acme).',
        'name'      => 'Display name for the tenant.',
    ];
    protected $options = [
        '--domain'   => 'Custom domain for the tenant (optional).',
        '--inactive' => 'Create the tenant as inactive.',
    ];

    public function run(array $params): void
    {
        $subdomain = $params[0] ?? CLI::prompt('Subdomain', null, 'required');
        $name      = $params[1] ?? CLI::prompt('Tenant name', null, 'required');

        $subdomain = trim((string) $subdomain);
        $name      = trim((string) $name);

        if ($subdomain === '' || $name === '') {
            CLI::error('Both subdomain and name are required.');
            return;
        }

        $model = new TenantModel();

        // Check for duplicates
        if ($model->subdomainExists($subdomain)) {
            CLI::error("Subdomain '{$subdomain}' already exists.");
            return;
        }

        $data = [
            'subdomain' => $subdomain,
            'name'      => $name,
            'is_active' => ! (bool) CLI::getOption('inactive'),
        ];

        $domain = CLI::getOption('domain');
        if (is_string($domain) && $domain !== '') {
            $data['domain'] = $domain;
        }

        CLI::write('');
        CLI::write("Creating tenant '{$name}' ({$subdomain})...", 'yellow');

        if (! $model->insert($data)) {
            $errors = $model->errors();
            CLI::error('  Failed to create tenant.');
            foreach ($errors as $field => $msg) {
                CLI::write("  {$field}: {$msg}", 'red');
            }
            return;
        }

        $id     = $model->getInsertID();
        $tenant = $model->find((int) $id);
        $dbName = TenantModel::getDatabaseName($tenant);

        CLI::write('');
        CLI::write(CLI::color('  Tenant created.', 'green'));
        CLI::write("  ID:        {$id}");
        CLI::write("  Subdomain: {$subdomain}");
        CLI::write("  Name:      {$name}");

        if (! empty($data['domain'])) {
            CLI::write("  Domain:    {$data['domain']}");
        }

        // Check if auto-provisioning ran
        $config = config(\nuelcyoung\tenantable\Config\Tenantable::class);
        $mode   = $config->isolationMode ?? ($config->separateDatabasePerTenant ? 'database' : 'row');

        if ($mode === 'database') {
            CLI::write("  Database:  {$dbName}");

            // Verify the database was created
            try {
                $baseConfig             = (new \nuelcyoung\tenantable\Services\TenantDatabaseManager(
                    $config->separateDatabasePerTenant,
                    $config->defaultDatabaseGroup,
                ))->getDefaultGroupConfig();
                $baseConfig['database'] = $dbName;

                $testDb = \CodeIgniter\Database\Database::connect($baseConfig, false);
                $testDb->connect();
                CLI::write(CLI::color('  Database provisioned and accessible.', 'green'));
                $testDb->close();
            } catch (\Throwable $e) {
                CLI::write(CLI::color('  Warning: could not verify database connection.', 'yellow'));
                CLI::write("  {$e->getMessage()}", 'light_gray');
            }
        }

        CLI::write('');
    }
}
