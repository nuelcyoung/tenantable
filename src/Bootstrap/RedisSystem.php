<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap;

use nuelcyoung\tenantable\Services\TenantManager;

/**
 * RedisSystem - Handles tenant-aware Redis configuration
 * 
 * Supports:
 * - Database selection per tenant (0-15)
 * - Key prefixing for shared databases
 * - Connection pooling
 */
class RedisSystem implements TenantAwareInterface
{
    /**
     * Redis config reference
     */
    protected ?object $config = null;
    
    /**
     * Original settings
     */
    protected array $originalSettings = [];
    
    /**
     * Redis database mapping
     */
    protected ?int $originalDatabase = null;
    
    /**
     * Whether to use database per tenant
     */
    protected bool $useDatabasePerTenant = false;
    
    /**
     * Maximum Redis database number
     */
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

        // Store original settings
        $this->storeOriginalSettings();
        
        // Get tenant Redis config
        $tenantRedis = $tenant['settings']['redis'] ?? [];
        
        // Strategy 1: Use different database per tenant (0-15)
        if ($this->useDatabasePerTenant && $tenantId <= $this->maxDatabase) {
            $this->configureDatabasePerTenant($tenantId);
        }
        
        // Strategy 2: Key prefixing (always applied)
        $this->configureKeyPrefix($tenantId, $tenantRedis['prefix'] ?? null);
        
        // Clear Redis connection cache to force reconnect
        $this->clearRedisConnections();
    }

    public function shutdown(): void
    {
        $this->restoreOriginalSettings();
        $this->clearRedisConnections();
    }

    /**
     * Store original Redis settings
     */
    protected function storeOriginalSettings(): void
    {
        if (!$this->config) {
            return;
        }

        // Store default group settings
        if (isset($this->config->default)) {
            $this->originalSettings = $this->config->default;
            
            if (isset($this->config->default['database'])) {
                $this->originalDatabase = $this->config->default['database'];
            }
        }
    }

    /**
     * Configure database per tenant
     */
    protected function configureDatabasePerTenant(int $tenantId): void
    {
        if (!$this->config || !isset($this->config->default)) {
            return;
        }
        
        // Map tenant to database (0-15)
        // Tenant 1 -> DB 0, Tenant 2 -> DB 1, etc.
        $database = ($tenantId - 1) % ($this->maxDatabase + 1);
        
        $this->config->default['database'] = $database;
        
        log_message('debug', "Redis: Switched to database {$database} for tenant {$tenantId}");
    }

    /**
     * Configure key prefixing
     */
    protected function configureKeyPrefix(int $tenantId, ?string $customPrefix = null): void
    {
        if (!$this->config) {
            return;
        }
        
        $prefix = $customPrefix ?? "tenant:{$tenantId}:";
        
        // Set prefix in Redis config
        if (!isset($this->config->default)) {
            $this->config->default = [];
        }
        
        $this->config->default['prefix'] = $prefix;
        
        // Also set in main config for Cache/Session to pick up
        $cacheConfig = config('Cache');
        if ($cacheConfig && isset($cacheConfig->redis)) {
            $cacheConfig->redis['prefix'] = $prefix;
        }
    }

    /**
     * Clear Redis connections to force reconnect
     */
    protected function clearRedisConnections(): void
    {
        // Clear any cached Redis connections
        // This forces CodeIgniter to reconnect with new config
        if (class_exists('Config\Services')) {
            try {
                // Reset the cache service
                $services = new \ReflectionClass('Config\Services');
                $property = $services->getProperty('instances');
                $property->setAccessible(true);
                $instances = $property->getValue(null);
                
                if (isset($instances['cache'])) {
                    unset($instances['cache']);
                    $property->setValue(null, $instances);
                }
            } catch (\Throwable $e) {
                // Ignore reflection errors
            }
        }
    }

    /**
     * Restore original settings
     */
    protected function restoreOriginalSettings(): void
    {
        if (!$this->config || empty($this->originalSettings)) {
            return;
        }
        
        $this->config->default = $this->originalSettings;
    }

    /**
     * Set whether to use database per tenant
     */
    public function setUseDatabasePerTenant(bool $use): self
    {
        $this->useDatabasePerTenant = $use;
        return $this;
    }

    /**
     * Set max database number
     */
    public function setMaxDatabase(int $max): self
    {
        $this->maxDatabase = $max;
        return $this;
    }

    /**
     * Get Redis key with tenant prefix
     */
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

    /**
     * Helper to generate Redis key
     */
    public static function cacheKey(string $name, ?int $tenantId = null): string
    {
        return self::key("cache:{$name}", $tenantId);
    }

    /**
     * Helper for session key
     */
    public static function sessionKey(string $sessionId, ?int $tenantId = null): string
    {
        return self::key("session:{$sessionId}", $tenantId);
    }
}

/**
 * Helper function for Redis keys
 */
if (!function_exists('tenant_redis_key')) {
    function tenant_redis_key(string $key, ?int $tenantId = null): string
    {
        return RedisSystem::key($key, $tenantId);
    }
}

/**
 * Helper for cache keys
 */
if (!function_exists('tenant_cache_key')) {
    function tenant_cache_key(string $name, ?int $tenantId = null): string
    {
        return RedisSystem::cacheKey($name, $tenantId);
    }
}
