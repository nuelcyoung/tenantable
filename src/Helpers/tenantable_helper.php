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

use nuelcyoung\tenantable\Bootstrap\TenantBootstrap;
use nuelcyoung\tenantable\Services\TenantManager;

if (!function_exists('tenant_id')) {
    function tenant_id(): ?int
    {
        try {
            return TenantManager::getInstance()->getTenantId();
        } catch (\Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('tenant')) {
    function tenant(): ?array
    {
        try {
            return TenantManager::getInstance()->getTenant();
        } catch (\Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('has_tenant')) {
    function has_tenant(): bool
    {
        try {
            return TenantManager::getInstance()->hasTenant();
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('tenant_subdomain')) {
    function tenant_subdomain(): ?string
    {
        try {
            return TenantManager::getInstance()->getSubdomain();
        } catch (\Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('tenant_url')) {
    function tenant_url(?string $path = '', ?string $subdomain = null): string
    {
        $subdomain = $subdomain ?? tenant_subdomain();

        if (empty($subdomain)) {
            return site_url($path);
        }

        $tenantConfig = config(\nuelcyoung\tenantable\Config\Tenantable::class);

        $baseUrl = config(\Config\App::class)->baseURL ?? 'http://localhost';
        $scheme  = str_starts_with($baseUrl, 'https://') ? 'https' : 'http';

        $baseDomain = $tenantConfig->baseDomain ?? 'localhost';

        $path = ltrim((string) $path, '/');

        return "{$scheme}://{$subdomain}.{$baseDomain}/{$path}";
    }
}

if (!function_exists('central')) {
    function central(callable $callback): mixed
    {
        return TenantBootstrap::getInstance()->runCentral($callback);
    }
}

if (!function_exists('tenancy_run')) {
    function tenancy_run(int $tenantId, callable $callback): mixed
    {
        $manager    = TenantManager::getInstance();
        $bootstrap  = TenantBootstrap::getInstance();

        try {
            $manager->setTenantById($tenantId);
            $bootstrap->initialize()->boot();

            return $callback();
        } finally {
            if ($manager->hasTenant()) {
                \CodeIgniter\Events\Events::trigger('tenancyEnded', new \nuelcyoung\tenantable\Events\TenancyEnded(
                    $manager->getTenantId(),
                    $manager->getTenant()
                ));
            }
            $bootstrap->shutdown();
            $manager->clear();
        }
    }
}

if (!function_exists('can_bypass_tenant')) {
    function can_bypass_tenant(): bool
    {
        if (!function_exists('auth')) {
            return false;
        }

        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $config = config(\nuelcyoung\tenantable\Config\Tenantable::class);

        foreach ($config->superadminGroups as $group) {
            if ($user->inGroup($group)) {
                return true;
            }
        }

        return false;
    }
}
