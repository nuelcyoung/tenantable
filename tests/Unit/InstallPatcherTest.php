<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Support\InstallPatcher;

/**
 * @covers \nuelcyoung\tenantable\Support\InstallPatcher
 */
class InstallPatcherTest extends TestCase
{
    // =========================================================================
    // patchFilters
    // =========================================================================

    public function testPatchFiltersAddsToCleanGlobals(): void
    {
        $source = $this->cleanFiltersFile();

        $result = InstallPatcher::patchFilters($source, 'tenant_subdomain');

        $this->assertTrue($result['located']);
        $this->assertTrue($result['changed']);
        $this->assertContains('before: tenant_subdomain', $result['added']);
        $this->assertContains('before: tenant_security', $result['added']);
        $this->assertContains('after: tenant_security', $result['added']);

        $this->assertStringContainsString(
            "'tenant_subdomain' => ['except' => ['health', 'api/*']]",
            $result['source'],
        );
        $this->assertStringContainsString(
            "'tenant_security' => ['except' => ['health', 'api/*']]",
            $result['source'],
        );

        // Patched output must remain syntactically valid PHP.
        $this->assertValidPhp($result['source']);
    }

    public function testPatchFiltersIsIdempotent(): void
    {
        $source = $this->cleanFiltersFile();
        $first  = InstallPatcher::patchFilters($source, 'tenant_subdomain');
        $second = InstallPatcher::patchFilters($first['source'], 'tenant_subdomain');

        $this->assertTrue($first['changed']);
        $this->assertFalse($second['changed']);
        $this->assertSame([], $second['added']);
        $this->assertSame($first['source'], $second['source']);
    }

    public function testPatchFiltersSkipsBeforeAliasWhenAnyTenantIdentifierAlreadyPresent(): void
    {
        $source = $this->filtersWithBeforeAlias("'tenant' => ['except' => ['health']],");

        $result = InstallPatcher::patchFilters($source, 'tenant_subdomain');

        $this->assertTrue($result['located']);
        $this->assertTrue($result['changed']); // tenant_security still gets added
        $this->assertNotContains('before: tenant_subdomain', $result['added']);
        $this->assertContains('before: tenant_security', $result['added']);
        $this->assertContains('after: tenant_security', $result['added']);

        // The existing 'tenant' alias was not duplicated as 'tenant_subdomain'.
        $this->assertStringNotContainsString("'tenant_subdomain' =>", $result['source']);
    }

    public function testPatchFiltersHandlesIdentifyTenantAliasAsTenantIdentifier(): void
    {
        $source = $this->filtersWithBeforeAlias("'identify_tenant' => ['except' => ['health']],");
        $result = InstallPatcher::patchFilters($source, 'tenant_subdomain');

        $this->assertNotContains('before: tenant_subdomain', $result['added']);
    }

    public function testPatchFiltersReportsUnlocatedWhenGlobalsMissing(): void
    {
        $source = "<?php\n\nclass Filters {}\n";
        $result = InstallPatcher::patchFilters($source, 'tenant_subdomain');

        $this->assertFalse($result['located']);
        $this->assertFalse($result['changed']);
        $this->assertSame($source, $result['source']);
    }

    public function testPatchFiltersHandlesInlineSubArrays(): void
    {
        $source = <<<'PHP'
<?php
namespace Config;
class Filters
{
    public array $globals = [
        'before' => ['forcehttps'],
        'after' => ['performance'],
    ];
}
PHP;

        $result = InstallPatcher::patchFilters($source, 'tenant_path');

        $this->assertTrue($result['located']);
        $this->assertTrue($result['changed']);
        $this->assertStringContainsString("'tenant_path'", $result['source']);
        $this->assertValidPhp($result['source']);
    }

    // =========================================================================
    // patchEvents
    // =========================================================================

    public function testPatchEventsAddsPackageEventsCallAndUse(): void
    {
        $source = $this->cleanEventsFile();
        $result = InstallPatcher::patchEvents($source, false);

        $this->assertTrue($result['changed']);
        $this->assertContains('PackageEvents::register()', $result['added']);
        $this->assertStringContainsString(
            'use nuelcyoung\\tenantable\\Bootstrap\\PackageEvents;',
            $result['source'],
        );
        $this->assertStringContainsString('PackageEvents::register();', $result['source']);
        $this->assertValidPhp($result['source']);
    }

    public function testPatchEventsAddsEarlyDetectionWhenRequested(): void
    {
        $source = $this->cleanEventsFile();
        $result = InstallPatcher::patchEvents($source, true);

        $this->assertTrue($result['changed']);
        $this->assertContains('PackageEvents::register()', $result['added']);
        $this->assertContains('EarlyTenantDetector pre_system listener', $result['added']);
        $this->assertStringContainsString(
            'use nuelcyoung\\tenantable\\Bootstrap\\EarlyTenantDetector;',
            $result['source'],
        );
        $this->assertStringContainsString("Events::on('pre_system'", $result['source']);
        $this->assertValidPhp($result['source']);
    }

    public function testPatchEventsIsIdempotent(): void
    {
        $source = $this->cleanEventsFile();
        $first  = InstallPatcher::patchEvents($source, true);
        $second = InstallPatcher::patchEvents($first['source'], true);

        $this->assertTrue($first['changed']);
        $this->assertFalse($second['changed']);
        $this->assertSame([], $second['added']);
        $this->assertSame($first['source'], $second['source']);
    }

    public function testPatchEventsDoesNotDuplicateExistingPackageEventsCall(): void
    {
        $source = <<<'PHP'
<?php
namespace Config;

use CodeIgniter\Events\Events;
use nuelcyoung\tenantable\Bootstrap\PackageEvents;

PackageEvents::register();
PHP;

        $result = InstallPatcher::patchEvents($source, false);

        $this->assertFalse($result['changed']);
        $this->assertSame($source, $result['source']);
    }

    public function testPatchEventsAppendsUseAfterExistingUses(): void
    {
        $source = <<<'PHP'
<?php
namespace Config;

use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\FrameworkException;

Events::on('pre_system', fn() => null);
PHP;

        $result = InstallPatcher::patchEvents($source, false);

        $this->assertTrue($result['changed']);
        // Use statement order: existing uses then the new one.
        $packagePos = strpos($result['source'], 'use nuelcyoung');
        $eventsPos  = strpos($result['source'], 'use CodeIgniter\\Events\\Events;');
        $this->assertNotFalse($packagePos);
        $this->assertNotFalse($eventsPos);
        $this->assertGreaterThan($eventsPos, $packagePos);
    }

    // =========================================================================
    // renderConfig
    // =========================================================================

    public function testRenderConfigSubstitutesAllTokens(): void
    {
        $stub = file_get_contents(__DIR__ . '/../../src/stubs/Tenantable.config.stub');
        $this->assertNotFalse($stub);

        $out = InstallPatcher::renderConfig($stub, 'acme.test', 'prefix', 'subdomain', false);

        $this->assertStringContainsString("'acme.test'", $out);
        $this->assertStringContainsString("\$isolationMode = 'prefix'", $out);
        $this->assertStringContainsString('$separateDatabasePerTenant = false', $out);
        $this->assertStringContainsString("'tenant_subdomain'", $out);
        $this->assertStringContainsString("'off'", $out); // early detection off
        $this->assertStringNotContainsString('{{', $out);
        $this->assertValidPhp($out);
    }

    public function testRenderConfigDatabaseModeFlipsSeparateDb(): void
    {
        $stub = file_get_contents(__DIR__ . '/../../src/stubs/Tenantable.config.stub');
        $out  = InstallPatcher::renderConfig($stub, 'example.com', 'database', 'domain', true);

        $this->assertStringContainsString("\$isolationMode = 'database'", $out);
        $this->assertStringContainsString('$separateDatabasePerTenant = true', $out);
        $this->assertStringContainsString("'tenant_domain'", $out);
        $this->assertStringContainsString("'domain'", $out); // early detection mirrors strategy
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function cleanFiltersFile(): string
    {
        return <<<'PHP'
<?php

namespace Config;

use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\ForceHTTPS;

class Filters extends BaseFilters
{
    public array $aliases = [
        'forcehttps' => ForceHTTPS::class,
    ];

    public array $globals = [
        'before' => [
            'forcehttps',
        ],
        'after' => [
        ],
    ];
}
PHP;
    }

    private function filtersWithBeforeAlias(string $entry): string
    {
        return <<<PHP
<?php

namespace Config;

class Filters
{
    public array \$globals = [
        'before' => [
            {$entry}
        ],
        'after' => [
        ],
    ];
}
PHP;
    }

    private function cleanEventsFile(): string
    {
        return <<<'PHP'
<?php

namespace Config;

use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\FrameworkException;

Events::on('pre_system', static function (): void {
    // existing
});
PHP;
    }

    private function assertValidPhp(string $code): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'patcher_');
        file_put_contents($tmp, $code);

        $output     = [];
        $returnCode = 0;
        @exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $returnCode);
        @unlink($tmp);

        $this->assertSame(0, $returnCode, "PHP lint failed:\n" . implode("\n", $output) . "\n--- SOURCE ---\n" . $code);
    }
}
