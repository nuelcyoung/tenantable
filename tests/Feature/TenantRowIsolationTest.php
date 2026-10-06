<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Feature;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Database;
use nuelcyoung\tenantable\Exceptions\TenantIsolationException;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;
use nuelcyoung\tenantable\Models\TenantableModel;
use nuelcyoung\tenantable\Services\TenantManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests that row-level isolation actually filters queries by tenant_id.
 *
 * @coversNothing
 */
class TenantRowIsolationTest extends TestCase
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
        ], 'isolation-tests');
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
        TenantableModel::disableTenantBypass();
        parent::tearDown();
    }

    private function setTenant(?int $id): void
    {
        $mgr = TenantManager::getInstance();
        $ref = new \ReflectionProperty($mgr, 'tenantId');
        $ref->setAccessible(true);
        $ref->setValue($mgr, $id);
    }

    private function model(): TenantableModel
    {
        return new class($this->db) extends TenantableModel {
            protected $table         = 'widgets';
            protected $primaryKey    = 'id';
            protected $returnType    = 'array';
            protected $allowedFields = ['tenant_id', 'name'];
            protected $useTimestamps = false;
        };
    }

    private function modelWithoutTenantColumn(): TenantableModel
    {
        $this->db->query(
            'CREATE TABLE unscoped_widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)'
        );

        return new class($this->db) extends TenantableModel {
            protected $table         = 'unscoped_widgets';
            protected $primaryKey    = 'id';
            protected $returnType    = 'array';
            protected $allowedFields = ['name'];
            protected $useTimestamps = false;
        };
    }

    public function testFindAllReturnsOnlyCurrentTenantRows(): void
    {
        $this->setTenant(1);

        $rows = $this->model()->findAll();

        $this->assertCount(2, $rows);
        $this->assertSame(['alpha', 'beta'], array_column($rows, 'name'));
    }

    public function testFindAllForOtherTenantReturnsTheirRows(): void
    {
        $this->setTenant(2);

        $rows = $this->model()->findAll();

        $this->assertCount(1, $rows);
        $this->assertSame('gamma', $rows[0]['name']);
    }

    public function testFindByIdAcrossTenantsReturnsNull(): void
    {
        // Row id 3 belongs to tenant 2; tenant 1 must not be able to read it.
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
        // IDOR guard: a caller-supplied tenant_id must never decide where the
        // row lands; the active tenant context always wins.
        $this->setTenant(1);

        $id = $this->model()->insert(['name' => 'delta', 'tenant_id' => 2], true);

        $row = $this->db->table('widgets')->where('id', $id)->get()->getRowArray();
        $this->assertSame(1, (int) $row['tenant_id']);
    }

    public function testInsertBatchStampsCurrentTenantIdOnAllRows(): void
    {
        // Batch insertion fires a different event than single inserts; without
        // a dedicated handler these rows would be orphaned (no tenant_id).
        $this->setTenant(2);

        $this->model()->insertBatch([
            ['name' => 'delta'],
            ['name' => 'epsilon'],
        ]);

        $rows = $this->db->table('widgets')->whereIn('name', ['delta', 'epsilon'])->get()->getResultArray();
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame(2, (int) $row['tenant_id']);
        }
    }

    public function testInsertBatchOverwritesSuppliedForeignTenantId(): void
    {
        $this->setTenant(1);

        $this->model()->insertBatch([
            ['name' => 'delta', 'tenant_id' => 2],
        ]);

        $row = $this->db->table('widgets')->where('name', 'delta')->get()->getRowArray();
        $this->assertSame(1, (int) $row['tenant_id']);
    }

    public function testInsertBatchWithoutTenantThrows(): void
    {
        $this->setTenant(null);

        $this->expectException(TenantNotFoundException::class);
        $this->model()->insertBatch([['name' => 'delta']]);
    }

    public function testUpdateBatchOnlyTouchesOwnedRows(): void
    {
        // The framework's batch update ignores builder where clauses, so the
        // beforeUpdateBatch callback adds the tenant column to the match
        // constraint instead: tenant 1's batch must not touch row 3 (tenant 2),
        // mirroring the silent scoping of a single update.
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

    public function testModelUpdateBatchAlwaysRequiresAnIndex(): void
    {
        // Load-bearing assumption: the framework rejects a missing
        // index before reaching the builder, so the constraint we add in
        // beforeUpdateBatch is always ANDed with a caller constraint rather
        // than standing alone. If a future CI4 relaxes this, tenant_id
        // would become the sole match column and every row in the tenant
        // would be updated. Fail here first.
        $this->setTenant(1);

        $this->expectException(\CodeIgniter\Exceptions\InvalidArgumentException::class);
        $this->model()->updateBatch([['id' => 1, 'name' => 'x']]);
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

    public function testUpdateBatchWithoutTenantThrows(): void
    {
        $this->setTenant(null);

        // The native beforeUpdateBatch callback refuses the write.
        $this->expectException(TenantNotFoundException::class);
        $this->model()->updateBatch([['id' => 1, 'name' => 'x']], 'id');
    }

    public function testUpdateCannotTouchAnotherTenantsRow(): void
    {
        // Tenant 1 tries to rename tenant 2's row (id 3).
        $this->setTenant(1);

        $this->model()->update(3, ['name' => 'hacked']);

        $row = $this->db->table('widgets')->where('id', 3)->get()->getRowArray();
        $this->assertSame('gamma', $row['name'], 'tenant 2 row must be untouched');
    }

    public function testWithoutTenantBypassesScoping(): void
    {
        $this->setTenant(1);

        $rows = TenantableModel::withoutTenant(fn () => $this->model()->findAll());

        $this->assertCount(3, $rows);
    }

    public function testCountAllResultsCountsOnlyCurrentTenantRows(): void
    {
        // CI4 fires no model event around countAllResults(), so this is
        // the one read path carried by an override rather than a callback.
        $this->setTenant(1);

        $this->assertSame(2, $this->model()->countAllResults());
    }

    public function testCountAllResultsWithoutTenantReturnsZero(): void
    {
        // Fail safe: no tenant means no rows, matching findAll().
        $this->setTenant(null);

        $this->assertSame(0, $this->model()->countAllResults());
    }

    public function testCountAllResultsCountsAllRowsWhenBypassing(): void
    {
        $this->setTenant(1);

        $count = TenantableModel::withoutTenant(fn () => $this->model()->countAllResults());

        $this->assertSame(3, $count);
    }

    public function testMissingTenantColumnFailsClosed(): void
    {
        $this->setTenant(1);

        $this->expectException(TenantIsolationException::class);
        $this->modelWithoutTenantColumn()->findAll();
    }
}
