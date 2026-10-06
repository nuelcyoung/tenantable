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
use nuelcyoung\tenantable\Support\TenantableConfig;
use nuelcyoung\tenantable\Traits\NormalizesCliOptions;

class TenantsList extends BaseCommand
{
    use NormalizesCliOptions;

    protected $group       = 'Tenantable';
    protected $name        = 'tenants:list';
    protected $description = 'List all registered tenants.';
    protected $usage       = 'tenants:list [--active] [--inactive]';
    protected $options     = [
        '--active'   => 'Show only active tenants.',
        '--inactive' => 'Show only inactive tenants.',
    ];

    public function run(array $params): void
    {
        $model = new TenantModel();

        if (array_key_exists('active', $params) || $this->hasCliOption('active')) {
            $tenants = $model->where('is_active', 1)->findAll();
            $filter  = 'active';
        } elseif (array_key_exists('inactive', $params) || $this->hasCliOption('inactive')) {
            $tenants = $model->where('is_active', 0)->findAll();
            $filter  = 'inactive';
        } else {
            $tenants = $model->findAll();
            $filter  = 'all';
        }

        if (empty($tenants)) {
            CLI::write("No {$filter} tenants found.", 'yellow');
            return;
        }

        CLI::write('');
        CLI::write(CLI::color("  Tenants ({$filter})  ", 'white', 'blue'));
        CLI::write('');

        $config    = TenantableConfig::get();
        $isPrefix  = $config->resolvedIsolationMode() === 'prefix';
        $tableSize = $isPrefix ? $this->physicalTableCounts() : [];

        $tbody = [];

        foreach ($tenants as $tenant) {
            $row = [
                $tenant['id'],
                $tenant['name']      ?? '—',
                $tenant['subdomain'] ?? '—',
                $tenant['domain']    ?? '—',
                $tenant['is_active'] ? CLI::color('active', 'green') : CLI::color('inactive', 'red'),
                $tenant['created_at'] ?? '—',
            ];

            if ($isPrefix) {
                // In prefix mode every tenant adds real tables to one shared
                // database; the count is what makes that growth visible.
                $row[] = $tableSize[(int) $tenant['id']] ?? '—';
            }

            $tbody[] = $row;
        }

        $headers = ['ID', 'Name', 'Subdomain', 'Domain', 'Status', 'Created At'];

        if ($isPrefix) {
            $headers[] = 'Tables';
        }

        CLI::table($tbody, $headers);

        CLI::write('');
        CLI::write(sprintf('  Total: %d tenant(s)', count($tenants)));

        if ($isPrefix) {
            $threshold = $config->prefixTenantWarningThreshold;
            $total     = array_sum($tableSize);

            CLI::write(sprintf('  Physical tables across all tenants: %d', $total), 'light_gray');

            if ($threshold > 0 && count($tenants) > $threshold) {
                CLI::write(
                    "  Prefix mode is designed for up to a few hundred tenants; run tenants:doctor.",
                    'yellow'
                );
            }
        }

        CLI::write('');
    }

    /**
     * Physical tables per tenant, keyed by tenant ID. One listTables() call,
     * not one per tenant, since it is expensive on prefix-mode databases.
     *
     * @return array<int, int>
     */
    private function physicalTableCounts(): array
    {
        try {
            $tables = \Config\Database::connect()->listTables();
        } catch (\Throwable $e) {
            return [];
        }

        $counts = [];

        foreach ($tables as $table) {
            if (preg_match('/^tenant_(\d+)_/', (string) $table, $matches) !== 1) {
                continue;
            }

            $id          = (int) $matches[1];
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        return $counts;
    }
}
