<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Filters;

/**
 * DomainFilter
 *
 * Identifies the tenant from the full hostname (custom domain mapping).
 *
 * @deprecated Use IdentifyTenant with strategy='domain' instead.
 */
class DomainFilter extends IdentifyTenant
{
    protected string $strategy = 'domain';
}
