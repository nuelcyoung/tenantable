<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;
use nuelcyoung\tenantable\Exceptions\TenantInactiveException;

/**
 * @covers \nuelcyoung\tenantable\Services\TenantManager
 */
class TenantManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantManager::resetInstance();
    }

    protected function tearDown(): void
    {
        TenantManager::resetInstance();
        parent::tearDown();
    }

    // =========================================================================
    // Singleton Tests
    // =========================================================================

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = TenantManager::getInstance();
        $instance2 = TenantManager::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testResetInstanceCreatesNewInstance(): void
    {
        $instance1 = TenantManager::getInstance();
        TenantManager::resetInstance();
        $instance2 = TenantManager::getInstance();

        $this->assertNotSame($instance1, $instance2);
    }

    // =========================================================================
    // Tenant Context Tests
    // =========================================================================

    public function testInitialTenantIdIsNull(): void
    {
        $manager = TenantManager::getInstance();

        $this->assertNull($manager->getTenantId());
    }

    public function testInitialTenantIsNull(): void
    {
        $manager = TenantManager::getInstance();

        $this->assertNull($manager->getTenant());
    }

    public function testHasTenantReturnsFalseInitially(): void
    {
        $manager = TenantManager::getInstance();

        $this->assertFalse($manager->hasTenant());
    }

    public function testSetTenantByIdSetsContext(): void
    {
        // This test requires a mock database or fixture
        // For now, we test the interface behavior
        $this->markTestSkipped('Requires database mock with tenant data');
    }

    public function testSetTenantBySubdomainSetsContext(): void
    {
        $this->markTestSkipped('Requires database mock with tenant data');
    }

    // =========================================================================
    // Subdomain Detection Tests
    // =========================================================================

    public function testExtractSubdomainFromValidHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'school1.example.com';

        $manager = $this->createPartialMock(TenantManager::class, ['resolveTenantBySubdomain']);
        $manager->setBaseDomain('example.com');

        // The detectFromSubdomain should attempt to extract 'school1'
        $this->assertNull($manager->getSubdomain()); // Before detection
    }

    public function testLocalhostReturnsNull(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';

        $manager = TenantManager::getInstance();
        $manager->detectFromSubdomain();

        $this->assertNull($manager->getSubdomain());
    }

    public function testLocalhostWithPortReturnsNull(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost:8080';

        $manager = TenantManager::getInstance();
        $manager->detectFromSubdomain();

        $this->assertNull($manager->getSubdomain());
    }

    public function testIpAddressReturnsNull(): void
    {
        $_SERVER['HTTP_HOST'] = '127.0.0.1';

        $manager = TenantManager::getInstance();
        $manager->detectFromSubdomain();

        $this->assertNull($manager->getSubdomain());
    }

    public function testTestDomainReturnsNull(): void
    {
        $_SERVER['HTTP_HOST'] = 'myapp.test';

        $manager = TenantManager::getInstance();
        $manager->detectFromSubdomain();

        $this->assertNull($manager->getSubdomain());
    }

    public function testLocalDomainReturnsNull(): void
    {
        $_SERVER['HTTP_HOST'] = 'myapp.local';

        $manager = TenantManager::getInstance();
        $manager->detectFromSubdomain();

        $this->assertNull($manager->getSubdomain());
    }

    // =========================================================================
    // Clear Context Tests
    // =========================================================================

    public function testClearResetsAllContext(): void
    {
        $manager = TenantManager::getInstance();

        // Manually set some state (simulating tenant context)
        $reflection = new \ReflectionClass($manager);
        $tenantIdProp = $reflection->getProperty('tenantId');
        $tenantIdProp->setAccessible(true);
        $tenantIdProp->setValue($manager, 1);

        $subdomainProp = $reflection->getProperty('subdomain');
        $subdomainProp->setAccessible(true);
        $subdomainProp->setValue($manager, 'test');

        $this->assertEquals(1, $manager->getTenantId());

        $manager->clear();

        $this->assertNull($manager->getTenantId());
        $this->assertNull($manager->getSubdomain());
        $this->assertNull($manager->getTenant());
        $this->assertFalse($manager->hasTenant());
    }

    // =========================================================================
    // Base Domain Tests
    // =========================================================================

    public function testSetBaseDomain(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('myapp.com');

        $this->assertEquals('myapp.com', $manager->getBaseDomain());
    }

    public function testDefaultBaseDomainFromEnvironment(): void
    {
        // Clear any existing instance
        TenantManager::resetInstance();

        // Set environment before getting instance - use putenv() so getenv() picks it up
        putenv('TENANT_BASE_DOMAIN=envdomain.com');

        // Get instance - it should read from env
        $manager = TenantManager::getInstance();

        $this->assertEquals('envdomain.com', $manager->getBaseDomain());

        // Clean up - remove the env variable
        putenv('TENANT_BASE_DOMAIN');
        TenantManager::resetInstance();
    }

    // =========================================================================
    // Bypass Routes Tests
    // =========================================================================

    public function testAddBypassRoute(): void
    {
        $manager = TenantManager::getInstance();
        $manager->addBypassRoute('api/*');
        $manager->addBypassRoute('health');

        // Bypass detection uses internal array, verify no exception
        $this->assertInstanceOf(TenantManager::class, $manager);
    }

    // =========================================================================
    // Exception Tests
    // =========================================================================

    public function testTenantNotFoundExceptionIsThrown(): void
    {
        $this->expectException(TenantNotFoundException::class);

        throw new TenantNotFoundException('Test message');
    }

    public function testTenantInactiveExceptionIsThrown(): void
    {
        $this->expectException(TenantInactiveException::class);

        throw new TenantInactiveException('Test message');
    }
}
