<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Feature;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Database;
use nuelcyoung\tenantable\Services\TenantDatabaseManager;
use nuelcyoung\tenantable\Services\TenantTableManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests that prefix-mode provisioning actually creates each tenant's tables.
 *
 * @coversNothing
 */
class TenantPrefixProvisioningTest extends TestCase
{
    private const TEST_NAMESPACE = 'Tenantable\PrefixTestMigrations';

    private BaseConnection $db;
    private string $migrationDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Stop the framework's event initialization from including a
        // non-existent events config (which would emit a warning under failOnWarning).
        $ref = new \ReflectionProperty(\CodeIgniter\Events\Events::class, 'initialized');
        $ref->setAccessible(true);
        $ref->setValue(null, true);

        TenantTableManager::resetInstance();

        /** @var BaseConnection $db */
        $db = (new Database())->load([
            'DBDriver' => 'SQLite3',
            'database' => ':memory:',
            'DBPrefix' => '',
        ], 'prefix-provisioning-tests');
        $this->db = $db;

        $this->migrationDir = WRITEPATH . 'prefix-test-migrations' . DIRECTORY_SEPARATOR;
        if (! is_dir($this->migrationDir) && ! @mkdir($this->migrationDir, 0755, true) && ! is_dir($this->migrationDir)) {
            $this->fail("Could not create {$this->migrationDir}");
        }

        $migrationFile = $this->migrationDir . '2024-01-01-000000_CreateGadgetsTable.php';
        if (file_put_contents($migrationFile, $this->migrationSource()) === false) {
            $this->fail("Could not write {$migrationFile}");
        }

        \Config\Services::autoloader()->addNamespace(self::TEST_NAMESPACE, $this->migrationDir);
    }

    protected function tearDown(): void
    {
        \Config\Services::autoloader()->removeNamespace(self::TEST_NAMESPACE);

        foreach (glob($this->migrationDir . '*.php') ?: [] as $file) {
            @unlink($file);
        }
        if (is_dir($this->migrationDir)) {
            @rmdir($this->migrationDir);
        }

        TenantTableManager::resetInstance();
        parent::tearDown();
    }

    private function manager(): TenantDatabaseManager
    {
        return new TenantDatabaseManager(false, 'default');
    }

    private function migrationSource(): string
    {
        $ns = self::TEST_NAMESPACE;

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$ns};

        use CodeIgniter\\Database\\Migration;
        use nuelcyoung\\tenantable\\Services\\TenantTableManager;

        class CreateGadgetsTable extends Migration
        {
            public function up(): void
            {
                \$tableManager = TenantTableManager::getInstance();

                \$this->forge->addField([
                    'id'   => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                    'name' => ['type' => 'VARCHAR', 'constraint' => 100],
                ]);
                \$this->forge->addKey('id', true);
                \$this->forge->createTable(\$tableManager->getTable('gadgets'), true);
            }

            public function down(): void
            {
                \$tableManager = TenantTableManager::getInstance();
                \$this->forge->dropTable(\$tableManager->getTable('gadgets'), true);
            }
        }

        PHP;
    }

    public function testProvisionsPrefixedTablesForTenant(): void
    {
        $applied = $this->manager()->migrateTenantTables(
            ['id' => 1, 'subdomain' => 'acme'],
            self::TEST_NAMESPACE,
            $this->db
        );

        $this->assertSame(1, $applied);
        $this->assertTrue($this->db->tableExists('tenant_1_gadgets'));
        $this->assertFalse($this->db->tableExists('tenant_2_gadgets'));
        $this->assertFalse($this->db->tableExists('gadgets'), 'un-prefixed table must not be created');
    }

    public function testSecondTenantIsNotSkippedBySharedTracking(): void
    {
        // The framework's shared migrations table would mark the namespace
        // applied after tenant 1 and skip tenant 2; per-tenant tracking must not.
        $manager = $this->manager();
        $manager->migrateTenantTables(['id' => 1], self::TEST_NAMESPACE, $this->db);
        $applied = $manager->migrateTenantTables(['id' => 2], self::TEST_NAMESPACE, $this->db);

        $this->assertSame(1, $applied);
        $this->assertTrue($this->db->tableExists('tenant_1_gadgets'));
        $this->assertTrue($this->db->tableExists('tenant_2_gadgets'));

        $rows = $this->db->table('tenant_migrations')
            ->orderBy('tenant_id', 'ASC')
            ->get()
            ->getResultArray();

        $this->assertCount(2, $rows);
        $this->assertSame([1, 2], array_map('intval', array_column($rows, 'tenant_id')));
    }

    public function testRerunForSameTenantIsIdempotent(): void
    {
        $manager = $this->manager();

        $this->assertSame(1, $manager->migrateTenantTables(['id' => 1], self::TEST_NAMESPACE, $this->db));
        $this->assertSame(0, $manager->migrateTenantTables(['id' => 1], self::TEST_NAMESPACE, $this->db));
        $this->assertTrue($this->db->tableExists('tenant_1_gadgets'));
    }

    public function testTenantContextIsRestoredAfterRun(): void
    {
        $this->manager()->migrateTenantTables(['id' => 7], self::TEST_NAMESPACE, $this->db);

        $this->assertFalse(
            TenantTableManager::getInstance()->hasTenant(),
            'migrator must not leak its tenant scope into the caller'
        );
    }

    public function testUnresolvableNamespaceAppliesNothing(): void
    {
        $applied = $this->manager()->migrateTenantTables(['id' => 1], 'No\Such\Namespace', $this->db);

        $this->assertSame(0, $applied);
        $this->assertFalse($this->db->tableExists('tenant_1_gadgets'));
    }

    public function testProvisionPrefixTenantCreatesTablesOnCreation(): void
    {
        // The path the tenant model's dispatch method takes in prefix mode: a
        // freshly created tenant gets its prefixed tables without any manual
        // tenants:setup run.
        $config                            = new \nuelcyoung\tenantable\Config\Tenantable();
        $config->isolationMode             = 'prefix';
        $config->tenantMigrationsNamespace = self::TEST_NAMESPACE;

        $ok = $this->manager()->provisionPrefixTenant(['id' => 5, 'subdomain' => 'newco'], $config, $this->db);

        $this->assertTrue($ok);
        $this->assertTrue($this->db->tableExists('tenant_5_gadgets'));
        $this->assertFalse($this->db->tableExists('gadgets'));
    }

    public function testFindTenantMigrationFilesListsFiles(): void
    {
        $files = $this->manager()->findTenantMigrationFiles(self::TEST_NAMESPACE);

        $this->assertCount(1, $files);
        $this->assertStringContainsString('CreateGadgetsTable', $files[0]);
    }
}
