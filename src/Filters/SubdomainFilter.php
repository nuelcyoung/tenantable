<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Filters;

/**
 * SubdomainFilter
 *
 * Identifies the tenant from the subdomain portion of the request host.
 *
 *   school.myapp.com  →  subdomain = 'school'
 *
 * @deprecated Use IdentifyTenant with strategy='subdomain' instead.
 */
class SubdomainFilter extends IdentifyTenant
{
    protected string $strategy = 'subdomain';
}
