<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use nuelcyoung\tenantable\Bootstrap\TenantBootstrap;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;
use nuelcyoung\tenantable\Exceptions\TenantInactiveException;

/**
 * Abstract base class shared by all identification filters.
 *
 * Each concrete filter only needs to implement identify(RequestInterface).
 * All shared logic (bypass routes, error handling, config loading, bootstrap
 * wiring, event dispatch) lives here.
 */
abstract class BaseTenantFilter implements FilterInterface
{
    protected array   $bypassRoutes    = [];
    protected bool    $throwExceptions = false;
    protected ?string $notFoundView    = null;
    protected ?string $inactiveView    = null;

    // -------------------------------------------------------------------------
    // FilterInterface
    // -------------------------------------------------------------------------

    final public function before(RequestInterface $request, $arguments = null)
    {
        if ($request instanceof \CodeIgniter\HTTP\CLIRequest) {
            return;
        }

        $this->loadConfigDefaults();

        if ($arguments !== null) {
            $this->configure((array) $arguments);
        }

        if ($this->shouldBypass($request->getUri()->getPath())) {
            return;
        }

        try {
            $this->identify($request);
        } catch (TenantNotFoundException $e) {
            return $this->handleNotFound($request);
        } catch (TenantInactiveException $e) {
            return $this->handleInactive($request);
        }

        if (!TenantManager::getInstance()->hasTenant()) {
            return; // identification method found no tenant — not an error
        }

        // Boot all subsystems
        TenantBootstrap::getInstance()->initialize()->boot();

        // Dispatch TenancyInitialized event
        $manager = TenantManager::getInstance();
        \CodeIgniter\Events\Events::trigger('tenancyInitialized', new \nuelcyoung\tenantable\Events\TenancyInitialized(
            $manager->getTenantId(),
            $manager->getTenant()
        ));
    }

    final public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Shutdown handled by TenantSecurityMiddleware::after()
    }

    // -------------------------------------------------------------------------
    // Abstract — concrete filters implement this
    // -------------------------------------------------------------------------

    /**
     * Identify the tenant from the request and call TenantManager accordingly.
     *
     * @throws TenantNotFoundException
     * @throws TenantInactiveException
     */
    abstract protected function identify(RequestInterface $request): void;

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    protected function loadConfigDefaults(): void
    {
        try {
            $config = config(\nuelcyoung\tenantable\Config\Tenantable::class);
            $this->throwExceptions = $config->throwExceptions ?? false;
            $this->notFoundView    = $config->notFoundView    ?? null;
            $this->inactiveView    = $config->inactiveView    ?? null;
        } catch (\Throwable $e) {}
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
        foreach ($this->bypassRoutes as $pattern) {
            if (fnmatch($pattern, $uriPath)) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

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
}
