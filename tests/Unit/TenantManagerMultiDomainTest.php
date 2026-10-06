<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Config\Tenantable;
use nuelcyoung\tenantable\Services\TenantManager;

/**
 * Multiple central domains ("$baseDomains").
 *
 * @covers \nuelcyoung\tenantable\Services\TenantManager
 * @covers \nuelcyoung\tenantable\Config\Tenantable::centralDomains
 */
class TenantManagerMultiDomainTest extends TestCase
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

    public function testSetBaseDomainAcceptsAList(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain(['example.com', 'example.org']);

        $this->assertSame('example.com', $manager->getBaseDomain());
        $this->assertSame(['example.com', 'example.org'], $manager->getBaseDomains());
    }

    public function testBaseDomainsAreNormalizedAndDeduped(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain(['EXAMPLE.com.', 'example.com', '  ']);

        $this->assertSame(['example.com'], $manager->getBaseDomains());
    }

    public function testEmptyListKeepsCurrentDomains(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain('example.com');
        $manager->setBaseDomain([]);

        $this->assertSame('example.com', $manager->getBaseDomain());
    }

    public function testExtractSubdomainMatchesAnyBaseDomain(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain(['example.com', 'example.org']);

        $this->assertSame('acme', $manager->extractSubdomain('acme.example.com'));
        $this->assertSame('acme', $manager->extractSubdomain('acme.example.org'));
        $this->assertNull($manager->extractSubdomain('acme.example.net'));
        $this->assertNull($manager->extractSubdomain('example.com'));
    }

    public function testMostSpecificBaseDomainWins(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain(['example.com', 'app.example.com']);

        $this->assertSame('acme', $manager->extractSubdomain('acme.app.example.com'));
    }

    public function testGetBaseDomainForHost(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain(['example.com', 'example.org']);

        $this->assertSame('example.org', $manager->getBaseDomainForHost('acme.example.org'));
        $this->assertSame('example.com', $manager->getBaseDomainForHost('example.com'));
        $this->assertSame('example.com', $manager->getBaseDomainForHost('acme.example.com:8080'));
        $this->assertNull($manager->getBaseDomainForHost('example.net'));
        $this->assertNull($manager->getBaseDomainForHost(null));
    }

    public function testHostAllowedAcrossAllBaseDomains(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain(['example.com', 'example.org']);

        $this->assertTrue($manager->isHostAllowed('example.com'));
        $this->assertTrue($manager->isHostAllowed('acme.example.com'));
        $this->assertTrue($manager->isHostAllowed('acme.example.org'));
        $this->assertFalse($manager->isHostAllowed('a.b.example.org'));
        $this->assertFalse($manager->isHostAllowed('notexample.com'));
        $this->assertFalse($manager->isHostAllowed('example.net'));
    }

    public function testDevTldHostsOnAnyBaseDomainAreNotLoopback(): void
    {
        $manager = TenantManager::getInstance();
        $manager->setBaseDomain(['example.com', 'example.org']);

        $this->assertFalse($manager->isLocalhost('tenant.example.org'));
        $this->assertTrue($manager->isLocalhost('tenant.unknowntld.test'));
    }

    public function testEnvironmentAcceptsCommaSeparatedDomains(): void
    {
        $original = getenv('TENANT_BASE_DOMAIN');

        putenv('TENANT_BASE_DOMAIN=example.com,example.org');

        try {
            $manager = TenantManager::initialize();

            $this->assertSame('example.com', $manager->getBaseDomain());
            $this->assertSame(['example.com', 'example.org'], $manager->getBaseDomains());
            $this->assertSame('acme', $manager->extractSubdomain('acme.example.org'));
        } finally {
            putenv($original === false ? 'TENANT_BASE_DOMAIN' : "TENANT_BASE_DOMAIN={$original}");
            TenantManager::resetInstance();
        }
    }

    public function testCentralDomainsMergesAndDedupes(): void
    {
        $config = new Tenantable();
        $config->baseDomain = 'example.com';
        $config->baseDomains = ['example.org', 'EXAMPLE.com'];

        $this->assertSame(['example.com', 'example.org'], $config->centralDomains());
    }

    public function testCentralDomainsExcludesEmptyEntries(): void
    {
        $config = new Tenantable();
        $config->baseDomain = 'example.com';
        $config->baseDomains = ['  ', ''];

        $this->assertSame(['example.com'], $config->centralDomains());
    }

    public function testCentralDomainsDefaultsToBaseDomainOnly(): void
    {
        $config = new Tenantable();
        $config->baseDomain = 'example.com';
        $config->baseDomains = [];

        $this->assertSame(['example.com'], $config->centralDomains());
    }
}
