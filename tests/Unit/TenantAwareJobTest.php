<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Bootstrap\TenantAwareInterface;
use nuelcyoung\tenantable\Bootstrap\TenantBootstrap;
use nuelcyoung\tenantable\Queue\TenantableJob;
use nuelcyoung\tenantable\Services\TenantDatabaseManager;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Services\TenantResolverCache;
use nuelcyoung\tenantable\Services\TenantableQueue;
use nuelcyoung\tenantable\Traits\TenantAwareJob;

/**
 * A job that records what the tenant context looked like while it ran.
 */
class SpyJob extends TenantableJob
{
    public ?int $sawTenantId = null;

    /** @var array<string, mixed>|null */
    public ?array $sawData = null;

    public bool $ran = false;

    protected function handle(array $data): mixed
    {
        $this->ran         = true;
        $this->sawTenantId = tenant_id();
        $this->sawData     = $data;

        return 'done';
    }
}

class ThrowingJob extends TenantableJob
{
    protected function handle(array $data): mixed
    {
        throw new \RuntimeException('job body failed');
    }
}

/** Uses the trait directly, the way a job with another parent would. */
class StandaloneJob
{
    use TenantAwareJob;

    /** @param array<string, mixed> $payload */
    public function run(array $payload, callable $handler): mixed
    {
        return $this->runInTenantContext($payload, $handler);
    }
}

/** Records boot/shutdown so context lifecycle can be asserted. */
class BootSpySystem implements TenantAwareInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function boot(?int $tenantId, ?array $tenant): void
    {
        $this->calls[] = 'boot:' . ($tenantId ?? 'central');
    }

    public function shutdown(): void
    {
        $this->calls[] = 'shutdown';
    }
}

/**
 * The acceptance case for queue tenancy: a job pushed under a tenant runs
 * under that tenant, and the worker is left clean afterwards.
 *
 * @covers \nuelcyoung\tenantable\Traits\TenantAwareJob
 * @covers \nuelcyoung\tenantable\Queue\TenantableJob
 */
class TenantAwareJobTest extends TestCase
{
    private static string $databaseFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        TenantManager::resetInstance();
        TenantBootstrap::resetInstance();
        TenantResolverCache::resetInstance();

        // tenancy_run() resolves the tenant through TenantModel, which runs on
        // the *central* connection. That one is non-shared by design, so an
        // in-memory SQLite database would be empty every time it is opened.
        // A file-backed database makes the real lookup path testable here.
        self::$databaseFile = WRITEPATH . 'tenantable_job_tests.sqlite';
        $this->setCentralConfig([
            'DBDriver' => 'SQLite3',
            'database' => self::$databaseFile,
            'DBPrefix' => '',
        ]);

        $db = \CodeIgniter\Database\Config::connect([
            'DBDriver' => 'SQLite3',
            'database' => self::$databaseFile,
            'DBPrefix' => '',
        ], false);

        $db->query('CREATE TABLE IF NOT EXISTS tenants ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, subdomain TEXT, domain TEXT, '
            . 'name TEXT, is_active INTEGER DEFAULT 1, settings TEXT, '
            . 'created_at TEXT, updated_at TEXT)');
        $db->query('DELETE FROM tenants');
        $db->table('tenants')->insertBatch([
            ['id' => 5, 'subdomain' => 'five', 'name' => 'Tenant 5', 'is_active' => 1],
            ['id' => 8, 'subdomain' => 'eight', 'name' => 'Tenant 8', 'is_active' => 1],
            ['id' => 3, 'subdomain' => 'three', 'name' => 'Tenant 3', 'is_active' => 1],
        ]);
        $db->close();
    }

    protected function tearDown(): void
    {
        TenantManager::resetInstance();
        TenantBootstrap::resetInstance();
        TenantResolverCache::resetInstance();
        $this->setCentralConfig(null);

        if (self::$databaseFile !== '' && is_file(self::$databaseFile)) {
            @unlink(self::$databaseFile);
        }

        parent::tearDown();
    }

    /**
     * TenantDatabaseManager caches the central connection settings in a
     * private static; there is no production reason to reset it, so the test
     * reaches in.
     *
     * @param array<string, mixed>|null $config
     */
    private function setCentralConfig(?array $config): void
    {
        $property = new \ReflectionProperty(TenantDatabaseManager::class, 'centralConfig');
        $property->setAccessible(true);
        $property->setValue(null, $config);
    }

    /** @param array<string, mixed> $data */
    private function job(array $data): SpyJob
    {
        return new SpyJob($data);
    }

    public function testAJobRunsInTheTenantItWasPushedUnder(): void
    {
        $job = $this->job(TenantableQueue::stamp(['invoice' => 12], 5));

        $job->process();

        $this->assertTrue($job->ran);
        $this->assertSame(5, $job->sawTenantId);
    }

    public function testTheHandlerNeverSeesThePackageKey(): void
    {
        $job = $this->job(TenantableQueue::stamp(['invoice' => 12], 5));

        $job->process();

        $this->assertSame(['invoice' => 12], $job->sawData);
    }

    public function testProcessReturnsTheHandlerResult(): void
    {
        $job = $this->job(TenantableQueue::stamp([], 5));

        $this->assertSame('done', $job->process());
    }

    public function testTheContextIsClearedAfterTheJob(): void
    {
        // A worker processes many tenants' jobs in one process; leaking the
        // context would hand the next job the previous tenant's connection.
        $this->job(TenantableQueue::stamp([], 5))->process();

        $this->assertNull(tenant_id());
    }

    public function testTheContextIsClearedWhenTheJobBodyThrows(): void
    {
        $job = new ThrowingJob(TenantableQueue::stamp([], 5));

        try {
            $job->process();
            $this->fail('The job body exception should surface.');
        } catch (\RuntimeException $e) {
            $this->assertSame('job body failed', $e->getMessage());
        }

        $this->assertNull(tenant_id());
    }

    public function testConsecutiveJobsGetTheirOwnTenant(): void
    {
        $first  = $this->job(TenantableQueue::stamp([], 5));
        $second = $this->job(TenantableQueue::stamp([], 8));

        $first->process();
        $second->process();

        $this->assertSame(5, $first->sawTenantId);
        $this->assertSame(8, $second->sawTenantId);
    }

    public function testAnUnstampedJobRunsCentrally(): void
    {
        $job = $this->job(['scope' => 'all']);

        $job->process();

        $this->assertTrue($job->ran);
        $this->assertNull($job->sawTenantId);
        $this->assertSame(['scope' => 'all'], $job->sawData);
    }

    public function testAJobForAMissingTenantFailsInsteadOfRunningUntenanted(): void
    {
        $job = $this->job(TenantableQueue::stamp([], 404));

        try {
            $job->process();
            $this->fail('An unresolvable tenant must fail the job.');
        } catch (\Throwable $e) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($job->ran, 'The job body must not run without its tenant.');
        $this->assertNull(tenant_id());
    }

    public function testTheTenantSystemsAreBootedAndTornDownAroundTheJob(): void
    {
        $spy = new BootSpySystem();
        TenantBootstrap::getInstance()->registerSystem('spy', $spy);

        $this->job(TenantableQueue::stamp([], 5))->process();

        $this->assertSame(['boot:5', 'shutdown'], $spy->calls);
    }

    public function testTheTraitWorksOnAJobWithAnotherParent(): void
    {
        $seen = null;

        $result = (new StandaloneJob())->run(
            TenantableQueue::stamp(['x' => 1], 3),
            static function (array $data) use (&$seen): string {
                $seen = ['tenant' => tenant_id(), 'data' => $data];

                return 'ok';
            }
        );

        $this->assertSame('ok', $result);
        $this->assertSame(['tenant' => 3, 'data' => ['x' => 1]], $seen);
    }
}
