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

namespace nuelcyoung\tenantable\Exceptions;

use RuntimeException;

    /**
     * Thrown when a tenant-aware model writes with no active tenant context.
     * Use the withoutTenant wrapper or a GlobalModel for cross-tenant queries.
     */
class MissingTenantContextException extends RuntimeException
{
    public static function forModel(string $model, string $operation): self
    {
        return new self(
            "Tenantable: refusing to {$operation} on tenant-aware model '{$model}' with no active tenant " .
            'context. Wrap deliberate cross-tenant access in ' .
            'Model::withoutTenant() or use a GlobalModel.'
        );
    }

    /** Prefix-mode variant: writing without a tenant hits the shared table. */
    public static function forTablePrefixModel(string $model, string $operation): self
    {
        return new self(
            "Tenantable: refusing to {$operation} on table-prefix model '{$model}' with no active tenant " .
            'context — the write would land in the un-prefixed shared table. Run inside a tenant ' .
            'context (TenantFilter or TenantTableManager::setTenant()) or mark the model as a global table.'
        );
    }
}
