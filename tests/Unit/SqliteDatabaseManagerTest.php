<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use CodeIgniter\Database\Config as DbConfig;
use nuelcyoung\tenantable\Services\TenantDatabaseManager;
use PHPUnit\Framework\TestCase;

/**
 * SQLite tenant databases: file-based create/exists/delete and
 * connection-config mapping.
 *
 * @covers \nuelcyoung\tenantable\Services\TenantDatabaseManager
 */
class SqliteDatabaseManagerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tenantable_sqlite_' . uniqid('', false);

        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
        parent::tearDown();
    }

    private function manager(): TenantDatabaseManager
    {
        return new class($this->directory) extends TenantDatabaseManager {
            public function __construct(private string $directory)
            {
                parent::__construct(true, 'default');
            }

            public function sqliteDatabasesDirectory(): string
            {
                return $this->directory;
            }

            public function getDefaultGroupConfig(): array
            {
                return [
                    'DBDriver' => 'SQLite3',
                    'database' => ':memory:',
                    'DBPrefix' => '',
                ];
            }
        };
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($directory);
    }

    public function testCreateDatabaseCreatesTheFile(): void
    {
        $manager = $this->manager();

        $this->assertTrue($manager->createDatabase('tenant_5'));
        $this->assertFileExists($this->directory . DIRECTORY_SEPARATOR . 'tenant_5.sqlite');
    }

    public function testCreateDatabaseIsIdempotent(): void
    {
        $manager = $this->manager();

        $manager->createDatabase('tenant_5');
        $this->assertTrue($manager->createDatabase('tenant_5'));
        $this->assertFileExists($this->directory . DIRECTORY_SEPARATOR . 'tenant_5.sqlite');
    }

    public function testCreateDatabaseRejectsUnsafeNames(): void
    {
        $manager = $this->manager();

        $this->expectException(\InvalidArgumentException::class);

        $manager->createDatabase('tenant;drop');
    }

    public function testDatabaseExistsUsesTheFile(): void
    {
        $manager = $this->manager();

        $this->assertFalse($manager->databaseExists('tenant_9'));

        $manager->createDatabase('tenant_9');

        $this->assertTrue($manager->databaseExists('tenant_9'));
        $this->assertFalse($manager->databaseExists('tenant_10'));
    }

    public function testDeleteDatabaseRemovesTheFile(): void
    {
        $manager = $this->manager();
        $manager->createDatabase('tenant_5');

        $this->assertTrue($manager->deleteDatabase('tenant_5'));
        $this->assertFileDoesNotExist($this->directory . DIRECTORY_SEPARATOR . 'tenant_5.sqlite');
    }

    public function testDeleteDatabaseIsIdempotent(): void
    {
        $manager = $this->manager();

        $this->assertTrue($manager->deleteDatabase('tenant_never_created'));
    }

    public function testApplyTenantDatabaseMapsSqliteToFilePath(): void
    {
        $manager = $this->manager();

        $config = $manager->applyTenantDatabase(
            ['DBDriver' => 'SQLite3', 'database' => ':memory:'],
            'tenant_5'
        );

        $this->assertSame(
            $this->directory . DIRECTORY_SEPARATOR . 'tenant_5.sqlite',
            $config['database']
        );
    }

    public function testApplyTenantDatabaseKeepsSqlNameForMysql(): void
    {
        $manager = $this->manager();

        $config = $manager->applyTenantDatabase(
            ['DBDriver' => 'MySQLi', 'database' => 'central'],
            'tenant_5'
        );

        $this->assertSame('tenant_5', $config['database']);
    }

    public function testApplyTenantDatabaseRejectsUnsafeNames(): void
    {
        $manager = $this->manager();

        $this->expectException(\InvalidArgumentException::class);

        $manager->applyTenantDatabase(['DBDriver' => 'SQLite3'], '../escape');
    }

    public function testMigrateTenantRunsAgainstSqliteFile(): void
    {
        if (! extension_loaded('sqlite3')) {
            self::markTestSkipped('The sqlite3 extension is required for this test.');
        }

        $manager = $this->manager();
        $manager->createDatabase('tenant_7');

        $migrationDir = $this->directory . DIRECTORY_SEPARATOR . 'migrations';
        mkdir($migrationDir, 0755, true);

        $namespace = 'TenantableTestSqliteMigrations';

        file_put_contents(
            $migrationDir . DIRECTORY_SEPARATOR . '2099-01-01-000001_CreateThingsTable.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace TenantableTestSqliteMigrations;

use CodeIgniter\Database\Migration;

class CreateThingsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'   => ['type' => 'INTEGER', 'auto_increment' => true],
            'name' => ['type' => 'TEXT'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('things');
    }

    public function down(): void
    {
        $this->forge->dropTable('things', true);
    }
}
PHP
        );

        \Config\Services::autoloader()->addNamespace($namespace, $migrationDir);

        $db = null;

        try {
            $tenant = ['id' => 7];

            $this->assertTrue($manager->migrateTenant($tenant, $namespace));

            $db = DbConfig::connect(
                ['DBDriver' => 'SQLite3', 'database' => $manager->sqliteDatabasePath('tenant_7')],
                false
            );

            $this->assertTrue($db->tableExists('things'));
            $this->assertTrue($db->tableExists('migrations'));

            // Running again must not re-apply the migration.
            $this->assertTrue($manager->migrateTenant($tenant, $namespace));
        } finally {
            \Config\Services::autoloader()->removeNamespace($namespace);

            if ($db !== null) {
                $db->close();
            }
        }
    }
}
