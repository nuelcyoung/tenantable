<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Config;

class Tenantable extends \CodeIgniter\Config\BaseConfig
{
    public string $packageName = 'tenantable';

    public string $baseDomain = 'localhost';

    public string $tenantsTable   = 'tenants';
    public string $tenantIdColumn = 'tenant_id';

    public bool   $separateDatabasePerTenant = false;
    public string $defaultDatabaseGroup      = 'default';

    public string $identificationMethod = 'tenant_subdomain';

    public array $superadminGroups = ['superadmin'];

    public bool $tenantFilteringEnabled = true;

    public array $bypassRoutes = [
        'api/*',
        'health',
        '_health',
    ];

    public bool    $throwExceptions = false;
    public ?string $notFoundView    = null;
    public ?string $inactiveView    = null;

    public ?int $fallbackTenantId = null;
    public bool $allowLocalhost   = true;

    public bool $cacheTenantData = true;
    public int  $cacheTtl        = 3600;

    public array $bootstrappers = [
        'table'   => \nuelcyoung\tenantable\Bootstrap\Systems\TableSystem::class,
        'cache'   => \nuelcyoung\tenantable\Bootstrap\Systems\CacheSystem::class,
        'storage' => \nuelcyoung\tenantable\Bootstrap\Systems\StorageSystem::class,
        'session' => \nuelcyoung\tenantable\Bootstrap\Systems\SessionSystem::class,
        'logging' => \nuelcyoung\tenantable\Bootstrap\Systems\LoggingSystem::class,
        'config'  => \nuelcyoung\tenantable\Bootstrap\Systems\ConfigSystem::class,
    ];

    public array $subdomainRules = [
        'min_length' => 2,
        'max_length' => 50,
        'pattern'    => '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/',
    ];

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

    /** @deprecated Use registerFilters() instead */
    public function registerFilter(): array
    {
        return ['tenant' => \nuelcyoung\tenantable\Filters\TenantFilter::class];
    }
}
