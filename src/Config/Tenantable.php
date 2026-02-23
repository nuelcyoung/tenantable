<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Config;

/**
 * Tenantable Package Configuration
 *
 * Copy this file to app/Config/Tenantable.php to override defaults.
 *
 * FIX 3.6 – $superadminGroups default is ['superadmin'] only.
 * M-1     – $bootstrappers array controls which systems are registered.
 * NEW     – $identification lets you switch the default filter strategy.
 */
class Tenantable extends \CodeIgniter\Config\BaseConfig
{
    public string $packageName = 'tenantable';

    // -------------------------------------------------------------------------
    // Domain / Subdomain
    // -------------------------------------------------------------------------

    /**
     * Base domain for subdomain extraction.
     *
     * Example: 'myapp.com' → school.myapp.com extracts 'school'.
     * Use 'localhost' + $fallbackTenantId during local development.
     */
    public string $baseDomain = 'localhost';

    // -------------------------------------------------------------------------
    // Database
    // -------------------------------------------------------------------------

    public string $tenantsTable   = 'tenants';
    public string $tenantIdColumn = 'tenant_id';

    public bool   $separateDatabasePerTenant = false;
    public string $defaultDatabaseGroup      = 'default';

    // -------------------------------------------------------------------------
    // Identification strategy
    // -------------------------------------------------------------------------

    /**
     * Which identification method to use by default.
     *
     * Can be any of the registered filter aliases:
     *   'tenant'                  — subdomain (default, back-compat)
     *   'tenant_subdomain'        — subdomain
     *   'tenant_domain'           — full custom domain
     *   'tenant_domain_or_subdomain' — try domain first, then subdomain
     *   'tenant_path'             — URI path segment
     *   'tenant_request'          — X-Tenant header or ?tenant= query param
     *
     * This is informational; you still register the actual filter on your
     * routes or in app/Config/Filters.php.
     */
    public string $identificationMethod = 'tenant_subdomain';

    // -------------------------------------------------------------------------
    // Security
    // -------------------------------------------------------------------------

    /**
     * FIX 3.6 – Only 'superadmin' bypasses tenant filtering by default.
     * @var string[]
     */
    public array $superadminGroups = ['superadmin'];

    public bool $tenantFilteringEnabled = true;

    /**
     * URI patterns that always bypass tenant detection (glob syntax).
     */
    public array $bypassRoutes = [
        'api/*',
        'health',
        '_health',
    ];

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    /** Throw exceptions instead of rendering views (for API-only apps). */
    public bool    $throwExceptions = false;

    /** View rendered when tenant is not found (404). Null → plain text. */
    public ?string $notFoundView    = null;

    /** View rendered when tenant is inactive (403). Null → plain text. */
    public ?string $inactiveView    = null;

    // -------------------------------------------------------------------------
    // Local development
    // -------------------------------------------------------------------------

    /** Fallback tenant ID when no subdomain is present (dev only). Null in prod. */
    public ?int  $fallbackTenantId = null;
    public bool  $allowLocalhost   = true;

    // -------------------------------------------------------------------------
    // Cache
    // -------------------------------------------------------------------------

    public bool $cacheTenantData = true;
    public int  $cacheTtl        = 3600;

    // -------------------------------------------------------------------------
    // Bootstrap systems  (M-1)
    // -------------------------------------------------------------------------

    /**
     * Systems booted on every request once a tenant is identified.
     * Comment out or remove entries to opt-out.
     *
     * Each value must be a class implementing TenantAwareInterface.
     *
     * @var array<string, class-string<\nuelcyoung\tenantable\Bootstrap\TenantAwareInterface>>
     */
    public array $bootstrappers = [
        'table'   => \nuelcyoung\tenantable\Bootstrap\Systems\TableSystem::class,
        'cache'   => \nuelcyoung\tenantable\Bootstrap\Systems\CacheSystem::class,
        'storage' => \nuelcyoung\tenantable\Bootstrap\Systems\StorageSystem::class,
        'session' => \nuelcyoung\tenantable\Bootstrap\Systems\SessionSystem::class,
        'logging' => \nuelcyoung\tenantable\Bootstrap\Systems\LoggingSystem::class,
        'config'  => \nuelcyoung\tenantable\Bootstrap\Systems\ConfigSystem::class,
    ];

    // -------------------------------------------------------------------------
    // Subdomain validation
    // -------------------------------------------------------------------------

    public array $subdomainRules = [
        'min_length' => 2,
        'max_length' => 50,
        'pattern'    => '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/',
    ];

    // -------------------------------------------------------------------------
    // Filter + Command registration (CI4 package discovery)
    // -------------------------------------------------------------------------

    /**
     * Register all identification filter aliases.
     *
     * In app/Config/Filters.php you can then use any of:
     *   'tenant'                     — subdomain (default alias)
     *   'tenant_subdomain'           — subdomain (explicit)
     *   'tenant_domain'              — full custom domain
     *   'tenant_domain_or_subdomain' — domain first, then subdomain
     *   'tenant_path'                — URI path segment
     *   'tenant_request'             — X-Tenant header / ?tenant= param
     *
     * @return array<string, class-string>
     */
    public function registerFilters(): array
    {
        return [
            'tenant'                     => \nuelcyoung\tenantable\Filters\TenantFilter::class,
            'tenant_subdomain'           => \nuelcyoung\tenantable\Filters\SubdomainFilter::class,
            'tenant_domain'              => \nuelcyoung\tenantable\Filters\DomainFilter::class,
            'tenant_domain_or_subdomain' => \nuelcyoung\tenantable\Filters\DomainOrSubdomainFilter::class,
            'tenant_path'                => \nuelcyoung\tenantable\Filters\PathFilter::class,
            'tenant_request'             => \nuelcyoung\tenantable\Filters\RequestDataFilter::class,
        ];
    }

    /**
     * @deprecated Use registerFilters() instead (kept for back-compat).
     * @return array<string, class-string>
     */
    public function registerFilter(): array
    {
        return ['tenant' => \nuelcyoung\tenantable\Filters\TenantFilter::class];
    }
}
