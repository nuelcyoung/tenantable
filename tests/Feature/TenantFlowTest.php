<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Feature;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Services\TenantTableManager;
use nuelcyoung\tenantable\Bootstrap\TenantBootstrap;
use nuelcyoung\tenantable\Bootstrap\Systems\TableSystem;

/**
 * Integration tests for the complete tenant flow.
 * 
 * @coversNothing
 */
class TenantFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantManager::resetInstance();
        TenantTableManager::resetInstance();
        TenantBootstrap::resetInstance();
    }

    protected function tearDown(): void
    {
        TenantManager::resetInstance();
        TenantTableManager::resetInstance();
        TenantBootstrap::resetInstance();
        parent::tearDown();
    }

    // =========================================================================
    // Full Tenant Switch Flow
    // =========================================================================

    public function testCompleteTenantSwitchFlow(): void
    {
        // Skip tests that require CI4 Events
        $this->markTestSkipped('Requires CI4 Events system');
    }

    public function testTenantSwitchClearsPreviousContext(): void
    {
        $tableManager = TenantTableManager::getInstance();

        // First tenant
        $tableManager->setTenant(1, 'school1');
        $table1 = $tableManager->getTable('users');

        // Switch to second tenant
        $tableManager->setTenant(2, 'school2');
        $table2 = $tableManager->getTable('users');

        $this->assertEquals('tenant_1_users', $table1);
        $this->assertEquals('tenant_2_users', $table2);
        $this->assertNotEquals($table1, $table2);
    }

    public function testClearResetsAllContext(): void
    {
        $tenantManager = TenantManager::getInstance();
        $tableManager = TenantTableManager::getInstance();

        // Set up context
        $reflection = new \ReflectionClass($tenantManager);
        $tenantIdProp = $reflection->getProperty('tenantId');
        $tenantIdProp->setAccessible(true);
        $tenantIdProp->setValue($tenantManager, 1);

        $tableManager->setTenant(1, 'school1');

        $this->assertTrue($tenantManager->hasTenant());
        $this->assertTrue($tableManager->hasTenant());

        // Clear
        $tenantManager->clear();
        $tableManager->clear();

        $this->assertFalse($tenantManager->hasTenant());
        $this->assertFalse($tableManager->hasTenant());
    }

    // =========================================================================
    // Error Handling Tests
    // =========================================================================

    public function testTableWithoutTenantThrows(): void
    {
        $tableManager = TenantTableManager::getInstance();

        $this->assertFalse($tableManager->hasTenant());

        $this->expectException(\RuntimeException::class);
        $tableManager->getTable('students');
    }

    public function testGlobalTablesWorkWithoutTenant(): void
    {
        $tableManager = TenantTableManager::getInstance();

        // These should work even without tenant
        $this->assertEquals('tenants', $tableManager->getTable('tenants'));
        $this->assertEquals('migrations', $tableManager->getTable('migrations'));
    }

    // =========================================================================
    // Multiple Tables Consistency Tests
    // =========================================================================

    public function testMultipleTablesSameTenantConsistency(): void
    {
        $tableManager = TenantTableManager::getInstance();
        $tableManager->setTenant(5, 'school5');

        $tables = [
            'students' => $tableManager->getTable('students'),
            'teachers' => $tableManager->getTable('teachers'),
            'classes' => $tableManager->getTable('classes'),
            'subjects' => $tableManager->getTable('subjects'),
        ];

        foreach ($tables as $name => $prefixed) {
            $this->assertEquals("tenant_5_{$name}", $prefixed);
        }
    }

    public function testHighTenantIdHandling(): void
    {
        $tableManager = TenantTableManager::getInstance();
        $tableManager->setTenant(999, 'big-school');

        $this->assertEquals('tenant_999_students', $tableManager->getTable('students'));
    }

    // =========================================================================
    // Cache Invalidation Tests
    // =========================================================================

    public function testTableCacheInvalidatedOnTenantChange(): void
    {
        $tableManager = TenantTableManager::getInstance();

        // Tenant 1
        $tableManager->setTenant(1, 'school1');
        $cached1 = $tableManager->getTable('users');

        // Change to tenant 2
        $tableManager->setTenant(2, 'school2');
        $cached2 = $tableManager->getTable('users');

        // Change back to tenant 1 - should re-cache
        $tableManager->setTenant(1, 'school1');
        $cached3 = $tableManager->getTable('users');

        $this->assertEquals('tenant_1_users', $cached1);
        $this->assertEquals('tenant_2_users', $cached2);
        $this->assertEquals('tenant_1_users', $cached3);
    }
}
