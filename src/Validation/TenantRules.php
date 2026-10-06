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

namespace nuelcyoung\tenantable\Validation;

use CodeIgniter\Database\BaseBuilder;
use InvalidArgumentException;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Support\TenantableConfig;
use nuelcyoung\tenantable\Support\TenantContextState;

/**
 * Tenant-scoped versions of CodeIgniter's is_unique/is_not_unique rules for
 * row-level isolation; they add the active tenant_id to the lookup query.
 */
class TenantRules
{
    /**
     * The value is unique within the active tenant's rows.
     *
     * @param array|bool|float|int|object|string|null $str
     * @param string                                  $field table.field[,ignoreField,ignoreValue[,tenantColumn]]
     * @param array<string, mixed>                    $data
     */
    public function is_unique_for_tenant($str, string $field, array $data): bool
    {
        if (is_object($str) || is_array($str)) {
            return false;
        }

        [$builder, $extraField, $extraValue] = $this->prepareScopedQuery($str, $field, $data, __FUNCTION__);

        if ($builder === null) {
            return false;
        }

        // Skip the ignore clause for an unfilled {placeholder} (insert, no id yet).
        if ($this->hasUsableExtra($extraField, $extraValue)) {
            $builder = $builder->where("{$extraField} !=", $extraValue);
        }

        return $builder->get()->getRow() === null;
    }

    /**
     * The value exists within the active tenant's rows.
     *
     * @param array|bool|float|int|object|string|null $str
     * @param string                                  $field table.field[,whereField,whereValue[,tenantColumn]]
     * @param array<string, mixed>                    $data
     */
    public function is_not_unique_for_tenant($str, string $field, array $data): bool
    {
        if (is_object($str) || is_array($str)) {
            return false;
        }

        [$builder, $extraField, $extraValue] = $this->prepareScopedQuery($str, $field, $data, __FUNCTION__);

        if ($builder === null) {
            return false;
        }

        if ($this->hasUsableExtra($extraField, $extraValue)) {
            $builder = $builder->where($extraField, $extraValue);
        }

        return $builder->get()->getRow() !== null;
    }

    /**
     * Build the tenant-scoped lookup. Returns a null builder when there is
     * no active tenant, so both rules fail closed instead of querying all tenants.
     *
     * @param array|bool|float|int|object|string|null $value
     * @param array<string, mixed>                    $data
     * @param string                                  $rule  Calling rule name, for the fail-closed log.
     *
     * @return array{0: BaseBuilder|null, 1: string|null, 2: string|null}
     */
    private function prepareScopedQuery($value, string $field, array $data, string $rule): array
    {
        if (! is_string($value) && $value !== null) {
            $value = (string) $value;
        }

        [$field, $extraField, $extraValue, $tenantColumn] = array_pad(explode(',', $field), 4, null);

        $parts    = explode('.', $field, 3);
        $numParts = count($parts);

        if ($numParts === 3) {
            [$dbGroup, $table, $field] = $parts;
        } elseif ($numParts === 2) {
            [$table, $field] = $parts;
        } else {
            throw new InvalidArgumentException(
                'Tenantable: the field must be in the format "table.field" or "dbGroup.table.field".'
            );
        }

        $dbGroup ??= $data['DBGroup'] ?? null;

        $builder = \Config\Database::connect($dbGroup)
            ->table($table)
            ->select('1')
            ->where($field, $value)
            ->limit(1);

        // Superadmin bypass queries across tenants, so uniqueness does too.
        if (TenantContextState::isBypassingTenantFilter()) {
            return [$builder, $extraField, $extraValue];
        }

        $tenantId = $this->activeTenantId();

        if ($tenantId === null) {
            log_message(
                'warning',
                'Tenantable: {rule} ran with no active tenant; failing closed. '
                . 'Ensure a tenant filter is running, or use the framework\'s unscoped rule.',
                ['rule' => $rule]
            );

            return [null, null, null];
        }

        $column = ($tenantColumn !== null && $tenantColumn !== '')
            ? $tenantColumn
            : $this->defaultTenantColumn();

        return [$builder->where($column, $tenantId), $extraField, $extraValue];
    }

    /** True when the optional extra pair is set and not an unfilled placeholder. */
    private function hasUsableExtra(?string $extraField, ?string $extraValue): bool
    {
        return $extraField !== null && $extraField !== ''
            && $extraValue !== null && $extraValue !== ''
            && preg_match('/^\{(\w+)\}$/', $extraValue) !== 1;
    }

    private function activeTenantId(): ?int
    {
        try {
            return TenantManager::getInstance()->getTenantId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function defaultTenantColumn(): string
    {
        try {
            return TenantableConfig::get()->tenantIdColumn;
        } catch (\Throwable $e) {
            return 'tenant_id';
        }
    }
}
