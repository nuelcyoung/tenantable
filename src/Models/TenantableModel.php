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
use nuelcyoung\tenantable\Support\TenantContextState;

abstract class TenantableModel extends Model
{
    protected string $tenantIdColumn = 'tenant_id';

    private static array $tenantColumnCache = [];

    public function __construct(...$params)
    {
        parent::__construct(...$params);
        $this->setupTenantFiltering();
    }

    protected function setupTenantFiltering(): void
    {
        $this->beforeFind[]   = 'applyTenantFilter';
        $this->beforeInsert[] = 'enforceTenantId';
        $this->beforeUpdate[] = 'protectTenantId';
        $this->beforeDelete[] = 'applyTenantFilter';
    }

    protected function applyTenantFilter(array $data): array
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

        $builder = $data['builder'] ?? null;

        if ($builder !== null && $this->hasTenantColumn()) {
            $builder->where("{$this->table}.{$this->tenantIdColumn}", $tenantId);
        }

        return $data;
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

        $data['data'][$this->tenantIdColumn] = $tenantId;

        return $data;
    }

    protected function protectTenantId(array $data): array
    {
        if (TenantContextState::isBypassingTenantFilter() || $this->isExemptFromTenant()) {
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

    public function find($id = null, $columns = '*')
    {
        if (!TenantContextState::isBypassingTenantFilter() && !$this->isExemptFromTenant()) {
            if ($this->getTenantId() === null) {
                return null;
            }
        }

        return parent::find($id, $columns);
    }

    public function first($columns = '*')
    {
        if (!TenantContextState::isBypassingTenantFilter() && !$this->isExemptFromTenant()) {
            if ($this->getTenantId() === null) {
                return null;
            }
        }

        return parent::first($columns);
    }

    public function countAllResults(bool $reset = true, bool $test = false): int
    {
        if (!TenantContextState::isBypassingTenantFilter() && !$this->isExemptFromTenant()) {
            if ($this->getTenantId() === null) {
                return 0;
            }
        }

        return parent::countAllResults($reset, $test);
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
        $cacheKey = $this->table . '.' . $this->tenantIdColumn;

        if (array_key_exists($cacheKey, self::$tenantColumnCache)) {
            return self::$tenantColumnCache[$cacheKey];
        }

        try {
            $db     = \Config\Database::connect();
            $result = in_array($this->tenantIdColumn, $db->getFieldNames($this->table), true);
        } catch (\Throwable $e) {
            $result = true;
        }

        self::$tenantColumnCache[$cacheKey] = $result;

        return $result;
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
