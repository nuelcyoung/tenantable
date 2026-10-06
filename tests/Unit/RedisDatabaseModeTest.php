<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Bootstrap\RedisSystem;
use nuelcyoung\tenantable\Tests\Support\LogCapture;

/**
 * Redis exposes a fixed number of logical databases (16 by default), so
 * database-per-tenant cannot scale past that. The mode is deprecated; what
 * these tests pin down is that exhausting it degrades to prefix-only
 * isolation *loudly* rather than silently parking two tenants on one database.
 *
 * @covers \nuelcyoung\tenantable\Bootstrap\RedisSystem
 */
class RedisDatabaseModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LogCapture::start();
    }

    protected function tearDown(): void
    {
        LogCapture::stop();
        parent::tearDown();
    }

    /** @return array{0: TestableRedisSystem, 1: object} */
    private function system(int $maxDatabase = 15): array
    {
        $config = new RedisConfigStub();

        $system = new TestableRedisSystem();
        $system->redisStub = $config;
        $system->setUseDatabasePerTenant(true)->setMaxDatabase($maxDatabase);

        return [$system, $config];
    }

    public function testTenantWithinRangeGetsItsOwnDatabase(): void
    {
        [$system, $config] = $this->system();

        $system->boot(3, ['name' => 'C']);

        $this->assertSame(2, $config->default['database']);
        $this->assertSame('tenant:3:', $config->default['prefix']);
        $this->assertSame([], LogCapture::entries('error'));
    }

    public function testEveryTenantInRangeLandsOnADistinctDatabase(): void
    {
        $seen = [];

        for ($tenantId = 1; $tenantId <= 15; $tenantId++) {
            [$system, $config] = $this->system();
            $system->boot($tenantId, ['name' => "T{$tenantId}"]);
            $seen[] = $config->default['database'];
        }

        $this->assertSame($seen, array_unique($seen), 'Two tenants shared one Redis database.');
    }

    public function testTenantBeyondMaxDatabaseKeepsTheApplicationDatabase(): void
    {
        [$system, $config] = $this->system();

        $system->boot(16, ['name' => 'P']);

        // The whole point of the fallback: never silently reuse another
        // tenant's database index.
        $this->assertSame(0, $config->default['database'], 'Overflow tenant wrapped onto a used database.');
    }

    public function testTenantBeyondMaxDatabaseStillGetsPrefixIsolation(): void
    {
        [$system, $config] = $this->system();

        $system->boot(16, ['name' => 'P']);

        $this->assertSame('tenant:16:', $config->default['prefix']);
    }

    public function testTenantBeyondMaxDatabaseLogsAnError(): void
    {
        [$system] = $this->system();

        $system->boot(20, ['name' => 'P']);

        $this->assertTrue(
            LogCapture::has('error', 'tenant 20 exceeds maxDatabase'),
            'Exhausting the Redis database range must be logged at error level.'
        );
        $this->assertTrue(
            LogCapture::has('error', 'deprecated'),
            'The error should point operators at the prefix-only replacement.'
        );
    }

    public function testDatabaseIsNotTouchedWhenTheModeIsOff(): void
    {
        $config = new RedisConfigStub();
        $config->default['database'] = 7;

        $system = new TestableRedisSystem();
        $system->redisStub = $config;

        $system->boot(3, ['name' => 'C']);

        $this->assertSame(7, $config->default['database']);
        $this->assertSame('tenant:3:', $config->default['prefix']);
    }

    public function testShutdownRestoresTheApplicationDatabase(): void
    {
        [$system, $config] = $this->system();
        $config->default['database'] = 4;

        $system->boot(2, ['name' => 'B']);
        $this->assertSame(1, $config->default['database']);

        $system->shutdown();
        $this->assertSame(4, $config->default['database']);
    }
}
