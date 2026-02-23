<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;
use nuelcyoung\tenantable\Exceptions\TenantInactiveException;

/**
 * @covers \nuelcyoung\tenantable\Exceptions\TenantNotFoundException
 * @covers \nuelcyoung\tenantable\Exceptions\TenantInactiveException
 */
class ExceptionsTest extends TestCase
{
    public function testTenantNotFoundExceptionHasCorrectMessage(): void
    {
        $exception = TenantNotFoundException::forSubdomain('school1');

        $this->assertStringContainsString('school1', $exception->getMessage());
        $this->assertStringContainsString('not found', $exception->getMessage());
    }

    public function testTenantNotFoundExceptionForId(): void
    {
        $exception = TenantNotFoundException::forId(123);

        $this->assertStringContainsString('123', $exception->getMessage());
        $this->assertStringContainsString('ID', $exception->getMessage());
    }

    public function testTenantInactiveExceptionHasCorrectMessage(): void
    {
        $exception = TenantInactiveException::forSubdomain('school1');

        $this->assertStringContainsString('school1', $exception->getMessage());
        $this->assertStringContainsString('inactive', $exception->getMessage());
    }

    public function testTenantInactiveExceptionForId(): void
    {
        $exception = TenantInactiveException::forId(456);

        $this->assertStringContainsString('456', $exception->getMessage());
        $this->assertStringContainsString('inactive', $exception->getMessage());
    }

    public function testTenantNotFoundExceptionExtendsRuntimeException(): void
    {
        $exception = new TenantNotFoundException('test');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testTenantInactiveExceptionExtendsRuntimeException(): void
    {
        $exception = new TenantInactiveException('test');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }
}
