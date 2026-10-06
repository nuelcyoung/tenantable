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

namespace nuelcyoung\tenantable\Bootstrap\Systems;

use nuelcyoung\tenantable\Bootstrap\TenantAwareInterface;
use nuelcyoung\tenantable\Support\FrameworkState;

class CacheSystem implements TenantAwareInterface
{
    protected bool $captured = false;
    protected ?string $originalPrefix = null;

    /** Capture the application prefix before EarlyTenantDetector mutates it. */
    public function captureOriginalState(): void
    {
        if ($this->captured) {
            return;
        }

        $config = $this->cacheConfig();

        if ($config === null) {
            return;
        }

        $this->originalPrefix = isset($config->prefix) && is_string($config->prefix)
            ? $config->prefix
            : null;
        $this->captured = true;
    }

    public function boot(?int $tenantId, ?array $tenant): void
    {
        $config = $this->cacheConfig();

        if ($config === null) {
            return;
        }

        // Capture once per cycle; boot() reruns on every in-process switch
        // and recapturing would store a tenant prefix as the "original".
        $this->captureOriginalState();

        $config->prefix = $tenantId !== null
            ? "tenant_{$tenantId}_"
            : ($this->originalPrefix ?? '');

        // CI4 cache handlers capture the prefix in their constructor, so a
        // shared handler built before boot must be dropped, not just reconfigured.
        self::resetSharedCache();
    }

    public function shutdown(): void
    {
        $config = $this->cacheConfig();

        if ($config !== null && $this->captured && $this->originalPrefix !== null) {
            $config->prefix = $this->originalPrefix;
        }

        self::resetSharedCache();

        $this->captured       = false;
        $this->originalPrefix = null;
    }

    protected function cacheConfig(): ?object
    {
        return config('Cache');
    }

    /**
     * Drop the handler whose constructor captured the previous prefix,
     * via FrameworkState.
     */
    public static function resetSharedCache(): void
    {
        FrameworkState::resetSharedService('cache');
    }
}
