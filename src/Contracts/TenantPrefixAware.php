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

namespace nuelcyoung\tenantable\Contracts;

    /**
     * Contract between the table manager and prefix-aware models: the manager
     * pushes the active tenant's DBPrefix onto each bound model's connection.
     */
interface TenantPrefixAware
{
    /**
     * Apply the tenant's DBPrefix and drop the cached builder so the next
     * query compiles against the new tenant's tables.
     *
     * @internal Called by the table manager only.
     */
    public function syncTenantPrefix(string $prefix): void;

    /**
     * Throw when the model has un-executed clauses composed for the
     * outgoing tenant.
     *
     * @internal Called by the table manager only.
     */
    public function assertNoPendingTenantClauses(): void;
}
