<?php

/**
 * This file is part of the Tenantable.
 *
 * (c) Nuel Young Chukwunalu <nuelmega@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace nuelcyoung\tenantable\Config;

use nuelcyoung\tenantable\Filters\DomainFilter;
use nuelcyoung\tenantable\Filters\DomainOrSubdomainFilter;
use nuelcyoung\tenantable\Filters\IdentifyTenant;
use nuelcyoung\tenantable\Filters\OriginHeaderFilter;
use nuelcyoung\tenantable\Filters\PathFilter;
use nuelcyoung\tenantable\Filters\RequestDataFilter;
use nuelcyoung\tenantable\Filters\SubdomainFilter;
use nuelcyoung\tenantable\Filters\TenantFilter;
use nuelcyoung\tenantable\Filters\TenantSecurityFilter;
use nuelcyoung\tenantable\Validation\TenantRules;

/**
 * Auto-discovered Registrar.
 *
 * CI4 discovers this file across all registered namespaces and merges
 * the returned arrays into the matching config property.
 */
class Registrar
{
    /** Register Tenantable filter aliases. */
    public static function Filters(): array
    {
        return [
            'aliases' => [
                'tenant'                     => TenantFilter::class,
                'tenant_subdomain'           => SubdomainFilter::class,
                'tenant_domain'              => DomainFilter::class,
                'tenant_domain_or_subdomain' => DomainOrSubdomainFilter::class,
                'tenant_path'                => PathFilter::class,
                'tenant_request'             => RequestDataFilter::class,
                'tenant_origin'              => OriginHeaderFilter::class,
                'tenant_security'            => TenantSecurityFilter::class,
                'identify_tenant'            => IdentifyTenant::class,
            ],
        ];
    }

    /**
     * Register the tenant-scoped validation rules, appended to the app's
     * rule sets so is_unique_for_tenant needs no app config.
     */
    public static function Validation(): array
    {
        return [
            'ruleSets' => [
                TenantRules::class,
            ],
        ];
    }
}
