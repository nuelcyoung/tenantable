<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap;

use nuelcyoung\tenantable\Services\TenantManager;

class RedisSystem implements TenantAwareInterface
{
    protected ?object $config = null;
    protected array $originalSettings = [];
    protected ?int $originalDatabase = null;
    protected bool $useDatabasePerTenant = false;
    protected int $maxDatabase = 15;

    public function boot(?int $tenantId, ?array $tenant): void
    {
        if ($tenantId === null) {
            return;
        }

        $this->config = config('Redis');

        if (!$this->config) {
            return;
        }

        $this->storeOriginalSettings();

        $tenantRedis = $tenant['settings']['redis'] ?? [];

        if ($this->useDatabasePerTenant && $tenantId <= $this->maxDatabase) {
            $this->configureDatabasePerTenant($tenantId);
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
        if (!$this->config) {
            return;
        }

        if (isset($this->config->default)) {
            $this->originalSettings = $this->config->default;

            if (isset($this->config->default['database'])) {
                $this->originalDatabase = $this->config->default['database'];
            }
        }
    }

    protected function configureDatabasePerTenant(int $tenantId): void
    {
        if (!$this->config || !isset($this->config->default)) {
            return;
        }

        $database = ($tenantId - 1) % ($this->maxDatabase + 1);

        $this->config->default['database'] = $database;

        log_message('debug', "Redis: Switched to database {$database} for tenant {$tenantId}");
    }

    protected function configureKeyPrefix(int $tenantId, ?string $customPrefix = null): void
    {
        if (!$this->config) {
            return;
        }

        $prefix = $customPrefix ?? "tenant:{$tenantId}:";

        if (!isset($this->config->default)) {
            $this->config->default = [];
        }

        $this->config->default['prefix'] = $prefix;

        $cacheConfig = config('Cache');
        if ($cacheConfig && isset($cacheConfig->redis)) {
            $cacheConfig->redis['prefix'] = $prefix;
        }
    }

    protected function clearRedisConnections(): void
    {
        if (class_exists('Config\Services')) {
            try {
                $services = new \ReflectionClass('Config\Services');
                $property = $services->getProperty('instances');
                $property->setAccessible(true);
                $instances = $property->getValue(null);

                if (isset($instances['cache'])) {
                    unset($instances['cache']);
                    $property->setValue(null, $instances);
                }
            } catch (\Throwable $e) {
            }
        }
    }

    protected function restoreOriginalSettings(): void
    {
        if (!$this->config || empty($this->originalSettings)) {
            return;
        }

        $this->config->default = $this->originalSettings;
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