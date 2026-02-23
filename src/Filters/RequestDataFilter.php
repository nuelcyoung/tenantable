<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Filters;

use CodeIgniter\HTTP\RequestInterface;
use nuelcyoung\tenantable\Services\TenantManager;

/**
 * RequestDataFilter
 *
 * Identifies the tenant from a request header or query parameter.
 * Designed for API / SPA backends where the frontend can't use subdomains.
 *
 * Lookup order (both checked by default):
 *   1. HTTP header  (default: X-Tenant)
 *   2. Query param  (default: tenant)
 *
 * Examples:
 *   GET /api/students  →  Header: X-Tenant: school-alpha
 *   GET /api/students?tenant=school-alpha
 *
 * To disable one lookup method, set its static property to null:
 *   RequestDataFilter::$header         = null;  // header-only disabled
 *   RequestDataFilter::$queryParameter = null;  // query param disabled
 *
 * Register as filter alias 'tenant_request'.
 */
class RequestDataFilter extends BaseTenantFilter
{
    /**
     * HTTP header name to inspect.
     * Set to null to disable header-based identification.
     */
    public static ?string $header = 'X-Tenant';

    /**
     * Query parameter name to inspect.
     * Set to null to disable query-param identification.
     */
    public static ?string $queryParameter = 'tenant';

    protected function identify(RequestInterface $request): void
    {
        $identifier = $this->extractIdentifier($request);

        if ($identifier === null) {
            return; // No tenant info in request — not an error
        }

        TenantManager::getInstance()->setTenantBySubdomain($identifier);
    }

    protected function extractIdentifier(RequestInterface $request): ?string
    {
        // Check header first
        if (static::$header !== null) {
            $value = $request->getHeaderLine(static::$header);
            if (!empty($value)) {
                return trim($value);
            }
        }

        // Fall back to query parameter
        if (static::$queryParameter !== null) {
            $value = $request->getGet(static::$queryParameter);
            if (!empty($value)) {
                return trim((string) $value);
            }
        }

        return null;
    }
}
