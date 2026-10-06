<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Feature;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Filters\BaseTenantFilter;

/**
 * Feature tests for HTTP_HOST validation and injection protection.
 */
class HostHeaderInjectionTest extends TestCase
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

    public function testEvilHostReturns400(): void
    {
        $this->markTestSkipped('Requires CI4 request/response services');
    }

    public function testCrlfInHostReturns400(): void
    {
        $this->markTestSkipped('Requires CI4 request/response services');
    }

    public function testTrustedWildcardPasses(): void
    {
        $this->markTestSkipped('Requires CI4 request/response services');
    }

    public function testExactTrustedHostPasses(): void
    {
        $this->markTestSkipped('Requires CI4 request/response services');
    }

    public function testOptOutAllowsAnyHost(): void
    {
        $this->markTestSkipped('Requires CI4 request/response services');
    }
}
