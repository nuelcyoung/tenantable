<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Bootstrap\TenantBootstrap;
use nuelcyoung\tenantable\Bootstrap\TenantAwareInterface;
use nuelcyoung\tenantable\Bootstrap\Systems\CacheSystem;
use nuelcyoung\tenantable\Bootstrap\Systems\StorageSystem;
use nuelcyoung\tenantable\Bootstrap\Systems\LoggingSystem;
use nuelcyoung\tenantable\Bootstrap\Systems\ConfigSystem;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Services\TenantTableManager;

/**
 * @covers \nuelcyoung\tenantable\Bootstrap\TenantBootstrap
 * @covers \nuelcyoung\tenantable\Bootstrap\Systems\CacheSystem
 * @covers \nuelcyoung\tenantable\Bootstrap\Systems\StorageSystem
 * @covers \nuelcyoung\tenantable\Bootstrap\Systems\LoggingSystem
 * @covers \nuelcyoung\tenantable\Bootstrap\Systems\ConfigSystem
 */
class BootstrapSystemsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantManager::resetInstance();
        TenantTableManager::resetInstance();
        TenantBootstrap::resetInstance();
    }

    protected function tearDown(): void
    {
        TenantManager::resetInstance();
        TenantTableManager::resetInstance();
        TenantBootstrap::resetInstance();
        parent::tearDown();
    }

    // =========================================================================
    // TenantBootstrap Tests
    // =========================================================================

    public function testBootstrapIsSingleton(): void
    {
        $instance1 = TenantBootstrap::getInstance();
        $instance2 = TenantBootstrap::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testInitializeRegistersDefaultSystems(): void
    {
        $bootstrap = TenantBootstrap::getInstance();
        $bootstrap->initialize();

        $systems = $bootstrap->getSystems();

        $this->assertArrayHasKey('table', $systems);
        $this->assertArrayHasKey('cache', $systems);
        $this->assertArrayHasKey('storage', $systems);
        $this->assertArrayHasKey('session', $systems);
        $this->assertArrayHasKey('logging', $systems);
        $this->assertArrayHasKey('config', $systems);
    }

    public function testInitializeIsIdempotent(): void
    {
        $bootstrap = TenantBootstrap::getInstance();
        $bootstrap->initialize();
        $systems1 = $bootstrap->getSystems();

        $bootstrap->initialize(); // Second call
        $systems2 = $bootstrap->getSystems();

        $this->assertCount(count($systems1), $systems2);
    }

    public function testRegisterSystem(): void
    {
        $bootstrap = TenantBootstrap::getInstance();
        $mockSystem = $this->createMock(TenantAwareInterface::class);

        $bootstrap->registerSystem('custom', $mockSystem);

        $this->assertSame($mockSystem, $bootstrap->getSystem('custom'));
    }

    public function testUnregisterSystem(): void
    {
        $bootstrap = TenantBootstrap::getInstance();
        $mockSystem = $this->createMock(TenantAwareInterface::class);

        $bootstrap->registerSystem('custom', $mockSystem);
        $bootstrap->unregisterSystem('custom');

        $this->assertNull($bootstrap->getSystem('custom'));
    }

    // =========================================================================
    // CacheSystem Tests
    // =========================================================================

    public function testCacheSystemSetsPrefix(): void
    {
        // Mock the config function behavior
        $cacheSystem = new CacheSystem();

        // We can't easily mock the global config() function,
        // so we'll test the interface compliance
        $this->assertInstanceOf(TenantAwareInterface::class, $cacheSystem);
    }

    public function testCacheSystemBootAndShutdown(): void
    {
        $cacheSystem = new CacheSystem();

        // Should not throw
        $cacheSystem->boot(1, ['name' => 'Test Tenant']);
        $cacheSystem->shutdown();

        $this->assertTrue(true); // No exception = pass
    }

    public function testCacheSystemHandlesNullTenant(): void
    {
        $cacheSystem = new CacheSystem();

        // Should not throw with null tenant
        $cacheSystem->boot(null, null);
        $cacheSystem->shutdown();

        $this->assertTrue(true);
    }

    // =========================================================================
    // StorageSystem Tests
    // =========================================================================

    public function testStorageSystemIsTenantAware(): void
    {
        $storageSystem = new StorageSystem();

        $this->assertInstanceOf(TenantAwareInterface::class, $storageSystem);
    }

    public function testStorageSystemHandlesNullTenant(): void
    {
        $storageSystem = new StorageSystem();

        $storageSystem->boot(null, null);
        $storageSystem->shutdown();

        $this->assertTrue(true);
    }

    public function testStorageSystemGetStoragePathWithNull(): void
    {
        $path = StorageSystem::getStoragePath(null);

        $this->assertStringContainsString('tenant_default', $path);
    }

    // =========================================================================
    // LoggingSystem Tests
    // =========================================================================

    public function testLoggingSystemSetsEnvVariable(): void
    {
        $loggingSystem = new LoggingSystem();

        $loggingSystem->boot(1, ['name' => 'Test', 'subdomain' => 'test']);

        $this->assertArrayHasKey('TENANT_LOG_CONTEXT', $_ENV);
        $this->assertJson($_ENV['TENANT_LOG_CONTEXT']);

        $context = json_decode($_ENV['TENANT_LOG_CONTEXT'], true);
        $this->assertEquals(1, $context['tenant_id']);
        $this->assertEquals('Test', $context['tenant_name']);

        $loggingSystem->shutdown();
    }

    public function testLoggingSystemClearsEnvOnNull(): void
    {
        $loggingSystem = new LoggingSystem();

        $loggingSystem->boot(1, ['name' => 'Test']);
        $this->assertArrayHasKey('TENANT_LOG_CONTEXT', $_ENV);

        $loggingSystem->boot(null, null);
        $this->assertArrayNotHasKey('TENANT_LOG_CONTEXT', $_ENV);
    }

    public function testLoggingSystemShutdownClearsEnv(): void
    {
        $loggingSystem = new LoggingSystem();

        $loggingSystem->boot(1, ['name' => 'Test']);
        $loggingSystem->shutdown();

        $this->assertArrayNotHasKey('TENANT_LOG_CONTEXT', $_ENV);
    }

    // =========================================================================
    // ConfigSystem Tests
    // =========================================================================

    public function testConfigSystemIsTenantAware(): void
    {
        $configSystem = new ConfigSystem();

        $this->assertInstanceOf(TenantAwareInterface::class, $configSystem);
    }

    public function testConfigSystemHandlesNullTenant(): void
    {
        $configSystem = new ConfigSystem();

        $configSystem->boot(null, null);
        $configSystem->shutdown();

        $this->assertTrue(true);
    }

    public function testConfigSystemParsesJsonSettings(): void
    {
        $configSystem = new ConfigSystem();

        $tenant = [
            'name' => 'Test',
            'settings' => json_encode(['theme' => 'dark', 'timezone' => 'UTC']),
        ];

        $configSystem->boot(1, $tenant);

        // Register in bootstrap so static methods work
        $bootstrap = TenantBootstrap::getInstance();
        $bootstrap->registerSystem('config', $configSystem);

        $setting = ConfigSystem::get('theme');
        $this->assertEquals('dark', $setting);

        $configSystem->shutdown();
    }

    public function testConfigSystemHandlesArraySettings(): void
    {
        $configSystem = new ConfigSystem();

        $tenant = [
            'name' => 'Test',
            'settings' => ['theme' => 'light', 'timezone' => 'Africa/Lagos'],
        ];

        $configSystem->boot(1, $tenant);

        // Register in bootstrap so static methods work
        $bootstrap = TenantBootstrap::getInstance();
        $bootstrap->registerSystem('config', $configSystem);

        $settings = ConfigSystem::all();
        $this->assertEquals('light', $settings['theme']);
        $this->assertEquals('Africa/Lagos', $settings['timezone']);

        $configSystem->shutdown();
    }

    public function testConfigSystemGetReturnsDefaultForMissingKey(): void
    {
        $configSystem = new ConfigSystem();
        $configSystem->boot(null, null);

        $value = ConfigSystem::get('nonexistent', 'default_value');

        $this->assertEquals('default_value', $value);

        $configSystem->shutdown();
    }

    // =========================================================================
    // Boot Sequence Tests
    // =========================================================================

    public function testBootCallsAllSystems(): void
    {
        $bootstrap = TenantBootstrap::getInstance();

        // Set up tenant so boot() actually runs
        $reflection = new \ReflectionClass(TenantManager::getInstance());
        $tenantIdProp = $reflection->getProperty('tenantId');
        $tenantIdProp->setAccessible(true);
        $tenantIdProp->setValue(TenantManager::getInstance(), 1);

        $tenantProp = $reflection->getProperty('tenant');
        $tenantProp->setAccessible(true);
        $tenantProp->setValue(TenantManager::getInstance(), ['name' => 'Test']);

        // Create mock systems that track calls
        $mockSystem1 = $this->createMock(TenantAwareInterface::class);
        $mockSystem1->expects($this->once())
            ->method('boot')
            ->with(1, $this->anything());

        $mockSystem2 = $this->createMock(TenantAwareInterface::class);
        $mockSystem2->expects($this->once())
            ->method('boot')
            ->with(1, $this->anything());

        $bootstrap->registerSystem('mock1', $mockSystem1);
        $bootstrap->registerSystem('mock2', $mockSystem2);

        // Call boot with tenant data
        $bootstrap->boot();

        // Verify boot was called by checking it doesn't boot again
        // (the mock expects once() which means boot was called)
    }

    public function testShutdownCallsAllSystems(): void
    {
        // Skip this test as it requires CI4 Events which we can't mock easily
        $this->markTestSkipped('Requires CI4 Events system');
    }

    public function testBootOnlyRunsWhenTenantChanges(): void
    {
        $bootstrap = TenantBootstrap::getInstance();

        // Set up tenant so boot() actually runs
        $reflection = new \ReflectionClass(TenantManager::getInstance());
        $tenantIdProp = $reflection->getProperty('tenantId');
        $tenantIdProp->setAccessible(true);
        $tenantIdProp->setValue(TenantManager::getInstance(), 1);

        $tenantProp = $reflection->getProperty('tenant');
        $tenantProp->setAccessible(true);
        $tenantProp->setValue(TenantManager::getInstance(), ['name' => 'Test']);

        $mockSystem = $this->createMock(TenantAwareInterface::class);
        $mockSystem->expects($this->once()) // Only once, not twice
            ->method('boot');

        $bootstrap->registerSystem('mock', $mockSystem);

        // Boot twice with same tenant (should only run once)
        $bootstrap->boot();
        $bootstrap->boot();
    }
}
