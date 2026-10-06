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

namespace nuelcyoung\tenantable\Database\Migrations\Tenant;

use CodeIgniter\Database\Migration;

/**
 * Sessions table for tenant databases.
 *
 * Runs on each tenant database when the ship-sessions flag is enabled.
 * Schema mirrors CI4's --session template per driver.
 */
class CreateSessionsTable extends Migration
{
    public function up(): void
    {
        if (($this->db->DBDriver ?? '') === 'Postgre') {
            $this->forge->addField([
                'id' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
                'ip_address inet NOT NULL',
                'timestamp timestamptz DEFAULT CURRENT_TIMESTAMP NOT NULL',
                "data bytea DEFAULT '' NOT NULL",
            ]);
        } else {
            $this->forge->addField([
                'id'         => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
                'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => false],
                '`timestamp` timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL',
                'data'       => ['type' => 'BLOB', 'null' => false],
            ]);
        }

        if (self::matchIP()) {
            $this->forge->addKey(['id', 'ip_address'], true);
        } else {
            $this->forge->addKey('id', true);
        }

        $this->forge->addKey('timestamp');
        $this->forge->createTable(self::tableName(), true);
    }

    public function down(): void
    {
        $this->forge->dropTable(self::tableName(), true);
    }

    /** The table name. Uses the session save path if set, else "ci_sessions". */
    public static function tableName(?object $session = null): string
    {
        $session ??= self::sessionConfig();

        $savePath = is_object($session) && isset($session->savePath) && is_string($session->savePath)
            ? $session->savePath
            : '';

        if ($savePath !== ''
            && preg_match('/\A\w+\z/', $savePath) === 1
            && self::usesDatabaseHandler($session)) {
            return $savePath;
        }

        return 'ci_sessions';
    }

    /** True when the session match-IP setting is enabled. */
    public static function matchIP(?object $session = null): bool
    {
        $session ??= self::sessionConfig();

        return is_object($session) && (bool) ($session->matchIP ?? false);
    }

    protected static function usesDatabaseHandler(?object $session): bool
    {
        $driver = is_object($session) && isset($session->driver) && is_string($session->driver)
            ? $session->driver
            : '';

        if ($driver === '') {
            return false;
        }

        if (class_exists(\CodeIgniter\Session\Handlers\DatabaseHandler::class) && class_exists($driver)) {
            return is_a($driver, \CodeIgniter\Session\Handlers\DatabaseHandler::class, true);
        }

        return str_contains($driver, 'DatabaseHandler');
    }

    protected static function sessionConfig(): ?object
    {
        $config = function_exists('config') ? config('Session') : null;

        return is_object($config) ? $config : null;
    }
}
