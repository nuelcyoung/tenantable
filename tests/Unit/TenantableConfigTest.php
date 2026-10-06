<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Config\Tenantable;

/**
 * @covers \nuelcyoung\tenantable\Config\Tenantable
 */
class TenantableConfigTest extends TestCase
{
    /**
     * Build the config without running BaseConfig's constructor (which needs
     * the full framework bootstrap). Declared property defaults still apply.
     */
    private function makeConfig(): Tenantable
    {
        /** @var Tenantable $config */
        $config = (new \ReflectionClass(Tenantable::class))->newInstanceWithoutConstructor();

        return $config;
    }

    public function testResolvedModeDefaultsToRow(): void
    {
        $config = $this->makeConfig();

        $this->assertSame('row', $config->resolvedIsolationMode());
        $this->assertFalse($config->isDatabaseIsolation());
    }

    public function testResolvedModeDerivedFromLegacyFlag(): void
    {
        $config = $this->makeConfig();
        $config->isolationMode             = null;
        $config->separateDatabasePerTenant = true;

        $this->assertSame('database', $config->resolvedIsolationMode());
        $this->assertTrue($config->isDatabaseIsolation());
    }

    public function testExplicitIsolationModeWinsOverLegacyFlag(): void
    {
        $config = $this->makeConfig();
        // The two settings disagree on purpose: the explicit mode must win so
        // runtime switching and provisioning don't diverge.
        $config->isolationMode             = 'prefix';
        $config->separateDatabasePerTenant = true;

        $this->assertSame('prefix', $config->resolvedIsolationMode());
        $this->assertFalse($config->isDatabaseIsolation());
    }

    public function testInvalidModeFallsBackToRow(): void
    {
        $config = $this->makeConfig();
        $config->isolationMode = 'bogus';

        $this->assertSame('row', $config->resolvedIsolationMode());
    }

    public function testDatabaseModeReportsDatabaseIsolation(): void
    {
        $config = $this->makeConfig();
        $config->isolationMode = 'database';

        $this->assertTrue($config->isDatabaseIsolation());
    }

    public function testTenantIsolationFailsClosedByDefault(): void
    {
        $config = $this->makeConfig();

        $this->assertTrue($config->strictTenantIsolation);
        $this->assertTrue($config->rejectUnboundSessions);
    }

    public function testPostgreAdminDatabaseDefaultsToPostgres(): void
    {
        $config = $this->makeConfig();

        $this->assertSame('postgres', $config->postgresAdminDatabase);
    }

    // Migration namespace resolution tests

    public function testMigrationNamespacesDefaultToAppNamespaceOnly(): void
    {
        $config = $this->makeConfig();

        $this->assertSame(['App\Database\Migrations\Tenant'], $config->tenantMigrationNamespaces());
    }

    public function testSessionsNamespaceIncludedInDatabaseModeWhenEnabled(): void
    {
        $config = $this->makeConfig();
        $config->isolationMode           = 'database';
        $config->shipTenantSessionsTable = true;

        $this->assertSame(
            [
                'App\Database\Migrations\Tenant',
                Tenantable::PACKAGE_TENANT_MIGRATIONS_NAMESPACE,
            ],
            $config->tenantMigrationNamespaces()
        );
    }

    public function testSessionsNamespaceExcludedOutsideDatabaseMode(): void
    {
        $config = $this->makeConfig();
        // Enabled but pointless outside database isolation: row/prefix modes
        // share the central DB, whose sessions table the app manages itself.
        $config->isolationMode           = 'row';
        $config->shipTenantSessionsTable = true;

        $this->assertSame(['App\Database\Migrations\Tenant'], $config->tenantMigrationNamespaces());
    }

    public function testSessionsNamespaceExcludedWhenDisabled(): void
    {
        $config = $this->makeConfig();
        $config->isolationMode           = 'database';
        $config->shipTenantSessionsTable = false;

        $this->assertSame(['App\Database\Migrations\Tenant'], $config->tenantMigrationNamespaces());
    }

    public function testThirdPartyNamespacesAppendedAndDeduplicated(): void
    {
        $config = $this->makeConfig();
        $config->isolationMode              = 'database';
        $config->shipTenantSessionsTable    = true;
        $config->tenantMigrationsNamespaces = [
            'CodeIgniter\Shield\Database\Migrations',
            'App\Database\Migrations\Tenant', // duplicate of the primary
            '',                               // ignored
        ];

        $this->assertSame(
            [
                'App\Database\Migrations\Tenant',
                Tenantable::PACKAGE_TENANT_MIGRATIONS_NAMESPACE,
                'CodeIgniter\Shield\Database\Migrations',
            ],
            $config->tenantMigrationNamespaces()
        );
    }
}
