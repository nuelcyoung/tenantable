<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Bootstrap\SessionTenantGuard;
use nuelcyoung\tenantable\Config\Tenantable as TenantableConfig;

/**
 * Minimal stand-in for CodeIgniter's Session service.
 */
class FakeSession
{
    public array $data      = [];
    public bool  $destroyed = false;

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function destroy(): void
    {
        $this->destroyed = true;
        $this->data      = [];
    }
}

/**
 * Guard with the framework seams (session resolution, cookie sniffing,
 * config) replaced so behaviour can be tested in isolation.
 */
class TestableSessionTenantGuard extends SessionTenantGuard
{
    public ?FakeSession $session          = null;
    public ?FakeSession $startedSession   = null;
    public bool $hasCookie                = true;
    public ?TenantableConfig $config      = null;

    protected function resolveSession(): ?object
    {
        return $this->session;
    }

    protected function sessionIfStarted(): ?object
    {
        return $this->startedSession;
    }

    protected function requestCarriesSessionCookie(): bool
    {
        return $this->hasCookie;
    }

    protected function isEnabled(): bool
    {
        return (bool) (($this->config ?? new TenantableConfig())->bindSessionsToTenant ?? true);
    }

    protected function rejectsUnboundSessions(): bool
    {
        return (bool) (($this->config ?? new TenantableConfig())->rejectUnboundSessions ?? false);
    }
}

class CookieAwareSessionTenantGuard extends SessionTenantGuard
{
    public function carriesCookie(): bool
    {
        return $this->requestCarriesSessionCookie();
    }
}

/**
 * @covers \nuelcyoung\tenantable\Bootstrap\SessionTenantGuard
 */
class SessionTenantGuardTest extends TestCase
{
    private TestableSessionTenantGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        SessionTenantGuard::resetInstance();
        $this->guard          = new TestableSessionTenantGuard();
        $this->guard->session = new FakeSession();
    }

    protected function tearDown(): void
    {
        SessionTenantGuard::resetInstance();
        parent::tearDown();
    }

    // Session validation tests

    public function testValidateStampsUnboundSession(): void
    {
        $config = new TenantableConfig();
        $config->rejectUnboundSessions = false;
        $this->guard->config = $config;

        $this->guard->validate(7);

        $this->assertSame(7, $this->guard->session->get(SessionTenantGuard::SESSION_KEY));
        $this->assertFalse($this->guard->session->destroyed);
    }

    public function testValidateAcceptsMatchingStamp(): void
    {
        $this->guard->session->set(SessionTenantGuard::SESSION_KEY, 7);
        $this->guard->session->set('user_id', 42);

        $this->guard->validate(7);

        $this->assertFalse($this->guard->session->destroyed);
        $this->assertSame(42, $this->guard->session->get('user_id'));
    }

    public function testValidateDestroysSessionBoundToAnotherTenant(): void
    {
        $this->guard->session->set(SessionTenantGuard::SESSION_KEY, 1);
        $this->guard->session->set('user_id', 42);

        $this->guard->validate(2);

        $this->assertTrue($this->guard->session->destroyed);
        $this->assertNull($this->guard->session->get('user_id'));
    }

    public function testValidateHandlesStringStamp(): void
    {
        // Session data may round-trip through storage as a string.
        $this->guard->session->set(SessionTenantGuard::SESSION_KEY, '7');

        $this->guard->validate(7);

        $this->assertFalse($this->guard->session->destroyed);
    }

    public function testValidateDoesNothingWithoutTenant(): void
    {
        $this->guard->session->set('user_id', 42);

        $this->guard->validate(null);

        $this->assertFalse($this->guard->session->destroyed);
        $this->assertNull($this->guard->session->get(SessionTenantGuard::SESSION_KEY));
    }

    public function testValidateDoesNotTouchSessionWithoutCookie(): void
    {
        // No inbound cookie: the guard must not start a session for a guest.
        $this->guard->hasCookie = false;

        $this->guard->validate(7);

        $this->assertNull($this->guard->session->get(SessionTenantGuard::SESSION_KEY));
    }

    public function testValidateDisabledByConfig(): void
    {
        $config = new TenantableConfig();
        $config->bindSessionsToTenant = false;
        $this->guard->config = $config;

        $this->guard->session->set(SessionTenantGuard::SESSION_KEY, 1);

        $this->guard->validate(2);

        $this->assertFalse($this->guard->session->destroyed);
    }

    public function testValidateRejectsUnboundSessionInStrictMode(): void
    {
        $config = new TenantableConfig();
        $config->rejectUnboundSessions = true;
        $this->guard->config = $config;

        $this->guard->session->set('user_id', 42);

        $this->guard->validate(7);

        $this->assertTrue($this->guard->session->destroyed);
    }

    public function testValidateHandlesMissingSessionService(): void
    {
        $this->guard->session = null;

        $this->guard->validate(7); // must not throw

        $this->addToAssertionCount(1);
    }

    public function testCookieDetectionRecognizesTenantCookieAfterBootstrap(): void
    {
        $_COOKIE = ['tenant_7_session' => 'session-id'];

        try {
            $guard = new CookieAwareSessionTenantGuard();

            $this->assertTrue($guard->carriesCookie());
        } finally {
            $_COOKIE = [];
        }
    }

    // Session stamping tests

    public function testStampBindsSessionStartedDuringRequest(): void
    {
        $this->guard->startedSession = new FakeSession();

        $this->guard->stamp(7);

        $this->assertSame(7, $this->guard->startedSession->get(SessionTenantGuard::SESSION_KEY));
    }

    public function testStampDoesNotOverwriteExistingBinding(): void
    {
        $this->guard->startedSession = new FakeSession();
        $this->guard->startedSession->set(SessionTenantGuard::SESSION_KEY, 3);

        $this->guard->stamp(7);

        $this->assertSame(3, $this->guard->startedSession->get(SessionTenantGuard::SESSION_KEY));
    }

    public function testStampDoesNothingWhenSessionNeverStarted(): void
    {
        $this->guard->startedSession = null;

        $this->guard->stamp(7); // must not throw

        $this->addToAssertionCount(1);
    }

    public function testStampDoesNothingWithoutTenant(): void
    {
        $this->guard->startedSession = new FakeSession();

        $this->guard->stamp(null);

        $this->assertNull($this->guard->startedSession->get(SessionTenantGuard::SESSION_KEY));
    }

    // =========================================================================
    // Config defaults
    // =========================================================================

    public function testGuardIsEnabledByDefault(): void
    {
        $config = new TenantableConfig();

        $this->assertTrue($config->bindSessionsToTenant);
        $this->assertTrue($config->rejectUnboundSessions);
    }

    public function testSingletonAccessors(): void
    {
        $a = SessionTenantGuard::getInstance();
        $b = SessionTenantGuard::getInstance();

        $this->assertSame($a, $b);

        SessionTenantGuard::resetInstance();

        $this->assertNotSame($a, SessionTenantGuard::getInstance());
    }
}
