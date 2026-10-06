<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Services\TenantDatabaseManager;

/**
 * Tests that the in-process migrator records the same version token as CI4's MigrationRunner.
 *
 * @covers \nuelcyoung\tenantable\Services\TenantDatabaseManager
 */
class MigrationVersionTest extends TestCase
{
    private function version(string $basename): string
    {
        $manager = new TenantDatabaseManager();
        $ref     = new \ReflectionMethod($manager, 'extractMigrationVersion');
        $ref->setAccessible(true);

        return (string) $ref->invoke($manager, $basename);
    }

    public function testExtractsLeadingTimestampToken(): void
    {
        $this->assertSame(
            '2024-01-15-000001',
            $this->version('2024-01-15-000001_CreateStudentsTable'),
        );
    }

    public function testExtractsUnderscoreStyleTimestamp(): void
    {
        $this->assertSame(
            '20240115000001',
            $this->version('20240115000001_CreateStudentsTable'),
        );
    }

    public function testFallsBackToBasenameForNonConformingNames(): void
    {
        $this->assertSame('NotAMigration', $this->version('NotAMigration'));
    }
}
