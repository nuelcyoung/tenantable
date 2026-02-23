<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Middleware;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use nuelcyoung\tenantable\Bootstrap\TenantBootstrap;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Traits\TenantableTrait;

/**
 * TenantSecurityMiddleware
 *
 * Additional security layer that runs after TenantFilter:
 *   - Enforces tenant context is present
 *   - Validates POST/GET tenant_id fields against the resolved tenant (IDOR guard)
 *   - C-2 – Shuts down all TenantBootstrap systems at end of request
 *   - Clears bypass flags at end of request
 *
 * FIX 2.1 – Implements FilterInterface (was missing, causing type error).
 * FIX 2.2 – validateRequestTenantId() uses setGlobal() to actually apply sanitisation.
 * C-2     – after() calls TenantBootstrap::shutdown() to reset all subsystems.
 */
class TenantSecurityMiddleware implements FilterInterface
{
    protected array $exemptRoutes     = [];
    protected array $protectedFields  = ['tenant_id', 'school_id', 'org_id'];
    protected bool  $blockWithoutTenant = true;

    // -------------------------------------------------------------------------
    // FilterInterface
    // -------------------------------------------------------------------------

    public function before(RequestInterface $request, $arguments = null)
    {
        if ($request instanceof \CodeIgniter\HTTP\CLIRequest) {
            return;
        }

        $uri = $request->getUri()->getPath();

        if ($this->isExempt($uri)) {
            return;
        }

        if ($this->canBypass()) {
            return;
        }

        $manager = TenantManager::getInstance();

        if (!$manager->hasTenant()) {
            if ($this->blockWithoutTenant) {
                return $this->denyAccess('No tenant context');
            }
            return;
        }

        // FIX 2.2 – Actually neutralise tampered tenant_id fields
        $this->validateRequestTenantId($request, $manager->getTenantId());
    }

    /**
     * C-2 – Shut down TenantBootstrap systems and clear bypass flags.
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Shutdown all bootstrapped subsystems (restore cache prefix, storage path, etc.)
        TenantBootstrap::getInstance()->shutdown();

        // Clear bypass flag to prevent state bleed in long-running processes
        TenantableTrait::disableTenantBypass();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function isExempt(string $uri): bool
    {
        foreach ($this->exemptRoutes as $pattern) {
            if (fnmatch($pattern, $uri)) {
                return true;
            }
        }
        return false;
    }

    protected function canBypass(): bool
    {
        if (!function_exists('auth')) {
            return false;
        }

        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $config = config(\nuelcyoung\tenantable\Config\Tenantable::class);

        foreach ($config->superadminGroups ?? [] as $group) {
            if ($user->inGroup($group)) {
                return true;
            }
        }

        return false;
    }

    /**
     * FIX 2.2 – Strip tampered tenant_id fields from POST/GET.
     *
     * Original code mutated local array copies (zero effect on the request).
     * Now uses setGlobal() so subsequent getPost()/getGet() return clean data.
     */
    protected function validateRequestTenantId(RequestInterface $request, int $tenantId): void
    {
        $post    = $request->getPost() ?? [];
        $get     = $request->getGet()  ?? [];
        $changed = false;

        foreach ($this->protectedFields as $field) {
            if (isset($post[$field]) && (int) $post[$field] !== $tenantId) {
                log_message('warning', 'Potential tenant_id tampering in POST.', [
                    'user_id'            => function_exists('auth') ? (auth()->id() ?? 'guest') : 'guest',
                    'provided_tenant_id' => $post[$field],
                    'actual_tenant_id'   => $tenantId,
                    'field'              => $field,
                    'uri'                => $request->getUri()->getPath(),
                ]);
                unset($post[$field]);
                $changed = true;
            }

            if (isset($get[$field]) && (int) $get[$field] !== $tenantId) {
                log_message('warning', 'Potential tenant_id tampering in GET.', [
                    'user_id'            => function_exists('auth') ? (auth()->id() ?? 'guest') : 'guest',
                    'provided_tenant_id' => $get[$field],
                    'actual_tenant_id'   => $tenantId,
                    'field'              => $field,
                    'uri'                => $request->getUri()->getPath(),
                ]);
                unset($get[$field]);
                $changed = true;
            }
        }

        if ($changed) {
            $request->setGlobal('post', $post);
            $request->setGlobal('get',  $get);
        }
    }

    protected function denyAccess(string $reason): ResponseInterface
    {
        $response = service('response');
        $response->setStatusCode(403);
        $response->setJSON([
            'error'  => 'Access denied',
            'reason' => $reason,
        ]);

        return $response;
    }
}
