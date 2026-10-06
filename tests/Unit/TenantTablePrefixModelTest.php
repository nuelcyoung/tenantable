<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Traits\TenantTablePrefixModel;

/**
 * Guards the PSR-4 autoloadability of the prefix-model class.
 *
 * The class used to live inside the trait file, so the PSR-4 autoloader could
 * not resolve it on a clean install and extending it would fatal.
 */
class TenantTablePrefixModelTest extends TestCase
{
    public function testClassIsAutoloadable(): void
    {
        // Triggers the autoloader; fails if PSR-4 can't resolve the class.
        $this->assertTrue(class_exists(TenantTablePrefixModel::class));
    }

    public function testClassLivesInItsOwnPsr4File(): void
    {
        $ref = new \ReflectionClass(TenantTablePrefixModel::class);

        $this->assertSame(
            'TenantTablePrefixModel.php',
            basename((string) $ref->getFileName()),
            'TenantTablePrefixModel must live in its own file to be PSR-4 autoloadable.',
        );
    }
}
