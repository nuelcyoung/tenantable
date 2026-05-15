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

        $host = $this->extractHost($request);

        // #8 — Validate HTTP_HOST against allowlist
        if (!$this->validateHost($host)) {
            $response = service('response');
            $response->setStatusCode(400);
            return $response->setBody('Bad Request');
        }

        $manager = TenantManager::getInstance();

        // EarlyTenantDetector may have resolved the tenant in pre_system
        // already. If so, skip identify() to avoid a duplicate DB query.
        if (! $manager->hasTenant()) {
            try {
                $this->identify($request);
            } catch (TenantNotFoundException $e) {
                return $this->handleNotFound($request);
            } catch (TenantInactiveException $e) {
                return $this->handleInactive($request);
            }

            if (! $manager->hasTenant()) {
                return; // identification method found no tenant — not an error
            }
        }

        TenantBootstrap::getInstance()->initialize()->boot();

        \CodeIgniter\Events\Events::trigger('tenancyInitialized', new \nuelcyoung\tenantable\Events\TenancyInitialized(
            $manager->getTenantId(),
            $manager->getTenant()
        ));
    }

    final public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Shutdown is handled later by PackageEvents::register() on post_system.
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
    // Host validation (#8)
    // -------------------------------------------------------------------------

    protected function extractHost(RequestInterface $request): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $request->getServer('HTTP_HOST') ?? '';
        return explode(':', (string) $host)[0];
    }

    protected function validateHost(string $host): bool
    {
        return TenantManager::getInstance()->isHostAllowed($host);
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
