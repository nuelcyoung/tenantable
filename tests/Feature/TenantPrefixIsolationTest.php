<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Feature;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Database;
use nuelcyoung\tenantable\Exceptions\MissingTenantContextException;
use nuelcyoung\tenantable\Services\TenantTableManager;
use nuelcyoung\tenantable\Traits\TenantTablePrefixModel;
use PHPUnit\Framework\TestCase;

/**
 * Tests that the table-prefix strategy actually queries per-tenant tables.
 * Guards against the old bug where getTable()-only overrode nothing.
 *
 * @coversNothing
 */
class TenantPrefixIsolationTest extends TestCase
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

        TenantTableManager::resetInstance();

        /** @var BaseConnection $db */
        $db = (new Database())->load([
            'DBDriver' => 'SQLite3',
            'database' => ':memory:',
            'DBPrefix' => '',
        ], 'prefix-isolation-tests');
        $this->db = $db;

        foreach (['tenant_1_students', 'tenant_2_students'] as $table) {
            $this->db->query("CREATE TABLE {$table} (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
        }

        // Poison pill: the un-prefixed shared table must never be queried.
        // Any regression to base-table access shows up as this row leaking
        // into results (or row counts changing underneath the assertions).
        $this->db->query('CREATE TABLE students (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->db->table('students')->insert(['name' => 'shared-poison']);

        $this->db->table('tenant_1_students')->insertBatch([
            ['name' => 'alpha'],
            ['name' => 'beta'],
        ]);
        // Explicit id 3: each physical table has its own AUTOINCREMENT
        // sequence, so without it gamma would get id 1 and the cross-tenant
        // assertions would collide with tenant 1's record ids.
        $this->db->table('tenant_2_students')->insert(['id' => 3, 'name' => 'gamma']);
    }

    protected function tearDown(): void
    {
        TenantTableManager::resetInstance();
        parent::tearDown();
    }

    private function setTenant(?int $id): void
    {
        $manager = TenantTableManager::getInstance();

        if ($id === null) {
            $manager->clear();
        } else {
            $manager->setTenant($id, "tenant{$id}");
        }
    }

    private function model(): TenantTablePrefixModel
    {
        return new class($this->db) extends TenantTablePrefixModel {
            protected $table         = 'students';
            protected $primaryKey    = 'id';
            protected $returnType    = 'array';
            protected $allowedFields = ['name'];
            protected $useTimestamps = false;
        };
    }

    private function sharedTableNames(): array
    {
        return array_column(
            $this->db->query('SELECT name FROM students')->getResultArray(),
            'name'
        );
    }

    /**
     * Raw-SQL assertion channels. Once a model binds to its connection the
     * connection carries the tenant's DBPrefix, so the query builder
     * would prefix these names a second time; raw queries are immune.
     */
    private function rawRow(string $table, int $id): ?array
    {
        return $this->db->query("SELECT * FROM {$table} WHERE id = {$id}")->getRowArray();
    }

    private function rawCount(string $table): int
    {
        return (int) ($this->db->query("SELECT COUNT(*) AS c FROM {$table}")->getRowArray()['c'] ?? 0);
    }

    public function testFindAllQueriesPrefixedTableForTenant1(): void
    {
        $this->setTenant(1);

        $rows = $this->model()->findAll();

        $this->assertSame(['alpha', 'beta'], array_column($rows, 'name'));
    }

    public function testFindAllQueriesPrefixedTableForTenant2(): void
    {
        $this->setTenant(2);

        $rows = $this->model()->findAll();

        $this->assertSame(['gamma'], array_column($rows, 'name'));
    }

    public function testBuilderTargetsPhysicalPrefixedTable(): void
    {
        $this->setTenant(1);

        $model = $this->model();

        // The model's $table stays the base name; CI4's DBPrefix resolves
        // the physical table when the query is compiled.
        $this->assertSame('tenant_1_students', $model->getTable());
        $this->assertSame('students', $model->getBaseTableName());
        $this->assertStringContainsString('tenant_1_students', $model->builder()->getCompiledSelect(false));
    }

    public function testExplicitBuilderTableCannotBypassTenantPrefix(): void
    {
        $this->setTenant(1);

        // No exception any more: the connection forces the tenant's
        // DBPrefix onto every table at compile time, so another tenant's
        // table can never be addressed. 'tenant_2_students' becomes
        // 'tenant_1_tenant_2_students', a table that does not exist.
        $builder = $this->model()->builder('tenant_2_students');

        $this->assertStringContainsString('tenant_1_tenant_2_students', $builder->getCompiledSelect(false));
    }

    public function testCrossTenantRowIsInvisible(): void
    {
        // Row id 3 lives in tenant_2_students; tenant 1 must not see it.
        $this->setTenant(1);
        $this->assertNull($this->model()->find(3));

        // And tenant 1's rows are invisible to tenant 2.
        $this->setTenant(2);
        $this->assertNull($this->model()->find(1));
    }

    public function testInsertLandsInOwnTenantTable(): void
    {
        $this->setTenant(2);

        $id = $this->model()->insert(['name' => 'delta'], true);

        $row = $this->rawRow('tenant_2_students', (int) $id);
        $this->assertSame('delta', $row['name']);

        $this->assertSame(2, $this->rawCount('tenant_1_students'));
        $this->assertSame(['shared-poison'], $this->sharedTableNames());
    }

    public function testUpdateOnlyTouchesOwnTenantTable(): void
    {
        $this->setTenant(1);

        $this->model()->update(1, ['name' => 'alpha-updated']);

        $own = $this->rawRow('tenant_1_students', 1);
        $this->assertSame('alpha-updated', $own['name']);

        $foreign = $this->rawRow('tenant_2_students', 3);
        $this->assertSame('gamma', $foreign['name'], 'tenant 2 table must be untouched');
    }

    public function testDeleteOnlyTouchesOwnTenantTable(): void
    {
        $this->setTenant(2);

        $this->model()->delete(3);

        $this->assertSame(0, $this->rawCount('tenant_2_students'));
        $this->assertSame(2, $this->rawCount('tenant_1_students'));
    }

    public function testSwitchingTenantRetargetsSameModelInstance(): void
    {
        // The builder method caches a shared builder; a tenant switch must
        // invalidate it or the second query would hit tenant 1's table.
        $model = $this->model();

        $this->setTenant(1);
        $this->assertCount(2, $model->findAll());

        $this->setTenant(2);
        $rows = $model->findAll();

        $this->assertSame(['gamma'], array_column($rows, 'name'));
        $this->assertSame('tenant_2_students', $model->getTable());
        $this->assertStringContainsString('tenant_2_students', $model->builder()->getCompiledSelect(false));
    }

    public function testCrudCycleNeverTouchesUnprefixedSharedTable(): void
    {
        $this->setTenant(1);

        $model = $this->model();
        $id    = $model->insert(['name' => 'delta'], true);
        $model->update($id, ['name' => 'delta-updated']);
        $model->find($id);
        $model->delete($id);

        $this->assertSame(['shared-poison'], $this->sharedTableNames());
    }

    public function testReadsWithoutTenantFailSafeEmpty(): void
    {
        $this->setTenant(null);

        $model = $this->model();

        $this->assertNull($model->find(1));
        $this->assertNull($model->first());
        $this->assertSame([], $model->findAll());
        $this->assertSame(['shared-poison'], $this->sharedTableNames());
    }

    public function testCountAllResultsWithoutTenantFailsClosed(): void
    {
        $this->setTenant(null);

        // The count method fires no model event, so no callback can
        // short-circuit it. Instead the bound connection carries the
        // manager's NO_TENANT_PREFIX sentinel: the query targets a table
        // that never exists and fails loudly; it can never silently
        // count the un-prefixed shared table.
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->model()->countAllResults();
    }

    public function testInsertWithoutTenantThrows(): void
    {
        $this->setTenant(null);

        $this->expectException(MissingTenantContextException::class);
        $this->model()->insert(['name' => 'delta']);
    }

    public function testDeleteWithoutTenantThrows(): void
    {
        $this->setTenant(null);

        $this->expectException(MissingTenantContextException::class);
        $this->model()->delete(1);
    }

    public function testTenantSwitchWithPendingClausesThrows(): void
    {
        // Clauses chained before a tenant switch belong to the old tenant's
        // query; the switch drops the cached builder, so pending clauses
        // would vanish silently. The switch itself now rejects them.
        $this->setTenant(1);

        $model = $this->model();
        $model->where('name', 'alpha'); // pending, never executed

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/pending/');
        $this->setTenant(2);
    }

    public function testTenantSwitchAfterCompletedQueryDoesNotThrow(): void
    {
        // Executed queries reset the builder, so a switch afterwards is
        // clean: the guard must not false-positive on the cached builder.
        $model = $this->model();

        $this->setTenant(1);
        $model->where('name', 'alpha')->findAll();

        $this->setTenant(2);

        $this->assertSame(['gamma'], array_column($model->findAll(), 'name'));
    }

    public function testCountAllResultsScopedPerTenant(): void
    {
        $this->setTenant(1);
        $this->assertSame(2, $this->model()->countAllResults());

        $this->setTenant(2);
        $this->assertSame(1, $this->model()->countAllResults());
    }
}
