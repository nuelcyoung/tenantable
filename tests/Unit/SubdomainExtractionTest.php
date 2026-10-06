<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Services\TenantManager;

/**
 * @covers \nuelcyoung\tenantable\Services\TenantManager
 */
class SubdomainExtractionTest extends TestCase
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

    // #1: Anchored suffix extraction

    public function testExtractSubdomainFromValidHost(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('app.test');

        $this->assertEquals('school', $manager->extractSubdomain('school.app.test'));
    }

    public function testExtractSubdomainWithRepeatBaseDomain(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('app.test');

        // 'foo.app.test.app.test' ends with '.app.test' → extract 'foo.app.test'
        $this->assertEquals('foo.app.test', $manager->extractSubdomain('foo.app.test.app.test'));
    }

    public function testExtractSubdomainWithBaseDomainAsSubstring(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('bar.test');

        // 'foobar.test' should NOT match base 'bar.test' because it lacks the leading dot
        $this->assertNull($manager->extractSubdomain('foobar.test'));
    }

    public function testExtractSubdomainReturnsNullForBaseDomainOnly(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('app.test');

        $this->assertNull($manager->extractSubdomain('app.test'));
    }

    public function testExtractSubdomainWithPort(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('app.test');

        $this->assertEquals('school', $manager->extractSubdomain('school.app.test:8080'));
    }

    public function testExtractSubdomainWithEmptyHost(): void
    {
        $manager = TenantManager::getInstance();

        $this->assertNull($manager->extractSubdomain(''));
        $this->assertNull($manager->extractSubdomain(null));
    }

    public function testExtractSubdomainWithIpv6InBrackets(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('app.test');

        // IPv6 should not match base domain
        $this->assertNull($manager->extractSubdomain('[::1]'));
    }

    public function testExtractSubdomainWithTrailingDot(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('app.test');

        // Trailing dot is unusual but should handle gracefully
        $this->assertNull($manager->extractSubdomain('app.test.'));
    }

    public function testExtractSubdomainIsCaseInsensitive(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('APP.TEST');

        // Hosts and base domain are both normalized to lowercase before matching.
        $this->assertEquals('school', $manager->extractSubdomain('school.app.test'));
        $this->assertEquals('school', $manager->extractSubdomain('SCHOOL.App.Test'));
    }

    // #2: isLocalhost public

    public function testIsLocalhostExactMatches(): void
    {
        $manager = TenantManager::getInstance();

        $this->assertTrue($manager->isLocalhost('localhost'));
        $this->assertTrue($manager->isLocalhost('127.0.0.1'));
        $this->assertTrue($manager->isLocalhost('::1'));
        $this->assertTrue($manager->isLocalhost('0.0.0.0'));
    }

    public function testIsLocalhostWithPort(): void
    {
        $manager = TenantManager::getInstance();

        $this->assertTrue($manager->isLocalhost('localhost:8080'));
    }

    public function testIsLocalhostTestDomain(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('app.test');

        // myapp.local is localhost because it doesn't end with baseDomain
        $this->assertTrue($manager->isLocalhost('myapp.local'));

        // tenant.app.test is NOT localhost
        $this->assertFalse($manager->isLocalhost('tenant.app.test'));

        // app.test itself is NOT localhost
        $this->assertFalse($manager->isLocalhost('app.test'));
    }

    public function testIsLocalhostPrivateIp(): void
    {
        $manager = TenantManager::getInstance();

        $this->assertTrue($manager->isLocalhost('10.0.0.1'));
        $this->assertTrue($manager->isLocalhost('172.16.0.1'));
        $this->assertTrue($manager->isLocalhost('192.168.1.1'));
        $this->assertFalse($manager->isLocalhost('8.8.8.8'));
    }
}
