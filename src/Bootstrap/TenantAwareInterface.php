<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap;

/**
 * TenantAwareInterface
 *
 * Contract for all tenant-aware bootstrap systems.
 * Each system is responsible for reconfiguring one subsystem
 * (cache, storage, sessions, etc.) when the active tenant changes.
 *
 * S-3 – Extracted from the bottom of TenantBootstrap.php into its own file
 *        to remove the hidden file-order dependency between TenantBootstrap.php
 *  now explicitly import this interface.
 */
interface TenantAwareInterface
{
    /**
     * Boot this system for the given tenant.
     *
     * Called by TenantBootstrap after a tenant is resolved.
     * Receives `null` when no tenant is active (superadmin bypass etc.).
     *
     * @param int|null   $tenantId  Current tenant ID (null = no tenant)
     * @param array|null $tenant    Full tenant row from the database
     */
    public function boot(?int $tenantId, ?array $tenant): void;

    /**
     * Shutdown / reset this system.
     *
     * Called at the end of a request (or when the tenant context is cleared)
     * to restore any modified global state back to its original values.
     */
    public function shutdown(): void;
}
