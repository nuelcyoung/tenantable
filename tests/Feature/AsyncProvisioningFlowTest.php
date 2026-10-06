<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Feature;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Config\Tenantable as PackageConfig;
use nuelcyoung\tenantable\Exceptions\TenantNotReadyException;
use nuelcyoung\tenantable\Jobs\ProvisionTenantJob;
use nuelcyoung\tenantable\Models\TenantModel;
use nuelcyoung\tenantable\Services\TenantDatabaseManager;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Services\TenantResolverCache;
use nuelcyoung\tenantable\Services\TenantableQueue;
use nuelcyoung\tenantable\Tests\Support\LogCapture;

/** Captures pushes instead of needing a queue package. */
class SpyQueueHandler
{
    /** @var list<array{queue: string, job: string, data: array<string, mixed>}> */
    public array $pushed = [];

    public bool $accepts = true;

    public bool $throws = false;

    public function push(string $queue, string $job, array $data): bool
    {
        if ($this->throws) {
            throw new \RuntimeException('no queue configured');
        }

        $this->pushed[] = ['queue' => $queue, 'job' => $job, 'data' => $data];

        return $this->accepts;
    }
}

/** A TenantModel whose config and queue are supplied by the test. */
class AsyncTenantModel extends TenantModel
{
    public static ?PackageConfig $configOverride = null;
    public static ?SpyQueueHandler $handler      = null;

    // The harness registers no validation service, and the rules are not what
    // this test is about.
    protected $skipValidation = true;

    protected function tenantableConfig(): PackageConfig
    {
        return self::$configOverride ?? new PackageConfig();
    }

    protected function provisioningQueue(): TenantableQueue
    {
        return new TenantableQueue(self::$handler);
    }
}

/** Provisions nothing; records that it was asked to. */
class SpyProvisionJob extends ProvisionTenantJob
{
    public static int $provisionCalls = 0;
    public static bool $provisionFails = false;

    protected function tenantModel(): TenantModel
    {
        return new TenantModel();
    }

    protected function config(): PackageConfig
    {
        // Row isolation: provisionTenant() has no database to create, so the
        // job's own bookkeeping is what this test is about.
        $config                = new PackageConfig();
        $config->isolationMode = 'row';

        return $config;
    }
}

/**
 * The G2 acceptance case end to end: creating a tenant returns without doing
 * the provisioning work, the tenant does not resolve until the job has run,
 * and it resolves immediately after.
 *
 * @coversNothing
 */
class AsyncProvisioningFlowTest extends TestCase
{
    private static string $databaseFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        TenantManager::resetInstance();
        TenantResolverCache::resetInstance();
        LogCapture::start();

        AsyncTenantModel::$handler        = new SpyQueueHandler();
        AsyncTenantModel::$configOverride = $this->asyncConfig();

        // The central connection is non-shared by design, so an in-memory
        // database would be empty each time it is opened.
        self::$databaseFile = WRITEPATH . 'tenantable_async_tests.sqlite';
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
            . "name TEXT, is_active INTEGER DEFAULT 1, status TEXT NOT NULL DEFAULT 'ready', "
            . 'settings TEXT, created_at TEXT, updated_at TEXT)');
        $db->query('DELETE FROM tenants');
        $db->close();
    }

    protected function tearDown(): void
    {
        TenantManager::resetInstance();
        TenantResolverCache::resetInstance();
        LogCapture::stop();

        AsyncTenantModel::$handler        = null;
        AsyncTenantModel::$configOverride = null;
        SpyProvisionJob::$provisionCalls  = 0;
        SpyProvisionJob::$provisionFails  = false;

        $this->setCentralConfig(null);

        if (self::$databaseFile !== '' && is_file(self::$databaseFile)) {
            @unlink(self::$databaseFile);
        }

        parent::tearDown();
    }

    private function asyncConfig(): PackageConfig
    {
        $config                 = new PackageConfig();
        $config->provisionAsync = true;
        $config->isolationMode  = 'row';

        return $config;
    }

    /** @param array<string, mixed>|null $config */
    private function setCentralConfig(?array $config): void
    {
        $property = new \ReflectionProperty(TenantDatabaseManager::class, 'centralConfig');
        $property->setAccessible(true);
        $property->setValue(null, $config);
    }

    private function createTenant(): int
    {
        $model = new AsyncTenantModel();
        $id    = $model->insert(['subdomain' => 'acme', 'name' => 'Acme', 'is_active' => 1]);

        $this->assertIsInt($id, 'Tenant insert failed: ' . implode(', ', $model->errors()));

        return $id;
    }

    private function tenantStatus(int $id): string
    {
        return (string) (new TenantModel())->find($id)['status'];
    }

    public function testANewTenantStartsOutProvisioning(): void
    {
        $id = $this->createTenant();

        $this->assertSame(TenantModel::STATUS_PROVISIONING, $this->tenantStatus($id));
    }

    public function testCreatingATenantQueuesTheProvisioningJob(): void
    {
        $id = $this->createTenant();

        $pushed = AsyncTenantModel::$handler->pushed;

        $this->assertCount(1, $pushed);
        $this->assertSame(ProvisionTenantJob::QUEUE, $pushed[0]['queue']);
        $this->assertSame(ProvisionTenantJob::NAME, $pushed[0]['job']);
        $this->assertSame(['tenant_id' => $id], $pushed[0]['data']);
    }

    public function testTheQueuedJobCarriesNoTenantStamp(): void
    {
        // It runs centrally on purpose: booting the tenant would mean
        // connecting to storage this job has not created yet.
        $this->createTenant();

        $this->assertNull(TenantableQueue::tenantId(AsyncTenantModel::$handler->pushed[0]['data']));
    }

    public function testAProvisioningTenantDoesNotResolve(): void
    {
        $id = $this->createTenant();

        $this->expectException(TenantNotReadyException::class);

        TenantManager::getInstance()->setTenantById($id);
    }

    public function testTheTenantResolvesOnceTheJobHasRun(): void
    {
        $id = $this->createTenant();

        (new SpyProvisionJob(['tenant_id' => $id]))->process();

        $this->assertSame(TenantModel::STATUS_READY, $this->tenantStatus($id));

        TenantManager::getInstance()->setTenantById($id);
        $this->assertSame($id, TenantManager::getInstance()->getTenantId());
    }

    public function testTheJobFiresTenantCreatedWhenTheTenantIsUsable(): void
    {
        $id     = $this->createTenant();
        $seen   = [];

        \CodeIgniter\Events\Events::on('tenantCreated', static function ($event) use (&$seen): void {
            $seen[] = $event->tenantId ?? null;
        });

        (new SpyProvisionJob(['tenant_id' => $id]))->process();

        $this->assertSame([$id], $seen);

        \CodeIgniter\Events\Events::removeAllListeners('tenantCreated');
    }

    public function testAJobForADeletedTenantIsNotAFailure(): void
    {
        $id = $this->createTenant();
        (new TenantModel())->where('id', $id)->delete(null, true);

        $result = (new SpyProvisionJob(['tenant_id' => $id]))->process();

        $this->assertFalse($result);
        $this->assertTrue(LogCapture::has('info', 'no longer exists'));
    }

    public function testAnUnavailableQueueFallsBackToInlineProvisioning(): void
    {
        // Better a slow signup than a tenant stuck in 'provisioning' forever.
        AsyncTenantModel::$handler->throws = true;

        $id = $this->createTenant();

        $this->assertSame(TenantModel::STATUS_READY, $this->tenantStatus($id));
        $this->assertTrue(LogCapture::has('error', 'Provisioning inline instead.'));
    }

    public function testARejectedPushFallsBackToInlineProvisioning(): void
    {
        AsyncTenantModel::$handler->accepts = false;

        $id = $this->createTenant();

        $this->assertSame(TenantModel::STATUS_READY, $this->tenantStatus($id));
    }

    public function testSyncModeNeverTouchesTheQueue(): void
    {
        $config                 = new PackageConfig();
        $config->provisionAsync = false;
        $config->isolationMode  = 'row';

        AsyncTenantModel::$configOverride = $config;

        $id = $this->createTenant();

        $this->assertSame([], AsyncTenantModel::$handler->pushed);
        $this->assertSame(TenantModel::STATUS_READY, $this->tenantStatus($id));
    }
}
