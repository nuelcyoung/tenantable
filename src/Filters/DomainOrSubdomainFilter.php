<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Filters;

use CodeIgniter\HTTP\RequestInterface;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Models\TenantModel;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;
use nuelcyoung\tenantable\Exceptions\TenantInactiveException;

/**
 * DomainOrSubdomainFilter
 *
 * Tries full-domain identification first; falls back to subdomain.
 * Equivalent to stancl's InitializeTenancyByDomainOrSubdomain.
 *
 * Rules (same as stancl):
 *   - hosts containing a dot in the stored value → full domain match
 *   - hosts without a dot → subdomain match
 *
 * Register as filter alias 'tenant_domain_or_subdomain'.
 */
class DomainOrSubdomainFilter extends BaseTenantFilter
{
    public static string $domainColumn = 'domain';

    protected function identify(RequestInterface $request): void
    {
        $host = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];

        if ($host === '') {
            return;
        }

        // Try full domain first
        $model  = new TenantModel();
        $tenant = $model->where(static::$domainColumn, $host)->first();

        if ($tenant !== null) {
            if (($tenant['is_active'] ?? true) === false) {
                throw new TenantInactiveException("Tenant for domain '{$host}' is inactive");
            }
            TenantManager::getInstance()->setTenantById((int) $tenant['id']);
            return;
        }

        // Fall back to subdomain detection
        TenantManager::getInstance()->detectFromSubdomain();
    }
}
