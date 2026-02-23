<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Create Tenants Table Migration
 *
 * Creates the central tenants table used by all three isolation strategies.
 *
 * FIX 4.2 – Removed the duplicate addUniqueKey('subdomain') call.
 *            The 'subdomain' column already has 'unique' => true in its
 *            addField() definition, so calling addUniqueKey() a second time
 *            created a redundant duplicate index.
 */
class CreateTenantsTable extends Migration
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
            'subdomain' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'unique'     => true,
                'null'       => true,
                'comment'    => 'Used by SubdomainFilter; null when using custom domain only',
            ],
            // For DomainFilter / DomainOrSubdomainFilter
            'domain' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'unique'     => true,
                'comment'    => 'Full custom domain (e.g. myschool.com); null when using subdomains only',
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            // Columns for the "Separate DB per tenant" strategy (null when unused)
            'database_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'comment'    => 'Separate DB name if using schema-per-tenant',
            ],
            'database_host' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'database_username' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'database_password' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'comment'    => 'Store encrypted; decrypt at runtime',
            ],
            'is_active' => [
                'type'    => 'BOOLEAN',
                'default' => true,
            ],
            'settings' => [
                'type'    => 'JSON',
                'null'    => true,
                'comment' => 'Tenant-specific settings (arbitrary JSON)',
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
        // FIX 4.2 – addUniqueKey('subdomain') removed (already unique in addField above)

        $this->forge->createTable('tenants', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('tenants', true);
    }
}
