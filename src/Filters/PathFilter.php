<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Filters;

/**
 * PathFilter
 *
 * Identifies the tenant from a URI path segment.
 *
 * @deprecated Use IdentifyTenant with strategy='path' instead.
 */
class PathFilter extends IdentifyTenant
{
    protected string $strategy = 'path';
}
