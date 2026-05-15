<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Config;

use nuelcyoung\tenantable\Filters\DomainFilter;
use nuelcyoung\tenantable\Filters\DomainOrSubdomainFilter;
use nuelcyoung\tenantable\Filters\IdentifyTenant;
use nuelcyoung\tenantable\Filters\PathFilter;
use nuelcyoung\tenantable\Filters\RequestDataFilter;
use nuelcyoung\tenantable\Filters\SubdomainFilter;
use nuelcyoung\tenantable\Middleware\TenantSecurityMiddleware;

/**
 * Auto-discovered Registrar.
 *
 * CodeIgniter 4 discovers `Config/Registrar.php` across every namespace
 * registered through `extra.codeigniter4` (auto-enabled when
 * `Config\Modules::$discoverInComposer` is true — the default).
 *
 * For each config class, CI calls the method named after the config's
 * short name and merges the returned arrays into the matching property.
 *
 * This Registrar makes Tenantable's filter aliases available everywhere
 * — no need to copy them into `app/Config/Filters.php`.
 */
class Registrar
{
    /**
     * Auto-registers Tenantable filter aliases into Config\Filters::$aliases.
     *
     * @return array{aliases: array<string, class-string>}
     */
    public static function Filters(): array
    {
        return [
            'aliases' => [
                'tenant_subdomain'           => SubdomainFilter::class,
                'tenant_domain'              => DomainFilter::class,
                'tenant_domain_or_subdomain' => DomainOrSubdomainFilter::class,
                'tenant_path'                => PathFilter::class,
                'tenant_request'             => RequestDataFilter::class,
                'tenant_security'            => TenantSecurityMiddleware::class,
                'identify_tenant'            => IdentifyTenant::class,
            ],
        ];
    }
}
