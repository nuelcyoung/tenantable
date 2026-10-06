<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Feature;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Database;
use CodeIgniter\Database\Forge;
use nuelcyoung\tenantable\Database\Migrations\CreateTenantDomainsTable;
use nuelcyoung\tenantable\Database\Migrations\CreateTenantsTable;
use nuelcyoung\tenantable\Services\TenantDatabaseManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Runs only when TENANTABLE_TEST_POSTGRES=1, which CI enables with a real
 * PostgreSQL service. Local SQLite-only test runs remain fast and isolated.
 *
 * @coversNothing
 */
#[Group('postgres')]
class PostgreCompatibilityTest extends TestCase
{
    private array $baseConfig;
    private ?BaseConnection $admin = null;

    /** @var list<string> */
    private array $createdDatabases = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('TENANTABLE_TEST_POSTGRES') !== '1') {
            self::markTestSkipped('Set TENANTABLE_TEST_POSTGRES=1 to run PostgreSQL integration tests.');
        }

        if (! extension_loaded('pgsql')) {
            self::markTestSkipped('The pgsql extension is required for PostgreSQL integration tests.');
        }

        $this->baseConfig = [
            'DSN'      => '',
            'hostname' => getenv('TENANTABLE_POSTGRES_HOST') ?: '127.0.0.1',
            'username' => getenv('TENANTABLE_POSTGRES_USER') ?: 'tenantable',
            'password' => getenv('TENANTABLE_POSTGRES_PASSWORD') ?: 'tenantable',
            'database' => getenv('TENANTABLE_POSTGRES_DATABASE') ?: 'postgres',
            'DBDriver' => 'Postgre',
            'DBPrefix' => '',
            'pConnect' => false,
            'DBDebug'  => true,
            'charset'  => 'utf8',
            'DBCollat' => '',
            'swapPre'  => '',
            'encrypt'  => false,
            'compress' => false,
            'strictOn' => false,
            'failover' => [],
            'port'     => (int) (getenv('TENANTABLE_POSTGRES_PORT') ?: 5432),
            'schema'   => 'public',
        ];

        $this->admin = (new Database())->load($this->baseConfig, 'tenantable-postgres-admin');

        try {
            if ($this->admin->connect() === false) {
                self::fail('Could not connect to the PostgreSQL maintenance database.');
            }
        } catch (\Throwable $e) {
            self::fail('Could not connect to the PostgreSQL maintenance database: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->admin !== null) {
            foreach ($this->createdDatabases as $database) {
                try {
                    $this->admin->query(
                        'SELECT pg_terminate_backend(pid) FROM pg_stat_activity ' .
                        'WHERE datname = ? AND pid <> pg_backend_pid()',
                        [$database]
                    );
                    $this->admin->query('DROP DATABASE IF EXISTS ' . $this->admin->escapeIdentifier($database));
                } catch (\Throwable $e) {
                    // Preserve the original test failure; CI's disposable service
                    // removes any leftover database after the job completes.
                }
            }

            $this->admin->close();
        }

        parent::tearDown();
    }

    public function testCreatesTenantDatabaseAndRunsPortableMigrations(): void
    {
        $tenant   = $this->nextTenant();
        $database = $tenant['database'];
        $manager  = $this->manager();

        $this->assertTrue($manager->createDatabase($database));
        $this->createdDatabases[] = $database;
        $this->assertTrue($manager->createDatabase($database), 'provisioning must be idempotent');

        // Run before opening our own connection: pg_connect() reuses an open
        // link with an identical DSN, so migrateTenant()'s close() would
        // otherwise close ours too.
        $this->runTenantMigrations($manager, $tenant);

        $config             = $this->baseConfig;
        $config['database'] = $database;
        $tenantDb           = (new Database())->load($config, 'tenantable-postgres-tenant');

        try {
            $this->assertNotFalse($tenantDb->connect());

            $forge = (new Database())->loadForge($tenantDb);
            $this->runCentralMigrations($forge);

            $this->assertTrue($tenantDb->tableExists('tenants'));
            $this->assertTrue($tenantDb->tableExists('tenant_domains'));
            $this->assertTrue($tenantDb->tableExists('ci_sessions'));
            $this->assertTrue($tenantDb->tableExists('migrations'));

            $tenantDb->table('tenants')->insert([
                'id'        => 1,
                'subdomain' => 'acme',
                'name'      => 'Acme',
                'is_active' => true,
            ]);
            $tenantDb->table('tenant_domains')->insert([
                'tenant_id'   => 1,
                'domain'      => 'acme.example.test',
                'is_primary'  => 1,
                'is_verified' => 0,
                'ssl_state'   => 'active',
            ]);

            $domain = $tenantDb->table('tenant_domains')->where('domain', 'acme.example.test')->get()->getRowArray();
            $this->assertSame('active', $domain['ssl_state']);
        } finally {
            $tenantDb->close();
        }
    }

    private function manager(): TenantDatabaseManager
    {
        return new class($this->baseConfig) extends TenantDatabaseManager {
            /** @param array<string, mixed> $config */
            public function __construct(private array $config)
            {
                parent::__construct(true, 'default');
            }

            public function getDefaultGroupConfig(): array
            {
                return $this->config;
            }
        };
    }

    /** @return array{id: int, database: string} */
    private function nextTenant(): array
    {
        $id = random_int(100000000, 999999999);

        return ['id' => $id, 'database' => 'tenant_' . $id];
    }

    private function runCentralMigrations(Forge $forge): void
    {
        // The package migrations normally use the configured default group.
        // Override it here so this integration test targets its disposable DB.
        (new class($forge) extends CreateTenantsTable {
            protected $DBGroup = null;
        })->up();

        (new class($forge) extends CreateTenantDomainsTable {
            protected $DBGroup = null;
        })->up();
    }

    /** @param array{id: int, database: string} $tenant */
    private function runTenantMigrations(TenantDatabaseManager $manager, array $tenant): void
    {
        $namespace = 'nuelcyoung\\tenantable\\Database\\Migrations\\Tenant';
        \Config\Services::autoloader()->addNamespace(
            $namespace,
            ROOTPATH . 'src' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations' . DIRECTORY_SEPARATOR . 'Tenant'
        );

        try {
            $this->assertTrue($manager->migrateTenant($tenant, $namespace));
        } finally {
            \Config\Services::autoloader()->removeNamespace($namespace);
        }
    }
}
