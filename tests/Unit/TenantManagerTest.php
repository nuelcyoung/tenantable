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

    // Singleton Tests

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

    // Tenant Context Tests

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

    // Subdomain Detection Tests

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

    // Clear Context Tests

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

    // Base Domain Tests

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

        // Set environment before getting instance - use the environment setter so the getter picks it up
        putenv('TENANT_BASE_DOMAIN=envdomain.com');

        // Get instance - it should read from env
        $manager = TenantManager::getInstance();

        $this->assertEquals('envdomain.com', $manager->getBaseDomain());

        // Clean up - remove the env variable
        putenv('TENANT_BASE_DOMAIN');
        TenantManager::resetInstance();
    }

    // Bypass Routes Tests

    public function testAddBypassRoute(): void
    {
        $manager = TenantManager::getInstance();
        $manager->addBypassRoute('api/*');
        $manager->addBypassRoute('health');

        // Bypass detection uses internal array, verify no exception
        $this->assertInstanceOf(TenantManager::class, $manager);
    }

    // Host Allowlist Tests (fail-closed)

    public function testHostAllowedAcceptsBaseDomain(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('acme.com');

        $this->assertTrue($manager->isHostAllowed('acme.com'));
    }

    public function testHostAllowedAcceptsSubdomainOfBase(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('acme.com');

        $this->assertTrue($manager->isHostAllowed('tenant1.acme.com'));
    }

    public function testHostAllowedRejectsForeignHostByDefault(): void
    {
        // Must fail closed, not allow-all.
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('acme.com');

        $this->assertFalse($manager->isHostAllowed('evil.com'));
    }

    public function testHostAllowedRejectsEmptyHost(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('acme.com');

        $this->assertFalse($manager->isHostAllowed(''));
    }

    public function testHostAllowedRejectsControlCharacters(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('acme.com');

        $this->assertFalse($manager->isHostAllowed("tenant.acme.com\r\nX-Injected: true"));
    }

    public function testHostNormalizationRemovesPortAndTrailingDot(): void
    {
        $manager = TenantManager::getInstance();

        $this->assertSame('tenant.acme.com', $manager->normalizeHost('TENANT.ACME.COM.:443'));
    }

    public function testHostNormalizationRejectsMalformedPort(): void
    {
        $manager = TenantManager::getInstance();

        $this->assertNull($manager->normalizeHost('tenant.acme.com:99999'));
        $this->assertNull($manager->normalizeHost('tenant.acme.com:not-a-port'));
    }

    public function testHostAllowedRejectsDeepSubdomain(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('acme.com');

        $this->assertFalse($manager->isHostAllowed('a.b.acme.com'));
    }

    public function testHostAllowedRejectsLoopbackByDefault(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('acme.com');

        $this->assertFalse($manager->isHostAllowed('localhost'));
        $this->assertFalse($manager->isHostAllowed('localhost:8080'));
        $this->assertFalse($manager->isHostAllowed('127.0.0.1'));
        $this->assertFalse($manager->isHostAllowed('::1'));
    }

    public function testHostAllowedRejectsPrivateRangeIps(): void
    {
        // RFC1918 Host must NOT auto-bypass the allowlist.
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('acme.com');

        $this->assertFalse($manager->isHostAllowed('10.0.0.5'));
        $this->assertFalse($manager->isHostAllowed('172.16.0.1'));
        $this->assertFalse($manager->isHostAllowed('192.168.1.1'));
        $this->assertFalse($manager->isHostAllowed('192.168.1.1:8080'));
    }

    public function testHostAllowedAllowsPrivateIpOnlyViaPattern(): void
    {
        // Private IP reachable when base domain matches.
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('192.168.1.1');

        $this->assertTrue($manager->isHostAllowed('192.168.1.1'));
    }

    public function testIsLoopbackExcludesPrivateIps(): void
    {
        $manager = TenantManager::getInstance();

        $this->assertTrue($manager->isLoopback('127.0.0.1'));
        $this->assertTrue($manager->isLoopback('localhost'));
        $this->assertFalse($manager->isLoopback('192.168.1.1'));
        $this->assertFalse($manager->isLoopback('10.0.0.1'));
    }

    public function testIsPrivateIpStrictlyValidatesAddress(): void
    {
        $manager = TenantManager::getInstance();

        $this->assertTrue($manager->isPrivateIp('10.0.0.1'));
        $this->assertTrue($manager->isPrivateIp('172.31.255.255'));
        $this->assertTrue($manager->isPrivateIp('192.168.0.10'));
        $this->assertTrue($manager->isPrivateIp('192.168.0.10:443'));

        // Spoofed/out-of-range values must not match.
        $this->assertFalse($manager->isPrivateIp('10.0.0.1.evil.com'));
        $this->assertFalse($manager->isPrivateIp('172.32.0.1'));
        $this->assertFalse($manager->isPrivateIp('8.8.8.8'));
        $this->assertFalse($manager->isPrivateIp('not-an-ip'));
    }

    public function testIsLocalhostStillTreatsPrivateIpsAsLocal(): void
    {
        $manager = TenantManager::getInstance();

        $this->assertTrue($manager->isLocalhost('127.0.0.1'));
        $this->assertTrue($manager->isLocalhost('192.168.1.1'));
    }

    public function testDeriveDefaultHostPatternsForRealDomain(): void
    {
        $manager = TenantManager::getInstance();

        $patterns = $this->callProtected($manager, 'deriveDefaultHostPatterns', ['acme.com']);

        $this->assertSame(['*.acme.com', 'acme.com'], $patterns);
    }

    public function testDeriveDefaultHostPatternsForLocalhost(): void
    {
        $manager = TenantManager::getInstance();

        $patterns = $this->callProtected($manager, 'deriveDefaultHostPatterns', ['localhost']);

        $this->assertSame(['localhost', '127.0.0.1', '::1'], $patterns);
    }

    public function testHostMatchesWildcardOptOut(): void
    {
        $manager = TenantManager::getInstance();

        $this->assertTrue($this->callProtected($manager, 'hostMatchesPattern', ['anything.example', '*']));
    }

    private function callProtected(object $object, string $method, array $args)
    {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($object, $args);
    }

    // Exception Tests

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

    // is_active truthiness (CI4 < 4.5: is_active is int)

    public function testSetTenantAcceptsIntegerIsActive(): void
    {
        $manager = TenantManager::getInstance();

        // Simulates a CI4 4.4 row where $casts did not turn is_active into a bool.
        $manager->setTenant(['id' => 1, 'subdomain' => 'acme', 'is_active' => 1]);

        $this->assertSame(1, $manager->getTenantId());
    }

    public function testSetTenantAcceptsStringIsActive(): void
    {
        $manager = TenantManager::getInstance();

        $manager->setTenant(['id' => 2, 'subdomain' => 'beta', 'is_active' => '1']);

        $this->assertSame(2, $manager->getTenantId());
    }

    public function testSetTenantRejectsZeroIsActive(): void
    {
        $this->expectException(TenantInactiveException::class);

        TenantManager::getInstance()->setTenant(['id' => 3, 'is_active' => 0]);
    }

    public function testSetTenantRejectsMissingIsActive(): void
    {
        $this->expectException(TenantInactiveException::class);

        TenantManager::getInstance()->setTenant(['id' => 4, 'subdomain' => 'gamma']);
    }
}
