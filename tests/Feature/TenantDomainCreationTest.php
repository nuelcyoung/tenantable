<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Feature;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Database;
use nuelcyoung\tenantable\Models\TenantDomainModel;
use PHPUnit\Framework\TestCase;

/**
 * Tests that `tenants:create --domain` stores domains in the tenant_domains table.
 *
 * @coversNothing
 */
class TenantDomainCreationTest extends TestCase
{
    private BaseConnection $db;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent the framework's event initialization from including a
        // non-existent events config under failOnWarning.
        $ref = new \ReflectionProperty(\CodeIgniter\Events\Events::class, 'initialized');
        $ref->setAccessible(true);
        $ref->setValue(null, true);

        /** @var BaseConnection $db */
        $db = (new Database())->load([
            'DBDriver' => 'SQLite3',
            'database' => ':memory:',
            'DBPrefix' => '',
        ], 'domain-tests');
        $this->db = $db;

        $this->db->query(
            'CREATE TABLE tenant_domains (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER,
                domain TEXT,
                is_primary INTEGER DEFAULT 0,
                is_verified INTEGER DEFAULT 0,
                verified_at TEXT NULL,
                ssl_state TEXT DEFAULT "none",
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );
    }

    private function model(): TenantDomainModel
    {
        // Skip validation + callbacks so the test does not depend on the
        // validation service or the Events pipeline.
        return new class($this->db) extends TenantDomainModel {
            protected $skipValidation = true;
            protected $allowCallbacks = false;
        };
    }

    public function testDomainIsStoredInTenantDomainsAsPrimary(): void
    {
        $model = $this->model();

        $id = $model->insert([
            'tenant_id'  => 1,
            'domain'     => 'acme.com',
            'is_primary' => 1,
        ], true);

        $this->assertNotFalse($id);

        $row = $this->db->table('tenant_domains')->where('id', $id)->get()->getRowArray();

        $this->assertSame(1, (int) $row['tenant_id']);
        $this->assertSame('acme.com', $row['domain']);
        $this->assertSame(1, (int) $row['is_primary']);
    }

    public function testDomainIsRetrievableByLookup(): void
    {
        $model = $this->model();
        $model->insert([
            'tenant_id'  => 7,
            'domain'     => 'globex.example',
            'is_primary' => 1,
        ], true);

        $found = $model->where('domain', 'globex.example')->first();

        $this->assertNotNull($found);
        $this->assertSame(7, (int) $found['tenant_id']);
    }

    public function testResolverLookupRequiresVerifiedDomain(): void
    {
        $this->db->table('tenant_domains')->insert([
            'tenant_id'   => 1,
            'domain'      => 'pending.example',
            'is_verified' => 0,
            'is_primary'  => 1,
        ]);
        $this->db->table('tenant_domains')->insert([
            'tenant_id'   => 2,
            'domain'      => 'verified.example',
            'is_verified' => 1,
            'verified_at' => '2026-01-01 00:00:00',
            'is_primary'  => 1,
        ]);

        $model = $this->model();

        $verified = fn (string $domain) => $model
            ->where('domain', TenantDomainModel::normalizeDomain($domain))
            ->where('is_verified', 1)
            ->where('verified_at IS NOT NULL')
            ->first();

        $this->assertNull($verified('pending.example'));
        $this->assertSame(2, (int) $verified('VERIFIED.EXAMPLE.')['tenant_id']);
    }

    public function testVerificationStateCannotBeClaimedOnInsert(): void
    {
        $model = new class($this->db) extends TenantDomainModel {
            protected $skipValidation = true;
        };

        $id = $model->insert([
            'tenant_id'   => 1,
            'domain'      => 'pending.example',
            'is_verified' => 1,
        ], true);

        $row = $this->db->table('tenant_domains')->where('id', $id)->get()->getRowArray();
        $this->assertSame(0, (int) $row['is_verified']);

        $this->assertTrue($model->markVerified((int) $id));
        $row = $this->db->table('tenant_domains')->where('id', $id)->get()->getRowArray();
        $this->assertSame(1, (int) $row['is_verified']);
    }
}
