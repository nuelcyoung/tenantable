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

namespace nuelcyoung\tenantable\Bootstrap\Systems;

use nuelcyoung\tenantable\Bootstrap\TenantAwareInterface;
use nuelcyoung\tenantable\Support\TenantableConfig;

/**
 * Points the session layer at the active tenant: rewrites savePath for file
 * handlers and the cookie name for per-tenant cookies. Shared with
 * EarlyTenantDetector.
 */
class SessionSystem implements TenantAwareInterface
{
    protected bool $captured = false;
    protected ?string $originalSavePath   = null;
    protected ?string $originalCookieName = null;

    /** Save the app's session settings before tenant detection changes them. */
    public function captureOriginalState(): void
    {
        if ($this->captured) {
            return;
        }

        $config = $this->sessionConfig();

        if ($config === null) {
            return;
        }

        $this->originalSavePath   = isset($config->savePath) && is_string($config->savePath)
            ? $config->savePath
            : null;
        $this->originalCookieName = isset($config->cookieName) && is_string($config->cookieName)
            ? $config->cookieName
            : null;
        $this->captured = true;
    }

    public function boot(?int $tenantId, ?array $tenant): void
    {
        $config = $this->sessionConfig();

        if ($config === null) {
            return;
        }

        // Capture once. boot() runs on every tenant switch, so recapturing
        // would make shutdown() restore a tenant's values instead of the app's.
        $this->captureOriginalState();

        if (self::usesFileHandler($config)) {
            $tenantSavePath = self::tenantSavePath($tenantId);

            if (!is_dir($tenantSavePath)) {
                mkdir($tenantSavePath, 0700, true);
            }

            $config->savePath = $tenantSavePath;
        }

        if ($this->perTenantCookiesEnabled() && property_exists($config, 'cookieName')) {
            if ($tenantId !== null) {
                $config->cookieName = self::tenantCookieName($tenantId);
            } elseif ($this->originalCookieName !== null) {
                // No tenant: restore the app's cookie.
                $config->cookieName = $this->originalCookieName;
            }
        }
    }

    public function shutdown(): void
    {
        $config = $this->sessionConfig();

        if ($config !== null && $this->captured) {
            // Only restore values that were actually set (not null).
            if ($this->originalSavePath !== null) {
                $config->savePath = $this->originalSavePath;
            }

            if ($this->originalCookieName !== null) {
                $config->cookieName = $this->originalCookieName;
            }
        }

        $this->captured           = false;
        $this->originalSavePath   = null;
        $this->originalCookieName = null;
    }

    /** True when the session handler stores sessions on disk. */
    public static function usesFileHandler(object $config): bool
    {
        $driver = $config->driver ?? null;

        if (! is_string($driver) || $driver === '') {
            // CI4's default session handler is the file handler.
            return true;
        }

        if (class_exists(\CodeIgniter\Session\Handlers\FileHandler::class) && class_exists($driver)) {
            return is_a($driver, \CodeIgniter\Session\Handlers\FileHandler::class, true);
        }

        return str_contains($driver, 'FileHandler');
    }

    public static function tenantSavePath(?int $tenantId): string
    {
        return WRITEPATH . 'session/tenant_' . ($tenantId ?? 'global');
    }

    public static function tenantCookieName(int $tenantId): string
    {
        return 'tenant_' . $tenantId . '_session';
    }

    public static function perTenantCookiesFlag(): bool
    {
        try {
            return TenantableConfig::get()->perTenantSessionCookies;
        } catch (\Throwable $e) {
            return true;
        }
    }

    protected function sessionConfig(): ?object
    {
        return config('Session');
    }

    protected function perTenantCookiesEnabled(): bool
    {
        return self::perTenantCookiesFlag();
    }
}
