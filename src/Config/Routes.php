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

/**
 * Tenant assets route. Discovered automatically when module route discovery
 * is on; otherwise register it yourself in app/Config/Routes.php. Only added
 * when Config\Tenantable::$tenantAssetsEnabled is true.
 *
 * @var \CodeIgniter\Router\RouteCollection $routes
 */

use nuelcyoung\tenantable\Support\TenantableConfig;

try {
    $tenantableAssets = TenantableConfig::get();
} catch (\Throwable $e) {
    $tenantableAssets = null;
}

if ($tenantableAssets !== null && $tenantableAssets->tenantAssetsEnabled) {
    $route = trim($tenantableAssets->tenantAssetsRoute, '/');

    $routes->get(
        $route . '/(:num)/(:any)',
        '\nuelcyoung\tenantable\Controllers\TenantAssetsController::serve/$1/$2'
    );
}
