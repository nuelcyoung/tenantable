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
use nuelcyoung\tenantable\Events\TenancyEnded;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;
use nuelcyoung\tenantable\Exceptions\TenantInactiveException;

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
        if (class_exists(\Config\Tenantable::class)) {
            Factories::define(
                'config',
                \nuelcyoung\tenantable\Config\Tenantable::class,
                \Config\Tenantable::class,
            );
        }
    }

    private static function registerWebHooks(): void
    {
        Events::on('pre_system', static function (): void {
            if (PHP_SAPI !== 'cli') {
                TenantManager::getInstance()->clear();
                TenantResolverCache::resetInstance(); // drop per-request version memo
            }
        }, 0);

        Events::on('pre_system', [EarlyTenantDetector::class, 'detect'], 1);

        Events::on('post_system', static function (): void {
            if (PHP_SAPI === 'cli') {
                return;
            }

            $manager = TenantManager::getInstance();

            // Dispatch TenancyEnded BEFORE shutdown
            if ($manager->hasTenant()) {
                Events::trigger('tenancyEnded', new TenancyEnded(
                    $manager->getTenantId(),
                    $manager->getTenant()
                ));
            }

            TenantBootstrap::getInstance()->shutdown();
            TenantableModel::disableTenantBypass();
            TenantContextState::disableTenantBypass();

            // Clear tenant manager but keep baseDomain
            $manager->clear();
        });
    }

    private static function registerCliHooks(): void
    {
        if (PHP_SAPI !== 'cli') {
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

            // Belt-and-suspenders: also flush the old domain key if the
            // tenant's domain column changed (covers any per-host caches
            // beyond the version-keyed resolver cache).
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
}
