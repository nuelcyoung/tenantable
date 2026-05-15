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

namespace nuelcyoung\tenantable\Filters;

use CodeIgniter\HTTP\RequestInterface;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Services\TenantResolverCache;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;
use nuelcyoung\tenantable\Exceptions\TenantInactiveException;

/**
 * IdentifyTenant
 *
 * Strategy-driven tenant identification filter.
 * Replaces the individual DomainFilter, SubdomainFilter, etc. with one
 * canonical path while keeping the old aliases as thin wrappers.
 *
 * Usage:
 *   'tenant' => ['except' => ['health']]
 *   'tenant:strategy=domain_or_subdomain' => ['except' => ['health']]
 *   'tenant:strategy=domain,strict=true' => ['except' => ['health']]
 */
class IdentifyTenant extends BaseTenantFilter
{
    protected string $strategy = 'domain_or_subdomain';
    protected bool   $strict   = false;

    protected function configure(array $arguments): void
    {
        parent::configure($arguments);

        if (isset($arguments['strategy'])) {
            $this->strategy = (string) $arguments['strategy'];
        }
        if (isset($arguments['strict'])) {
            $this->strict = filter_var($arguments['strict'], FILTER_VALIDATE_BOOLEAN);
        }
    }

    protected function identify(RequestInterface $request): void
    {
        $host = $this->extractHost($request);

        if ($host === '') {
            return;
        }

        $manager = TenantManager::getInstance();

        // Localhost bypass unless strict
        if (!$this->strict && $manager->isLocalhost($host)) {
            $config = $this->getTenantableConfig();
            $devTenantId = $config->developmentTenantId ?? null;
            if ($devTenantId !== null) {
                $manager->setTenantById((int) $devTenantId);
            }
            return;
        }

        switch ($this->strategy) {
            case 'domain':
                if (!$this->resolveByDomain($host, $manager)) {
                    throw new TenantNotFoundException("No tenant found for domain '{$host}'");
                }
                break;

            case 'subdomain':
                $manager->detectFromSubdomain();
                break;

            case 'domain_or_subdomain':
            default:
                $resolved = $this->resolveByDomain($host, $manager);
                if (!$resolved) {
                    $manager->detectFromSubdomain();
                }
                break;

            case 'request_data':
                $this->resolveByRequestData($request, $manager);
                break;

            case 'path':
                $this->resolveByPath($request, $manager);
                break;
        }
    }

    /**
     * Resolve by full domain. Returns true if resolved.
     */
    protected function resolveByDomain(string $host, TenantManager $manager): bool
    {
        $cache  = TenantResolverCache::getInstance();
        $cached = $cache->resolveByHost($host);

        if ($cached === null) {
            return false;
        }

        if (!empty($cached['tenant']) && is_array($cached['tenant'])) {
            // Populate from cache row — no extra DB read. setTenant() handles
            // the is_active check.
            $manager->setTenant($cached['tenant']);
            return true;
        }

        // Legacy cache shape (id + is_active only) — fall back to a fresh lookup.
        if ($cached['is_active'] !== true) {
            throw new TenantInactiveException("Tenant for domain '{$host}' is inactive");
        }
        $manager->setTenantById($cached['tenant_id']);
        return true;
    }

    protected function resolveByRequestData(RequestInterface $request, TenantManager $manager): void
    {
        $header = $request->getHeaderLine('X-Tenant');
        if (!empty($header)) {
            $manager->setTenantBySubdomain(trim($header));
            return;
        }

        $query = $request->getGet('tenant');
        if (!empty($query)) {
            $manager->setTenantBySubdomain(trim((string) $query));
            return;
        }
    }

    protected function resolveByPath(RequestInterface $request, TenantManager $manager): void
    {
        $segments = array_values(
            array_filter(
                explode('/', trim($request->getUri()->getPath(), '/'))
            )
        );

        if (isset($segments[0])) {
            $manager->setTenantBySubdomain($segments[0]);
        }
    }

    protected function extractHost(RequestInterface $request): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $request->getServer('HTTP_HOST') ?? '';
        return explode(':', (string) $host)[0];
    }

    protected function getTenantableConfig(): object
    {
        try {
            return config(\nuelcyoung\tenantable\Config\Tenantable::class);
        } catch (\Throwable $e) {
            return new class {
                public ?int $developmentTenantId = null;
            };
        }
    }
}
