<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use nuelcyoung\tenantable\Models\TenantModel;
use nuelcyoung\tenantable\Bootstrap\TenantBootstrap;
use nuelcyoung\tenantable\Services\TenantManager;

/**
 * tenants:run — execute a Spark command in the context of each tenant
 *
 * Usage:
 *   php spark tenants:run db:migrate
 *   php spark tenants:run db:seed --seeder=TenantSeeder --tenants=1,3,5
 *
 * Options:
 *   --tenants   Comma-separated tenant IDs to run for (default: all active)
 *
 * How it works:
 *   For each tenant it:
 *     1. Boots that tenant's context (TenantBootstrap::bootForTenant)
 *     2. Passes the TENANT_ID env var to the sub-process
 *     3. Runs `php spark <command> <args>` as a shell subprocess
 */
class TenantsRun extends BaseCommand
{
    protected $group       = 'Tenantable';
    protected $name        = 'tenants:run';
    protected $description = 'Run a Spark command for each (or specific) tenant(s).';
    protected $usage       = 'tenants:run <command> [args] [--tenants=1,2,3]';
    protected $options     = [
        '--tenants' => 'Comma-separated list of tenant IDs (default: all active).',
    ];

    public function run(array $params): void
    {
        if (empty($params)) {
            CLI::error('Usage: php spark tenants:run <command> [args]');
            return;
        }

        $command    = array_shift($params);
        $extraArgs  = implode(' ', array_map('escapeshellarg', $params));

        // Resolve tenant list
        $model   = new TenantModel();
        $tenants = $this->resolveTenants($model);

        if (empty($tenants)) {
            CLI::write('No tenants found to run command for.', 'yellow');
            return;
        }

        $sparkPath = ROOTPATH . 'spark';
        $php       = PHP_BINARY;

        CLI::write('');
        CLI::write(CLI::color("Running: php spark {$command} {$extraArgs}", 'cyan'));
        CLI::write('');

        $success = 0;
        $failed  = 0;

        foreach ($tenants as $tenant) {
            $id   = (int) $tenant['id'];
            $name = $tenant['name'] ?? $tenant['subdomain'] ?? "tenant #{$id}";

            CLI::write(CLI::color("  ► [{$id}] {$name}", 'yellow'));

            $exitCode = 0;
            $cmd      = "{$php} {$sparkPath} {$command} {$extraArgs} 2>&1";

            // Pass tenant context via env variable
            putenv("TENANTABLE_TENANT_ID={$id}");

            passthru($cmd, $exitCode);

            putenv("TENANTABLE_TENANT_ID=");

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
