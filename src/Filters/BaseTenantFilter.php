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

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use nuelcyoung\tenantable\Bootstrap\TenantBootstrap;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;
use nuelcyoung\tenantable\Exceptions\TenantInactiveException;
use nuelcyoung\tenantable\Exceptions\TenantAccessDeniedException;
use nuelcyoung\tenantable\Support\TenantableConfig;
use CodeIgniter\Events\Events;

/** Abstract base for identification filters. Only identify() is abstract. */
abstract class BaseTenantFilter implements FilterInterface
{
    protected array   $bypassRoutes    = [];
    protected bool    $throwExceptions = false;
    protected ?string $notFoundView    = null;
    protected ?string $inactiveView    = null;

    final public function before(RequestInterface $request, $arguments = null)
    {
        if ($request instanceof \CodeIgniter\HTTP\CLIRequest) {
            return;
        }

        $this->loadConfigDefaults();

        if ($arguments !== null) {
            $this->configure($this->normalizeArguments((array) $arguments));
        }

        if ($this->shouldBypass($request->getUri()->getPath())) {
            return;
        }

        $host = $this->extractHost($request);

        if (!$this->validateHost($host)) {
            $response = service('response');
            $response->setStatusCode(400);
            return $response->setBody('Bad Request');
        }

        $manager = TenantManager::getInstance();

        // Skip if early detection already resolved.
        $hasTenant = $manager->hasTenant();

        if (! $hasTenant) {
            try {
                $this->identify($request);
            } catch (TenantNotFoundException $e) {
                return $this->handleNotFound($request);
            } catch (TenantInactiveException $e) {
                return $this->handleInactive($request);
            } catch (TenantAccessDeniedException $e) {
                return $this->handleAccessDenied($request);
            }

            $hasTenant = $manager->hasTenant();
        }

        if (! $hasTenant) {
            $config = TenantableConfig::get();

            // Local dev may run without a tenant. Stop here otherwise.
            if ($config->allowLocalhost && TenantManager::getInstance()->isLocalhost($host)) {
                return;
            }

            return $this->handleNotFound($request);
        }

        try {
            // Authorize even if early detection resolved. Routing is not authorization.
            $this->authorizeTenant($request, $manager);

            TenantBootstrap::getInstance()->initialize()->boot();

            Events::trigger('tenancyInitialized', new \nuelcyoung\tenantable\Events\TenancyInitialized(
                $manager->getTenantId(),
                $manager->getTenant()
            ));
        } catch (TenantAccessDeniedException $e) {
            return $this->handleAccessDenied($request);
        }
    }

    final public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    /** Identify the tenant from the request. */
    abstract protected function identify(RequestInterface $request): void;

    /** Hook for authorization. */
    protected function authorizeTenant(RequestInterface $request, TenantManager $manager): void
    {
    }

    protected function loadConfigDefaults(): void
    {
        try {
            $config = TenantableConfig::get();
            $this->throwExceptions = $config->throwExceptions;
            $this->notFoundView    = $config->notFoundView    ?? null;
            $this->inactiveView    = $config->inactiveView    ?? null;
            $this->bypassRoutes    = $config->bypassRoutes;
        } catch (\Throwable $e) {}
    }

    /** Normalize filter arguments (key=value tokens to assoc array). */
    protected function normalizeArguments(array $arguments): array
    {
        $normalized = [];
        $positional = [];

        foreach ($arguments as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
                continue;
            }

            if (is_string($value) && str_contains($value, '=')) {
                [$k, $v] = explode('=', $value, 2);
                $normalized[trim($k)] = trim($v);
                continue;
            }

            $positional[] = $value;
        }

        if ($positional !== []) {
            $normalized['_positional'] = $positional;
        }

        return $normalized;
    }

    protected function configure(array $arguments): void
    {
        if (isset($arguments['bypass'])) {
            $this->bypassRoutes = is_array($arguments['bypass'])
                ? $arguments['bypass']
                : [$arguments['bypass']];
        }
        if (isset($arguments['throw_exceptions'])) {
            $this->throwExceptions = (bool) $arguments['throw_exceptions'];
        }
        if (isset($arguments['not_found_view'])) {
            $this->notFoundView = $arguments['not_found_view'];
        }
        if (isset($arguments['inactive_view'])) {
            $this->inactiveView = $arguments['inactive_view'];
        }
    }

    protected function shouldBypass(string $uriPath): bool
    {
        $uriPath = ltrim($uriPath, '/');

        foreach ($this->bypassRoutes as $pattern) {
            if (fnmatch($pattern, $uriPath)) {
                return true;
            }
        }
        return false;
    }

    protected function extractHost(RequestInterface $request): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $request->getServer('HTTP_HOST') ?? '';

        return TenantManager::getInstance()->normalizeHost((string) $host) ?? '';
    }

    protected function validateHost(string $host): bool
    {
        return TenantManager::getInstance()->isHostAllowed($host);
    }
    protected function handleNotFound(RequestInterface $request)
    {
        if ($this->throwExceptions) {
            throw new TenantNotFoundException('Tenant not found');
        }

        $response = service('response');
        $response->setStatusCode(404);

        if ($this->notFoundView !== null && view_exists($this->notFoundView)) {
            return view($this->notFoundView);
        }

        return $response->setBody('Tenant not found');
    }

    protected function handleInactive(RequestInterface $request)
    {
        if ($this->throwExceptions) {
            throw new TenantInactiveException('Tenant is inactive');
        }

        $response = service('response');
        $response->setStatusCode(403);

        if ($this->inactiveView !== null && view_exists($this->inactiveView)) {
            return view($this->inactiveView);
        }

        return $response->setBody('Tenant is inactive');
    }

    protected function handleAccessDenied(RequestInterface $request)
    {
        if ($this->throwExceptions) {
            throw new TenantAccessDeniedException('Tenant access denied');
        }

        $response = service('response');
        $response->setStatusCode(403);

        return $response->setBody('Tenant access denied');
    }
}
