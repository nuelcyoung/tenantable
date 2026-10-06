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

namespace nuelcyoung\tenantable\Traits;

use nuelcyoung\tenantable\Exceptions\MissingTenantContextException;
use nuelcyoung\tenantable\Exceptions\TenantIsolationException;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Support\TenantContextState;

trait TenantableTrait
{
    protected bool $tenantable = true;
    protected string $tenantIdColumn = 'tenant_id';

    private static array $tenantColumnCache = [];
    private bool $tenantableCallbacksRegistered = false;

    public static function bootTenantableTrait(): void {}

    /**
     * Wire the tenant callbacks automatically; call initializeTenantableTrait()
     * from your own initialize() if you define one.
     */
    protected function initialize(): void
    {
        $this->initializeTenantableTrait();
    }

    public function initializeTenantableTrait(): void
    {
        $this->setupTenantableCallbacks();
    }

    protected function setupTenantableCallbacks(): void
    {
        if ($this->tenantableCallbacksRegistered) {
            return;
        }
        $this->tenantableCallbacksRegistered = true;

        $this->beforeFind[]        = 'tenantableBeforeFind';
        $this->beforeInsert[]      = 'tenantableBeforeInsert';
        $this->beforeUpdate[]      = 'tenantableBeforeUpdate';
        $this->beforeDelete[]      = 'tenantableBeforeDelete';
        $this->beforeInsertBatch[] = 'tenantableBeforeInsertBatch';
        $this->beforeUpdateBatch[] = 'tenantableBeforeUpdateBatch';
    }

    protected function tenantableBeforeFind(array $data): array
    {
        if (!$this->isTenantableEnabled() || TenantContextState::isBypassingTenantFilter()) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            // No tenant: return nothing.
            $data['returnData'] = true;
            $data['data']       = ($data['singleton'] ?? false) ? null : [];

            return $data;
        }

        $this->applyTenantScope($tenantId);

        return $data;
    }

    protected function tenantableBeforeInsert(array $data): array
    {
        if (!$this->isTenantableEnabled() || TenantContextState::isBypassingTenantFilter()) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            // No tenant: refuse the write.
            throw MissingTenantContextException::forModel(static::class, 'insert');
        }

        if (isset($data['data'])) {
            $column = $this->tenantIdColumn;

            // Always use the active tenant. A caller-supplied tenant column
            // would be an IDOR: writing into another tenant.
            if (isset($data['data'][$column]) && (int) $data['data'][$column] !== $tenantId) {
                log_message('warning', 'Supplied tenant_id ignored on insert; using active tenant.', [
                    'model'    => static::class,
                    'supplied' => $data['data'][$column],
                    'tenant'   => $tenantId,
                ]);
            }

            $data['data'][$column] = $tenantId;
        }

        return $data;
    }

    protected function tenantableBeforeUpdate(array $data): array
    {
        if (!$this->isTenantableEnabled() || TenantContextState::isBypassingTenantFilter()) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            // No tenant: refuse the update.
            throw MissingTenantContextException::forModel(static::class, 'update');
        } else {
            $this->applyTenantScope($tenantId);
        }

        if (isset($data['data'][$this->tenantIdColumn])) {
            unset($data['data'][$this->tenantIdColumn]);

            log_message('warning', 'Attempted to change tenant_id was blocked.', [
                'model' => static::class,
            ]);
        }

        return $data;
    }

    protected function tenantableBeforeDelete(array $data): array
    {
        if (!$this->isTenantableEnabled() || TenantContextState::isBypassingTenantFilter()) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            // No tenant: refuse the delete. The framework's delete method
            // discards the beforeDelete return value, so failing closed means throwing.
            throw MissingTenantContextException::forModel(static::class, 'delete');
        }

        $this->applyTenantScope($tenantId);

        return $data;
    }

    /** Stamp every batch-insert row with the active tenant. */
    protected function tenantableBeforeInsertBatch(array $data): array
    {
        if (!$this->isTenantableEnabled() || TenantContextState::isBypassingTenantFilter()) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            // No tenant: refuse the write.
            throw MissingTenantContextException::forModel(static::class, 'insertBatch');
        }

        if (isset($data['data']) && is_array($data['data'])) {
            $column = $this->tenantIdColumn;

            foreach ($data['data'] as &$row) {
                if (! is_array($row)) {
                    continue;
                }

                if (isset($row[$column]) && (int) $row[$column] !== $tenantId) {
                    log_message('warning', 'Supplied tenant_id ignored on insertBatch; using active tenant.', [
                        'model'    => static::class,
                        'supplied' => $row[$column],
                        'tenant'   => $tenantId,
                    ]);
                }

                $row[$column] = $tenantId;
            }
            unset($row);
        }

        return $data;
    }

    /**
     * Scope batch updates to the active tenant: CI4's batch update matches rows
     * via constraint columns, so the tenant column becomes a constraint and
     * every row is stamped with it.
     */
    protected function tenantableBeforeUpdateBatch(array $data): array
    {
        if (!$this->isTenantableEnabled() || TenantContextState::isBypassingTenantFilter()) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            // No tenant: refuse the update.
            throw MissingTenantContextException::forModel(static::class, 'updateBatch');
        }

        if (! $this->hasTenantColumn($this->tenantIdColumn)) {
            throw TenantIsolationException::forMissingColumn(
                static::class,
                (string) $this->table,
                $this->tenantIdColumn,
            );
        }

        $builder = $this->builder();

        if (! method_exists($builder, 'onConstraint')) {
            throw TenantIsolationException::forMissingBatchConstraint(static::class);
        }

        if (isset($data['data']) && is_array($data['data'])) {
            $supplied = false;

            // Stamp every row: the constraint matches on this value, so a
            // foreign tenant_id excludes the row instead of reaching it.
            foreach ($data['data'] as &$row) {
                if (! is_array($row)) {
                    continue;
                }

                if (array_key_exists($this->tenantIdColumn, $row)
                    && (int) $row[$this->tenantIdColumn] !== $tenantId) {
                    $supplied = true;
                }

                $row[$this->tenantIdColumn] = $tenantId;
            }
            unset($row);

            if ($supplied) {
                log_message('warning', 'Attempted to change tenant_id was blocked.', [
                    'model' => static::class,
                ]);
            }
        }

        // Keyed by column name: onConstraint() merges by array key and CI4's
        // index-derived constraint is numerically indexed, which would win.
        $builder->onConstraint([$this->tenantIdColumn => $this->tenantIdColumn]);

        return $data;
    }

    /** Apply tenant scope to the builder. */
    protected function applyTenantScope(int $tenantId): void
    {
        if (! $this->hasTenantColumn($this->tenantIdColumn)) {
            throw TenantIsolationException::forMissingColumn(
                static::class,
                (string) $this->table,
                $this->tenantIdColumn,
            );
        }

        $this->builder()->where("{$this->table}.{$this->tenantIdColumn}", $tenantId);
    }

    /**
     * Tenant-scope count queries: CI4 fires no model event around count, so
     * this is the one read path that cannot ride the native callbacks.
     */
    public function countAllResults(...$args)
    {
        if (! $this->isTenantableEnabled() || TenantContextState::isBypassingTenantFilter()) {
            return parent::countAllResults(...$args);
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            return 0;
        }

        $this->applyTenantScope($tenantId);

        return parent::countAllResults(...$args);
    }

    protected function isTenantableEnabled(): bool
    {
        return $this->tenantable;
    }

    public function enableTenantable(): static
    {
        $this->tenantable = true;
        return $this;
    }

    public function disableTenantable(): static
    {
        $this->tenantable = false;
        return $this;
    }

    public static function enableTenantBypass(): void
    {
        TenantContextState::enableTenantBypass();
    }

    public static function disableTenantBypass(): void
    {
        TenantContextState::disableTenantBypass();
    }

    public static function isBypassingTenantFilter(): bool
    {
        return TenantContextState::isBypassingTenantFilter();
    }

    public static function withoutTenant(callable $callback): mixed
    {
        $previous = TenantContextState::isBypassingTenantFilter();
        TenantContextState::enableTenantBypass();

        try {
            return $callback();
        } finally {
            if ($previous) {
                TenantContextState::enableTenantBypass();
            } else {
                TenantContextState::disableTenantBypass();
            }
        }
    }

    protected function getTenantId(): ?int
    {
        try {
            return TenantManager::getInstance()->getTenantId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function hasTenantColumn(string $column): bool
    {
        try {
            $db = $this->db ?? \Config\Database::connect();
        } catch (\Throwable $e) {
            return true;
        }

        // Key by database: schemas differ in database-per-tenant mode.
        $database = (string) ($db->getDatabase() ?? '');
        $cacheKey = $database . '|' . $this->table . '.' . $column;

        if (array_key_exists($cacheKey, self::$tenantColumnCache)) {
            return self::$tenantColumnCache[$cacheKey];
        }

        try {
            $result = in_array($column, $db->getFieldNames($this->table), true);
        } catch (\Throwable $e) {
            $result = true;
        }

        self::$tenantColumnCache[$cacheKey] = $result;

        return $result;
    }

    public function setTenantIdColumn(string $column): static
    {
        $this->tenantIdColumn = $column;
        return $this;
    }

    public function getTenantIdColumn(): string
    {
        return $this->tenantIdColumn;
    }
}
