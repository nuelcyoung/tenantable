<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Feature;

use CodeIgniter\Database\BaseConnection;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Support\TenantContextState;
use nuelcyoung\tenantable\Tests\Support\LogCapture;
use nuelcyoung\tenantable\Validation\TenantRules;
use PHPUnit\Framework\TestCase;

/**
 * Row-level isolation puts the tenant in a column, and CodeIgniter's
 * is_unique builds its query straight off the connection, so it never sees
 * the model callbacks that would add the tenant_id predicate. These tests
 * pin the scoped replacements' behaviour against a real database.
 *
 * @coversNothing
 */
class TenantScopedValidationTest extends TestCase
{
    private BaseConnection $db;
    private TenantRules $rules;

    protected function setUp(): void
    {
        parent::setUp();

        // Stop the framework's event initialization from including a
        // non-existent events config (which would emit a warning under
        // failOnWarning).
        $ref = new \ReflectionProperty(\CodeIgniter\Events\Events::class, 'initialized');
        $ref->setAccessible(true);
        $ref->setValue(null, true);

        TenantManager::resetInstance();
        TenantContextState::disableTenantBypass();

        $this->rules = new TenantRules();

        // The test bootstrap's Config\Database::connect() hands back a
        // shared in-memory SQLite connection, the same one the rule will
        // resolve internally.
        $this->db = \Config\Database::connect();
        $this->db->query('DROP TABLE IF EXISTS posts');
        $this->db->query(
            'CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, slug TEXT)'
        );
        $this->db->table('posts')->insertBatch([
            ['id' => 10, 'tenant_id' => 1, 'slug' => 'hello'],
            ['id' => 11, 'tenant_id' => 1, 'slug' => 'other'],
            ['id' => 12, 'tenant_id' => 2, 'slug' => 'world'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS posts');
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

    public function testValueTakenByAnotherTenantIsFreeForThisTenant(): void
    {
        $this->setTenant(1);

        // "world" belongs to tenant 2. The framework's rule would reject it;
        // scoped to tenant 1 it is available.
        $this->assertTrue($this->rules->is_unique_for_tenant('world', 'posts.slug', []));
    }

    public function testValueTakenWithinTheSameTenantIsRejected(): void
    {
        $this->setTenant(1);

        $this->assertFalse($this->rules->is_unique_for_tenant('hello', 'posts.slug', []));
    }

    public function testUnusedValueIsUnique(): void
    {
        $this->setTenant(1);

        $this->assertTrue($this->rules->is_unique_for_tenant('brand-new', 'posts.slug', []));
    }

    public function testRowMayKeepItsOwnValueOnUpdate(): void
    {
        $this->setTenant(1);

        // The ignore pair excludes the row being updated.
        $this->assertTrue($this->rules->is_unique_for_tenant('hello', 'posts.slug,id,10', []));
    }

    public function testRowMayNotTakeASiblingValueOnUpdate(): void
    {
        $this->setTenant(1);

        $this->assertFalse($this->rules->is_unique_for_tenant('other', 'posts.slug,id,10', []));
    }

    public function testUnfilledPlaceholderIsIgnoredLikeTheFrameworkRule(): void
    {
        $this->setTenant(1);

        // On insert there is no id yet, so {id} is never substituted; the
        // ignore clause must be dropped rather than matched literally.
        $this->assertFalse($this->rules->is_unique_for_tenant('hello', 'posts.slug,id,{id}', []));
        $this->assertTrue($this->rules->is_unique_for_tenant('world', 'posts.slug,id,{id}', []));
    }

    public function testFailsClosedWithoutATenant(): void
    {
        $this->setTenant(null);

        // Never silently widen to every tenant's rows: an unscoped check is
        // refused outright, even for a value nobody has taken.
        $this->assertFalse($this->rules->is_unique_for_tenant('brand-new', 'posts.slug', []));
    }

    public function testBypassChecksAcrossTenants(): void
    {
        $this->setTenant(1);
        TenantContextState::enableTenantBypass();

        // Mirrors TenantableTrait: a superadmin bypass spans tenants, so
        // uniqueness spans them too.
        $this->assertFalse($this->rules->is_unique_for_tenant('world', 'posts.slug', []));
    }

    public function testCustomTenantColumnViaFourthParameter(): void
    {
        $this->db->query('DROP TABLE IF EXISTS accounts');
        $this->db->query(
            'CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, slug TEXT)'
        );
        $this->db->table('accounts')->insert(['account_id' => 2, 'slug' => 'taken']);

        $this->setTenant(1);

        // The row belongs to account 2, so it is free for tenant 1 …
        $this->assertTrue($this->rules->is_unique_for_tenant('taken', 'accounts.slug,,,account_id', []));

        // … and taken for tenant 2.
        $this->setTenant(2);
        $this->assertFalse($this->rules->is_unique_for_tenant('taken', 'accounts.slug,,,account_id', []));

        $this->db->query('DROP TABLE IF EXISTS accounts');
    }

    public function testIsNotUniqueForTenantFindsOwnRows(): void
    {
        $this->setTenant(1);

        $this->assertTrue($this->rules->is_not_unique_for_tenant('hello', 'posts.slug', []));
    }

    public function testIsNotUniqueForTenantIgnoresOtherTenantsRows(): void
    {
        $this->setTenant(1);

        // "world" exists, but not for this tenant, so it must not count.
        $this->assertFalse($this->rules->is_not_unique_for_tenant('world', 'posts.slug', []));
    }

    public function testIsNotUniqueForTenantFailsClosedWithoutATenant(): void
    {
        $this->setTenant(null);

        $this->assertFalse($this->rules->is_not_unique_for_tenant('hello', 'posts.slug', []));
    }

    public function testFailClosedLogNamesTheRuleThatFailed(): void
    {
        $this->setTenant(null);

        LogCapture::start();

        try {
            $this->rules->is_unique_for_tenant('brand-new', 'posts.slug', []);
            $this->assertTrue(
                LogCapture::has('warning', 'is_unique_for_tenant ran with no active tenant'),
                'The warning must name the rule that failed closed.'
            );

            LogCapture::start();

            $this->rules->is_not_unique_for_tenant('hello', 'posts.slug', []);
            $this->assertTrue(
                LogCapture::has('warning', 'is_not_unique_for_tenant ran with no active tenant'),
                'Each rule must log under its own name, not the other one\'s.'
            );
        } finally {
            LogCapture::stop();
        }
    }

    public function testNonScalarValuesAreRejected(): void
    {
        $this->setTenant(1);

        $this->assertFalse($this->rules->is_unique_for_tenant(['a'], 'posts.slug', []));
        $this->assertFalse($this->rules->is_not_unique_for_tenant(new \stdClass(), 'posts.slug', []));
    }

    public function testMalformedFieldParameterThrows(): void
    {
        $this->setTenant(1);

        $this->expectException(\InvalidArgumentException::class);

        $this->rules->is_unique_for_tenant('hello', 'posts', []);
    }
}
