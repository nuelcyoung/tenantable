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

use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;

class TenantTableManager
{
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
    protected array $globalTables = ['tenants', 'migrations'];
    protected array $tableCache = [];

    public function setTenant(int $tenantId, ?string $subdomain = null): self
    {
        $this->tenantId       = $tenantId;
        $this->tenantSubdomain = $subdomain;
        $this->tableCache     = [];
        return $this;
    }

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
        $pattern = str_replace(['{id}', '{table}'], ['(\d+)', '.+'], $this->prefixFormat);

        if (preg_match("/^{$pattern}$/", $prefixedTableName, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

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
