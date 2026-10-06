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

namespace nuelcyoung\tenantable\Bootstrap;

use nuelcyoung\tenantable\Support\TenantableConfig;

/**
 * Binds sessions to their tenant: stamps the tenant id into session data and
 * destroys sessions presented to a different tenant, whatever the handler.
 */
class SessionTenantGuard
{
    public const SESSION_KEY = '__tenantable_tenant_id';

    private static ?SessionTenantGuard $instance = null;

    /** Cookie name before SessionSystem applies a tenant-specific name. */
    private ?string $originalCookieName = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /** Capture the inbound cookie name before tenant bootstrap mutates config. */
    public function captureOriginalCookieName(): void
    {
        $this->originalCookieName = null;

        try {
            $config = config('Session');
            $name   = $config->cookieName ?? null;

            if (is_string($name) && $name !== '') {
                $this->originalCookieName = $name;
            }
        } catch (\Throwable $e) {
            // Session may not be configured for this application.
        }
    }

    /** Validate inbound session against active tenant. */
    public function validate(?int $tenantId): bool
    {
        if ($tenantId === null || ! $this->isEnabled()) {
            return true;
        }

        if (! $this->requestCarriesSessionCookie()) {
            return true;
        }

        $session = $this->resolveSession();

        if ($session === null) {
            return true;
        }

        $stamped = $session->get(self::SESSION_KEY);

        if ($stamped === null) {
            if ($this->rejectsUnboundSessions()) {
                log_message('warning', "Tenantable: unbound session presented on tenant {$tenantId}; destroying (rejectUnboundSessions is enabled).");
                $this->destroySession($session);
                return false;
            }

            // Unbound session: adopt for this tenant.
            $session->set(self::SESSION_KEY, $tenantId);
            return true;
        }

        if ((int) $stamped === $tenantId) {
            return true;
        }

        log_message('warning', "Tenantable: session bound to tenant {$stamped} presented on tenant {$tenantId}; destroying session.", [
            'bound_tenant_id'  => (int) $stamped,
            'active_tenant_id' => $tenantId,
        ]);

        $this->destroySession($session);

        return false;
    }

    /** Stamp an unbound session. Never starts a new session. */
    public function stamp(?int $tenantId): void
    {
        if ($tenantId === null || ! $this->isEnabled()) {
            return;
        }

        $session = $this->sessionIfStarted();

        if ($session === null) {
            return;
        }

        if ($session->get(self::SESSION_KEY) === null) {
            $session->set(self::SESSION_KEY, $tenantId);
        }
    }

    protected function isEnabled(): bool
    {
        try {
            return TenantableConfig::get()->bindSessionsToTenant;
        } catch (\Throwable $e) {
            return true;
        }
    }

    protected function rejectsUnboundSessions(): bool
    {
        try {
            return TenantableConfig::get()->rejectUnboundSessions;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function requestCarriesSessionCookie(): bool
    {
        $names = [
            $this->sessionCookieName(),
            $this->originalCookieName,
        ];

        if (function_exists('session_name')) {
            $names[] = session_name();
        }

        foreach (array_filter($names, static fn ($name): bool => is_string($name) && $name !== '') as $name) {
            if (isset($_COOKIE[$name])) {
                return true;
            }
        }

        // A session may predate SessionSystem's cookie rename; detect
        // package-issued names without trusting request-supplied names.
        foreach (array_keys($_COOKIE) as $name) {
            if (preg_match('/^tenant_\d+_session$/D', (string) $name) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function sessionCookieName(): string
    {
        $config = config('Session');
        $name   = $config->cookieName ?? null;

        return (is_string($name) && $name !== '') ? $name : 'ci_session';
    }

    /** Resolve session service. Only called when cookie is present. */
    protected function resolveSession(): ?object
    {
        try {
            if (function_exists('session')) {
                return session();
            }
        } catch (\Throwable $e) {
            log_message('error', "Tenantable: SessionTenantGuard could not resolve session: {$e->getMessage()}");
        }

        return null;
    }

    /** Return session only if already started. Never instantiate here. */
    protected function sessionIfStarted(): ?object
    {
        if (! class_exists('Config\Services')) {
            return null;
        }

        try {
            $ref  = new \ReflectionClass('Config\Services');
            $prop = $ref->getProperty('instances');
            $prop->setAccessible(true);
            $instances = (array) $prop->getValue();

            $session = $instances['session'] ?? null;

            return is_object($session) ? $session : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function destroySession(object $session): void
    {
        try {
        // Clear $_SESSION so downstream code sees no data after destroy.
        $_SESSION = [];

            if (method_exists($session, 'destroy')) {
                $session->destroy();
            }
        } catch (\Throwable $e) {
            log_message('error', "Tenantable: SessionTenantGuard failed to destroy session: {$e->getMessage()}");
        }
    }
}
