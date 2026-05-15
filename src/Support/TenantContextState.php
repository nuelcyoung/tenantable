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

final class TenantContextState
{
    private static bool $bypassTenantFilter = false;

    public static function enableTenantBypass(): void
    {
        self::$bypassTenantFilter = true;
    }

    public static function disableTenantBypass(): void
    {
        self::$bypassTenantFilter = false;
    }

    public static function isBypassingTenantFilter(): bool
    {
        return self::$bypassTenantFilter;
    }
}
