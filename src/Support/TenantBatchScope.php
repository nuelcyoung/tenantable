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

namespace nuelcyoung\tenantable\Support;

use CodeIgniter\Database\BaseConnection;

/**
 * Tenant scoping for batch updates: the framework ignores builder where
 * clauses there, so pre-filter to rows owned by the active tenant instead.
 */
final class TenantBatchScope
{
    /** Keep only rows owned by the active tenant. Rows can be arrays or objects. */
    public static function filterOwnedRows(
        BaseConnection $db,
        string $table,
        string $tenantIdColumn,
        array $set,
        string $index,
        int $tenantId
    ): array {
        $ids = [];

        foreach ($set as $row) {
            $value = self::rowValue($row, $index);

            if ($value !== null) {
                $ids[] = $value;
            }
        }

        if ($ids === []) {
            // Nothing to check. Let updateBatch() handle the error.
            return $set;
        }

        $owned = $db->table($table)
            ->select($index)
            ->whereIn($index, array_unique($ids))
            ->where("{$table}.{$tenantIdColumn}", $tenantId)
            ->get()
            ->getResultArray();

        // Compare as strings: drivers may return ints while the set has strings.
        $ownedIds = array_map(static fn ($value): string => (string) $value, array_column($owned, $index));

        return array_values(array_filter(
            $set,
            static fn ($row): bool => ($value = self::rowValue($row, $index)) !== null
                && in_array((string) $value, $ownedIds, true),
        ));
    }

    /** Read a column from a row (array or object). */
    private static function rowValue(object|array $row, string $column): mixed
    {
        return is_array($row) ? ($row[$column] ?? null) : ($row->{$column} ?? null);
    }
}
