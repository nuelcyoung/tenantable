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

namespace nuelcyoung\tenantable\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use nuelcyoung\tenantable\Models\TenantModel;
use nuelcyoung\tenantable\Services\TenantManager;

/**
 * Serves tenant-scoped public files (writable/uploads/tenant_{id}/...) through
 * a central route, so tenant assets work in emails and cross-domain contexts.
 * Paths stay inside the tenant's own upload directory.
 */
class TenantAssetsController extends Controller
{
    /** How long browsers may cache a served asset. */
    protected int $cacheSeconds = 86400;

    /** Serve a tenant asset. Route: GET {tenantAssetsRoute}/(:num)/(:any) */
    public function serve(string $tenantId, string $path): ResponseInterface
    {
        $tenantId = (int) $tenantId;

        if ($tenantId <= 0 || $path === '') {
            return $this->notFound();
        }

        $manager = TenantManager::getInstance();

        if ($manager->hasTenant()) {
            // Never serve another tenant's files from a tenant domain.
            if ($manager->getTenantId() !== $tenantId) {
                return $this->notFound();
            }
        } else {
            // Central context: the tenant must exist and be active.
            try {
                $tenant = (new TenantModel())->find($tenantId);
            } catch (\Throwable $e) {
                $tenant = null;
            }

            if ($tenant === null || empty($tenant['is_active'])) {
                return $this->notFound();
            }
        }

        $filePath = $this->resolveFile($tenantId, $path);

        if ($filePath === null) {
            return $this->notFound();
        }

        return $this->serveFile($filePath);
    }

    /**
     * Resolve a requested path inside the tenant's upload directory;
     * null for anything that escapes it.
     */
    protected function resolveFile(int $tenantId, string $path): ?string
    {
        $segments = explode('/', str_replace('\\', '/', $path));

        foreach ($segments as $segment) {
            // No traversal, no empty or dot segments.
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        $baseDir = \nuelcyoung\tenantable\Bootstrap\Systems\StorageSystem::getStoragePath($tenantId);

        $realBase = realpath($baseDir);
        $realPath = realpath($baseDir . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments));

        if ($realBase === false || $realPath === false) {
            return null;
        }

        // realpath() already resolved symlinks; keep only paths inside the tenant dir.
        if (! str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (! is_file($realPath) || ! is_readable($realPath)) {
            return null;
        }

        return $realPath;
    }

    protected function serveFile(string $filePath): ResponseInterface
    {
        $response = $this->response;

        $etag = $this->buildEtag($filePath);

        $response->setStatusCode(200);
        $response->setContentType($this->detectMimeType($filePath));
        $response->setHeader('Content-Length', (string) filesize($filePath));
        $response->setHeader('Cache-Control', 'public, max-age=' . $this->cacheSeconds);
        $response->setHeader('ETag', $etag);

        if ($this->ifNoneMatchMatches($etag)) {
            // 304 responses must not carry a body.
            return $response->setStatusCode(304);
        }

        $contents = @file_get_contents($filePath);

        if ($contents === false) {
            return $this->notFound();
        }

        return $response->setBody($contents);
    }

    protected function notFound(): ResponseInterface
    {
        return $this->response->setStatusCode(404)->setBody('Not Found');
    }

    /** Weak validation-free ETag from file identity. */
    protected function buildEtag(string $filePath): string
    {
        $stat = @stat($filePath);

        return '"' . md5($filePath . ':' . ($stat['size'] ?? 0) . ':' . ($stat['mtime'] ?? 0)) . '"';
    }

    protected function ifNoneMatchMatches(string $etag): bool
    {
        $clientEtag = '';

        if ($this->request instanceof IncomingRequest) {
            $clientEtag = (string) $this->request->getHeaderLine('If-None-Match');
        } elseif (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
            $clientEtag = trim((string) $_SERVER['HTTP_IF_NONE_MATCH']);
        }

        return $clientEtag !== '' && $clientEtag === $etag;
    }

    /** Extension map first (finfo misreports text formats), fileinfo second. */
    protected function detectMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $types = [
            'avif' => 'image/avif',
            'css'  => 'text/css',
            'csv'  => 'text/csv',
            'gif'  => 'image/gif',
            'htm'  => 'text/html',
            'html' => 'text/html',
            'ico'  => 'image/x-icon',
            'jpeg' => 'image/jpeg',
            'jpg'  => 'image/jpeg',
            'js'   => 'application/javascript',
            'json' => 'application/json',
            'mjs'  => 'application/javascript',
            'mp3'  => 'audio/mpeg',
            'mp4'  => 'video/mp4',
            'pdf'  => 'application/pdf',
            'png'  => 'image/png',
            'svg'  => 'image/svg+xml',
            'txt'  => 'text/plain',
            'webm' => 'video/webm',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
        ];

        if (isset($types[$extension])) {
            return $types[$extension];
        }

        try {
            $mime = (new \CodeIgniter\Files\File($filePath))->getMimeType();

            if ($mime !== '') {
                return $mime;
            }
        } catch (\Throwable $e) {
        }

        return 'application/octet-stream';
    }
}
