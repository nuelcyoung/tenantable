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
 * Thrown when a tenant-aware row model cannot prove that its schema supports
 * tenant scoping. Continuing without the tenant column would fail open.
 */
class TenantIsolationException extends RuntimeException
{
    public static function forMissingColumn(string $model, string $table, string $column): self
    {
        return new self(
            "Tenantable: refusing to use tenant-aware model '{$model}' because table '{$table}' " .
            "does not contain the required tenant column '{$column}'."
        );
    }

    /**
     * Batch updates are scoped by adding the tenant column to the builder's
     * match constraint. Without that method there is no way to bound a batch
     * update, so refuse rather than run one that could reach another tenant's
     * rows.
     */
    public static function forMissingBatchConstraint(string $model): self
    {
        return new self(
            "Tenantable: refusing to run a batch update for '{$model}' because this CodeIgniter " .
            'build has no BaseBuilder::onConstraint(). Tenant scoping for batch updates depends ' .
            'on it — upgrade CodeIgniter, or update the batch rows one at a time.'
        );
    }
}
