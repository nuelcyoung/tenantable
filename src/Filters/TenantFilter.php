<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Filters;

use CodeIgniter\HTTP\RequestInterface;
use nuelcyoung\tenantable\Services\TenantManager;

/**
 * TenantFilter  (default identification filter — subdomain-based)
 *
 * Kept as the default 'tenant' filter alias for backwards compatibility.
 * Simply extends SubdomainFilter.
 *
 * For other identification strategies, use:
 *   - SubdomainFilter         alias: tenant_subdomain
 *   - DomainFilter            alias: tenant_domain
 *   - DomainOrSubdomainFilter alias: tenant_domain_or_subdomain
 *   - PathFilter              alias: tenant_path
 *   - RequestDataFilter       alias: tenant_request
 */
class TenantFilter extends SubdomainFilter
{
    // Inherits everything from SubdomainFilter → BaseTenantFilter.
    // No additional logic needed.
}
