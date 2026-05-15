<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Database\Migrations;

use CodeIgniter\Database\Migration;

class BackfillTenantDomainsFromTenants extends Migration
{
    protected $DBGroup = 'default';

    public function up(): void
    {
        $db = $this->db;

        // Skip backfill if the legacy column was already removed.
        if (! $db->fieldExists('domain', 'tenants')) {
            return;
        }

        // Idempotent: only backfill rows that don't already exist
        $builder = $db->table('tenants');
        $tenants = $builder->where('domain IS NOT NULL')->get()->getResultArray();

        foreach ($tenants as $tenant) {
            $domain = $tenant['domain'];
            if (empty($domain)) {
                continue;
            }

            // Check if already backfilled
            $exists = $db->table('tenant_domains')
                ->where('domain', $domain)
                ->countAllResults();

            if ($exists > 0) {
                continue;
            }

            $db->table('tenant_domains')->insert([
                'tenant_id'   => $tenant['id'],
                'domain'      => $domain,
                'is_primary'  => 1,
                'is_verified' => 1,
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function down(): void
    {
        // No-op: we don't remove backfilled data on rollback
    }
}
