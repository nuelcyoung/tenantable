<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Models\TenantModel;

/**
 * Tests that database names are validated before reaching SQL.
 *
 * @covers \nuelcyoung\tenantable\Models\TenantModel::assertValidDatabaseName
 */
class DatabaseNameValidationTest extends TestCase
{
    public function testAcceptsPortableNames(): void
    {
        $this->assertSame('tenant_5', TenantModel::assertValidDatabaseName('tenant_5'));
        $this->assertSame('acmeDB', TenantModel::assertValidDatabaseName('acmeDB'));
    }

    /**
     * @dataProvider unsafeNames
     */
    public function testRejectsUnsafeNames(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TenantModel::assertValidDatabaseName($name);
    }

    public static function unsafeNames(): array
    {
        return [
            'empty'        => [''],
            'backtick'     => ['tenant`drop'],
            'semicolon'    => ['tenant; DROP DATABASE x'],
            'space'        => ['tenant 5'],
            'dot'          => ['tenant.5'],
            'quote'        => ["tenant'"],
            'too long'     => [str_repeat('a', 65)],
        ];
    }
}
