<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use nuelcyoung\tenantable\Controllers\TenantAssetsController;
use nuelcyoung\tenantable\Services\TenantManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \nuelcyoung\tenantable\Controllers\TenantAssetsController
 */
class TenantAssetsControllerTest extends TestCase
{
    private const TENANT_ID = 999991;

    /** @var array<int, mixed> */
    private array $statusCodes = [];

    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<string, string> */
    private array $contentTypes = [];

    /** @var list<string> */
    private array $bodies = [];

    private string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();

        TenantManager::resetInstance();

        $this->tenantDir = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'tenant_' . self::TENANT_ID;

        if (! is_dir($this->tenantDir)) {
            mkdir($this->tenantDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        TenantManager::resetInstance();

        $this->removeDirectory($this->tenantDir);

        parent::tearDown();
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($directory);
    }

    /**
     * @return array{0: TenantAssetsController, 1: ResponseInterface}
     */
    private function controller(string $ifNoneMatch = ''): array
    {
        $this->statusCodes = [];
        $this->headers     = [];
        $this->contentTypes = [];
        $this->bodies      = [];

        $response = $this->createMock(ResponseInterface::class);

        $response->method('setStatusCode')->willReturnCallback(function (int $code) use ($response) {
            $this->statusCodes[] = $code;
            return $response;
        });

        $response->method('setHeader')->willReturnCallback(function (string $name, string $value) use ($response) {
            $this->headers[$name] = $value;
            return $response;
        });

        $response->method('setContentType')->willReturnCallback(function (string $type) use ($response) {
            $this->contentTypes[] = $type;
            return $response;
        });

        $response->method('setBody')->willReturnCallback(function (string $body) use ($response) {
            $this->bodies[] = $body;
            return $response;
        });

        $request = $this->createMock(IncomingRequest::class);
        $request->method('getHeaderLine')->willReturnCallback(function (string $name) use ($ifNoneMatch): string {
            return $name === 'If-None-Match' ? $ifNoneMatch : '';
        });

        $controller = new TenantAssetsController();
        $controller->initController($request, $response, $this->createMock(LoggerInterface::class));

        return [$controller, $response];
    }

    private function activateTenant(int $tenantId = self::TENANT_ID): void
    {
        TenantManager::getInstance()->setTenant([
            'id'        => $tenantId,
            'subdomain' => 'testtenant',
            'is_active' => 1,
        ]);
    }

    private function writeAsset(string $relativePath, string $contents): string
    {
        $path = $this->tenantDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $dir  = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    public function testServesAssetForActiveTenant(): void
    {
        $this->activateTenant();
        $this->writeAsset('img/logo.png', 'png-bytes');

        [$controller] = $this->controller();

        $controller->serve((string) self::TENANT_ID, 'img/logo.png');

        $this->assertSame([200], $this->statusCodes);
        $this->assertSame(['png-bytes'], $this->bodies);
        $this->assertContains('image/png', $this->contentTypes);
        $this->assertSame('9', $this->headers['Content-Length'] ?? null);
        $this->assertArrayHasKey('ETag', $this->headers);
        $this->assertSame('public, max-age=86400', $this->headers['Cache-Control'] ?? null);
    }

    public function testDetectsMimeTypesByExtension(): void
    {
        $this->activateTenant();
        $this->writeAsset('app.css', 'body{}');

        [$controller] = $this->controller();

        $controller->serve((string) self::TENANT_ID, 'app.css');

        $this->assertContains('text/css', $this->contentTypes);
    }

    public function testServesNestedPaths(): void
    {
        $this->activateTenant();
        $this->writeAsset('css/theme/dark.css', 'a{}');

        [$controller] = $this->controller();

        $controller->serve((string) self::TENANT_ID, 'css/theme/dark.css');

        $this->assertSame([200], $this->statusCodes);
    }

    public function testMissingFileIsNotFound(): void
    {
        $this->activateTenant();

        [$controller] = $this->controller();

        $controller->serve((string) self::TENANT_ID, 'img/nope.png');

        $this->assertSame([404], $this->statusCodes);
    }

    public function testParentTraversalIsBlocked(): void
    {
        $this->activateTenant();
        $this->writeAsset('secret.txt', 'top secret');
        file_put_contents(WRITEPATH . 'uploads' . 'tenantable_traversal_probe.txt', 'must-not-leak');

        [$controller] = $this->controller();

        try {
            $controller->serve((string) self::TENANT_ID, '../tenantable_traversal_probe.txt');
            $this->assertSame([404], $this->statusCodes);

            $controller->serve((string) self::TENANT_ID, 'foo/../../uploads/tenantable_traversal_probe.txt');
            $this->assertSame([404, 404], $this->statusCodes);

            $controller->serve((string) self::TENANT_ID, 'sub/../../secret.txt');
            $this->assertSame([404, 404, 404], $this->statusCodes);
        } finally {
            @unlink(WRITEPATH . 'uploads' . 'tenantable_traversal_probe.txt');
        }
    }

    public function testBackslashTraversalIsBlocked(): void
    {
        $this->activateTenant();

        [$controller] = $this->controller();

        $controller->serve((string) self::TENANT_ID, '..\\..\\env');

        $this->assertSame([404], $this->statusCodes);
    }

    public function testDotAndEmptySegmentsAreBlocked(): void
    {
        $this->activateTenant();
        $this->writeAsset('a.txt', 'x');

        [$controller] = $this->controller();

        $controller->serve((string) self::TENANT_ID, './a.txt');
        $this->assertSame([404], $this->statusCodes);

        $controller->serve((string) self::TENANT_ID, 'a.txt/.');
        $this->assertSame([404, 404], $this->statusCodes);
    }

    public function testOtherTenantIsBlockedWhenTenantContextIsActive(): void
    {
        $this->activateTenant(self::TENANT_ID);
        $this->writeAsset('img/logo.png', 'png-bytes');

        // Another tenant's id: must not serve our files.
        [$controller] = $this->controller();

        $controller->serve((string) (self::TENANT_ID + 1), 'img/logo.png');

        $this->assertSame([404], $this->statusCodes);
    }

    public function testUnknownTenantWithoutContextIsNotFound(): void
    {
        // No tenant context: central lookup hits the (table-less) test DB.
        [$controller] = $this->controller();

        $controller->serve('12345', 'img/logo.png');

        $this->assertSame([404], $this->statusCodes);
    }

    public function testInvalidTenantIdIsNotFound(): void
    {
        [$controller] = $this->controller();

        $controller->serve('0', 'img/logo.png');

        $this->assertSame([404], $this->statusCodes);
    }

    public function testMatchingEtagReturnsNotModified(): void
    {
        $this->activateTenant();
        $this->writeAsset('img/logo.png', 'png-bytes');

        [$controller] = $this->controller();

        // First request captures the ETag the controller generated.
        $controller->serve((string) self::TENANT_ID, 'img/logo.png');
        $etag = $this->headers['ETag'] ?? '';
        $this->assertNotSame('', $etag);

        // Replay with a matching If-None-Match.
        [$controller] = $this->controller($etag);
        $controller->serve((string) self::TENANT_ID, 'img/logo.png');

        $this->assertSame([200, 304], $this->statusCodes);
        $this->assertSame([], $this->bodies);
    }
}
