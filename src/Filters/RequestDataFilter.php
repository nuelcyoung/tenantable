<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Filters;

/**
 * RequestDataFilter
 *
 * Identifies the tenant from a request header or query parameter.
 *
 * @deprecated Use IdentifyTenant with strategy='request_data' instead.
 */
class RequestDataFilter extends IdentifyTenant
{
    protected string $strategy = 'request_data';
}
