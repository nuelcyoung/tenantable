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
use nuelcyoung\tenantable\Models\TenantModel;
use nuelcyoung\tenantable\Jobs\ProvisionTenantJob;
use nuelcyoung\tenantable\Models\TenantDomainModel;
use nuelcyoung\tenantable\Support\TenantableConfig;
use nuelcyoung\tenantable\Traits\NormalizesCliOptions;

class TenantsCreate extends BaseCommand
{
    use NormalizesCliOptions;

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

        if ($model->where('subdomain', $subdomain)->first() !== null) {
            CLI::error("Subdomain '{$subdomain}' already exists.");
            return;
        }

        $data = [
            'subdomain' => $subdomain,
            'name'      => $name,
            'is_active' => ! $this->hasCliOption('inactive'),
        ];

        $domainOpt = $this->cliOption('domain');
        $domain    = $domainOpt !== null && $domainOpt !== '' ? trim($domainOpt) : null;

        if ($domain !== null) {
            try {
                $domain = TenantDomainModel::normalizeDomain($domain);
            } catch (\InvalidArgumentException $e) {
                CLI::error($e->getMessage());
                return;
            }
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

        // Store the custom domain and mark it primary.
        if ($domain !== null) {
            $domainModel = new TenantDomainModel();
            $inserted    = $domainModel->insert([
                'tenant_id'  => (int) $id,
                'domain'     => $domain,
                'is_primary' => 1,
            ]);

            if (! $inserted) {
                CLI::write('');
                CLI::write(CLI::color("  Warning: tenant created, but domain '{$domain}' could not be registered.", 'yellow'));
                foreach ($domainModel->errors() as $field => $msg) {
                    CLI::write("  {$field}: {$msg}", 'red');
                }
                $domain = null; // Don't report it below.
            }
        }

        CLI::write('');
        CLI::write(CLI::color('  Tenant created.', 'green'));
        CLI::write("  ID:        {$id}");
        CLI::write("  Subdomain: {$subdomain}");
        CLI::write("  Name:      {$name}");

        if ($domain !== null) {
            CLI::write("  Domain:    {$domain}");
        }

        $config = TenantableConfig::get();
        $mode   = $config->isolationMode ?? ($config->separateDatabasePerTenant ? 'database' : 'row');

        // With async provisioning the storage does not exist yet, so none of
        // the checks below would say anything true about it.
        if ($config->provisionAsync && $this->isProvisioning((int) $id)) {
            CLI::write('');
            CLI::write(CLI::color('  Provisioning queued.', 'yellow'));
            CLI::write('  The tenant will not resolve until a worker finishes it:', 'light_gray');
            CLI::write('    php spark queue:work ' . ProvisionTenantJob::QUEUE, 'light_gray');
            CLI::write('');

            return;
        }

        if ($mode === 'database') {
            CLI::write("  Database:  {$dbName}");

            try {
                $baseConfig             = (new \nuelcyoung\tenantable\Services\TenantDatabaseManager(
                    $config->separateDatabasePerTenant,
                    $config->defaultDatabaseGroup,
                ))->getDefaultGroupConfig();
                $baseConfig['database'] = $dbName;

                $testDb = \Config\Database::connect($baseConfig, false);
                $testDb->connect();
                CLI::write(CLI::color('  Database provisioned and accessible.', 'green'));
                $testDb->close();
            } catch (\Throwable $e) {
                CLI::write(CLI::color('  Warning: could not verify database connection.', 'yellow'));
                CLI::write("  {$e->getMessage()}", 'light_gray');
            }
        } elseif ($mode === 'prefix') {
            $this->reportPrefixProvisioning((int) $id, $config);
        }

        CLI::write('');
    }

    /** True while the tenant is still waiting on its provisioning job. */
    private function isProvisioning(int $tenantId): bool
    {
        $tenant = (new TenantModel())->find($tenantId);

        return ($tenant['status'] ?? TenantModel::STATUS_READY) === TenantModel::STATUS_PROVISIONING;
    }

    /** Report whether prefix-mode tables were provisioned. */
    private function reportPrefixProvisioning(int $tenantId, object $config): void
    {
        if (empty($config->autoMigrateTenant)) {
            CLI::write('  Tables:    not provisioned (autoMigrateTenant is off).', 'yellow');
            CLI::write("  Next:      php spark tenants:setup --mode=prefix --tenants={$tenantId}", 'light_gray');
            return;
        }

        $applied = 0;

        try {
            $db    = \Config\Database::connect();
            $table = \nuelcyoung\tenantable\Services\TenantDatabaseManager::TENANT_MIGRATIONS_TABLE;

            if ($db->tableExists($table)) {
                $applied = $db->table($table)->where('tenant_id', $tenantId)->countAllResults();
            }
        } catch (\Throwable $e) {
            // Fall through to the warning below.
        }

        if ($applied > 0) {
            CLI::write(CLI::color("  Tables:    {$applied} tenant migration(s) applied.", 'green'));
            return;
        }

        CLI::write(CLI::color('  Warning: no tenant migrations were applied for this tenant.', 'yellow'));
        CLI::write('  Check Config\Tenantable::$tenantMigrationsNamespace, then run:', 'light_gray');
        CLI::write("    php spark tenants:setup --mode=prefix --tenants={$tenantId}", 'light_gray');
    }
}
