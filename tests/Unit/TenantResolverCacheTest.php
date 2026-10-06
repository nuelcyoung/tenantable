<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Services\TenantResolverCache;

/** @covers \nuelcyoung\tenantable\Services\TenantResolverCache */
class TenantResolverCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantResolverCache::resetInstance();
        \Config\Services::reset();
    }

    protected function tearDown(): void
    {
        TenantResolverCache::resetInstance();
        \Config\Services::reset();
        parent::tearDown();
    }

    public function testResolverUsesStableNonTenantCacheNamespace(): void
    {
        $resolver = TenantResolverCache::getInstance();
        $method   = new \ReflectionMethod($resolver, 'getCache');
        $method->setAccessible(true);

        $cache = $method->invoke($resolver);

        $this->assertSame('tenantable_resolver_', $cache->config->prefix);
    }
}
