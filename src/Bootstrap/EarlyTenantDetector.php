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
use nuelcyoung\tenantable\Services\TenantTableManager;
use nuelcyoung\tenantable\Services\TenantResolverCache;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;
use nuelcyoung\tenantable\Exceptions\TenantInactiveException;

/** Detects tenant in pre_system before sessions/cache/services initialize. */
class EarlyTenantDetector
{
    public static function detect(): void
    {
        if (PHP_SAPI === 'cli' && ! self::isHttpRequest()) {
            return;
        }

        $config = self::getConfig();

        $strategy = $config->earlyDetectionStrategy ?? 'domain_or_subdomain';
        if ($strategy === 'off') {
            return;
        }

        if (self::isBypassedRoute($config)) {
            return;
        }

        $manager = TenantManager::getInstance();
        $host    = $manager->normalizeHost((string) ($_SERVER['HTTP_HOST'] ?? ''));

        if ($host === null) {
            return;
        }

        // Reject untrusted hosts before touching cache/DB.
        if (! $manager->isHostAllowed($host)) {
            return;
        }

        if ($manager->isLocalhost($host)) {
            if ($config->allowLocalhost ?? false) {
                return;
            }
        }

        try {
            $resolved = false;

            if (in_array($strategy, ['domain', 'domain_or_subdomain'], true)) {
                $resolved = self::resolveByDomain($host, $manager);
            }

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

            $tableManager = TenantTableManager::getInstance();
            $tableManager->setTenant(
                $manager->getTenantId(),
                $manager->getSubdomain()
            );

            self::configureSession($manager->getTenantId());

            self::configureCache($manager->getTenantId());
            self::configureStorage($manager->getTenantId());

        } catch (TenantNotFoundException|TenantInactiveException $e) {
            // Let the filter re-resolve; don't surface a raw exception.
            TenantManager::getInstance()->clear();
        } catch (\Throwable $e) {
            TenantManager::getInstance()->clear();
            error_log("EarlyTenantDetector: {$e->getMessage()}");
        }
    }

    /** Resolve tenant by custom domain via cache. */
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

        if (empty($cached['is_active'])) {
            throw new TenantInactiveException("Tenant for domain '{$host}' is inactive");
        }
        $manager->setTenantById($cached['tenant_id']);
        return true;
    }

    /** Configure session for tenant. Must run before session starts. */
    protected static function configureSession(?int $tenantId, ?object $config = null): void
    {
        if ($tenantId === null) {
            return;
        }

        $config ??= config('Session');

        if (!$config) {
            return;
        }

        // Only file handler uses savePath as directory.
        if (Systems\SessionSystem::usesFileHandler($config)) {
            $tenantPath = Systems\SessionSystem::tenantSavePath($tenantId);

            if (!is_dir($tenantPath)) {
                mkdir($tenantPath, 0700, true);
            }

            $config->savePath = $tenantPath;
        }

        if (Systems\SessionSystem::perTenantCookiesFlag() && property_exists($config, 'cookieName')) {
            $config->cookieName = Systems\SessionSystem::tenantCookieName($tenantId);
        }
    }

    /** Set cache prefix for tenant. */
    protected static function configureCache(?int $tenantId): void
    {
        $config = config('Cache');
        
        if (!$config) {
            return;
        }
        
        // Set prefix for all cache keys
        $config->prefix = $tenantId !== null ? "tenant_{$tenantId}_" : '';
        Systems\CacheSystem::resetSharedCache();
    }

    /** Create storage path for tenant. */
    protected static function configureStorage(?int $tenantId): void
    {
        if ($tenantId === null) {
            unset($_ENV['TENANT_STORAGE_PATH'], $_ENV['TENANT_UPLOAD_PATH']);
            return;
        }

        // Define tenant storage path constant
        $storagePath = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'tenant_' . $tenantId;
        
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0700, true);
        }
        
        // Set as environment variable for helpers
        $_ENV['TENANT_STORAGE_PATH'] = $storagePath;
        $_ENV['TENANT_UPLOAD_PATH'] = $storagePath;
    }

    /** Match URI against bypass routes. */
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

    protected static function getConfig()
    {
        try {
            return config('Tenantable');
        } catch (\Throwable $e) {
            return new class {
                public string $baseDomain = 'localhost';
                public bool $allowLocalhost = false;
                public string $earlyDetectionStrategy = 'domain_or_subdomain';
            };
        }
    }

    /** Persistent HTTP workers commonly report PHP_SAPI as "cli". */
    private static function isHttpRequest(): bool
    {
        return PHP_SAPI !== 'cli'
            || (isset($_SERVER['REQUEST_METHOD']) && (string) $_SERVER['REQUEST_METHOD'] !== '');
    }
}
