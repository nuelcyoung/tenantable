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

namespace nuelcyoung\tenantable\Services;

use CodeIgniter\Config\Factories;
use nuelcyoung\tenantable\Contracts\TenantPrefixAware;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;
use nuelcyoung\tenantable\Support\TenantableConfig;
use nuelcyoung\tenantable\Traits\TenantTablePrefixTrait;

class TenantTableManager
{
    /**
     * Prefix on bound connections while no tenant is active: queries hit
     * tables that never exist, so anything past the guards fails loudly.
     */
    public const NO_TENANT_PREFIX = 'tenantable_no_tenant_';

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    protected string $prefixFormat = 'tenant_{id}_{table}';
    protected ?int $tenantId = null;
    protected ?string $tenantSubdomain = null;
    protected array $globalTables = ['tenants', 'migrations', 'tenant_migrations'];
    protected array $tableCache = [];

    /**
     * Prefix-aware models, held weakly. A tenant switch re-prefixes every
     * bound model's connection and drops its cached query builder.
     *
     * @var \WeakMap<object, true>|null
     */
    private ?\WeakMap $boundModels = null;

    /** Cached answer for "does this app isolate by connection prefix?". */
    private ?bool $prefixesConnection = null;

    /**
     * Register a prefix-aware model and sync it to the current tenant;
     * duck-typed so userland models need not implement TenantPrefixAware.
     */
    public function bindModel(object $model): void
    {
        if (! method_exists($model, 'syncTenantPrefix') || ! method_exists($model, 'assertNoPendingTenantClauses')) {
            throw new \LogicException(
                'Tenantable: ' . $model::class . ' cannot be bound for prefixing — it must use '
                . TenantTablePrefixTrait::class . ' (or extend TenantTablePrefixModel), which supplies '
                . 'syncTenantPrefix() and assertNoPendingTenantClauses().'
            );
        }

        if ($this->boundModels === null) {
            $this->boundModels = new \WeakMap();
        }

        $this->boundModels[$model] = true;
        $model->syncTenantPrefix($this->getPrefix());
    }

    public function setTenant(int $tenantId, ?string $subdomain = null): self
    {
        // A real switch (A → B, both non-null) with un-executed chained
        // clauses would drop them silently; fail loudly first instead.
        if ($this->tenantId !== null && $this->tenantId !== $tenantId) {
            $this->assertNoPendingClauses();
        }

        $this->tenantId        = $tenantId;
        $this->tenantSubdomain = $subdomain;
        $this->tableCache      = [];
        $this->syncConnection();
        $this->syncBoundModels();

        return $this;
    }

    public function clear(): self
    {
        $this->tenantId        = null;
        $this->tenantSubdomain = null;
        $this->tableCache      = [];
        $this->syncConnection();
        $this->syncBoundModels();

        return $this;
    }

    /**
     * The DBPrefix for the active tenant (e.g. "tenant_1_"), or
     * NO_TENANT_PREFIX when none is active (fail-closed).
     */
    public function getPrefix(): string
    {
        if ($this->tenantId === null) {
            return self::NO_TENANT_PREFIX;
        }

        $marker = '{table}';
        $at     = strpos($this->prefixFormat, $marker);

        if ($at === false || substr($this->prefixFormat, $at + strlen($marker)) !== '') {
            throw new \LogicException(
                "Tenantable: prefixFormat '{$this->prefixFormat}' is not usable — the connection-level "
                . "DBPrefix mechanism can only prepend, so the format must end with '{table}'."
            );
        }

        return str_replace('{id}', (string) $this->tenantId, substr($this->prefixFormat, 0, $at));
    }

    public function getTenantId(): ?int   { return $this->tenantId; }
    public function getSubdomain(): ?string { return $this->tenantSubdomain; }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function getTable(string $table): string
    {
        if (in_array($table, $this->globalTables, true)) {
            return $table;
        }

        if (isset($this->tableCache[$table])) {
            return $this->tableCache[$table];
        }

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

    public function getTables(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = $this->getTable($table);
        }
        return $result;
    }

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

    public function setPrefixFormat(string $format): self
    {
        if (! str_ends_with($format, '{table}')) {
            throw new \InvalidArgumentException(
                "Tenantable: prefixFormat '{$format}' must end with '{table}' — the native DBPrefix "
                . "mechanism can only prepend to the table name (e.g. 'tenant_{id}_{table}')."
            );
        }

        $this->prefixFormat = $format;
        $this->tableCache   = [];
        return $this;
    }

    public function getPrefixFormat(): string
    {
        return $this->prefixFormat;
    }

    public function getTenantTables(array $baseTables): array
    {
        if ($this->tenantId === null) {
            throw new \RuntimeException('Tenant must be set before calling getTenantTables().');
        }

        return array_map([$this, 'getTable'], $baseTables);
    }

    public function extractTenantId(string $prefixedTableName): ?int
    {
        $pattern = $this->buildPrefixRegex('.+');

        if (preg_match($pattern, $prefixedTableName, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /** Compile $prefixFormat into a regex. Escapes literal parts. */
    private function buildPrefixRegex(string $tablePattern): string
    {
        $quoted = preg_quote($this->prefixFormat, '/');

        // preg_quote turns "{id}" into "\{id\}", so replace the escaped tokens.
        $pattern = str_replace(
            ['\{id\}', '\{table\}'],
            ['(\d+)', $tablePattern],
            $quoted,
        );

        return "/^{$pattern}$/";
    }

    public function getAllTenantPrefixes(): array
    {
        $db      = \Config\Database::connect();
        $tables  = $db->listTables();
        $pattern = $this->buildPrefixRegex('.*');

        $prefixes = [];
        foreach ($tables as $table) {
            if (preg_match($pattern, $table, $matches)) {
                $prefixes[] = (int) $matches[1];
            }
        }

        return array_unique($prefixes);
    }

    /**
     * Push the active prefix onto the shared default connection. CI4 applies
     * DBPrefix at compile time, so every table reference resolves to the
     * tenant's table. Only prefix isolation may carry a prefix.
     */
    private function syncConnection(): void
    {
        if (! $this->prefixesConnection()) {
            return;
        }

        try {
            \Config\Database::connect()->setPrefix($this->getPrefix());
        } catch (\Throwable $e) {
            log_message('error', 'Tenantable: could not apply tenant prefix to the default connection: ' . $e->getMessage());

            return;
        }

        // Cached model instances hold builders compiled against the old
        // prefix; reset so model() rebuilds against the new tenant.
        if (class_exists(Factories::class)) {
            Factories::reset('models');
        }
    }

    /** True when the app isolates tenants by table prefix. */
    private function prefixesConnection(): bool
    {
        if ($this->prefixesConnection !== null) {
            return $this->prefixesConnection;
        }

        try {
            return $this->prefixesConnection = TenantableConfig::get()->resolvedIsolationMode() === 'prefix';
        } catch (\Throwable $e) {
            return $this->prefixesConnection = false;
        }
    }

    /** Push the current prefix onto every bound model's connection. */
    private function syncBoundModels(): void
    {
        if ($this->boundModels === null) {
            return;
        }

        $prefix = $this->getPrefix();

        foreach ($this->boundModels as $model => $_) {
            $model->syncTenantPrefix($prefix);
        }
    }

    /** Reject a tenant switch while any bound model has un-executed clauses. */
    private function assertNoPendingClauses(): void
    {
        if ($this->boundModels === null) {
            return;
        }

        foreach ($this->boundModels as $model => $_) {
            $model->assertNoPendingTenantClauses();
        }
    }
}
