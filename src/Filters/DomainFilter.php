<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Filters;

use CodeIgniter\HTTP\RequestInterface;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Models\TenantModel;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;
use nuelcyoung\tenantable\Exceptions\TenantInactiveException;

/**
 * DomainFilter
 *
 * Identifies the tenant from the full hostname (custom domain mapping).
 * The `domain` column on the tenants table must match the full request host.
 *
 *   myschool.com  →  looks up tenants WHERE domain = 'myschool.com'
 *
 * You need to add a `domain` column to the tenants table migration:
 *   'domain' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'unique' => true]
 *
 * Register as filter alias 'tenant_domain'.
 */
class DomainFilter extends BaseTenantFilter
{
    /**
     * Column name on the tenants table that stores the full custom domain.
     */
    public static string $domainColumn = 'domain';

    protected function identify(RequestInterface $request): void
    {
        $host = $this->extractHost($request);

        if ($host === '') {
            return; // No host — skip silently
        }

        $model  = new TenantModel();
        $tenant = $model->where(static::$domainColumn, $host)->first();

        if ($tenant === null) {
            throw new TenantNotFoundException("No tenant found for domain '{$host}'");
        }

        if (($tenant['is_active'] ?? true) === false) {
            throw new TenantInactiveException("Tenant for domain '{$host}' is inactive");
        }

        TenantManager::getInstance()->setTenantById((int) $tenant['id']);
    }

    protected function extractHost(RequestInterface $request): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $request->getServer('HTTP_HOST') ?? '';
        return explode(':', (string) $host)[0]; // strip port
    }
}
