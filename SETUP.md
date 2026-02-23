<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable;

/**
 * Setup Instructions for CodeIgniter 4
 * 
 * Add these to your app/Config/Events.php
 */

/*
// app/Config/Events.php

use CodeIgniter\Events\Events;
use nuelcyoung\tenantable\Bootstrap\EarlyTenantDetector;
use nuelcyoung\tenantable\Filters\TenantFilter;

/**
 * CRITICAL: Early tenant detection (Priority 1)
 * 
 * Must run BEFORE:
 * - Session initialization
 * - Cache initialization
 * - Database auto-connection
 * - Any services needing tenant context
 */
Events::on('pre_system', [EarlyTenantDetector::class, 'detect'], 1);

/**
 * Late tenant detection via Filter
 * 
 * This runs after early detection but provides:
 * - Request object available
 * - Better error handling
 * - Redirect capability
 */
// In app/Config/Filters.php:
public $filters = [
    'tenant' => ['before' => ['/*'], 'except' => ['health', 'api/*']],
];

// In app/Config/Routes.php (if needed):
$routes->setDefaultNamespace('App\Controllers');

/**
 * Session Configuration
 * 
 * The EarlyTenantDetector automatically configures session.
 * DO NOT set session.savePath in php.ini or Config/Session.php
 * The detector will set it dynamically based on tenant.
 */

/**
 * Alternative: Session Data Validation (if you can't use early detection)
 * 
 * If early detection isn't possible, validate session data:
 */
Events::on('post_controller_constructor', function() {
    $sessionTenant = session()->get('tenant_id');
    $currentTenant = \nuelcyoung\tenantable\Services\TenantManager::getInstance()->getTenantId();
    
    if ($sessionTenant !== null && $sessionTenant !== $currentTenant) {
        // Tenant mismatch - destroy session
        session()->destroy();
        session()->start();
        session()->set('tenant_id', $currentTenant);
    }
});

/**
 * CLI Support
 * 
 * For CLI commands, manually set tenant:
 */
// php spark tenant:switch school1
// or
// TENANT_SUBDOMAIN=school1 php spark migrate

if (PHP_SAPI === 'cli') {
    $subdomain = $_ENV['TENANT_SUBDOMAIN'] ?? null;
    
    if ($subdomain) {
        \nuelcyoung\tenantable\Services\TenantManager::getInstance()
            ->setTenantBySubdomain($subdomain);
    }
}

/**
 * Helper Functions
 * 
 * Available globally after autoload:
 */
// tenant_id() - Get current tenant ID
// tenant() - Get current tenant data
// tenant_subdomain() - Get current subdomain
// tenant_url() - Generate tenant URL
// has_tenant() - Check if tenant is set

/**
 * Model Usage
 * 
 * Option 1: Table Prefix (Recommended)
 */
use nuelcyoung\tenantable\Traits\TenantTablePrefixModel;

class StudentModel extends TenantTablePrefixModel
{
    protected $table = 'students';
    // Automatically uses: tenant_1_students, tenant_2_students, etc.
}

/**
 * Option 2: tenant_id Column
 */
use nuelcyoung\tenantable\Traits\TenantableTrait;

class StudentModel extends Model
{
    use TenantableTrait;
    // Automatically adds: WHERE tenant_id = X
}

/**
 * Option 3: Separate Database
 */
// Configure in tenants table:
// database_name, database_host, database_username, database_password

/**
 * Security: Superadmin Bypass
 */
// In controller or model:
if (can_bypass_tenant()) {
    // Access all tenants
    Model::withoutTenant(function() {
        return Model::findAll();
    });
}

/**
 * Configuration
 * 
 * Copy src/Config/Tenantable.php to app/Config/Tenantable.php
 * and customize:
 */
public $baseDomain = 'example.com';
public $isolationStrategy = 'table_prefix';
public $superadminGroups = ['superadmin', 'admin'];
public $bypassRoutes = ['api/*', 'health'];
