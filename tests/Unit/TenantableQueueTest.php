<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Exceptions\QueueUnavailableException;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Services\TenantableQueue;
use nuelcyoung\tenantable\Tests\Support\LogCapture;

/**
 * Records every push so the payload that would reach the worker can be
 * asserted on without a queue package installed.
 */
class RecordingQueueHandler
{
    /** @var list<array{queue: string, job: string, data: array<string, mixed>}> */
    public array $pushed = [];

    public bool $returns = true;

    public function push(string $queue, string $job, array $data): bool
    {
        $this->pushed[] = ['queue' => $queue, 'job' => $job, 'data' => $data];

        return $this->returns;
    }

    public function size(string $queue): int
    {
        return count($this->pushed);
    }

    /** @return array<string, mixed> */
    public function lastPayload(): array
    {
        return $this->pushed[array_key_last($this->pushed)]['data'];
    }
}

/**
 * @covers \nuelcyoung\tenantable\Services\TenantableQueue
 * @covers \nuelcyoung\tenantable\Exceptions\QueueUnavailableException
 */
class TenantableQueueTest extends TestCase
{
    private RecordingQueueHandler $handler;
    private TenantableQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new RecordingQueueHandler();
        $this->queue   = new TenantableQueue($this->handler);

        TenantManager::getInstance()->clear();
    }

    protected function tearDown(): void
    {
        TenantManager::getInstance()->clear();
        LogCapture::stop();

        parent::tearDown();
    }

    private function activateTenant(int $id): void
    {
        TenantManager::getInstance()->setTenant([
            'id'        => $id,
            'name'      => "Tenant {$id}",
            'is_active' => 1,
        ]);
    }

    public function testPushStampsTheActiveTenant(): void
    {
        $this->activateTenant(5);

        $this->queue->push('emails', 'invoice', ['to' => 'a@example.com']);

        $this->assertSame(
            ['to' => 'a@example.com', TenantableQueue::TENANT_KEY => 5],
            $this->handler->lastPayload()
        );
    }

    public function testPushWithoutATenantStampsNothing(): void
    {
        $this->queue->push('emails', 'digest', ['scope' => 'all']);

        $this->assertSame(['scope' => 'all'], $this->handler->lastPayload());
    }

    public function testPushForwardsQueueAndJobNames(): void
    {
        $this->activateTenant(2);

        $this->queue->push('reports', 'monthly', []);

        $this->assertSame('reports', $this->handler->pushed[0]['queue']);
        $this->assertSame('monthly', $this->handler->pushed[0]['job']);
    }

    public function testPushReturnsWhatTheHandlerReturns(): void
    {
        $this->handler->returns = false;

        $this->assertFalse($this->queue->push('emails', 'invoice'));
    }

    public function testPushCentralDropsTheActiveTenant(): void
    {
        $this->activateTenant(5);

        $this->queue->pushCentral('billing', 'reconcile', ['month' => '2026-08']);

        $this->assertSame(['month' => '2026-08'], $this->handler->lastPayload());
    }

    public function testPushCentralDropsASuppliedStampToo(): void
    {
        // "Central" has to mean central, whatever the caller put in the array.
        $this->queue->pushCentral('billing', 'reconcile', [TenantableQueue::TENANT_KEY => 9]);

        $this->assertSame([], $this->handler->lastPayload());
    }

    public function testPushForTenantIgnoresTheActiveTenant(): void
    {
        $this->activateTenant(5);

        $this->queue->pushForTenant(11, 'emails', 'invoice', []);

        $this->assertSame(11, $this->handler->lastPayload()[TenantableQueue::TENANT_KEY]);
    }

    public function testACallerSuppliedStampNeverBeatsTheContext(): void
    {
        $this->activateTenant(5);

        $this->queue->push('emails', 'invoice', [TenantableQueue::TENANT_KEY => 9]);

        $this->assertSame(5, $this->handler->lastPayload()[TenantableQueue::TENANT_KEY]);
    }

    public function testAnOverriddenStampIsLogged(): void
    {
        LogCapture::start();
        $this->activateTenant(5);

        $this->queue->push('emails', 'invoice', [TenantableQueue::TENANT_KEY => 9]);

        $this->assertTrue(LogCapture::has('warning', 'was pushed under tenant 5'));
    }

    public function testStampingLeavesTheJobsOwnTenantIdFieldAlone(): void
    {
        $this->activateTenant(5);

        $this->queue->push('emails', 'invoice', ['tenant_id' => 42]);

        $payload = $this->handler->lastPayload();

        $this->assertSame(42, $payload['tenant_id']);
        $this->assertSame(5, $payload[TenantableQueue::TENANT_KEY]);
    }

    public function testTenantIdReadsBackWhatStampWrote(): void
    {
        $stamped = TenantableQueue::stamp(['a' => 1], 7);

        $this->assertSame(7, TenantableQueue::tenantId($stamped));
        $this->assertSame(['a' => 1], TenantableQueue::strip($stamped));
    }

    public function testTenantIdAcceptsADigitStringFromJsonStorage(): void
    {
        $this->assertSame(7, TenantableQueue::tenantId([TenantableQueue::TENANT_KEY => '7']));
    }

    /**
     * @dataProvider unusableStamps
     */
    public function testTenantIdRejectsAnythingElse(mixed $stamp): void
    {
        // Coercing junk would pick a tenant at random, so a bad stamp must
        // read as "central" and let the fail-closed paths take over.
        $this->assertNull(TenantableQueue::tenantId([TenantableQueue::TENANT_KEY => $stamp]));
    }

    /** @return array<string, array{0: mixed}> */
    public static function unusableStamps(): array
    {
        return [
            'empty string' => [''],
            'non numeric'  => ['abc'],
            'float string' => ['3.7'],
            'zero'         => [0],
            'negative'     => [-1],
            'bool'         => [true],
            'array'        => [[5]],
            'null'         => [null],
        ];
    }

    public function testUnknownMethodsProxyToTheHandler(): void
    {
        $this->queue->push('emails', 'invoice');

        $this->assertSame(1, $this->queue->size('emails'));
    }

    public function testAMethodTheHandlerLacksFailsLoudly(): void
    {
        $this->expectException(QueueUnavailableException::class);
        $this->expectExceptionMessageMatches('/nope/');

        $this->queue->nope();
    }

    public function testPushingWithNoQueueInstalledFailsLoudly(): void
    {
        // The test harness registers no 'queue' service, matching an app that
        // never ran `composer require codeigniter4/queue`.
        $this->expectException(QueueUnavailableException::class);
        $this->expectExceptionMessageMatches('/codeigniter4\/queue/');

        (new TenantableQueue())->push('emails', 'invoice');
    }

    public function testBuildingTheWrapperWithoutAQueueIsFine(): void
    {
        // Resolution is lazy so the helper functions and the bootstrapper can
        // exist in an app that has no queue at all.
        $queue = new TenantableQueue();

        $this->assertInstanceOf(TenantableQueue::class, $queue);
    }
}
