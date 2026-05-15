<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap;

use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Services\TenantTableManager;
use nuelcyoung\tenantable\Services\TenantResolverCache;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;
use nuelcyoung\tenantable\Exceptions\TenantInactiveException;

/**
 * EarlyTenantDetector
 * 
 * Detects tenant BEFORE CodeIgniter initializes sessions, cache, etc.
 * Must be registered in Events.php under 'pre_system' with priority 1.
 * 
 * This runs before:
 * - Session initialization
 * - Cache initialization  
 * - Database connection (optional)
 * - Any services that need tenant context
 */
class EarlyTenantDetector
{
    /**
     * Detect tenant from request
     * 
     * @return void
     */
    public static function detect(): void
    {
        // Don't run in CLI
        if (PHP_SAPI === 'cli') {
            return;
        }

        $config = self::getConfig();

        // Check early detection strategy
        $strategy = $config->earlyDetectionStrategy ?? 'domain_or_subdomain';
        if ($strategy === 'off') {
            return;
        }

        // Skip bypass routes (health probes, public APIs, etc) — they don't
        // need tenant context and pre_system DB work is pure waste for them.
        if (self::isBypassedRoute($config)) {
            return;
        }

        // Get host
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $host = explode(':', (string) $host)[0]; // strip port

        if ($host === '') {
            return;
        }

        $manager = TenantManager::getInstance();

        // Reject hosts not on the trusted allowlist before touching the
        // cache or DB. The filter will render a 400 downstream — here we
        // just bail out so we don't pollute the negative-cache with
        // attacker-controlled hosts.
        if (! $manager->isHostAllowed($host)) {
            return;
        }

        // Skip if localhost and not configured
        if ($manager->isLocalhost($host)) {
            if ($config->allowLocalhost ?? true) {
                return;
            }
        }

        try {
            $resolved = false;

            // Try domain resolution first (if strategy allows)
            if (in_array($strategy, ['domain', 'domain_or_subdomain'], true)) {
                $resolved = self::resolveByDomain($host, $manager);
            }

            // Fall back to subdomain (if strategy allows and domain didn't resolve)
            if (!$resolved && in_array($strategy, ['subdomain', 'domain_or_subdomain'], true)) {
                $subdomain = $manager->extractSubdomain($host);
                
                if ($subdomain !== null) {
                    $manager->setTenantBySubdomain($subdomain);
                    $resolved = true;
                }
            }

            if (!$resolved) {
                return;
            }

            // Bootstrap table manager if using prefix strategy
            $tableManager = TenantTableManager::getInstance();
            $tableManager->setTenant(
                $manager->getTenantId(),
                $manager->getSubdomain()
            );

            // Now configure session path BEFORE session starts
            self::configureSession($manager->getTenantId());

            // Configure cache prefix
            self::configureCache($manager->getTenantId());

            // Configure storage paths
            self::configureStorage($manager->getTenantId());
            
        } catch (TenantNotFoundException|TenantInactiveException $e) {
            // pre_system runs BEFORE the filter pipeline — rethrowing would
            // bypass BaseTenantFilter::handleNotFound/Inactive and surface a
            // raw exception page. Clear any partial state and let the filter
            // re-resolve and render the configured 404/403 view.
            TenantManager::getInstance()->clear();
        } catch (\Throwable $e) {
            // Generic safety net: log + abort early bootstrap, do not configure
            TenantManager::getInstance()->clear();
            error_log("EarlyTenantDetector: {$e->getMessage()}");
        }
    }

    /**
     * Resolve tenant by full custom domain using resolver cache.
     */
    protected static function resolveByDomain(string $host, TenantManager $manager): bool
    {
        $cache  = TenantResolverCache::getInstance();
        $cached = $cache->resolveByHost($host);

        if ($cached === null) {
            return false;
        }

        if (!empty($cached['tenant']) && is_array($cached['tenant'])) {
            $manager->setTenant($cached['tenant']);
            return true;
        }

        if ($cached['is_active'] !== true) {
            throw new TenantInactiveException("Tenant for domain '{$host}' is inactive");
        }
        $manager->setTenantById($cached['tenant_id']);
        return true;
    }

    /**
     * Configure session for tenant
     * 
     * CRITICAL: Must run before session starts
     */
    protected static function configureSession(?int $tenantId): void
    {
        if ($tenantId === null) {
            return;
        }

        $config = config('Session');
        
        if (!$config) {
            return;
        }

        // Set tenant-specific session save path
        $basePath = WRITEPATH . 'session';
        $tenantPath = $basePath . '/tenant_' . $tenantId;
        
        // Create directory if needed
        if (!is_dir($tenantPath)) {
            mkdir($tenantPath, 0755, true);
        }
        
        // Set BEFORE session starts
        $config->savePath = $tenantPath;

        // Also set session cookie name to include tenant (use declared $cookieName)
        $config->cookieName = 'tenant_' . $tenantId . '_session';
    }

    /**
     * Configure cache for tenant
     */
    protected static function configureCache(?int $tenantId): void
    {
        $config = config('Cache');
        
        if (!$config) {
            return;
        }
        
        // Set prefix for all cache keys
        $config->prefix = $tenantId !== null ? "tenant_{$tenantId}_" : '';
    }

    /**
     * Configure storage paths for tenant
     */
    protected static function configureStorage(?int $tenantId): void
    {
        if ($tenantId === null) {
            return;
        }

        // Define tenant storage path constant
        $storagePath = ROOTPATH . 'writable' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'tenant_' . $tenantId;
        
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }
        
        // Set as environment variable for helpers
        $_ENV['TENANT_STORAGE_PATH'] = $storagePath;
        $_ENV['TENANT_UPLOAD_PATH'] = $storagePath;
    }

    /**
     * Match the current request URI against $bypassRoutes from config.
     */
    protected static function isBypassedRoute($config): bool
    {
        $patterns = $config->bypassRoutes ?? [];
        if (empty($patterns)) {
            return false;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = parse_url($uri, PHP_URL_PATH) ?? '/';
        $uri = ltrim((string) $uri, '/');

        foreach ($patterns as $pattern) {
            if (fnmatch((string) $pattern, $uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get config
     */
    protected static function getConfig()
    {
        // Try to load config, return defaults if not found
        try {
            return config('Tenantable');
        } catch (\Throwable $e) {
            return new class {
                public string $baseDomain = 'localhost';
                public bool $allowLocalhost = true;
                public string $earlyDetectionStrategy = 'domain_or_subdomain';
            };
        }
    }
}
