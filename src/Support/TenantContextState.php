<?php

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