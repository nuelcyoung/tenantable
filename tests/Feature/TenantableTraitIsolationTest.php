<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Feature;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Database;
use CodeIgniter\Model;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Exceptions\TenantIsolationException;
use nuelcyoung\tenantable\Support\TenantContextState;
use nuelcyoung\tenantable\Traits\TenantableTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests that TenantableTrait scopes queries on a plain CI4 Model.
 *
 * @coversNothing
 */
class TenantableTraitIsolationTest extends TestCase
{
    private BaseConnection $db;

    protected function setUp(): void
    {
        parent::setUp();

        // Stop the framework's event initialization from including a
        // non-existent events config (which would emit a warning under failOnWarning).
        $ref = new \ReflectionProperty(\CodeIgniter\Events\Events::class, 'initialized');
        $ref->setAccessible(true);
        $ref->setValue(null, true);

        TenantManager::resetInstance();

        /** @var BaseConnection $db */
        $db = (new Database())->load([
            'DBDriver' => 'SQLite3',
            'database' => ':memory:',
            'DBPrefix' => '',
        ], 'trait-isolation-tests');
        $this->db = $db;

        $this->db->query(
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, name TEXT)'
        );
        $this->db->table('widgets')->insertBatch([
            ['tenant_id' => 1, 'name' => 'alpha'],
            ['tenant_id' => 1, 'name' => 'beta'],
            ['tenant_id' => 2, 'name' => 'gamma'],
        ]);
    }

    protected function tearDown(): void
    {
        TenantManager::resetInstance();
        TenantContextState::disableTenantBypass();
        parent::tearDown();
    }

    private function setTenant(?int $id): void
    {
        $mgr = TenantManager::getInstance();
        $ref = new \ReflectionProperty($mgr, 'tenantId');
        $ref->setAccessible(true);
        $ref->setValue($mgr, $id);
    }

    private function model(): Model
    {
        // The documented usage: plain Model + trait, no manual wiring.
        return new class($this->db) extends Model {
            use TenantableTrait;

            protected $table         = 'widgets';
            protected $primaryKey    = 'id';
            protected $returnType    = 'array';
            protected $allowedFields = ['tenant_id', 'name'];
            protected $useTimestamps = false;
        };
    }

    private function modelWithoutTenantColumn(): Model
    {
        $this->db->query(
            'CREATE TABLE unscoped_widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)'
        );

        return new class($this->db) extends Model {
            use TenantableTrait;

            protected $table         = 'unscoped_widgets';
            protected $primaryKey    = 'id';
            protected $returnType    = 'array';
            protected $allowedFields = ['name'];
            protected $useTimestamps = false;
        };
    }

    public function testFindAllIsScopedWithoutAnyManualWiring(): void
    {
        // Regression guard: if initialize() stops registering the callbacks,
        // this returns all 3 rows instead of tenant 1's 2.
        $this->setTenant(1);

        $rows = $this->model()->findAll();

        $this->assertCount(2, $rows);
        $this->assertSame(['alpha', 'beta'], array_column($rows, 'name'));
    }

    public function testFindByIdAcrossTenantsReturnsNull(): void
    {
        $this->setTenant(1);

        $this->assertNull($this->model()->find(3));
    }

    public function testInsertStampsCurrentTenantId(): void
    {
        $this->setTenant(2);

        $id = $this->model()->insert(['name' => 'delta'], true);

        $row = $this->db->table('widgets')->where('id', $id)->get()->getRowArray();
        $this->assertSame(2, (int) $row['tenant_id']);
    }

    public function testInsertIgnoresSuppliedForeignTenantId(): void
    {
        // IDOR guard: context wins over a caller-supplied tenant_id.
        $this->setTenant(1);

        $id = $this->model()->insert(['name' => 'delta', 'tenant_id' => 2], true);

        $row = $this->db->table('widgets')->where('id', $id)->get()->getRowArray();
        $this->assertSame(1, (int) $row['tenant_id']);
    }

    public function testUpdateCannotTouchAnotherTenantsRow(): void
    {
        $this->setTenant(1);

        $this->model()->update(3, ['name' => 'hacked']);

        $row = $this->db->table('widgets')->where('id', 3)->get()->getRowArray();
        $this->assertSame('gamma', $row['name'], 'tenant 2 row must be untouched');
    }

    public function testUpdateCannotMoveRowToAnotherTenant(): void
    {
        $this->setTenant(1);

        $this->model()->update(1, ['name' => 'alpha', 'tenant_id' => 2]);

        $row = $this->db->table('widgets')->where('id', 1)->get()->getRowArray();
        $this->assertSame(1, (int) $row['tenant_id'], 'tenant_id must be immutable');
    }

    public function testInsertBatchStampsCurrentTenantIdOnAllRows(): void
    {
        $this->setTenant(2);

        $this->model()->insertBatch([
            ['name' => 'delta'],
            ['name' => 'epsilon', 'tenant_id' => 1],
        ]);

        $rows = $this->db->table('widgets')->whereIn('name', ['delta', 'epsilon'])->get()->getResultArray();
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame(2, (int) $row['tenant_id']);
        }
    }

    public function testUpdateBatchOnlyTouchesOwnedRows(): void
    {
        $this->setTenant(1);

        $this->model()->updateBatch([
            ['id' => 1, 'name' => 'alpha-updated'],
            ['id' => 3, 'name' => 'hacked'],
        ], 'id');

        $owned = $this->db->table('widgets')->where('id', 1)->get()->getRowArray();
        $this->assertSame('alpha-updated', $owned['name']);

        $foreign = $this->db->table('widgets')->where('id', 3)->get()->getRowArray();
        $this->assertSame('gamma', $foreign['name'], 'tenant 2 row must be untouched');
    }

    public function testUpdateBatchCannotMoveRowsToAnotherTenant(): void
    {
        $this->setTenant(1);

        $this->model()->updateBatch([
            ['id' => 1, 'name' => 'alpha', 'tenant_id' => 2],
        ], 'id');

        $row = $this->db->table('widgets')->where('id', 1)->get()->getRowArray();
        $this->assertSame(1, (int) $row['tenant_id'], 'tenant_id must be immutable');
    }

    public function testCountAllResultsCountsOnlyCurrentTenantRows(): void
    {
        $this->setTenant(1);

        $this->assertSame(2, $this->model()->countAllResults());
    }

    public function testMissingTenantColumnFailsClosed(): void
    {
        $this->setTenant(1);

        $this->expectException(TenantIsolationException::class);
        $this->modelWithoutTenantColumn()->findAll();
    }
}
