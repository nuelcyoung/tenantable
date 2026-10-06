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

namespace nuelcyoung\tenantable\Services;

use nuelcyoung\tenantable\Models\TenantDomainModel;
use nuelcyoung\tenantable\Models\TenantModel;
use nuelcyoung\tenantable\Support\TenantableConfig;

/** Caches domain-to-tenant resolution. Uses global cache (pre-tenant). */
class TenantResolverCache
{
    private static ?self $instance = null;

    /** Memoized version counter to avoid extra cache round-trips. */
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

    /** Resolve tenant by host via cache. */
    public function resolveByHost(string $host): ?array
    {
        $host = TenantManager::getInstance()->normalizeHost($host);

        if ($host === null) {
            return null;
        }

        $config   = $this->getConfig();
        $cache    = $this->getCache();
        $ttl      = $config->resolverCacheTtl;
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

    /** Flush all keys via version bump (O(1), no scan). */
    public function flush(): void
    {
        $config = $this->getConfig();
        $cache  = $this->getCache();
        $prefix = $config->resolverCachePrefix;

        $version = (int) ($cache->get("{$prefix}_version") ?? 0);
        $cache->save("{$prefix}_version", $version + 1, 0);
        $this->versionCache = $version + 1;
    }

    /** Flush cache for a specific host. */
    public function flushHost(string $host): void
    {
        $host = TenantManager::getInstance()->normalizeHost($host);

        if ($host === null) {
            return;
        }

        $cache = $this->getCache();
        $cache->delete($this->buildKey($host));
    }

    /** Resolve tenant by subdomain via cache. */
    public function resolveBySubdomain(string $subdomain): ?array
    {
        $subdomain = strtolower(trim($subdomain));
        $config    = $this->getConfig();
        $cache     = $this->getCache();
        $ttl       = $config->resolverCacheTtl;
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
        $subdomain = strtolower(trim($subdomain));
        $cache = $this->getCache();
        $cache->delete($this->buildSubdomainKey($subdomain));
    }

    /** Build versioned cache key. */
    protected function buildKey(string $host): string
    {
        $config  = $this->getConfig();
        $prefix  = $config->resolverCachePrefix;
        $version = $this->getVersion($prefix);

        return "{$prefix}_v{$version}_host_" . md5(strtolower($host));
    }

    protected function buildSubdomainKey(string $subdomain): string
    {
        $config  = $this->getConfig();
        $prefix  = $config->resolverCachePrefix;
        $version = $this->getVersion($prefix);

        return "{$prefix}_v{$version}_sub_" . md5(strtolower($subdomain));
    }

    protected function getVersion(string $prefix): int
    {
        if ($this->versionCache !== null) {
            return $this->versionCache;
        }

        $cache   = $this->getCache();
        $version = (int) ($cache->get("{$prefix}_version") ?? 0);

        return $this->versionCache = $version;
    }

    protected function resolveFromDatabase(string $host): ?array
    {
        $domainModel = new TenantDomainModel();

        $domainRow = $domainModel
            ->where('domain', TenantDomainModel::normalizeDomain($host))
            ->where('is_verified', 1)
            ->where('verified_at IS NOT NULL')
            ->first();

        if ($domainRow !== null) {
            $tenant = (new TenantModel())->find((int) $domainRow['tenant_id']);
            if ($tenant !== null) {
                return $this->buildEntry($tenant);
            }
        }

        return null;
    }

    /** Build cache entry. Carries full row to skip second DB lookup. */
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
        return TenantableConfig::get();
    }

    /**
     * Resolver cache must never inherit the active tenant's application
     * cache prefix. Use a private non-shared handler with a stable namespace.
     */
    protected function getCache(): object
    {
        if (isset($this->cache)) {
            return $this->cache;
        }

        $config = config('Cache');

        if (is_object($config)) {
            $config = clone $config;

            if (property_exists($config, 'prefix')) {
                $config->prefix = 'tenantable_resolver_';
            }
        }

        $this->cache = \Config\Services::cache($config, false);

        return $this->cache;
    }

    private ?object $cache = null;
}
