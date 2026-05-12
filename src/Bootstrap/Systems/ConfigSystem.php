<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap\Systems;

use nuelcyoung\tenantable\Bootstrap\TenantAwareInterface;
use nuelcyoung\tenantable\Bootstrap\TenantBootstrap;
use nuelcyoung\tenantable\Services\TenantManager;

/**
 * Merges tenant-level settings (from the tenants.settings JSON column)
 * into the application config so controllers and services can read them.
 *
 * Also rewrites App::$baseURL to include the tenant subdomain so that
 * CI4 URL helpers (site_url, base_url, url_to, redirect) all preserve
 * the current tenant's subdomain.  Without this, Shield's session filter
 * would redirect to the bare base domain (e.g. ci4.test/login instead of
 * foodblog.ci4.test/login).
 *
 * S-1 – Fixed ConfigSystem::get() which called self::getInstance() — a method
 *        that doesn't exist on ConfigSystem. It now routes through TenantBootstrap
 *        to retrieve the live system instance.
 */
class ConfigSystem implements TenantAwareInterface
{
    /** Tenant-specific settings for the current request */
    protected array $tenantSettings = [];

    /** Original baseURL before tenant rewrite (for restore on shutdown) */
    protected ?string $originalBaseURL = null;

    public function boot(?int $tenantId, ?array $tenant): void
    {
        $this->tenantSettings = [];

        $appConfig = config('App');

        // ------------------------------------------------------------------
        // Rewrite baseURL to include the tenant subdomain
        // ------------------------------------------------------------------
        // CI4's SiteURI is constructed during bootstrap (before filters) and
        // caches baseURL in a private property.  Changing App::$baseURL alone
        // has NO effect on site_url() / url_to() / redirect() because they
        // read from the already-constructed SiteURI.
        //
        // We must:
        //   1. Update App::$baseURL          (for anything that reads config directly)
        //   2. Patch the live SiteURI object  (for site_url / url_to / redirect)
        // ------------------------------------------------------------------
        if ($tenantId !== null && $appConfig !== null) {
            $manager   = TenantManager::getInstance();
            $subdomain = $manager->getSubdomain();

            if ($subdomain !== null && $subdomain !== '') {
                // Save original so we can restore on shutdown / central swap
                if ($this->originalBaseURL === null) {
                    $this->originalBaseURL = $appConfig->baseURL;
                }

                $baseDomain  = $manager->getBaseDomain();
                $parsed      = parse_url($appConfig->baseURL);
                $scheme      = $parsed['scheme'] ?? 'http';
                $port        = isset($parsed['port']) ? ':' . $parsed['port'] : '';
                $path        = rtrim($parsed['path'] ?? '/', '/') . '/';
                $tenantHost  = "{$subdomain}.{$baseDomain}";
                $newBaseURL  = "{$scheme}://{$tenantHost}{$port}{$path}";

                // 1. Config-level (belt-and-suspenders)
                $appConfig->baseURL = $newBaseURL;

                // 2. Patch the live SiteURI on the current IncomingRequest
                $this->patchSiteURI($newBaseURL, $tenantHost);
            }
        } elseif ($tenantId === null && $this->originalBaseURL !== null && $appConfig !== null) {
            // Returning to central context — restore original baseURL
            $appConfig->baseURL = $this->originalBaseURL;

            $originalParsed = parse_url($this->originalBaseURL);
            $originalHost   = $originalParsed['host'] ?? 'localhost';
            $this->patchSiteURI($this->originalBaseURL, $originalHost);
        }

        // ------------------------------------------------------------------
        // Merge tenant-level settings
        // ------------------------------------------------------------------
        if ($tenantId === null || empty($tenant['settings'])) {
            return;
        }

        $settings = is_array($tenant['settings'])
            ? $tenant['settings']
            : json_decode((string) $tenant['settings'], true);

        if (is_array($settings)) {
            $this->tenantSettings = $settings;
        }
    }

    public function shutdown(): void
    {
        $this->tenantSettings = [];

        $appConfig = config('App');
        if ($appConfig !== null) {
            // Restore original baseURL
            if ($this->originalBaseURL !== null) {
                $appConfig->baseURL = $this->originalBaseURL;

                $originalParsed = parse_url($this->originalBaseURL);
                $originalHost   = $originalParsed['host'] ?? 'localhost';
                $this->patchSiteURI($this->originalBaseURL, $originalHost);

                $this->originalBaseURL = null;
            }
        }
    }

    // -------------------------------------------------------------------------
    // SiteURI patching
    // -------------------------------------------------------------------------

    /**
     * Patch the live SiteURI on the current IncomingRequest.
     *
     * CI4's SiteURI caches `baseURL` in a **private** property during
     * construction (which happens in the bootstrap phase, before any filter
     * runs). All URL helpers — site_url(), base_url(), url_to(), redirect()
     * — read from this cached SiteURI, NOT from App::$baseURL.
     *
     * The only way to make those helpers honour the tenant subdomain is to
     * patch the live SiteURI object via reflection.
     */
    protected function patchSiteURI(string $newBaseURL, string $newHost): void
    {
        try {
            $request = \Config\Services::request();

            if ($request instanceof \CodeIgniter\HTTP\CLIRequest) {
                return;
            }

            $uri = $request->getUri();

            if (! $uri instanceof \CodeIgniter\HTTP\SiteURI) {
                return;
            }

            // SiteURI::$baseURL is private readonly URI — reflection patch may 
            // fail on PHP 8.1+ (readonly) or due to type mismatch (string vs URI).
            // This is non-critical because siteUrl()/baseUrl() create new SiteURI 
            // instances from the updated config, so the stale $baseURL on the live 
            // object only affects getBaseURL() calls (internal).
            try {
                $refClass = new \ReflectionClass($uri);

                if ($refClass->hasProperty('baseURL')) {
                    $prop = $refClass->getProperty('baseURL');

                    // Skip readonly properties — they cannot be modified once
                    // initialized (PHP 8.1+ / CI4 >= 4.5). Host rewrite below
                    // is sufficient for tenant-aware URL generation.
                    if (PHP_VERSION_ID >= 80100 && $prop->isReadOnly() && $prop->isInitialized($uri)) {
                        // no-op: readonly $baseURL is already set
                    } else {
                        $prop->setAccessible(true);

                        // In CI4 >= 4.5, $baseURL is typed as URI (not string).
                        // Build a URI object so the type matches.
                        $baseURLValue = $newBaseURL;

                        if (method_exists($uri, 'getBaseURL')) {
                            $baseURLValue = new \CodeIgniter\HTTP\URI($newBaseURL);
                        }

                        $prop->setValue($uri, $baseURLValue);
                    }
                }
            } catch (\Throwable $e) {
                log_message('debug', 'ConfigSystem::patchSiteURI (baseURL reflection): ' . $e->getMessage());
            }

            // Always update the host — this is critical so that site_url(), 
            // base_url(), and url_to() generate tenant-aware URLs.
            $uri->setHost($newHost);

        } catch (\Throwable $e) {
            log_message('debug', 'ConfigSystem::patchSiteURI failed: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Static accessors
    // -------------------------------------------------------------------------

    /**
     * Read a single tenant setting by key.
     *
     * S-1 – Was calling self::getInstance() which doesn't exist on ConfigSystem.
     *        Now correctly retrieves the live system from TenantBootstrap.
     *
     * @param string $key      Setting key
     * @param mixed  $default  Default value when key is absent
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $system = TenantBootstrap::getInstance()->getSystem('config');

        if ($system instanceof self) {
            return $system->tenantSettings[$key] ?? $default;
        }

        return $default;
    }

    /**
     * Get all tenant settings for the current request.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $system = TenantBootstrap::getInstance()->getSystem('config');

        return $system instanceof self ? $system->tenantSettings : [];
    }
}
