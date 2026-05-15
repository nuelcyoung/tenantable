<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Traits;

use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Support\TenantContextState;

trait TenantableTrait
{
    protected bool $tenantable = true;
    protected string $tenantIdColumn = 'tenant_id';

    private static array $tenantColumnCache = [];

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

    protected function tenantableBeforeFind(array $data): array
    {
        if (!$this->isTenantableEnabled() || TenantContextState::isBypassingTenantFilter()) {
            return $data;
        }

        $tenantId = $this->getTenantId();

        if ($tenantId === null) {
            return $data;
        }

        $builder = $data['builder'] ?? null;

        if ($builder !== null && $this->hasTenantColumn($this->tenantIdColumn)) {
            $builder->where("{$this->table}.{$this->tenantIdColumn}", $tenantId);
        }

        return $data;
    }

    protected function tenantableBeforeInsert(array $data): array
    {
        if (!$this->isTenantableEnabled() || TenantContextState::isBypassingTenantFilter()) {
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
        if (!$this->isTenantableEnabled() || TenantContextState::isBypassingTenantFilter()) {
            return $data;
        }

        if (isset($data['data'][$this->tenantIdColumn])) {
            unset($data['data'][$this->tenantIdColumn]);
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
            $data['return'] = false;
            return $data;
        }

        $builder = $data['builder'] ?? null;

        if ($builder !== null && $this->hasTenantColumn($this->tenantIdColumn)) {
            $builder->where("{$this->table}.{$this->tenantIdColumn}", $tenantId);
        }

        return $data;
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
        $cacheKey = $this->table . '.' . $column;

        if (array_key_exists($cacheKey, self::$tenantColumnCache)) {
            return self::$tenantColumnCache[$cacheKey];
        }

        try {
            $db     = \Config\Database::connect();
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