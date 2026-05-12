<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap;

use CodeIgniter\Config\Factories;
use CodeIgniter\Events\Events;
use nuelcyoung\tenantable\Models\TenantableModel;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Support\TenantContextState;

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
            }
        }, 0);

        Events::on('pre_system', [EarlyTenantDetector::class, 'detect'], 1);

        Events::on('post_system', static function (): void {
            if (PHP_SAPI === 'cli') {
                return;
            }

            TenantBootstrap::getInstance()->shutdown();
            TenantableModel::disableTenantBypass();
            TenantContextState::disableTenantBypass();
        });
    }

    private static function registerCliHooks(): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        $subdomain = getenv('TENANT_SUBDOMAIN') ?: null;

        if ($subdomain) {
            TenantManager::getInstance()->setTenantBySubdomain($subdomain);
            TenantBootstrap::getInstance()->initialize()->boot();
        }

        $tenantId = getenv('TENANTABLE_TENANT_ID') ?: null;

        if ($tenantId) {
            TenantManager::getInstance()->setTenantById((int) $tenantId);
            TenantBootstrap::getInstance()->initialize()->boot();
        }
    }
}
