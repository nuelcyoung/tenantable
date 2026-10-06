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

namespace nuelcyoung\tenantable\Bootstrap;

use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Support\FrameworkState;

class RedisSystem implements TenantAwareInterface
{
    protected ?object $config = null;
    protected bool $captured = false;
    protected array $originalSettings = [];
    protected ?int $originalDatabase = null;
    protected bool $useDatabasePerTenant = false;
    protected int $maxDatabase = 15;

    public function boot(?int $tenantId, ?array $tenant): void
    {
        if ($tenantId === null) {
            // Central context: undo tenant scoping, keeping capture state
            // so a later tenant boot + shutdown still restores the originals.
            if ($this->captured && $this->config && isset($this->config->default)) {
                $this->config->default = $this->originalSettings;
                $this->clearRedisConnections();
            }
            return;
        }

        $this->config = $this->config ?? $this->redisConfig();

        if (!$this->config) {
            return;
        }

        $this->storeOriginalSettings();

        $tenantRedis = $tenant['settings']['redis'] ?? [];

        if ($this->useDatabasePerTenant) {
            if ($tenantId <= $this->maxDatabase) {
                $this->configureDatabasePerTenant($tenantId);
            } else {
                // Beyond maxDatabase the tenant stays on the default DB and
                // logs loudly rather than silently degrading to prefix-only.
                log_message(
                    'error',
                    "Redis: tenant {$tenantId} exceeds maxDatabase ({$this->maxDatabase}); " .
                    'falling back to key-prefix isolation only. Database-per-tenant mode is ' .
                    'deprecated — disable it and rely on the tenant:{id}: key prefix.'
                );
            }
        }

        $this->configureKeyPrefix($tenantId, $tenantRedis['prefix'] ?? null);

        $this->clearRedisConnections();
    }

    public function shutdown(): void
    {
        $this->restoreOriginalSettings();
        $this->clearRedisConnections();
    }

    protected function storeOriginalSettings(): void
    {
        // Capture once per cycle; boot() reruns on every in-process switch
        // and recapturing would store a tenant prefix as the "original".
        if (!$this->config || $this->captured) {
            return;
        }

        if (isset($this->config->default)) {
            $this->originalSettings = $this->config->default;

            if (isset($this->config->default['database'])) {
                $this->originalDatabase = $this->config->default['database'];
            }

            $this->captured = true;
        }
    }

    protected function configureDatabasePerTenant(int $tenantId): void
    {
        if (!$this->config || !isset($this->config->default)) {
            return;
        }

        // No modulo on purpose: wrapping would seat two tenants in one DB.
        // boot() keeps the caller in range and logs loudly otherwise.
        $database = $tenantId - 1;

        $this->config->default['database'] = $database;

        log_message('debug', "Redis: Switched to database {$database} for tenant {$tenantId}");
    }

    protected function configureKeyPrefix(int $tenantId, ?string $customPrefix = null): void
    {
        if (!$this->config) {
            return;
        }

        $requiredPrefix = "tenant:{$tenantId}:";
        $prefix         = $requiredPrefix;

        // Settings may be user-editable: custom prefixes are only allowed
        // as a suffix of the immutable tenant prefix.
        if ($customPrefix !== null && str_starts_with($customPrefix, $requiredPrefix)) {
            $prefix = $customPrefix;
        }

        if (!isset($this->config->default)) {
            $this->config->default = [];
        }

        $this->config->default['prefix'] = $prefix;

        $cacheConfig = config('Cache');
        if ($cacheConfig && isset($cacheConfig->redis)) {
            $cacheConfig->redis['prefix'] = $prefix;
        }
    }

    /**
     * Drop the shared cache handler so it is rebuilt with the tenant's
     * Redis prefix/database, via FrameworkState.
     */
    protected function clearRedisConnections(): void
    {
        if (! class_exists('Config\Services')) {
            return;
        }

        FrameworkState::resetSharedService('cache');
    }

    protected function restoreOriginalSettings(): void
    {
        if (!$this->config || !$this->captured) {
            return;
        }

        $this->config->default = $this->originalSettings;

        $this->captured         = false;
        $this->originalSettings = [];
        $this->originalDatabase = null;
    }

    protected function redisConfig(): ?object
    {
        return config('Redis');
    }

    public function setUseDatabasePerTenant(bool $use): self
    {
        $this->useDatabasePerTenant = $use;
        return $this;
    }

    public function setMaxDatabase(int $max): self
    {
        $this->maxDatabase = $max;
        return $this;
    }

    public static function key(string $key, ?int $tenantId = null): string
    {
        if ($tenantId === null) {
            $tenantId = TenantManager::getInstance()->getTenantId();
        }

        if ($tenantId === null) {
            return $key;
        }

        return "tenant:{$tenantId}:{$key}";
    }

    public static function cacheKey(string $name, ?int $tenantId = null): string
    {
        return self::key("cache:{$name}", $tenantId);
    }

    public static function sessionKey(string $sessionId, ?int $tenantId = null): string
    {
        return self::key("session:{$sessionId}", $tenantId);
    }
}

if (!function_exists('tenant_redis_key')) {
    function tenant_redis_key(string $key, ?int $tenantId = null): string
    {
        return RedisSystem::key($key, $tenantId);
    }
}

if (!function_exists('tenant_cache_key')) {
    function tenant_cache_key(string $name, ?int $tenantId = null): string
    {
        return RedisSystem::cacheKey($name, $tenantId);
    }
}
