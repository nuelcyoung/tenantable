<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Services;

use nuelcyoung\tenantable\Models\TenantDomainModel;
use nuelcyoung\tenantable\Models\TenantModel;

/**
 * TenantResolverCache
 *
 * Caches domain-to-tenant resolution to avoid repeated DB queries.
 * Uses the global CI4 cache (not tenant-prefixed) since this runs
 * before tenant context exists.
 */
class TenantResolverCache
{
    private static ?self $instance = null;

    /**
     * Per-request memoization of the version counter to avoid a second
     * cache round-trip on every resolveByHost call.
     */
    private ?int $versionCache = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Resolve tenant by host, using cache first.
     *
     * @return array|null ['tenant_id' => int, 'is_active' => bool, 'tenant' => array] or null
     */
    public function resolveByHost(string $host): ?array
    {
        $host     = strtolower($host);
        $config   = $this->getConfig();
        $cache    = \Config\Services::cache();
        $ttl      = $config->resolverCacheTtl ?? 300;
        $cacheKey = $this->buildKey($host);

        $cached = $cache->get($cacheKey);
        if ($cached !== null) {
            if (is_array($cached) && ($cached['tenant_id'] ?? null) === null) {
                return null;
            }
            return is_array($cached) ? $cached : null;
        }

        $result = $this->resolveFromDatabase($host);

        if ($result !== null) {
            $cache->save($cacheKey, $result, $ttl);
        } else {
            $cache->save($cacheKey, ['tenant_id' => null], 60);
        }

        return $result;
    }

    /**
     * Flush all resolver cache keys.
     *
     * Implemented via a version counter that's part of every cache key —
     * bumping the version makes all existing keys unreachable in O(1)
     * without scanning, which CI4 cache drivers don't support.
     */
    public function flush(): void
    {
        $config = $this->getConfig();
        $cache  = \Config\Services::cache();
        $prefix = $config->resolverCachePrefix ?? 'tenant_resolver';

        $version = (int) ($cache->get("{$prefix}_version") ?? 0);
        $cache->save("{$prefix}_version", $version + 1, 0);
        $this->versionCache = $version + 1;
    }

    /**
     * Flush resolver cache for a specific host (uses current version).
     */
    public function flushHost(string $host): void
    {
        $cache = \Config\Services::cache();
        $cache->delete($this->buildKey($host));
    }

    /**
     * Resolve tenant by subdomain, using cache first.
     *
     * @return array|null Same shape as resolveByHost
     */
    public function resolveBySubdomain(string $subdomain): ?array
    {
        $subdomain = strtolower($subdomain);
        $config    = $this->getConfig();
        $cache     = \Config\Services::cache();
        $ttl       = $config->resolverCacheTtl ?? 300;
        $cacheKey  = $this->buildSubdomainKey($subdomain);

        $cached = $cache->get($cacheKey);
        if ($cached !== null) {
            if (is_array($cached) && ($cached['tenant_id'] ?? null) === null) {
                return null;
            }
            return is_array($cached) ? $cached : null;
        }

        $tenant = (new TenantModel())->where('subdomain', $subdomain)->first();

        if ($tenant !== null) {
            $entry = $this->buildEntry($tenant);
            $cache->save($cacheKey, $entry, $ttl);
            return $entry;
        }

        $cache->save($cacheKey, ['tenant_id' => null], 60);
        return null;
    }

    public function flushSubdomain(string $subdomain): void
    {
        $cache = \Config\Services::cache();
        $cache->delete($this->buildSubdomainKey($subdomain));
    }

    /**
     * Build a versioned cache key. Bumping the version (via flush) makes
     * every previously-cached host unreachable atomically.
     */
    protected function buildKey(string $host): string
    {
        $config  = $this->getConfig();
        $prefix  = $config->resolverCachePrefix ?? 'tenant_resolver';
        $version = $this->getVersion($prefix);

        return "{$prefix}_v{$version}_host_" . md5(strtolower($host));
    }

    protected function buildSubdomainKey(string $subdomain): string
    {
        $config  = $this->getConfig();
        $prefix  = $config->resolverCachePrefix ?? 'tenant_resolver';
        $version = $this->getVersion($prefix);

        return "{$prefix}_v{$version}_sub_" . md5(strtolower($subdomain));
    }

    protected function getVersion(string $prefix): int
    {
        if ($this->versionCache !== null) {
            return $this->versionCache;
        }

        $cache   = \Config\Services::cache();
        $version = (int) ($cache->get("{$prefix}_version") ?? 0);

        return $this->versionCache = $version;
    }

    protected function resolveFromDatabase(string $host): ?array
    {
        // Try tenant_domains first
        $domainModel = new TenantDomainModel();
        $domainRow   = $domainModel->where('domain', $host)->first();

        if ($domainRow !== null) {
            $tenant = (new TenantModel())->find((int) $domainRow['tenant_id']);
            if ($tenant !== null) {
                return $this->buildEntry($tenant);
            }
        }

        // Fallback to tenants.domain (deprecated)
        $tenant = (new TenantModel())->where('domain', $host)->first();

        if ($tenant !== null) {
            return $this->buildEntry($tenant);
        }

        return null;
    }

    /**
     * Build a cache entry from a tenant row. Carrying the full row lets
     * callers populate TenantManager without a second DB lookup.
     */
    protected function buildEntry(array $tenant): array
    {
        return [
            'tenant_id' => (int) $tenant['id'],
            'is_active' => (bool) ($tenant['is_active'] ?? false),
            'tenant'    => $tenant,
        ];
    }

    protected function getConfig(): \nuelcyoung\tenantable\Config\Tenantable
    {
        return config(\nuelcyoung\tenantable\Config\Tenantable::class);
    }
}
