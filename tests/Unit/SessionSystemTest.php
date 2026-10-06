<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Bootstrap\EarlyTenantDetector;
use nuelcyoung\tenantable\Bootstrap\Systems\SessionSystem;

/**
 * Stand-in for CodeIgniter's Config\Session with the properties the
 * package touches declared (CI4 declares all of these).
 */
class SessionConfigStub
{
    public string $driver     = 'CodeIgniter\Session\Handlers\FileHandler';
    public string $savePath   = '';
    public string $cookieName = 'ci_session';
}

/**
 * SessionSystem with the framework seams replaced for isolated testing.
 */
class TestableSessionSystem extends SessionSystem
{
    public ?object $config          = null;
    public bool $perTenantCookies   = true;

    protected function sessionConfig(): ?object
    {
        return $this->config;
    }

    protected function perTenantCookiesEnabled(): bool
    {
        return $this->perTenantCookies;
    }
}

/**
 * @covers \nuelcyoung\tenantable\Bootstrap\Systems\SessionSystem
 * @covers \nuelcyoung\tenantable\Bootstrap\EarlyTenantDetector
 */
class SessionSystemTest extends TestCase
{
    private TestableSessionSystem $system;
    private SessionConfigStub $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->system = new TestableSessionSystem();
        $this->config = new SessionConfigStub();
        $this->config->savePath = WRITEPATH . 'session';
        $this->system->config   = $this->config;
    }

    // Handler awareness

    public function testFileHandlerGetsTenantSavePath(): void
    {
        $this->system->boot(5, ['name' => 'Test']);

        $this->assertSame(SessionSystem::tenantSavePath(5), $this->config->savePath);
        $this->assertStringContainsString('tenant_5', $this->config->savePath);
        $this->assertDirectoryExists($this->config->savePath);
    }

    public function testDatabaseHandlerSavePathIsNeverRewritten(): void
    {
        $this->config->driver   = 'CodeIgniter\Session\Handlers\DatabaseHandler';
        $this->config->savePath = 'ci_sessions'; // table name, not a directory

        $this->system->boot(5, ['name' => 'Test']);

        $this->assertSame('ci_sessions', $this->config->savePath);
    }

    public function testRedisHandlerSavePathIsNeverRewritten(): void
    {
        $this->config->driver   = 'CodeIgniter\Session\Handlers\RedisHandler';
        $this->config->savePath = 'tcp://127.0.0.1:6379';

        $this->system->boot(5, ['name' => 'Test']);

        $this->assertSame('tcp://127.0.0.1:6379', $this->config->savePath);
    }

    public function testMissingDriverDefaultsToFileHandler(): void
    {
        $config = new class {
            public string $savePath   = '';
            public string $cookieName = 'ci_session';
        };
        $this->system->config = $config;

        $this->system->boot(3, ['name' => 'Test']);

        $this->assertStringContainsString('tenant_3', $config->savePath);
    }

    public function testUsesFileHandlerRecognisesSubclassesByName(): void
    {
        $config = new SessionConfigStub();

        $config->driver = 'App\Session\CustomFileHandler';
        $this->assertTrue(SessionSystem::usesFileHandler($config));

        $config->driver = 'CodeIgniter\Session\Handlers\MemcachedHandler';
        $this->assertFalse(SessionSystem::usesFileHandler($config));
    }

    // =========================================================================
    // Per-tenant cookie names
    // =========================================================================

    public function testBootSetsPerTenantCookieName(): void
    {
        $this->system->boot(5, ['name' => 'Test']);

        $this->assertSame('tenant_5_session', $this->config->cookieName);
    }

    public function testCookieNameSetForNonFileHandlersToo(): void
    {
        $this->config->driver   = 'CodeIgniter\Session\Handlers\DatabaseHandler';
        $this->config->savePath = 'ci_sessions';

        $this->system->boot(5, ['name' => 'Test']);

        $this->assertSame('tenant_5_session', $this->config->cookieName);
    }

    public function testPerTenantCookiesCanBeDisabled(): void
    {
        $this->system->perTenantCookies = false;

        $this->system->boot(5, ['name' => 'Test']);

        $this->assertSame('ci_session', $this->config->cookieName);
    }

    public function testGlobalBootRestoresOriginalCookieName(): void
    {
        $this->system->boot(5, ['name' => 'Test']);
        $this->assertSame('tenant_5_session', $this->config->cookieName);

        $this->system->boot(null, null);

        $this->assertSame('ci_session', $this->config->cookieName);
    }

    // =========================================================================
    // State restore across boot/shutdown cycles
    // =========================================================================

    public function testShutdownRestoresOriginalValues(): void
    {
        $original = $this->config->savePath;

        $this->system->boot(5, ['name' => 'Test']);
        $this->system->shutdown();

        $this->assertSame($original, $this->config->savePath);
        $this->assertSame('ci_session', $this->config->cookieName);
    }

    public function testCapturePreservesOriginalBeforeEarlyDetection(): void
    {
        $original = $this->config->savePath;

        $this->system->captureOriginalState();
        $this->config->savePath   = SessionSystem::tenantSavePath(5);
        $this->config->cookieName = SessionSystem::tenantCookieName(5);
        $this->system->boot(5, ['name' => 'Test']);
        $this->system->shutdown();

        $this->assertSame($original, $this->config->savePath);
        $this->assertSame('ci_session', $this->config->cookieName);
    }

    public function testBlankOriginalSavePathIsRestoredAsBlank(): void
    {
        // SETUP.md tells users to leave savePath blank when early detection
        // manages it; '' must round-trip, not count as "nothing to restore".
        $this->config->savePath = '';

        $this->system->boot(5, ['name' => 'Test']);
        $this->assertNotSame('', $this->config->savePath);

        $this->system->shutdown();

        $this->assertSame('', $this->config->savePath);
    }

    public function testTenantSwitchDoesNotClobberOriginals(): void
    {
        $original = $this->config->savePath;

        // In-process switch: tenant 1 to tenant 2 without a shutdown between
        // (as long-running workers do when switching tenants).
        $this->system->boot(1, ['name' => 'A']);
        $this->system->boot(2, ['name' => 'B']);
        $this->system->shutdown();

        $this->assertSame($original, $this->config->savePath);
        $this->assertSame('ci_session', $this->config->cookieName);
    }

    public function testRunCentralSequenceRestoresOriginals(): void
    {
        $original = $this->config->savePath;

        // Switching back to central context and re-booting the same tenant preserves config
        $this->system->boot(1, ['name' => 'A']);
        $this->system->boot(null, null);
        $this->system->boot(1, ['name' => 'A']);
        $this->system->shutdown();

        $this->assertSame($original, $this->config->savePath);
        $this->assertSame('ci_session', $this->config->cookieName);
    }

    public function testShutdownWithoutBootIsHarmless(): void
    {
        $original = $this->config->savePath;

        $this->system->shutdown();

        $this->assertSame($original, $this->config->savePath);
    }

    public function testNullConfigIsHarmless(): void
    {
        $this->system->config = null;

        $this->system->boot(1, ['name' => 'A']);
        $this->system->shutdown();

        $this->addToAssertionCount(1); // no exception = pass
    }

    // =========================================================================
    // The early tenant detector's session configuration uses the same rules
    // =========================================================================

    public function testEarlyDetectorConfiguresFileHandlerSession(): void
    {
        $config = new SessionConfigStub();
        $config->savePath = '';

        $this->invokeConfigureSession(9, $config);

        $this->assertSame(SessionSystem::tenantSavePath(9), $config->savePath);
        $this->assertSame('tenant_9_session', $config->cookieName);
    }

    public function testEarlyDetectorLeavesDatabaseHandlerSavePathAlone(): void
    {
        $config = new SessionConfigStub();
        $config->driver   = 'CodeIgniter\Session\Handlers\DatabaseHandler';
        $config->savePath = 'ci_sessions';

        $this->invokeConfigureSession(9, $config);

        $this->assertSame('ci_sessions', $config->savePath);
        $this->assertSame('tenant_9_session', $config->cookieName);
    }

    public function testEarlyDetectorAndSessionSystemAgree(): void
    {
        $early  = new SessionConfigStub();
        $filter = new SessionConfigStub();
        $early->savePath = $filter->savePath = WRITEPATH . 'session';

        $this->invokeConfigureSession(4, $early);

        $this->system->config = $filter;
        $this->system->boot(4, ['name' => 'Test']);

        $this->assertSame($early->savePath, $filter->savePath);
        $this->assertSame($early->cookieName, $filter->cookieName);
    }

    private function invokeConfigureSession(?int $tenantId, object $config): void
    {
        $method = new \ReflectionMethod(EarlyTenantDetector::class, 'configureSession');
        $method->setAccessible(true);
        $method->invoke(null, $tenantId, $config);
    }
}
