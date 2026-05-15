<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTenantDomainsTable extends Migration
{
    protected $DBGroup = 'default';

    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type'       => 'INT',
                'constraint' => 11,
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
            'ssl_state' => [
                'type'    => 'ENUM',
                'constraint' => ['none', 'pending', 'active', 'failed'],
                'default' => 'none',
                'null'    => false,
            ],
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
        $this->forge->addKey('domain');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('tenant_domains', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('tenant_domains', true);
    }
}
