<?php

/**
 * Tenantable Helper Functions
 *
 * Provides convenient global helper functions for working with the current tenant.
 *
 * FIX 1.5 – Removed the `namespace` declaration that was present in the original
 *            file. PHP helper functions must live in the GLOBAL namespace to be
 *            callable as tenant_id(), tenant(), etc. Without this fix, every
 *            helper call would result in "function not found" because the functions
 *            were registered under nuelcyoung\tenantable\Helpers\ instead.
 *
 * FIX 3.5 – tenant_url() now respects the scheme (http vs https) from the
 *            application's baseURL instead of always forcing https://.
 */

declare(strict_types=1);

use nuelcyoung\tenantable\Services\TenantManager;

// -------------------------------------------------------------------------
// FIX 1.5 – All functions in global namespace (no namespace declaration above)
// -------------------------------------------------------------------------

/**
 * Get the current tenant ID.
 */
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

/**
 * Get the current tenant data array.
 */
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

/**
 * Check whether a tenant context is set for the current request.
 */
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

/**
 * Get the current tenant's subdomain.
 */
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

/**
 * Generate a URL for the given path under the tenant's subdomain.
 *
 * FIX 3.5 – Scheme (http vs https) is now derived from the application's
 *            baseURL instead of always hardcoding "https://".
 *
 * @param string|null $path      Relative URL path (e.g., 'dashboard')
 * @param string|null $subdomain Override the subdomain (defaults to current tenant)
 */
if (!function_exists('tenant_url')) {
    function tenant_url(?string $path = '', ?string $subdomain = null): string
    {
        $subdomain = $subdomain ?? tenant_subdomain();

        if (empty($subdomain)) {
            return site_url($path);
        }

        $tenantConfig = config(\nuelcyoung\tenantable\Config\Tenantable::class);

        // FIX 3.5 – Detect scheme from App baseURL
        $baseUrl = config(\Config\App::class)->baseURL ?? 'http://localhost';
        $scheme  = str_starts_with($baseUrl, 'https://') ? 'https' : 'http';

        $baseDomain = $tenantConfig->baseDomain ?? 'localhost';

        // Normalise path – remove leading slash to avoid double-slashes
        $path = ltrim((string) $path, '/');

        return "{$scheme}://{$subdomain}.{$baseDomain}/{$path}";
    }
}

/**
 * Check whether the currently authenticated user can bypass tenant filtering
 * (i.e., is a superadmin).
 */
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
