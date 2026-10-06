<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use CodeIgniter\CodeIgniter;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Config as DatabaseConfig;
use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Exceptions\FrameworkCompatibilityException;
use nuelcyoung\tenantable\Support\FrameworkState;

/**
 * @covers \nuelcyoung\tenantable\Support\FrameworkState
 * @covers \nuelcyoung\tenantable\Exceptions\FrameworkCompatibilityException
 */
class FrameworkStateTest extends TestCase
{
    private const TEST_GROUP = 'tenantable_fw_state_test';

    protected function tearDown(): void
    {
        // Never leave a stray config group or shared connection behind.
        $dbConfig = config('Database');

        if (property_exists($dbConfig, self::TEST_GROUP)) {
            unset($dbConfig->{self::TEST_GROUP});
        }

        FrameworkState::evictSharedDbConnection(self::TEST_GROUP);

        parent::tearDown();
    }

    public function testCiVersionMatchesInstalledFramework(): void
    {
        $this->assertSame(CodeIgniter::CI_VERSION, FrameworkState::ciVersion());
    }

    public function testInstalledFrameworkIsInsideTestedWindow(): void
    {
        $this->assertTrue(
            FrameworkState::isTestedCiVersion(),
            'Installed CI4 ' . CodeIgniter::CI_VERSION . ' is outside the tested window '
            . FrameworkState::MIN_CI_VERSION . ' – ' . FrameworkState::MAX_CI_VERSION_TESTED
            . '. Re-verify the internals stores before releasing support for this version.'
        );
    }

    public function testAssertInternalsSupportedPassesOnThisBuild(): void
    {
        FrameworkState::assertInternalsSupported();

        $this->addToAssertionCount(1);
    }

    public function testEvictSharedDbConnectionIsNoOpWhenGroupNotCached(): void
    {
        $before = DatabaseConfig::getConnections();

        FrameworkState::evictSharedDbConnection('tenantable_never_connected');

        $this->assertSame($before, DatabaseConfig::getConnections());
    }

    public function testEvictSharedDbConnectionClosesAndRemovesCachedConnection(): void
    {
        // Register a throwaway group cloned from the test connection.
        $dbConfig = config('Database');
        $dbConfig->{self::TEST_GROUP} = $dbConfig->tests;

        $shared = DatabaseConfig::connect(self::TEST_GROUP, true);

        $this->assertInstanceOf(BaseConnection::class, $shared);
        $this->assertArrayHasKey(self::TEST_GROUP, DatabaseConfig::getConnections());

        FrameworkState::evictSharedDbConnection(self::TEST_GROUP);

        $this->assertArrayNotHasKey(self::TEST_GROUP, DatabaseConfig::getConnections());

        // The next shared connect must build a brand new instance.
        $fresh = DatabaseConfig::connect(self::TEST_GROUP, true);
        $this->assertNotSame($shared, $fresh);
    }

    public function testResetSharedServiceDropsSharedCacheInstance(): void
    {
        $first = service('cache');

        FrameworkState::resetSharedService('cache');

        $second = service('cache');

        $this->assertNotSame($first, $second);
    }

    public function testLoudFailureWhenStoreClassIsMissing(): void
    {
        $this->expectException(FrameworkCompatibilityException::class);
        $this->expectExceptionMessageMatches('/Vendor\\\\Missing\\\\Thing/');

        $this->invokeReadStore('Vendor\\Missing\\Thing', 'instances');
    }

    public function testLoudFailureWhenStorePropertyIsMissing(): void
    {
        $this->expectException(FrameworkCompatibilityException::class);
        $this->expectExceptionMessageMatches('/instances/');

        $this->invokeReadStore(\stdClass::class, 'instances');
    }

    /**
     * Reach the private store reader to exercise its failure paths.
     */
    private function invokeReadStore(string $class, string $property): void
    {
        $method = new \ReflectionMethod(FrameworkState::class, 'readStaticStore');
        $method->invoke(null, $class, $property, 'a test purpose');
    }
}
