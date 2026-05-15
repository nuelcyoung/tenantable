<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Filters;

/**
 * TenantFilter  (default identification filter — subdomain-based)
 *
 * Kept as the default 'tenant' filter alias for backwards compatibility.
 *
 * @deprecated Use IdentifyTenant with strategy='subdomain' instead.
 */
class TenantFilter extends IdentifyTenant
{
    protected string $strategy = 'subdomain';
}
