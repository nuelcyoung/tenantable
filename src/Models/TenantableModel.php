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

namespace nuelcyoung\tenantable\Models;

use CodeIgniter\Model;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;
use nuelcyoung\tenantable\Exceptions\TenantIsolationException;
use nuelcyoung\tenantable\Support\TenantContextState;
use nuelcyoung\tenantable\Support\TenantableConfig;

abstract class TenantableModel extends Model
{
    protected string $tenantIdColumn = 'tenant_id';

    private static array $tenantColumnCache = [];

    /**
     * Wire tenant callbacks through CI4's initialize(). If your model defines
     * its own initialize method, call the parent initializer or the model runs unscoped.
     */
    protected function initialize(): void
    {
        $this->setupTenantFiltering();
    }

    protected function setupTenantFiltering(): void
    {
        $this->beforeFind[]        = 'applyTenantFilter';
        $this->beforeInsert[]      = 'enforceTenantId';
        $this->beforeUpdate[]      = 'protectTenantId';
        $this->beforeDelete[]      = 'scopeTenantDelete';
        $this->beforeInsertBatch[] = 'enforceTenantIdBatch';
        $this->beforeUpdateBatch[] = 'protectTenantIdBatch';
    }

    /**
     * Constrain reads to the active tenant. Uses the documented beforeFind
     * contract so reads without a tenant fail safe (empty) instead of leaking.
     */
    protected function applyTenantFilter(array $data): array
    {
        if (TenantContextState::isBypassingTenantFilter() || $this->isExemptFromTenant()) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            $data['returnData'] = true;
            $data['data']       = ($data['singleton'] ?? false) ? null : [];

            return $data;
        }

        $this->applyTenantScope($tenantId);

        return $data;
    }

    /**
     * Constrain deletes to the active tenant. CI4's delete() discards the
     * beforeDelete return value, so the callback scopes the builder and throws.
     */
    protected function scopeTenantDelete(array $data): array
    {
        if (TenantContextState::isBypassingTenantFilter() || $this->isExemptFromTenant()) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            throw new TenantNotFoundException(
                'Tenant context required for this operation. ' .
                'Ensure TenantFilter is running and a tenant is resolved.'
            );
        }

        $this->applyTenantScope($tenantId);

        return $data;
    }

    /** Constrain queries to the active tenant. */
    protected function applyTenantScope(int $tenantId): void
    {
        if ($this->usesDatabaseIsolation()) {
            return;
        }

        if (! $this->hasTenantColumn()) {
            throw TenantIsolationException::forMissingColumn(
                static::class,
                (string) $this->table,
                $this->tenantIdColumn,
            );
        }

        $this->builder()->where("{$this->table}.{$this->tenantIdColumn}", $tenantId);
    }

    protected function enforceTenantId(array $data): array
    {
        if (TenantContextState::isBypassingTenantFilter() || $this->isExemptFromTenant()) {
            return $data;
        }

        if (!isset($data['data'])) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            throw new TenantNotFoundException('Cannot insert without tenant context.');
        }

        if ($this->usesDatabaseIsolation()) {
            return $data;
        }

        // Always use the active tenant. A caller-supplied identifier would be an IDOR.
        if (isset($data['data'][$this->tenantIdColumn]) && (int) $data['data'][$this->tenantIdColumn] !== $tenantId) {
            log_message('warning', 'Supplied tenant_id ignored on insert; using active tenant.', [
                'model'    => static::class,
                'supplied' => $data['data'][$this->tenantIdColumn],
                'tenant'   => $tenantId,
            ]);
        }

        $data['data'][$this->tenantIdColumn] = $tenantId;

        return $data;
    }

    /** Stamp every batch-insert row with the active tenant. */
    protected function enforceTenantIdBatch(array $data): array
    {
        if (TenantContextState::isBypassingTenantFilter() || $this->isExemptFromTenant()) {
            return $data;
        }

        if (!isset($data['data']) || !is_array($data['data'])) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            throw new TenantNotFoundException('Cannot insert without tenant context.');
        }

        if ($this->usesDatabaseIsolation()) {
            return $data;
        }

        foreach ($data['data'] as &$row) {
            if (! is_array($row)) {
                continue;
            }

            if (isset($row[$this->tenantIdColumn]) && (int) $row[$this->tenantIdColumn] !== $tenantId) {
                log_message('warning', 'Supplied tenant_id ignored on insertBatch; using active tenant.', [
                    'model'    => static::class,
                    'supplied' => $row[$this->tenantIdColumn],
                    'tenant'   => $tenantId,
                ]);
            }

            $row[$this->tenantIdColumn] = $tenantId;
        }
        unset($row);

        return $data;
    }

    protected function protectTenantId(array $data): array
    {
        if (TenantContextState::isBypassingTenantFilter() || $this->isExemptFromTenant()) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            // No tenant: refuse the update.
            throw new TenantNotFoundException('Cannot update without tenant context.');
        }

        if ($this->usesDatabaseIsolation()) {
            return $data;
        }

        // Scope to this tenant. Don't let a guessed key modify another tenant.
        $this->applyTenantScope($tenantId);

        if (isset($data['data'][$this->tenantIdColumn])) {
            unset($data['data'][$this->tenantIdColumn]);

            log_message('warning', 'Attempted to change tenant_id was blocked.', [
                'model' => static::class,
                'data'  => $data['data'],
            ]);
        }

        return $data;
    }

    /**
     * Scope batch updates to the active tenant: CI4's batch update matches rows
     * via constraint columns, so the tenant column becomes a constraint and
     * every row is stamped with it.
     */
    protected function protectTenantIdBatch(array $data): array
    {
        if (TenantContextState::isBypassingTenantFilter() || $this->isExemptFromTenant()) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            // No tenant: refuse the update.
            throw new TenantNotFoundException('Cannot update without tenant context.');
        }

        if ($this->usesDatabaseIsolation()) {
            return $data;
        }

        if (! $this->hasTenantColumn()) {
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

    /**
     * Tenant-scope count queries: CI4 fires no model event around count, so
     * this is the one read path that cannot ride the native callbacks.
     */
    public function countAllResults(...$args)
    {
        if (! TenantContextState::isBypassingTenantFilter() && ! $this->isExemptFromTenant()) {
            $tenantId = $this->getTenantId();

            if ($tenantId === null) {
                return 0;
            }

            $this->applyTenantScope($tenantId);
        }

        return parent::countAllResults(...$args);
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

    protected function hasTenantColumn(): bool
    {
        try {
            $db = $this->db;
        } catch (\Throwable $e) {
            return true;
        }

        // Key by database: schemas differ in database-per-tenant mode.
        $database = (string) ($db->getDatabase() ?? '');
        $cacheKey = $database . '|' . $this->table . '.' . $this->tenantIdColumn;

        if (array_key_exists($cacheKey, self::$tenantColumnCache)) {
            return self::$tenantColumnCache[$cacheKey];
        }

        try {
            $result = in_array($this->tenantIdColumn, $db->getFieldNames($this->table), true);
        } catch (\Throwable $e) {
            $result = true;
        }

        self::$tenantColumnCache[$cacheKey] = $result;

        return $result;
    }

    protected function usesDatabaseIsolation(): bool
    {
        try {
            return TenantableConfig::get()->isDatabaseIsolation();
        } catch (\Throwable) {
            return false;
        }
    }

    protected function isExemptFromTenant(): bool
    {
        return false;
    }

    /** @deprecated Use enableTenantBypass() */
    public static function allowBypass(): void   { static::enableTenantBypass(); }
    /** @deprecated Use disableTenantBypass() */
    public static function disallowBypass(): void { static::disableTenantBypass(); }
    /** @deprecated Use isBypassingTenantFilter() */
    public static function isBypassAllowed(): bool { return static::isBypassingTenantFilter(); }
}
