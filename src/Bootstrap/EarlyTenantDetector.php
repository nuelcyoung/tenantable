<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap;

use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Services\TenantTableManager;

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

        // Get host
        $host = $_SERVER['HTTP_HOST'] ?? '';
        
        if (empty($host)) {
            return;
        }

        // Skip if localhost and not configured
        if (self::isLocalhost($host)) {
            $config = self::getConfig();
            if ($config->allowLocalhost ?? true) {
                return;
            }
        }

        // Extract subdomain
        $subdomain = self::extractSubdomain($host);
        
        if ($subdomain === null) {
            return;
        }

        try {
            // Set tenant early
            $manager = TenantManager::getInstance();
            $manager->setTenantBySubdomain($subdomain);
            
            // Bootstrap table manager if using prefix strategy
            $tableManager = TenantTableManager::getInstance();
            $tableManager->setTenant(
                $manager->getTenantId(),
                $subdomain
            );
            
            // Now configure session path BEFORE session starts
            self::configureSession($manager->getTenantId());
            
            // Configure cache prefix
            self::configureCache($manager->getTenantId());
            
            // Configure storage paths
            self::configureStorage($manager->getTenantId());
            
        } catch (\Throwable $e) {
            // Log error but don't crash
            error_log("EarlyTenantDetector: {$e->getMessage()}");
        }
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
        
        // Also set session name to include tenant
        $config->sessionName = 'tenant_' . $tenantId . '_session';
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
     * Extract subdomain from host
     */
    protected static function extractSubdomain(string $host): ?string
    {
        $config = self::getConfig();
        $baseDomain = $config->baseDomain ?? 'localhost';
        
        // Remove port
        $host = explode(':', $host)[0];
        
        // Check subdomain format
        if (str_ends_with($host, $baseDomain)) {
            $subdomain = rtrim(str_replace($baseDomain, '', $host), '.');
            return $subdomain ?: null;
        }
        
        // Multi-part domain
        $parts = explode('.', $host);
        if (count($parts) > 2) {
            return $parts[0];
        }
        
        return null;
    }

    /**
     * Check if localhost
     */
    protected static function isLocalhost(string $host): bool
    {
        $patterns = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
        
        if (in_array($host, $patterns, true)) {
            return true;
        }
        
        if (str_starts_with($host, 'localhost:')) {
            return true;
        }
        
        if (preg_match('/\.(test|local|example)$/', $host)) {
            return true;
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
            };
        }
    }
}
