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

/**
 * Tracks where a tenant is in provisioning: async provisioning creates the
 * row before its database, and without a status column the tenant would
 * resolve against missing storage. Existing rows default to 'ready'.
 */
/** @noinspection PhpIllegalPsrClassPathInspection */
class AddTenantProvisioningStatus extends Migration
{
    protected $DBGroup = 'default';

    public function up(): void
    {
        $db = $this->db;

        if ($db->fieldExists('status', 'tenants')) {
            return;
        }

        // VARCHAR rather than ENUM: PostgreSQL has no ENUM type of this shape,
        // and the value set is validated in the model either way.
        $this->forge->addColumn('tenants', [
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => false,
                'default'    => 'ready',
            ],
        ]);
    }

    public function down(): void
    {
        $db = $this->db;

        if (! $db->fieldExists('status', 'tenants')) {
            return;
        }

        $this->forge->dropColumn('tenants', 'status');
    }
}
