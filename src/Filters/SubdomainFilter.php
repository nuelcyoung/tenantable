<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Filters;

use CodeIgniter\HTTP\RequestInterface;
use nuelcyoung\tenantable\Services\TenantManager;

/**
 * SubdomainFilter  (was TenantFilter)
 *
 * Identifies the tenant from the subdomain portion of the request host.
 *
 *   school.myapp.com  →  subdomain = 'school'
 *
 * Register as filter alias 'tenant' or 'tenant_subdomain'.
 */
class SubdomainFilter extends BaseTenantFilter
{
    protected function identify(RequestInterface $request): void
    {
        TenantManager::getInstance()->detectFromSubdomain();
    }
}
