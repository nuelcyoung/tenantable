<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap\Systems;

use nuelcyoung\tenantable\Bootstrap\TenantAwareInterface;
use nuelcyoung\tenantable\Bootstrap\TenantBootstrap;

/**
 * Merges tenant-level settings (from the tenants.settings JSON column)
 * into the application config so controllers and services can read them.
 *
 * S-1 – Fixed ConfigSystem::get() which called self::getInstance() — a method
 *        that doesn't exist on ConfigSystem. It now routes through TenantBootstrap
 *        to retrieve the live system instance.
 */
class ConfigSystem implements TenantAwareInterface
{
    /** Tenant-specific settings for the current request */
    protected array $tenantSettings = [];

    public function boot(?int $tenantId, ?array $tenant): void
    {
        $this->tenantSettings = [];

        if ($tenantId === null || empty($tenant['settings'])) {
            return;
        }

        $settings = is_array($tenant['settings'])
            ? $tenant['settings']
            : json_decode((string) $tenant['settings'], true);

        if (is_array($settings)) {
            $this->tenantSettings = $settings;

            // Expose on App config for convenience
            $appConfig = config('App');
            if ($appConfig !== null) {
                $appConfig->tenantSettings = $settings;
            }
        }
    }

    public function shutdown(): void
    {
        $this->tenantSettings = [];

        $appConfig = config('App');
        if ($appConfig !== null && isset($appConfig->tenantSettings)) {
            unset($appConfig->tenantSettings);
        }
    }

    /**
     * Read a single tenant setting by key.
     *
     * S-1 – Was calling self::getInstance() which doesn't exist on ConfigSystem.
     *        Now correctly retrieves the live system from TenantBootstrap.
     *
     * @param string $key      Setting key
     * @param mixed  $default  Default value when key is absent
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $system = TenantBootstrap::getInstance()->getSystem('config');

        if ($system instanceof self) {
            return $system->tenantSettings[$key] ?? $default;
        }

        return $default;
    }

    /**
     * Get all tenant settings for the current request.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $system = TenantBootstrap::getInstance()->getSystem('config');

        return $system instanceof self ? $system->tenantSettings : [];
    }
}
