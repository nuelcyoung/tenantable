<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Traits;

use nuelcyoung\tenantable\Services\TenantTableManager;

/**
 * TenantTablePrefixTrait
 *
 * Use this trait for models that need tenant-specific tables.
 * Automatically prefixes table names based on the current tenant.
 *
 * Example:
 *   - Model: $table = 'students'
 *   - With tenant_id = 1: actual table = 'tenant_1_students'
 *
 * This completely eliminates tenant_id leakage risks.
 *
 * FIX 2.5 – Silent cross-tenant fallback removed: getTable() now throws when
 *            no tenant is set instead of quietly returning the base table name.
 * FIX 2.6 – Table name is no longer baked into $this->table at construction
 *            time. It is resolved dynamically on every getTable() call, so
 *            models instantiated before the tenant is set still work correctly.
 * FIX 2.7 – $isGlobalTable property declared on the trait itself so that
 *            using the trait standalone (outside TenantTablePrefixModel) works.
 * FIX 3.2 – Removed dead $baseTable property (was declared but never assigned
 *            or used anywhere).
 */
trait TenantTablePrefixTrait
{
    /**
     * FIX 2.7 – Property declared on the trait so the trait is self-contained.
     * Can be overridden in the concrete model class.
     */
    protected bool $isGlobalTable = false;

    // -------------------------------------------------------------------------
    // Table name resolution
    // -------------------------------------------------------------------------

    /**
     * Return the correct (possibly prefixed) table name for the current tenant.
     *
     * FIX 2.6 – The name is resolved dynamically on every call, not baked at
     *            construction time. This means models instantiated before the
     *            TenantFilter runs are still correct.
     * FIX 2.5 – Throws \RuntimeException when no tenant is set (instead of
     *            silently falling back to the unprefixed table name, which
     *            could expose cross-tenant data).
     *
     * @throws \RuntimeException when tenant is not set and table is not global.
     */
    public function getTable(): string
    {
        if ($this->isGlobalTable) {
            return $this->table;
        }

        $manager = TenantTableManager::getInstance();

        if (!$manager->hasTenant()) {
            // FIX 2.5 – Do not silently return unprefixed table; throw instead.
            throw new \RuntimeException(
                "TenantTablePrefixTrait: No tenant context set when resolving table '{$this->table}'. " .
                "Ensure TenantFilter has run or call TenantTableManager::getInstance()->setTenant() first."
            );
        }

        return $manager->getTable($this->table);
    }

    /**
     * Returns the base table name without any tenant prefix.
     */
    public function getBaseTableName(): string
    {
        return $this->table;
    }

    // -------------------------------------------------------------------------
    // Global table flag
    // -------------------------------------------------------------------------

    public function setGlobalTable(bool $isGlobal = true): static
    {
        $this->isGlobalTable = $isGlobal;
        return $this;
    }

    public function isGlobalTableFlag(): bool
    {
        return $this->isGlobalTable;
    }
}

/**
 * TenantTablePrefixModel
 *
 * Base model that automatically uses tenant-specific tables.
 * Extend this instead of CodeIgniter's Model for tenant tables.
 *
 * FIX 2.6 – Constructor no longer bakes $this->table at instantiation time.
 *            The table name is resolved dynamically via getTable() on each query.
 */
abstract class TenantTablePrefixModel extends \CodeIgniter\Model
{
    use TenantTablePrefixTrait;

    /**
     * Whether this is a global (un-prefixed) table.
     * Override to `true` in models that span all tenants (e.g., TenantModel itself).
     */
    protected bool $isGlobalTable = false;

    /**
     * FIX 2.6 – Constructor does NOT bake the table name anymore.
     *
     * Previously the constructor called getTableName() and stored the result in
     * $this->table. This was wrong because if the model was instantiated before
     * the TenantFilter ran (e.g., in a service provider or a base controller
     * constructor), $this->table would be permanently set to the raw table name
     * and subsequent queries would target the wrong table.
     */
    public function __construct(...$params)
    {
        parent::__construct(...$params);
        // Table name resolution is deferred to getTable() — no baking here.
    }

    // -------------------------------------------------------------------------
    // Guard methods – return early / empty when no tenant is set
    // -------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function find($id = null, $columns = '*')
    {
        if (!$this->isGlobalTable && !TenantTableManager::getInstance()->hasTenant()) {
            return null;
        }

        return parent::find($id, $columns);
    }

    /**
     * @inheritDoc
     */
    public function first($columns = '*')
    {
        if (!$this->isGlobalTable && !TenantTableManager::getInstance()->hasTenant()) {
            return null;
        }

        return parent::first($columns);
    }

    /**
     * @inheritDoc
     */
    public function findAll(int $limit = 0, int $offset = 0)
    {
        if (!$this->isGlobalTable && !TenantTableManager::getInstance()->hasTenant()) {
            return [];
        }

        return parent::findAll($limit, $offset);
    }

    /**
     * @inheritDoc
     */
    public function countAllResults(bool $reset = true, bool $test = false): int
    {
        if (!$this->isGlobalTable && !TenantTableManager::getInstance()->hasTenant()) {
            return 0;
        }

        return parent::countAllResults($reset, $test);
    }
}
