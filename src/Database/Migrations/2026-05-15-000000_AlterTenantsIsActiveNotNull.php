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

class AlterTenantsIsActiveNotNull extends Migration
{
    protected $DBGroup = 'default';

    public function up(): void
    {
        // Make is_active NOT NULL DEFAULT 0 for fail-closed behavior
        $this->forge->modifyColumn('tenants', [
            'is_active' => [
                'type'    => 'TINYINT',
                'constraint' => 1,
                'null'    => false,
                'default' => 0,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->modifyColumn('tenants', [
            'is_active' => [
                'type'    => 'BOOLEAN',
                'null'    => true,
                'default' => true,
            ],
        ]);
    }
}
