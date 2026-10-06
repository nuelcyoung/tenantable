<?php

/**
 * This file is part of the Tenantable.
 *
 * (c) Nuel Young Chukwunalu <nuelmega@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace nuelcyoung\tenantable\Config;

class Tenantable extends \CodeIgniter\Config\BaseConfig
{
    public string $packageName = 'tenantable';

    public string $baseDomain = 'localhost';

    /** Additional central domains. Subdomain identification matches against every one of them. */
    public array $baseDomains = [];

    public string $tenantsTable   = 'tenants';
    public string $tenantIdColumn = 'tenant_id';

    public bool   $separateDatabasePerTenant = false;
    public string $defaultDatabaseGroup      = 'default';

    /** PostgreSQL database used for CREATE DATABASE. */
    public string $postgresAdminDatabase = 'postgres';

    /** Directory holding tenant SQLite database files. Null = writable/tenant_databases. */
    public ?string $sqliteDatabasesPath = null;

    /** @var callable|null Receives the tenant row, returns a database name. */
    public $databaseNameGenerator = null;

    /** 'row', 'prefix', or 'database'. Null derives from the separate-database flag. */
    public ?string $isolationMode = null;

    /** Auto CREATE DATABASE on new tenant. Database isolation only. */
    public bool $autoCreateDatabase = true;

    /** Auto-run tenant migrations on creation. */
    public bool $autoMigrateTenant = true;

    /**
     * Provision new tenants through the queue instead of the creating request.
     * A queued tenant does not resolve until 'ready'. Default flips in v2.
     */
    public bool $provisionAsync = false;

    /** PSR-4 namespace for per-tenant migrations. */
    public ?string $tenantMigrationsNamespace = 'App\Database\Migrations\Tenant';

    /** Extra migration namespaces. The primary namespace is always included. */
    public array $tenantMigrationsNamespaces = [];

    /** Ship the sessions-table migration to each tenant database. */
    public bool $shipTenantSessionsTable = false;

    /** Namespace for tenant-scoped models. */
    public string $tenantModelsNamespace = 'App\Models\Tenant';

    /** Namespace for global models. */
    public string $globalModelsNamespace = 'App\Models';

    /** Filter alias for tenant identification. */
    public string $identificationMethod = 'tenant_subdomain';

    /** Default identification strategy. */
    public string $defaultStrategy = 'domain_or_subdomain';

    public array $superadminGroups = ['superadmin'];

    public bool $tenantFilteringEnabled = true;

    /** Kept for compatibility. Cross-tenant work uses withoutTenant(). */
    public bool $strictTenantIsolation = true;

    /** Bind sessions to tenants. */
    public bool $bindSessionsToTenant = true;

    /** Destroy unbound sessions instead of adopting them. */
    public bool $rejectUnboundSessions = true;

    /** Per-tenant cookie name. Prevents cross-tenant cookie reuse. */
    public bool $perTenantSessionCookies = true;

    /**
     * Refuse to boot in production when tenant sessions/cache live on
     * node-local disk. Off by default; ignored outside production.
     */
    public bool $requireSharedInfrastructure = false;

    /** Optional callback to authorize a tenant. Receives the request and tenant row. */
    public $tenantAuthorizer = null;

    public array $bypassRoutes = [
        'health',
        '_health',
    ];

    public bool    $throwExceptions = false;
    public ?string $notFoundView    = null;
    public ?string $inactiveView    = null;

    public ?int $fallbackTenantId = null;
    /** Allow localhost host headers. */
    public bool $allowLocalhost   = false;

    /** Tenant ID auto-activated on localhost. */
    public ?int $developmentTenantId = null;

    public bool $cacheTenantData = true;
    public int  $cacheTtl        = 3600;

    /** Serve tenant-scoped files (uploads) through the tenancy assets route. */
    public bool $tenantAssetsEnabled = false;

    /** Route path for tenant assets. Resolves to {route}/{tenantId}/{path}. */
    public string $tenantAssetsRoute = 'tenancy/assets';

    /**
     * Tenant count past which `tenants:doctor` warns about prefix mode, since
     * every tenant multiplies the table count in one database. 0 disables.
     */
    public int $prefixTenantWarningThreshold = 200;

    /** Resolver cache TTL in seconds. */
    public int $resolverCacheTtl = 300;

    /** Resolver cache key prefix. */
    public string $resolverCachePrefix = 'tenant_resolver';

    /** Early detection strategy. One of: subdomain, domain, domain_or_subdomain, off. */
    public string $earlyDetectionStrategy = 'domain_or_subdomain';

    /** Trusted host patterns. Null/[] falls back to base domain. ['*'] allows all. */
    public ?array $trustedHostPatterns = null;

    public array $bootstrappers = [
        'database' => \nuelcyoung\tenantable\Bootstrap\Systems\DatabaseSystem::class,
        'table'    => \nuelcyoung\tenantable\Bootstrap\Systems\TableSystem::class,
        'cache'    => \nuelcyoung\tenantable\Bootstrap\Systems\CacheSystem::class,
        'storage'  => \nuelcyoung\tenantable\Bootstrap\Systems\StorageSystem::class,
        'session'  => \nuelcyoung\tenantable\Bootstrap\Systems\SessionSystem::class,
        'logging'  => \nuelcyoung\tenantable\Bootstrap\Systems\LoggingSystem::class,
        'config'   => \nuelcyoung\tenantable\Bootstrap\Systems\ConfigSystem::class,
        'queue'    => \nuelcyoung\tenantable\Bootstrap\Systems\QueueSystem::class,
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
            'tenant_origin'              => \nuelcyoung\tenantable\Filters\OriginHeaderFilter::class,
            'identify_tenant'            => \nuelcyoung\tenantable\Filters\IdentifyTenant::class,
        ];
    }

    /** All central domains: the primary base domain plus $baseDomains. */
    public function centralDomains(): array
    {
        $domains = [];

        foreach (array_merge([$this->baseDomain], $this->baseDomains) as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($domain !== '' && ! in_array($domain, $domains, true)) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    /** @deprecated Use registerFilters() instead */
    public function registerFilter(): array
    {
        return ['tenant' => \nuelcyoung\tenantable\Filters\TenantFilter::class];
    }

    /** The effective isolation mode. */
    public function resolvedIsolationMode(): string
    {
        $mode = $this->isolationMode ?? ($this->separateDatabasePerTenant ? 'database' : 'row');

        return in_array($mode, ['row', 'prefix', 'database'], true) ? $mode : 'row';
    }

    /** True for database-per-tenant mode. */
    public function isDatabaseIsolation(): bool
    {
        return $this->resolvedIsolationMode() === 'database';
    }

    /** Namespace for the package's tenant-database migrations. */
    public const PACKAGE_TENANT_MIGRATIONS_NAMESPACE = 'nuelcyoung\tenantable\Database\Migrations\Tenant';

    /** All migration namespaces to run on a tenant database. */
    public function tenantMigrationNamespaces(): array
    {
        $namespaces = [];

        if (! empty($this->tenantMigrationsNamespace)) {
            $namespaces[] = $this->tenantMigrationsNamespace;
        }

        if ($this->shipTenantSessionsTable && $this->isDatabaseIsolation()) {
            $namespaces[] = self::PACKAGE_TENANT_MIGRATIONS_NAMESPACE;
        }

        foreach ($this->tenantMigrationsNamespaces as $ns) {
            if (! empty($ns) && ! in_array($ns, $namespaces, true)) {
                $namespaces[] = $ns;
            }
        }

        return $namespaces;
    }
}
