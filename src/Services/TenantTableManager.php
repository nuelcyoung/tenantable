<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Services;

// FIX 1.1 – Corrected namespace from the non-existent `CodeIgniter4\Tenantable`
//            to the actual package namespace `nuelcyoung\tenantable`.
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;

/**
 * TenantTableManager
 *
 * Handles tenant-specific table prefixes for schema-per-tenant architecture.
 *
 * Instead of: students (tenant_id)
 * Use: tenant_1_students, tenant_2_students
 *
 * This completely eliminates tenant_id leakage risks.
 *
 * FIX 1.1 – Wrong namespace import corrected.
 * FIX 1.3 – Added missing getInstance() static method (was called everywhere
 *            but never defined, causing fatal errors).
 */
class TenantTableManager
{
    // -------------------------------------------------------------------------
    // FIX 1.3 – Singleton pattern (same approach as TenantManager)
    // -------------------------------------------------------------------------

    /**
     * Singleton instance
     */
    private static ?self $instance = null;

    /**
     * Get the singleton instance.
     *
     * FIX 1.3 – This method was called in TenantTablePrefixTrait and
     *            TenantTablePrefixModel but never existed, causing fatal errors.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Reset the singleton (useful in tests or between requests).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    // -------------------------------------------------------------------------
    // Instance state
    // -------------------------------------------------------------------------

    /**
     * Table prefix format.
     * Placeholders: {id} → tenant ID, {table} → base table name.
     */
    protected string $prefixFormat = 'tenant_{id}_{table}';

    /**
     * Current tenant ID
     */
    protected ?int $tenantId = null;

    /**
     * Current tenant subdomain
     */
    protected ?string $tenantSubdomain = null;

    /**
     * Tables that should NOT be prefixed (global tables shared across tenants).
     */
    protected array $globalTables = ['tenants', 'migrations'];

    /**
     * Cache of prefixed table names for the current tenant.
     * Keyed by base table name.
     */
    protected array $tableCache = [];

    // -------------------------------------------------------------------------
    // Tenant context
    // -------------------------------------------------------------------------

    /**
     * Set the current tenant.
     */
    public function setTenant(int $tenantId, ?string $subdomain = null): self
    {
        $this->tenantId       = $tenantId;
        $this->tenantSubdomain = $subdomain;
        $this->tableCache     = []; // Clear cached names when tenant changes
        return $this;
    }

    /**
     * Clear tenant context.
     */
    public function clear(): self
    {
        $this->tenantId        = null;
        $this->tenantSubdomain = null;
        $this->tableCache      = [];
        return $this;
    }

    public function getTenantId(): ?int   { return $this->tenantId; }
    public function getSubdomain(): ?string { return $this->tenantSubdomain; }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    // -------------------------------------------------------------------------
    // Table name resolution
    // -------------------------------------------------------------------------

    /**
     * Get prefixed table name.
     *
     * @param string $table  Base table name (e.g., 'students')
     * @return string        Prefixed table name (e.g., 'tenant_1_students')
     *
     * @throws \RuntimeException when no tenant is set and the table is not global.
     */
    public function getTable(string $table): string
    {
        // Global tables are never prefixed
        if (in_array($table, $this->globalTables, true)) {
            return $table;
        }

        // Check cache
        if (isset($this->tableCache[$table])) {
            return $this->tableCache[$table];
        }

        // No tenant set – fail loudly rather than silently querying the wrong table
        if ($this->tenantId === null) {
            throw new \RuntimeException(
                "Tenant not set. Cannot determine table name for '{$table}'. " .
                "Ensure TenantFilter is running or explicitly set a tenant."
            );
        }

        $prefixed = str_replace(
            ['{id}', '{table}'],
            [(string) $this->tenantId, $table],
            $this->prefixFormat,
        );

        $this->tableCache[$table] = $prefixed;

        return $prefixed;
    }

    /**
     * Get prefixed names for multiple tables.
     *
     * @param array $tables  List of base table names
     * @return array         [base => prefixed]
     */
    public function getTables(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = $this->getTable($table);
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Global table management
    // -------------------------------------------------------------------------

    public function isGlobalTable(string $table): bool
    {
        return in_array($table, $this->globalTables, true);
    }

    public function addGlobalTable(string $table): self
    {
        if (!in_array($table, $this->globalTables, true)) {
            $this->globalTables[] = $table;
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Prefix format
    // -------------------------------------------------------------------------

    public function setPrefixFormat(string $format): self
    {
        $this->prefixFormat = $format;
        $this->tableCache   = []; // Clear cache when format changes
        return $this;
    }

    public function getPrefixFormat(): string
    {
        return $this->prefixFormat;
    }

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------

    /**
     * Get all expected prefixed table names for the current tenant.
     *
     * @param array $baseTables  List of base table names
     * @return array             Prefixed table names
     */
    public function getTenantTables(array $baseTables): array
    {
        if ($this->tenantId === null) {
            throw new \RuntimeException('Tenant must be set before calling getTenantTables().');
        }

        return array_map([$this, 'getTable'], $baseTables);
    }

    /**
     * Extract tenant ID from a prefixed table name.
     *
     * @param string $prefixedTableName  e.g., 'tenant_1_students'
     * @return int|null                  Tenant ID or null if not a tenant table
     */
    public function extractTenantId(string $prefixedTableName): ?int
    {
        $pattern = str_replace(['{id}', '{table}'], ['(\d+)', '.+'], $this->prefixFormat);

        if (preg_match("/^{$pattern}$/", $prefixedTableName, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Get distinct tenant IDs that have prefixed tables in the database.
     *
     * @return array  Unique tenant IDs
     */
    public function getAllTenantPrefixes(): array
    {
        $db      = \Config\Database::connect();
        $tables  = $db->listTables();
        $pattern = str_replace(['{id}', '{table}'], ['(\d+)', '.*'], $this->prefixFormat);

        $prefixes = [];
        foreach ($tables as $table) {
            if (preg_match("/^{$pattern}$/", $table, $matches)) {
                $prefixes[] = (int) $matches[1];
            }
        }

        return array_unique($prefixes);
    }
}
