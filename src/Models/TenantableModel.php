<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Models;

use CodeIgniter\Model;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;

/**
 * TenantableModel
 *
 * Base model that enforces tenant filtering on ALL operations.
 * Use this instead of CodeIgniter's Model for all tenant-specific tables.
 *
 * FIX 1.4 – Duplicate bypass API removed. This class no longer maintains its
 *            own $allowBypass flag. It now delegates to TenantableTrait's
 *            unified $bypassTenantFilter flag via trait methods so that both
 *            approaches (trait + base model) share the same state.
 *
 * FIX 3.1 – hasTenantColumn() caches its DESCRIBE result statically per table.
 *
 * FIX 3.10 – countAllResults() no longer calls $this->where() directly
 *             (which caused double-filtering alongside the beforeFind callback).
 *             Instead it relies purely on the beforeFind/applyTenantFilter
 *             callback, consistent with find() and first().
 */
abstract class TenantableModel extends Model
{
    /**
     * Tenant ID column name. Override in child models if it differs.
     */
    protected string $tenantIdColumn = 'tenant_id';

    /**
     * FIX 1.4 – Removed separate $allowBypass static property.
     *            Bypass state is now managed by a single flag (below) shared
     *            with TenantableTrait users.
     */
    protected static bool $bypassTenantFilter = false;

    /**
     * FIX 3.1 – Static cache of DESCRIBE results per table.column key.
     *
     * @var array<string, bool>
     */
    private static array $tenantColumnCache = [];

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(...$params)
    {
        parent::__construct(...$params);
        $this->setupTenantFiltering();
    }

    /**
     * Register model event callbacks.
     */
    protected function setupTenantFiltering(): void
    {
        $this->beforeFind[]   = 'applyTenantFilter';
        $this->beforeInsert[] = 'enforceTenantId';
        $this->beforeUpdate[] = 'protectTenantId';
        $this->beforeDelete[] = 'applyTenantFilter';
    }

    // -------------------------------------------------------------------------
    // Callbacks
    // -------------------------------------------------------------------------

    /**
     * Apply tenant filter to find/delete queries.
     */
    protected function applyTenantFilter(array $data): array
    {
        if (static::$bypassTenantFilter || $this->isExemptFromTenant()) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            throw new TenantNotFoundException(
                'Tenant context required for this operation. ' .
                'Ensure TenantFilter is running and a tenant is resolved.'
            );
        }

        $builder = $data['builder'] ?? null;

        if ($builder !== null && $this->hasTenantColumn()) {
            $builder->where("{$this->table}.{$this->tenantIdColumn}", $tenantId);
        }

        return $data;
    }

    /**
     * Enforce tenant_id on insert.
     */
    protected function enforceTenantId(array $data): array
    {
        if (static::$bypassTenantFilter || $this->isExemptFromTenant()) {
            return $data;
        }

        if (!isset($data['data'])) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            throw new TenantNotFoundException('Cannot insert without tenant context.');
        }

        // Always overwrite to prevent injection with a wrong tenant_id
        $data['data'][$this->tenantIdColumn] = $tenantId;

        return $data;
    }

    /**
     * Protect tenant_id from being changed on update.
     */
    protected function protectTenantId(array $data): array
    {
        if (static::$bypassTenantFilter || $this->isExemptFromTenant()) {
            return $data;
        }

        if (isset($data['data'][$this->tenantIdColumn])) {
            unset($data['data'][$this->tenantIdColumn]);

            log_message('warning', 'Attempted to change tenant_id was blocked.', [
                'model' => static::class,
                'data'  => $data['data'],
            ]);
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Guard method overrides
    // -------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function find($id = null, $columns = '*')
    {
        if (!static::$bypassTenantFilter && !$this->isExemptFromTenant()) {
            if ($this->getTenantId() === null) {
                return null;
            }
        }

        return parent::find($id, $columns);
    }

    /**
     * @inheritDoc
     */
    public function first($columns = '*')
    {
        if (!static::$bypassTenantFilter && !$this->isExemptFromTenant()) {
            if ($this->getTenantId() === null) {
                return null;
            }
        }

        return parent::first($columns);
    }

    /**
     * FIX 3.10 – Do NOT call $this->where() here (double-filtering).
     *
     * The beforeFind callback (applyTenantFilter) already adds the WHERE clause
     * through the query builder. Adding it again via $this->where() in
     * countAllResults() resulted in the condition being applied twice, which
     * also misdirected the state of the query builder chain.
     *
     * @inheritDoc
     */
    public function countAllResults(bool $reset = true, bool $test = false): int
    {
        if (!static::$bypassTenantFilter && !$this->isExemptFromTenant()) {
            if ($this->getTenantId() === null) {
                return 0;
            }
        }

        // Rely solely on the beforeFind callback for tenant filtering (FIX 3.10)
        return parent::countAllResults($reset, $test);
    }

    // -------------------------------------------------------------------------
    // Bypass (superadmin) – FIX 1.4 unified API
    // -------------------------------------------------------------------------

    /**
     * Enable global bypass (superadmin mode).
     *
     * FIX 1.4 – Replaces the old allowBypass()/disallowBypass() pair
     *            with names that match TenantableTrait so there is one
     *            consistent API across the package.
     */
    public static function enableTenantBypass(): void
    {
        static::$bypassTenantFilter = true;
    }

    public static function disableTenantBypass(): void
    {
        static::$bypassTenantFilter = false;
    }

    public static function isBypassingTenantFilter(): bool
    {
        return static::$bypassTenantFilter;
    }

    /**
     * Run a callback without tenant filtering, then restore the previous state.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function withoutTenant(callable $callback): mixed
    {
        $previous = static::$bypassTenantFilter;
        static::$bypassTenantFilter = true;

        try {
            return $callback();
        } finally {
            static::$bypassTenantFilter = $previous;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Get the current tenant ID from TenantManager.
     */
    protected function getTenantId(): ?int
    {
        try {
            return TenantManager::getInstance()->getTenantId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * FIX 3.1 – Check whether the tenant column exists in the table.
     *
     * Caches the result statically per table.column so the DESCRIBE query
     * only runs once per table per request lifecycle.
     */
    protected function hasTenantColumn(): bool
    {
        $cacheKey = $this->table . '.' . $this->tenantIdColumn;

        if (array_key_exists($cacheKey, self::$tenantColumnCache)) {
            return self::$tenantColumnCache[$cacheKey];
        }

        try {
            $db     = \Config\Database::connect();
            $result = in_array($this->tenantIdColumn, $db->getFieldNames($this->table), true);
        } catch (\Throwable $e) {
            $result = true; // Assume it exists if we can't verify
        }

        self::$tenantColumnCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Whether this model/table is exempt from tenant filtering.
     * Override in child classes for global tables (e.g., settings, plans).
     */
    protected function isExemptFromTenant(): bool
    {
        return false;
    }

    // -------------------------------------------------------------------------
    // Back-compat aliases for old bypass API (deprecated – will be removed)
    // -------------------------------------------------------------------------

    /** @deprecated Use enableTenantBypass() */
    public static function allowBypass(): void   { static::enableTenantBypass(); }
    /** @deprecated Use disableTenantBypass() */
    public static function disallowBypass(): void { static::disableTenantBypass(); }
    /** @deprecated Use isBypassingTenantFilter() */
    public static function isBypassAllowed(): bool { return static::isBypassingTenantFilter(); }
}
