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
use nuelcyoung\tenantable\Services\TenantableQueue;
use nuelcyoung\tenantable\Support\TenantableConfig;

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

        $tenantConfig = TenantableConfig::get();

        $baseUrl = config('App')->baseURL ?? 'http://localhost';
        $scheme  = str_starts_with($baseUrl, 'https://') ? 'https' : 'http';

        $manager = TenantManager::getInstance();

        // Prefer the central domain the current host belongs to
        // (multi-domain setups), falling back to the primary domain.
        $baseDomain = $manager->getBaseDomainForHost($_SERVER['HTTP_HOST'] ?? null)
            ?? $manager->getBaseDomain();

        if (empty($baseDomain) || $baseDomain === 'localhost') {
            $baseDomain = $tenantConfig->baseDomain;
        }

        $path = ltrim((string) $path, '/');

        return "{$scheme}://{$subdomain}.{$baseDomain}/{$path}";
    }
}

if (!function_exists('tenant_asset')) {
    /**
     * URL for a tenant-scoped asset via the central assets route; only
     * meaningful when $tenantAssetsEnabled is true, else site_url().
     */
    function tenant_asset(string $path, ?int $tenantId = null): string
    {
        $config = TenantableConfig::get();

        if (! $config->tenantAssetsEnabled) {
            return site_url($path);
        }

        $tenantId ??= tenant_id();

        if ($tenantId === null) {
            return site_url($path);
        }

        $route = trim($config->tenantAssetsRoute, '/');
        $path  = ltrim($path, '/');

        return site_url("{$route}/{$tenantId}/{$path}");
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

if (!function_exists('tenant_push')) {
    /**
     * Queue a job that will run in the tenant it was pushed from; with no
     * tenant active the job is pushed centrally.
     *
     * @param array<string, mixed> $data
     */
    function tenant_push(string $queue, string $job, array $data = []): bool
    {
        return (new TenantableQueue())->push($queue, $job, $data);
    }
}

if (!function_exists('central_push')) {
    /**
     * Queue a job that runs with no tenant context, even when one is active.
     *
     * @param array<string, mixed> $data
     */
    function central_push(string $queue, string $job, array $data = []): bool
    {
        return (new TenantableQueue())->pushCentral($queue, $job, $data);
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

        $config = TenantableConfig::get();

        foreach ($config->superadminGroups as $group) {
            if ($user->inGroup($group)) {
                return true;
            }
        }

        return false;
    }
}
