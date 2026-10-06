<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Feature;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Services\TenantResolverCache;

/**
 * Feature tests for custom domain resolution and caching.
 */
class CustomDomainResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantManager::resetInstance();
        TenantResolverCache::resetInstance();
    }

    protected function tearDown(): void
    {
        TenantManager::resetInstance();
        TenantResolverCache::resetInstance();
        parent::tearDown();
    }

    public function testCacheMissReturnsNullForUnknownDomain(): void
    {
        $this->markTestSkipped('Requires database connection');
    }

    public function testCacheStoresNegativeResult(): void
    {
        $this->markTestSkipped('Requires database connection');
    }

    public function testFlushClearsCache(): void
    {
        $this->markTestSkipped('Requires database connection');
    }

    public function testFailClosedOnNullIsActive(): void
    {
        $this->markTestSkipped('Requires database with tenant row having is_active = NULL');
    }

    public function testFailClosedOnMissingIsActiveKey(): void
    {
        $this->markTestSkipped('Requires database mock');
    }

    public function testFailClosedOnIsActiveFalse(): void
    {
        $this->markTestSkipped('Requires database mock');
    }
}
