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

class CreateTenantDomainsTable extends Migration
{
    protected $DBGroup = 'default';

    public function up(): void
    {
        $sslState = [
            'type'       => 'ENUM',
            'constraint' => ['none', 'pending', 'active', 'failed'],
            'default'    => 'none',
            'null'       => false,
        ];

        if (($this->db->DBDriver ?? '') === 'Postgre') {
            // PostgreSQL has no inline ENUM type. The domain model validates
            // the same supported values for its portable VARCHAR column.
            $sslState = [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'none',
                'null'       => false,
            ];
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => false,
            ],
            'domain' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
                'unique'     => true,
            ],
            'is_primary' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
            ],
            'is_verified' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
            ],
            'verified_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'ssl_state' => $sslState,
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('tenant_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('tenant_domains', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('tenant_domains', true);
    }
}
