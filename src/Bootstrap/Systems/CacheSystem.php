<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap\Systems;

use nuelcyoung\tenantable\Bootstrap\TenantAwareInterface;

/**
 * Isolates the cache per tenant by changing the cache key prefix.
 *
 * C-3 – Removed the $cache->clean() call that wiped the ENTIRE cache store
 *        on every single request. The prefix change is sufficient to keep
 *        tenant caches isolated — different prefixes mean different keys.
 */
class CacheSystem implements TenantAwareInterface
{
    protected string $originalPrefix = '';

    public function boot(?int $tenantId, ?array $tenant): void
    {
        $config = config('Cache');

        if ($config === null) {
            return;
        }

        // Save the original prefix so we can restore it on shutdown
        $this->originalPrefix = $config->prefix ?? '';

        // Apply tenant-specific prefix
        // C-3 – Prefix alone is sufficient; DO NOT call $cache->clean()
        $config->prefix = $tenantId !== null
            ? "tenant_{$tenantId}_"
            : '';
    }

    public function shutdown(): void
    {
        $config = config('Cache');

        if ($config !== null) {
            $config->prefix = $this->originalPrefix;
        }

        $this->originalPrefix = '';
    }
}
