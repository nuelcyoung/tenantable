<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Filters;

use CodeIgniter\HTTP\RequestInterface;
use nuelcyoung\tenantable\Services\TenantManager;

/**
 * PathFilter
 *
 * Identifies the tenant from a URI path segment.
 * Your routes must be prefixed with the tenant identifier.
 *
 *   GET /school-alpha/dashboard
 *         ^^^^^^^^^^ → subdomain = 'school-alpha'
 *
 * How to use:
 *   1. Register your tenant routes under a (:segment) group:
 *
 *      $routes->group('(:segment)', ['filter' => 'tenant_path'], function($routes) {
 *          $routes->get('dashboard', 'DashboardController::index');
 *      });
 *
 *   2. The tenant segment is extracted from position $segmentIndex (default: 1).
 *      The real URI seen by controllers is the path AFTER the tenant segment —
 *      you will typically still need the full URI for routing; this filter
 *      simply sets the tenant context.
 *
 * Register as filter alias 'tenant_path'.
 */
class PathFilter extends BaseTenantFilter
{
    /**
     * Which URI segment (1-indexed) holds the tenant identifier.
     * Default: 1  →  /{tenant}/...
     */
    public static int $segmentIndex = 1;

    protected function identify(RequestInterface $request): void
    {
        $segments = array_values(
            array_filter(
                explode('/', trim($request->getUri()->getPath(), '/'))
            )
        );

        $index = static::$segmentIndex - 1; // convert to 0-indexed

        if (!isset($segments[$index])) {
            return; // No segment — not an error; just no tenant
        }

        $identifier = $segments[$index];

        TenantManager::getInstance()->setTenantBySubdomain($identifier);
    }
}
