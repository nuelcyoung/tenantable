<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Bootstrap\RedisSystem;
use nuelcyoung\tenantable\Bootstrap\Systems\CacheSystem;

class CacheConfigStub
{
    public string $prefix = 'app_';
}

class TestableCacheSystem extends CacheSystem
{
    public ?object $config = null;

    protected function cacheConfig(): ?object
    {
        return $this->config;
    }
}

class RedisConfigStub
{
    public array $default = [
        'prefix'   => 'app:',
        'database' => 0,
    ];
}

class TestableRedisSystem extends RedisSystem
{
    public ?object $redisStub = null;

    protected function redisConfig(): ?object
    {
        return $this->redisStub;
    }

    protected function clearRedisConnections(): void
    {
        // No live connections in tests.
    }
}

/**
 * Tests that shutdown() restores the app's own settings after tenant switches.
 *
 * @covers \nuelcyoung\tenantable\Bootstrap\Systems\CacheSystem
 * @covers \nuelcyoung\tenantable\Bootstrap\RedisSystem
 */
class TenantSwitchStateRestoreTest extends TestCase
{
    // CacheSystem

    private function cacheSystem(): array
    {
        $system = new TestableCacheSystem();
        $config = new CacheConfigStub();
        $system->config = $config;

        return [$system, $config];
    }

    public function testCachePrefixAppliedAndRestored(): void
    {
        [$system, $config] = $this->cacheSystem();

        $system->boot(1, ['name' => 'A']);
        $this->assertSame('tenant_1_', $config->prefix);

        $system->shutdown();
        $this->assertSame('app_', $config->prefix);
    }

    public function testCacheCapturePreservesOriginalBeforeEarlyDetection(): void
    {
        [$system, $config] = $this->cacheSystem();

        $system->captureOriginalState();
        $config->prefix = 'tenant_1_';
        $system->boot(1, ['name' => 'A']);
        $system->shutdown();

        $this->assertSame('app_', $config->prefix);
    }

    public function testCacheTenantSwitchDoesNotClobberOriginalPrefix(): void
    {
        [$system, $config] = $this->cacheSystem();

        $system->boot(1, ['name' => 'A']);
        $system->boot(2, ['name' => 'B']);
        $this->assertSame('tenant_2_', $config->prefix);

        $system->shutdown();

        // Previously restored 'tenant_1_' (captured on the second boot).
        $this->assertSame('app_', $config->prefix);
    }

    public function testCacheCentralBootRestoresAppPrefix(): void
    {
        [$system, $config] = $this->cacheSystem();

        $system->boot(1, ['name' => 'A']);
        $system->boot(null, null); // runCentral

        // Previously forced to ''; the app's own prefix was lost.
        $this->assertSame('app_', $config->prefix);

        $system->boot(1, ['name' => 'A']);
        $this->assertSame('tenant_1_', $config->prefix);

        $system->shutdown();
        $this->assertSame('app_', $config->prefix);
    }

    // =========================================================================
    // RedisSystem
    // =========================================================================

    private function redisSystem(): array
    {
        $system = new TestableRedisSystem();
        $config = new RedisConfigStub();
        $system->redisStub = $config;

        return [$system, $config];
    }

    public function testRedisPrefixAppliedAndRestored(): void
    {
        [$system, $config] = $this->redisSystem();

        $system->boot(1, ['name' => 'A']);
        $this->assertSame('tenant:1:', $config->default['prefix']);

        $system->shutdown();
        $this->assertSame('app:', $config->default['prefix']);
    }

    public function testRedisTenantSwitchDoesNotClobberOriginalSettings(): void
    {
        [$system, $config] = $this->redisSystem();

        $system->boot(1, ['name' => 'A']);
        $system->boot(2, ['name' => 'B']);
        $this->assertSame('tenant:2:', $config->default['prefix']);

        $system->shutdown();

        // Previously restored tenant 1's settings (captured on second boot).
        $this->assertSame('app:', $config->default['prefix']);
        $this->assertSame(0, $config->default['database']);
    }

    public function testRedisCentralBootUndoesTenantScoping(): void
    {
        [$system, $config] = $this->redisSystem();

        $system->boot(1, ['name' => 'A']);
        $this->assertSame('tenant:1:', $config->default['prefix']);

        $system->boot(null, null); // runCentral

        // Previously a no-op: central work kept reading tenant 1's keyspace.
        $this->assertSame('app:', $config->default['prefix']);

        $system->boot(1, ['name' => 'A']);
        $this->assertSame('tenant:1:', $config->default['prefix']);

        $system->shutdown();
        $this->assertSame('app:', $config->default['prefix']);
    }

    public function testRedisShutdownWithoutBootIsHarmless(): void
    {
        [$system, $config] = $this->redisSystem();

        $system->shutdown();

        $this->assertSame('app:', $config->default['prefix']);
    }

    public function testRedisCustomTenantPrefixIsHonoured(): void
    {
        [$system, $config] = $this->redisSystem();

        $system->boot(3, ['settings' => ['redis' => ['prefix' => 'tenant:3:acme:']]]);

        $this->assertSame('tenant:3:acme:', $config->default['prefix']);

        $system->shutdown();
        $this->assertSame('app:', $config->default['prefix']);
    }

    public function testRedisCustomPrefixCannotCrossTenantNamespace(): void
    {
        [$system, $config] = $this->redisSystem();

        $system->boot(3, ['settings' => ['redis' => ['prefix' => 'tenant:4:']]]);

        $this->assertSame('tenant:3:', $config->default['prefix']);
    }
}
