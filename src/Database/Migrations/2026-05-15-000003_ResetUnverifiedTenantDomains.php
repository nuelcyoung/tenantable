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

namespace nuelcyoung\tenantable\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Reset legacy rows that were previously trusted without ownership proof. */
class ResetUnverifiedTenantDomains extends Migration
{
    protected $DBGroup = 'default';

    public function up(): void
    {
        $this->db->table('tenant_domains')
            ->where('is_verified', 1)
            ->where('verified_at IS NULL')
            ->update([
                'is_verified' => 0,
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
    }

    public function down(): void
    {
        // Verification state must not be restored automatically.
    }
}
