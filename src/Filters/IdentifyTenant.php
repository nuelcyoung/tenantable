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
use nuelcyoung\tenantable\Exceptions\TenantAccessDeniedException;
use nuelcyoung\tenantable\Support\TenantableConfig;

/** Strategy-driven tenant identification. */
class IdentifyTenant extends BaseTenantFilter
{
    protected string $strategy = 'domain_or_subdomain';
    protected bool   $strict   = false;

    private const STRATEGIES = ['domain', 'subdomain', 'domain_or_subdomain', 'request_data', 'path', 'origin'];

    protected function configure(array $arguments): void
    {
        parent::configure($arguments);

        if (isset($arguments['strategy'])) {
            $this->strategy = (string) $arguments['strategy'];
        } else {
            foreach ($arguments['_positional'] ?? [] as $token) {
                if (is_string($token) && in_array($token, self::STRATEGIES, true)) {
                    $this->strategy = $token;
                    break;
                }
            }
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

        $config = $this->getTenantableConfig();

        if (!$this->strict && ($config->allowLocalhost ?? false) && $manager->isLocalhost($host)) {
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

            case 'origin':
                if (!$this->resolveByOrigin($request, $manager)) {
                    throw new TenantNotFoundException('No tenant found for the request Origin header');
                }
                break;
        }

        if ($manager->hasTenant()) {
            $this->authorizeTenant($request, $manager);
        }
    }

    /** Resolve a tenant by its domain. */
    protected function resolveByDomain(string $host, TenantManager $manager): bool
    {
        $cache  = TenantResolverCache::getInstance();
        $cached = $cache->resolveByHost($host);

        if ($cached === null) {
            return false;
        }

        if (!empty($cached['tenant']) && is_array($cached['tenant'])) {
            $manager->setTenant($cached['tenant']);
            return true;
        }

        // Old cache format. Look it up again.
        if (empty($cached['is_active'])) {
            throw new TenantInactiveException("Tenant for domain '{$host}' is inactive");
        }
        $manager->setTenantById($cached['tenant_id']);
        return true;
    }

    /**
     * Resolve a tenant from the Origin header's host, for browser API
     * clients on a shared API domain, avoiding a CORS preflight.
     */
    protected function resolveByOrigin(RequestInterface $request, TenantManager $manager): bool
    {
        $origin = trim((string) $request->getHeaderLine('Origin'));

        if ($origin === '' || strtolower($origin) === 'null') {
            return false;
        }

        $host = parse_url($origin, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = $manager->normalizeHost($host) ?? '';

        if ($host === '') {
            return false;
        }

        return $this->resolveByDomain($host, $manager);
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

        $body = $request->getPost('tenant');
        if (empty($body) && method_exists($request, 'getJsonVar')) {
            $body = $request->getJsonVar('tenant');
        }
        if (!empty($body) && (is_string($body) || is_numeric($body))) {
            $manager->setTenantBySubdomain(trim((string) $body));
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

        return TenantManager::getInstance()->normalizeHost((string) $host) ?? '';
    }

    /** Request/path selectors require an authorization callback. */
    protected function authorizeTenant(RequestInterface $request, TenantManager $manager): void
    {
        $config     = $this->getTenantableConfig();
        $authorizer = $config->tenantAuthorizer ?? null;

        if (! is_callable($authorizer) && in_array($this->strategy, ['request_data', 'path'], true)) {
            $tenantId = $manager->getTenantId();
            $manager->clear();

            throw TenantAccessDeniedException::forTenant((int) $tenantId);
        }

        if (! is_callable($authorizer)) {
            return;
        }

        $tenant = $manager->getTenant();
        $tenantId = $manager->getTenantId();

        try {
            $allowed = is_array($tenant) && $authorizer($request, $tenant);
        } catch (\Throwable $e) {
            $allowed = false;
            log_message('warning', 'Tenant authorization callback failed.', [
                'tenant_id' => $tenantId,
                'exception' => $e,
            ]);
        }

        if (! $allowed) {
            $manager->clear();

            throw TenantAccessDeniedException::forTenant((int) $tenantId);
        }
    }

    protected function getTenantableConfig(): object
    {
        try {
            return TenantableConfig::get();
        } catch (\Throwable $e) {
            return new class {
                public ?int $developmentTenantId = null;
            };
        }
    }
}
