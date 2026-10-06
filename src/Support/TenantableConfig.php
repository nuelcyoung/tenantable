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

use nuelcyoung\tenantable\Config\Tenantable as PackageConfig;

/**
 * Resolves the active Tenantable config.
 *
 * Uses the app's config if published, otherwise the package default.
 */
final class TenantableConfig
{
    /** The active config instance. */
    public static function get(): PackageConfig
    {
        if (class_exists(\Config\Tenantable::class)) {
            return new \Config\Tenantable();
        }

        return new PackageConfig();
    }
}
