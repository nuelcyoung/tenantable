<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use nuelcyoung\tenantable\Models\TenantModel;

/**
 * tenants:list — display all tenants
 *
 * Usage:
 *   php spark tenants:list
 *   php spark tenants:list --active
 *   php spark tenants:list --inactive
 */
class TenantsList extends BaseCommand
{
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

        if (array_key_exists('active', $params) || CLI::getOption('active')) {
            $tenants = $model->where('is_active', 1)->findAll();
            $filter  = 'active';
        } elseif (array_key_exists('inactive', $params) || CLI::getOption('inactive')) {
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

        $tbody = [];

        foreach ($tenants as $tenant) {
            $tbody[] = [
                $tenant['id'],
                $tenant['name']      ?? '—',
                $tenant['subdomain'] ?? '—',
                $tenant['domain']    ?? '—',
                $tenant['is_active'] ? CLI::color('active', 'green') : CLI::color('inactive', 'red'),
                $tenant['created_at'] ?? '—',
            ];
        }

        CLI::table($tbody, ['ID', 'Name', 'Subdomain', 'Domain', 'Status', 'Created At']);

        CLI::write('');
        CLI::write(sprintf('  Total: %d tenant(s)', count($tenants)));
        CLI::write('');
    }
}
