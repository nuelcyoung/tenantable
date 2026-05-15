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
use nuelcyoung\tenantable\Bootstrap\TenantBootstrap;
use nuelcyoung\tenantable\Services\TenantManager;

class StorageSystem implements TenantAwareInterface
{
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

        $this->currentPath = $basePath;
    }

    public function shutdown(): void
    {
        $this->currentPath = '';
    }

    public static function getStoragePath(?int $tenantId = null): string
    {
        $system = TenantBootstrap::getInstance()->getSystem('storage');

        if ($system instanceof self && $system->currentPath !== '') {
            return $system->currentPath;
        }

        $tenantId ??= TenantManager::getInstance()->getTenantId();

        return WRITEPATH . 'uploads/tenant_' . ($tenantId ?? 'default');
    }
}
