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

use CodeIgniter\Config\Factories;
use CodeIgniter\Events\Events;
use nuelcyoung\tenantable\Models\TenantableModel;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Services\TenantResolverCache;
use nuelcyoung\tenantable\Support\TenantContextState;
use nuelcyoung\tenantable\Support\TenantableConfig;
use nuelcyoung\tenantable\Events\TenancyEnded;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;
use nuelcyoung\tenantable\Exceptions\TenantInactiveException;
use nuelcyoung\tenantable\Exceptions\TenantAccessDeniedException;

final class PackageEvents
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        self::registerConfigOverride();
        self::registerWebHooks();
        self::registerCliHooks();
        self::registerCacheInvalidation();
    }

    private static function registerConfigOverride(): void
    {
        // App did not publish a custom config, so the package default is fine.
        if (! class_exists(\Config\Tenantable::class)) {
            return;
        }

        // Alias package FQCN to the app-published config. Internals use the config helper.
        Factories::define(
            'config',
            \nuelcyoung\tenantable\Config\Tenantable::class,
            \Config\Tenantable::class,
        );
    }

    private static function registerWebHooks(): void
    {
        Events::on('pre_system', static function (): void {
            // Clear stale bypass flag. In long-running runtimes (Swoole/RoadRunner)
            // the static state survives between requests.
            TenantContextState::disableTenantBypass();

            if (self::isHttpRequest()) {
                // Restore any mutable subsystem state left by a previous
                // request before capturing the inbound session cookie name.
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }

                TenantBootstrap::getInstance()->shutdown();
                TenantManager::getInstance()->clear();
                \nuelcyoung\tenantable\Services\TenantTableManager::getInstance()->clear();
                SessionTenantGuard::getInstance()->captureOriginalCookieName();
                TenantResolverCache::resetInstance(); // drop per-request version memo

                // EarlyTenantDetector mutates these shared config objects;
                // capture app-owned values so shutdown can restore them.
                $bootstrap = TenantBootstrap::getInstance()->initialize();
                $cacheSystem = $bootstrap->getSystem('cache');
                if ($cacheSystem instanceof \nuelcyoung\tenantable\Bootstrap\Systems\CacheSystem) {
                    $cacheSystem->captureOriginalState();
                }
                $sessionSystem = $bootstrap->getSystem('session');
                if ($sessionSystem instanceof \nuelcyoung\tenantable\Bootstrap\Systems\SessionSystem) {
                    $sessionSystem->captureOriginalState();
                }
            }
        }, 0);

        Events::on('pre_system', [EarlyTenantDetector::class, 'detect'], 1);

        // Sessions are bound to the tenant they were created under; a session
        // presented to a different tenant is destroyed. See SessionTenantGuard.
        Events::on('tenancyInitialized', static function (object $event): void {
            if (! self::isHttpRequest()) {
                return;
            }

            if (! SessionTenantGuard::getInstance()->validate($event->tenantId ?? null)) {
                throw new TenantAccessDeniedException('Tenantable: the session is not valid for this tenant.');
            }
        });

        Events::on('post_system', static function (): void {
            if (! self::isHttpRequest()) {
                return;
            }

            $manager = TenantManager::getInstance();

            // Dispatch TenancyEnded BEFORE shutdown
            if ($manager->hasTenant()) {
                // Bind any session started during this request
                // before tenant context is torn down.
                SessionTenantGuard::getInstance()->stamp($manager->getTenantId());

                Events::trigger('tenancyEnded', new TenancyEnded(
                    $manager->getTenantId(),
                    $manager->getTenant()
                ));
            }

            // Close the session before shutdown() tears down the tenant DB;
            // a deferred close would RELEASE_LOCK on the wrong MySQL thread.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            TenantBootstrap::getInstance()->shutdown();
            TenantableModel::disableTenantBypass();
            TenantContextState::disableTenantBypass();
            \nuelcyoung\tenantable\Services\TenantTableManager::getInstance()->clear();

            // Clear tenant manager but keep baseDomain
            $manager->clear();
        });
    }

    private static function registerCliHooks(): void
    {
        if (PHP_SAPI !== 'cli' || self::isHttpRequest()) {
            return;
        }

        $subdomain = getenv('TENANT_SUBDOMAIN') ?: null;

        if ($subdomain) {
            try {
                TenantManager::getInstance()->setTenantBySubdomain($subdomain);
                TenantBootstrap::getInstance()->initialize()->boot();
            } catch (TenantNotFoundException|TenantInactiveException $e) {
                fwrite(STDERR, "Tenantable: TENANT_SUBDOMAIN={$subdomain} — {$e->getMessage()}\n");
            }
        }

        $tenantId = getenv('TENANTABLE_TENANT_ID') ?: null;

        if ($tenantId) {
            try {
                TenantManager::getInstance()->setTenantById((int) $tenantId);
                TenantBootstrap::getInstance()->initialize()->boot();
            } catch (TenantNotFoundException|TenantInactiveException $e) {
                fwrite(STDERR, "Tenantable: TENANTABLE_TENANT_ID={$tenantId} — {$e->getMessage()}\n");
            }
        }
    }

    private static function registerCacheInvalidation(): void
    {
        Events::on('tenantCreated', static function (): void {
            TenantResolverCache::getInstance()->flush();
        });

        Events::on('tenantUpdated', static function ($event): void {
            $cache = TenantResolverCache::getInstance();
            $cache->flush();

            // Also flush the old domain key when the domain column changed,
            // covering per-host caches beyond the version-keyed resolver.
            $oldDomain = $event->before['domain'] ?? null;
            $newDomain = $event->tenant['domain'] ?? null;
            if (!empty($oldDomain) && $oldDomain !== $newDomain) {
                $cache->flushHost((string) $oldDomain);
            }
        });

        Events::on('tenantDeleted', static function (): void {
            TenantResolverCache::getInstance()->flush();
        });

        Events::on('tenantDomainChanged', static function ($event): void {
            $cache = TenantResolverCache::getInstance();
            $cache->flush();

            if (!empty($event->data['_previous_domain'])) {
                $cache->flushHost((string) $event->data['_previous_domain']);
            }
        });
    }

    /** Persistent HTTP workers commonly report PHP_SAPI as "cli". */
    private static function isHttpRequest(): bool
    {
        return PHP_SAPI !== 'cli'
            || (isset($_SERVER['REQUEST_METHOD']) && (string) $_SERVER['REQUEST_METHOD'] !== '');
    }
}
