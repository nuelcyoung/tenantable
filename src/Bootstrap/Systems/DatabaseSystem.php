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
use nuelcyoung\tenantable\Config\Tenantable as TenantableConfig;
use nuelcyoung\tenantable\Services\TenantDatabaseManager;

class DatabaseSystem implements TenantAwareInterface
{
    private ?TenantDatabaseManager $manager = null;

    public function boot(?int $tenantId, ?array $tenant): void
    {
        $config = config(TenantableConfig::class);

        if (! $config->separateDatabasePerTenant) {
            return;
        }

        if ($tenantId === null) {
            $this->getManager($config)->switchToDefault();
            return;
        }

        if (empty($tenant)) {
            return;
        }

        $this->getManager($config)->connectToTenant($tenant);
    }

    public function shutdown(): void
    {
        if ($this->manager !== null) {
            $this->manager->switchToDefault();
        }
    }

    private function getManager(TenantableConfig $config): TenantDatabaseManager
    {
        if ($this->manager === null) {
            $this->manager = new TenantDatabaseManager(
                $config->separateDatabasePerTenant,
                $config->defaultDatabaseGroup,
            );
        }

        return $this->manager;
    }
}
