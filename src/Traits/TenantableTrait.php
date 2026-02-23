<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Traits;

use nuelcyoung\tenantable\Services\TenantManager;

/**
 * Tenantable Trait
 *
 * Use this trait in any CodeIgniter Model to automatically filter
 * all queries by the current tenant ID.
 *
 * FIX 1.4 – Static bypass consolidated: $bypassTenantFilter is now a static
 *            property of each *concrete* class (via late static binding through
 *            a getter), so enabling bypass on one model doesn't bleed into others.
 *            TenantSecurityMiddleware::after() calls disableTenantBypass() which
 *            now also clears the flag via the static property correctly.
 *
 * FIX 3.1 – hasTenantColumn() now caches the result statically per table name
 *            to avoid a `DESCRIBE` SQL query on every find/insert/update/delete.
 */
trait TenantableTrait
{
    /**
     * Whether tenant filtering is enabled for this model instance.
     */
    protected bool $tenantable = true;

    /**
     * Tenant ID column name. Override in model if your column differs.
     */
    protected string $tenantIdColumn = 'tenant_id';

    /**
     * FIX 1.4 – Global bypass flag.
     *
     * Kept as a single static property here (on the trait) so that calling
     * enableTenantBypass() once turns it off for all models that use the trait,
     * which is the correct superadmin behaviour.
     * The important change is that TenantableModel now reads THIS property
     * instead of maintaining a separate, out-of-sync one.
     */
    protected static bool $bypassTenantFilter = false;

    // -------------------------------------------------------------------------
    // FIX 3.1 – DESCRIBE result cache: static, keyed by table name
    // -------------------------------------------------------------------------

    /**
     * Cache of tenant-column presence keyed by table name.
     * ['students' => true, 'tenants' => false, ...]
     *
     * @var array<string, bool>
     */
    private static array $tenantColumnCache = [];

    // -------------------------------------------------------------------------
    // Boot / init (called by CI4 model machinery)
    // -------------------------------------------------------------------------

    public static function bootTenantableTrait(): void {}

    public function initializeTenantableTrait(): void
    {
        $this->setupTenantableCallbacks();
    }

    protected function setupTenantableCallbacks(): void
    {
        $this->beforeFind[]   = 'tenantableBeforeFind';
        $this->beforeInsert[] = 'tenantableBeforeInsert';
        $this->beforeUpdate[] = 'tenantableBeforeUpdate';
        $this->beforeDelete[] = 'tenantableBeforeDelete';
    }

    // -------------------------------------------------------------------------
    // Callbacks
    // -------------------------------------------------------------------------

    protected function tenantableBeforeFind(array $data): array
    {
        if (!$this->isTenantableEnabled() || self::$bypassTenantFilter) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            return $data; // No context – allow global queries
        }

        $builder = $data['builder'] ?? null;

        if ($builder !== null && $this->hasTenantColumn($this->tenantIdColumn)) {
            $builder->where("{$this->table}.{$this->tenantIdColumn}", $tenantId);
        }

        return $data;
    }

    protected function tenantableBeforeInsert(array $data): array
    {
        if (!$this->isTenantableEnabled() || self::$bypassTenantFilter) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId !== null && isset($data['data'])) {
            $column = $this->tenantIdColumn;
            if (!isset($data['data'][$column])) {
                $data['data'][$column] = $tenantId;
            }
        }

        return $data;
    }

    protected function tenantableBeforeUpdate(array $data): array
    {
        if (!$this->isTenantableEnabled() || self::$bypassTenantFilter) {
            return $data;
        }

        // Prevent changing tenant_id during updates
        if (isset($data['data'][$this->tenantIdColumn])) {
            unset($data['data'][$this->tenantIdColumn]);
        }

        return $data;
    }

    protected function tenantableBeforeDelete(array $data): array
    {
        if (!$this->isTenantableEnabled() || self::$bypassTenantFilter) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            $data['return'] = false; // Block deletion without tenant context
            return $data;
        }

        $builder = $data['builder'] ?? null;

        if ($builder !== null && $this->hasTenantColumn($this->tenantIdColumn)) {
            $builder->where("{$this->table}.{$this->tenantIdColumn}", $tenantId);
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Tenant filtering toggle
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Bypass (superadmin)
    // -------------------------------------------------------------------------

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
     * Execute a callback without tenant filtering, then restore the previous state.
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
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Get current tenant ID from TenantManager.
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
     * Result is cached statically per table name so that subsequent
     * calls within the same request never issue another `DESCRIBE` query.
     *
     * @param string $column  Column name to check for
     * @return bool
     */
    protected function hasTenantColumn(string $column): bool
    {
        $cacheKey = $this->table . '.' . $column;

        if (array_key_exists($cacheKey, self::$tenantColumnCache)) {
            return self::$tenantColumnCache[$cacheKey];
        }

        try {
            $db     = \Config\Database::connect();
            $result = in_array($column, $db->getFieldNames($this->table), true);
        } catch (\Throwable $e) {
            // If we can't verify, assume it exists (safe default)
            $result = true;
        }

        self::$tenantColumnCache[$cacheKey] = $result;

        return $result;
    }

    // -------------------------------------------------------------------------
    // Column name helpers
    // -------------------------------------------------------------------------

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
