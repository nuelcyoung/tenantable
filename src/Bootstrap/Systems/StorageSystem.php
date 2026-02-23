<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap\Systems;

use nuelcyoung\tenantable\Bootstrap\TenantAwareInterface;
use nuelcyoung\tenantable\Bootstrap\TenantBootstrap;
use nuelcyoung\tenantable\Services\TenantManager;

/**
 * Provides a tenant-specific file storage directory.
 *
 * S-2 – The resolved path is now stored as an instance property ($currentPath)
 *        instead of a local variable ($_TENANT_STORAGE_PATH) that was
 *        immediately discarded after boot() returned.
 */
class StorageSystem implements TenantAwareInterface
{
    /** Tenant-specific storage path for the current request */
    protected string $currentPath = '';

    public function boot(?int $tenantId, ?array $tenant): void
    {
        if ($tenantId === null) {
            $this->currentPath = '';
            return;
        }

        $basePath = WRITEPATH . 'uploads/tenant_' . $tenantId;

        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        // S-2 – Store on instance so getStoragePath() can read it
        $this->currentPath = $basePath;
    }

    public function shutdown(): void
    {
        $this->currentPath = '';
    }

    /**
     * Get the storage path for the given (or current) tenant.
     *
     * @param int|null $tenantId  Override tenant ID (defaults to current tenant context)
     */
    public static function getStoragePath(?int $tenantId = null): string
    {
        // Try to get path from the live instance first
        $system = TenantBootstrap::getInstance()->getSystem('storage');

        if ($system instanceof self && $system->currentPath !== '') {
            return $system->currentPath;
        }

        // Fallback: derive from tenant ID
        $tenantId ??= TenantManager::getInstance()->getTenantId();

        return WRITEPATH . 'uploads/tenant_' . ($tenantId ?? 'default');
    }
}
