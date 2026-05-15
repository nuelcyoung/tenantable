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
use nuelcyoung\tenantable\Services\TenantTableManager;

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
