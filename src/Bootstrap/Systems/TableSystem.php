<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap\Systems;

use nuelcyoung\tenantable\Bootstrap\TenantAwareInterface;
use nuelcyoung\tenantable\Services\TenantTableManager;

/**
 * Configures TenantTableManager with the current tenant, enabling
 * the table-prefix isolation strategy.
 */
class TableSystem implements TenantAwareInterface
{
    public function boot(?int $tenantId, ?array $tenant): void
    {
        $manager = TenantTableManager::getInstance();

        if ($tenantId !== null) {
            $manager->setTenant($tenantId, $tenant['subdomain'] ?? null);
        } else {
            $manager->clear();
        }
    }

    public function shutdown(): void
    {
        TenantTableManager::getInstance()->clear();
    }
}
