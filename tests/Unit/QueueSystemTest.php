<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Bootstrap\Systems\QueueSystem;
use nuelcyoung\tenantable\Bootstrap\TenantAwareInterface;
use nuelcyoung\tenantable\Config\Tenantable;
use nuelcyoung\tenantable\Tests\Support\LogCapture;

/**
 * Supplies a Config\Queue stand-in and a Tenantable config so the check can
 * run without a queue package or a published app config.
 */
class TestableQueueSystem extends QueueSystem
{
    public ?object $queueStub = null;
    public ?Tenantable $tenantableStub = null;
    public int $resets = 0;

    protected function queueConfig(): ?object
    {
        return $this->queueStub;
    }

    protected function tenantableConfig(): ?Tenantable
    {
        return $this->tenantableStub ?? new Tenantable();
    }

    protected function resetSharedQueue(): void
    {
        $this->resets++;
    }
}

/**
 * The queue table is central infrastructure. In database isolation the
 * default connection group is repointed at the active tenant, so a queue
 * sharing that group writes jobs into a tenant database no worker reads,
 * silently, because nothing errors.
 *
 * @covers \nuelcyoung\tenantable\Bootstrap\Systems\QueueSystem
 */
class QueueSystemTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        QueueSystem::resetWarningState();
        LogCapture::start();
    }

    protected function tearDown(): void
    {
        LogCapture::stop();
        QueueSystem::resetWarningState();

        parent::tearDown();
    }

    private function tenantable(string $mode, string $group = 'default'): Tenantable
    {
        $config                        = new Tenantable();
        $config->isolationMode         = $mode;
        $config->defaultDatabaseGroup  = $group;

        return $config;
    }

    private function queueConfig(string $handler, ?string $group): object
    {
        return new class ($handler, $group) {
            /** @var array<string, mixed>|null */
            public ?array $database;

            public function __construct(public string $handler, ?string $group)
            {
                $this->database = $group === null ? null : ['dbGroup' => $group];
            }
        };
    }

    private function system(?object $queueConfig, ?Tenantable $tenantable = null): TestableQueueSystem
    {
        $system                 = new TestableQueueSystem();
        $system->queueStub      = $queueConfig;
        $system->tenantableStub = $tenantable;

        return $system;
    }

    public function testIsATenantAwareBootstrapper(): void
    {
        $this->assertInstanceOf(TenantAwareInterface::class, new QueueSystem());
    }

    public function testTheDefaultBootstrapperListIncludesIt(): void
    {
        $this->assertContains(QueueSystem::class, (new Tenantable())->bootstrappers);
    }

    public function testNothingHappensWithoutAQueueInstalled(): void
    {
        $system = $this->system(null, $this->tenantable('database'));

        $system->boot(5, ['id' => 5]);

        $this->assertSame(0, $system->resets);
        $this->assertSame([], LogCapture::entries('error'));
    }

    public function testCentralBootIsANoOp(): void
    {
        $system = $this->system($this->queueConfig('database', 'default'), $this->tenantable('database'));

        $system->boot(null, null);

        $this->assertSame(0, $system->resets);
        $this->assertSame([], LogCapture::entries('error'));
    }

    public function testASharedGroupIsReportedWithTheFix(): void
    {
        $system = $this->system($this->queueConfig('database', 'default'), $this->tenantable('database'));

        $system->boot(5, ['id' => 5]);

        $this->assertTrue(LogCapture::has('error', "connection group 'default'"));
        $this->assertTrue(LogCapture::has('error', 'dbGroup'), 'The log should name the setting to change.');
    }

    public function testASharedGroupDropsTheStaleQueueHandler(): void
    {
        $system = $this->system($this->queueConfig('database', 'default'), $this->tenantable('database'));

        $system->boot(5, ['id' => 5]);

        $this->assertSame(1, $system->resets);
    }

    public function testTheWarningIsNotRepeatedPerJob(): void
    {
        // A worker boots a tenant for every job; one warning per process is
        // the difference between a signal and a log flood.
        $system = $this->system($this->queueConfig('database', 'default'), $this->tenantable('database'));

        $system->boot(5, ['id' => 5]);
        $system->boot(6, ['id' => 6]);
        $system->boot(7, ['id' => 7]);

        $this->assertCount(1, LogCapture::entries('error'));
        $this->assertSame(3, $system->resets, 'Every switch still needs a fresh handler.');
    }

    public function testAQueueOnItsOwnGroupIsLeftAlone(): void
    {
        $system = $this->system(
            $this->queueConfig('database', 'central_queue'),
            $this->tenantable('database')
        );

        $system->boot(5, ['id' => 5]);

        $this->assertSame(0, $system->resets);
        $this->assertSame([], LogCapture::entries('error'));
    }

    public function testRowModeIsNeverWarned(): void
    {
        // Only database isolation repoints the default group, so sharing it
        // with the queue is fine in row and prefix modes.
        $system = $this->system($this->queueConfig('database', 'default'), $this->tenantable('row'));

        $system->boot(5, ['id' => 5]);

        $this->assertSame([], LogCapture::entries('error'));
    }

    public function testPrefixModeIsNeverWarned(): void
    {
        $system = $this->system($this->queueConfig('database', 'default'), $this->tenantable('prefix'));

        $system->boot(5, ['id' => 5]);

        $this->assertSame([], LogCapture::entries('error'));
    }

    public function testANonDatabaseQueueHandlerIsNeverWarned(): void
    {
        $system = $this->system($this->queueConfig('redis', null), $this->tenantable('database'));

        $system->boot(5, ['id' => 5]);

        $this->assertSame([], LogCapture::entries('error'));
    }

    public function testShutdownWithoutAProblemDoesNothing(): void
    {
        $system = $this->system($this->queueConfig('database', 'central_queue'), $this->tenantable('database'));

        $system->boot(5, ['id' => 5]);
        $system->shutdown();

        $this->assertSame(0, $system->resets);
    }

    public function testShutdownClearsTheHandlerWhenTheQueueFollowedTheTenant(): void
    {
        $system = $this->system($this->queueConfig('database', 'default'), $this->tenantable('database'));

        $system->boot(5, ['id' => 5]);
        $system->shutdown();

        $this->assertSame(2, $system->resets);
    }
}
