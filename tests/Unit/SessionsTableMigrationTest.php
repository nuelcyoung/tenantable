<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Database\Migrations\Tenant\CreateSessionsTable;

/**
 * @covers \nuelcyoung\tenantable\Database\Migrations\Tenant\CreateSessionsTable
 */
class SessionsTableMigrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Date-prefixed migration files are not PSR-4 autoloadable.
        require_once __DIR__ . '/../../src/Database/Migrations/Tenant/2024-01-01-000001_CreateSessionsTable.php';
    }

    public function testTableNameDefaultsToCiSessions(): void
    {
        $this->assertSame('ci_sessions', CreateSessionsTable::tableName(new \stdClass()));
    }

    public function testTableNameFallsBackToGlobalSessionConfig(): void
    {
        // The test bootstrap mocks the session config with a file-handler-style
        // savePath, which must never be used as a table name.
        $this->assertSame('ci_sessions', CreateSessionsTable::tableName());
    }

    public function testTableNameUsesSavePathForDatabaseHandler(): void
    {
        $session = new class {
            public string $driver   = \CodeIgniter\Session\Handlers\DatabaseHandler::class;
            public string $savePath = 'app_sessions';
        };

        $this->assertSame('app_sessions', CreateSessionsTable::tableName($session));
    }

    public function testTableNameIgnoresSavePathForFileHandler(): void
    {
        $session = new class {
            public string $driver   = \CodeIgniter\Session\Handlers\FileHandler::class;
            public string $savePath = 'sessions';
        };

        $this->assertSame('ci_sessions', CreateSessionsTable::tableName($session));
    }

    public function testTableNameRejectsPathLikeSavePathEvenForDatabaseHandler(): void
    {
        $session = new class {
            public string $driver   = \CodeIgniter\Session\Handlers\DatabaseHandler::class;
            public string $savePath = '/writable/session';
        };

        $this->assertSame('ci_sessions', CreateSessionsTable::tableName($session));
    }

    public function testMatchIPDefaultsToFalse(): void
    {
        $this->assertFalse(CreateSessionsTable::matchIP(new \stdClass()));
    }

    public function testMatchIPReadsSessionConfig(): void
    {
        $session = new class {
            public bool $matchIP = true;
        };

        $this->assertTrue(CreateSessionsTable::matchIP($session));
    }
}
