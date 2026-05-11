<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap\Systems;

use nuelcyoung\tenantable\Bootstrap\TenantAwareInterface;
use nuelcyoung\tenantable\Config\Tenantable as TenantableConfig;
use nuelcyoung\tenantable\Services\TenantDatabaseManager;

/**
 * DatabaseSystem
 *
 * Swaps the application's default database connection to the current tenant's
 * database when Config\Tenantable::$separateDatabasePerTenant is true and the
 * tenant row carries a `database_name`.
 *
 * No-op when:
 *   - $separateDatabasePerTenant is false (row-mode or prefix-mode),
 *   - the tenant has no `database_name` (intentional shared-DB tenant), or
 *   - $tenantId is null (superadmin bypass / no tenant resolved).
 */
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

        if (empty($tenant['database_name'])) {
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
