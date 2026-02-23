<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Services\TenantTableManager;

/**
 * @covers \nuelcyoung\tenantable\Services\TenantTableManager
 */
class TenantTableManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantTableManager::resetInstance();
    }

    protected function tearDown(): void
    {
        TenantTableManager::resetInstance();
        parent::tearDown();
    }

    // =========================================================================
    // Singleton Tests
    // =========================================================================

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = TenantTableManager::getInstance();
        $instance2 = TenantTableManager::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testResetInstanceCreatesNewInstance(): void
    {
        $instance1 = TenantTableManager::getInstance();
        TenantTableManager::resetInstance();
        $instance2 = TenantTableManager::getInstance();

        $this->assertNotSame($instance1, $instance2);
    }

    // =========================================================================
    // Table Prefix Tests
    // =========================================================================

    public function testGetTableWithTenantSet(): void
    {
        $manager = TenantTableManager::getInstance();
        $manager->setTenant(1, 'school1');

        $tableName = $manager->getTable('students');

        $this->assertEquals('tenant_1_students', $tableName);
    }

    public function testGetTableWithDifferentTenant(): void
    {
        $manager = TenantTableManager::getInstance();
        $manager->setTenant(5, 'school5');

        $tableName = $manager->getTable('users');

        $this->assertEquals('tenant_5_users', $tableName);
    }

    public function testGetTableCachesResult(): void
    {
        $manager = TenantTableManager::getInstance();
        $manager->setTenant(1, 'school1');

        $table1 = $manager->getTable('students');
        $table2 = $manager->getTable('students');

        $this->assertEquals($table1, $table2);
    }

    public function testGetTableThrowsWithoutTenant(): void
    {
        $manager = TenantTableManager::getInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant not set');

        $manager->getTable('students');
    }

    public function testGetTableReturnsGlobalTableAsIs(): void
    {
        $manager = TenantTableManager::getInstance();

        // tenants is in globalTables list
        $tableName = $manager->getTable('tenants');

        $this->assertEquals('tenants', $tableName);
    }

    public function testGetTableReturnsMigrationsAsIs(): void
    {
        $manager = TenantTableManager::getInstance();

        $tableName = $manager->getTable('migrations');

        $this->assertEquals('migrations', $tableName);
    }

    // =========================================================================
    // Tenant Management Tests
    // =========================================================================

    public function testSetTenantClearsCache(): void
    {
        $manager = TenantTableManager::getInstance();
        $manager->setTenant(1, 'school1');
        $table1 = $manager->getTable('students');

        // Change tenant
        $manager->setTenant(2, 'school2');
        $table2 = $manager->getTable('students');

        $this->assertEquals('tenant_1_students', $table1);
        $this->assertEquals('tenant_2_students', $table2);
    }

    public function testClearResetsTenant(): void
    {
        $manager = TenantTableManager::getInstance();
        $manager->setTenant(1, 'school1');

        $this->assertEquals(1, $manager->getTenantId());

        $manager->clear();

        $this->assertNull($manager->getTenantId());
        $this->assertFalse($manager->hasTenant());
    }

    public function testHasTenantReturnsTrueWhenSet(): void
    {
        $manager = TenantTableManager::getInstance();

        $this->assertFalse($manager->hasTenant());

        $manager->setTenant(1, 'school1');

        $this->assertTrue($manager->hasTenant());
    }

    // =========================================================================
    // Multiple Tables Tests
    // =========================================================================

    public function testGetTablesReturnsAllPrefixed(): void
    {
        $manager = TenantTableManager::getInstance();
        $manager->setTenant(1, 'school1');

        $tables = $manager->getTables(['students', 'teachers', 'classes']);

        $this->assertEquals([
            'students' => 'tenant_1_students',
            'teachers' => 'tenant_1_teachers',
            'classes' => 'tenant_1_classes',
        ], $tables);
    }

    // =========================================================================
    // Global Table Tests
    // =========================================================================

    public function testAddGlobalTable(): void
    {
        $manager = TenantTableManager::getInstance();
        $manager->addGlobalTable('global_settings');

        $manager->setTenant(1, 'school1');

        // Global table should not be prefixed
        $tableName = $manager->getTable('global_settings');

        $this->assertEquals('global_settings', $tableName);
    }

    public function testIsGlobalTable(): void
    {
        $manager = TenantTableManager::getInstance();

        $this->assertTrue($manager->isGlobalTable('tenants'));
        $this->assertTrue($manager->isGlobalTable('migrations'));
        $this->assertFalse($manager->isGlobalTable('students'));
    }

    // =========================================================================
    // Prefix Format Tests
    // =========================================================================

    public function testCustomPrefixFormat(): void
    {
        $manager = TenantTableManager::getInstance();
        $manager->setPrefixFormat('t{id}_{table}'); // Different format
        $manager->setTenant(1, 'school1');

        $tableName = $manager->getTable('students');

        $this->assertEquals('t1_students', $tableName);
    }

    public function testPrefixFormatWithSubdomain(): void
    {
        $manager = TenantTableManager::getInstance();
        $manager->setPrefixFormat('{table}_{id}'); // Reversed order
        $manager->setTenant(1, 'school1');

        $tableName = $manager->getTable('students');

        $this->assertEquals('students_1', $tableName);
    }

    // =========================================================================
    // Extract Tenant ID Tests
    // =========================================================================

    public function testExtractTenantIdFromValidTable(): void
    {
        $manager = TenantTableManager::getInstance();

        $tenantId = $manager->extractTenantId('tenant_5_students');

        $this->assertEquals(5, $tenantId);
    }

    public function testExtractTenantIdReturnsNullForNonTenantTable(): void
    {
        $manager = TenantTableManager::getInstance();

        $tenantId = $manager->extractTenantId('students');

        $this->assertNull($tenantId);
    }

    public function testExtractTenantIdWithCustomFormat(): void
    {
        $manager = TenantTableManager::getInstance();
        $manager->setPrefixFormat('school_{id}_{table}');

        $tenantId = $manager->extractTenantId('school_3_users');

        $this->assertEquals(3, $tenantId);
    }
}
