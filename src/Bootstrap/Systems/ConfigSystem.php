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

namespace nuelcyoung\tenantable\Bootstrap\Systems;

use nuelcyoung\tenantable\Bootstrap\TenantAwareInterface;
use nuelcyoung\tenantable\Bootstrap\TenantBootstrap;
use nuelcyoung\tenantable\Services\TenantManager;

class ConfigSystem implements TenantAwareInterface
{
    protected array $tenantSettings = [];
    protected ?string $originalBaseURL = null;

    public function boot(?int $tenantId, ?array $tenant): void
    {
        $this->tenantSettings = [];

        $appConfig = config('App');

        if ($tenantId !== null && $appConfig !== null) {
            $manager   = TenantManager::getInstance();
            $subdomain = $manager->getSubdomain();

            if ($subdomain !== null && $subdomain !== '') {
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

                $appConfig->baseURL = $newBaseURL;

                $this->patchSiteURI($newBaseURL, $tenantHost);
            }
        } elseif ($tenantId === null && $this->originalBaseURL !== null && $appConfig !== null) {
            $appConfig->baseURL = $this->originalBaseURL;

            $originalParsed = parse_url($this->originalBaseURL);
            $originalHost   = $originalParsed['host'] ?? 'localhost';
            $this->patchSiteURI($this->originalBaseURL, $originalHost);
        }

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
            if ($this->originalBaseURL !== null) {
                $appConfig->baseURL = $this->originalBaseURL;

                $originalParsed = parse_url($this->originalBaseURL);
                $originalHost   = $originalParsed['host'] ?? 'localhost';
                $this->patchSiteURI($this->originalBaseURL, $originalHost);

                $this->originalBaseURL = null;
            }
        }
    }

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

            try {
                $refClass = new \ReflectionClass($uri);

                if ($refClass->hasProperty('baseURL')) {
                    $prop = $refClass->getProperty('baseURL');

                    if (PHP_VERSION_ID >= 80100 && $prop->isReadOnly() && $prop->isInitialized($uri)) {
                    } else {
                        $prop->setAccessible(true);

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

            $uri->setHost($newHost);

        } catch (\Throwable $e) {
            log_message('debug', 'ConfigSystem::patchSiteURI failed: ' . $e->getMessage());
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $system = TenantBootstrap::getInstance()->getSystem('config');

        if ($system instanceof self) {
            return $system->tenantSettings[$key] ?? $default;
        }

        return $default;
    }

    public static function all(): array
    {
        $system = TenantBootstrap::getInstance()->getSystem('config');

        return $system instanceof self ? $system->tenantSettings : [];
    }
}
