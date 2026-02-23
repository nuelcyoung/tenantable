<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Support;

/**
 * CodeIgniter 4 Mocks for isolated testing.
 * 
 * These mocks allow the tests to run without the full CI4 framework.
 */

// Define CI4 constants if not defined
if (!defined('ROOTPATH')) {
    define('ROOTPATH', dirname(__DIR__, 3) . DIRECTORY_SEPARATOR);
}

if (!defined('WRITEPATH')) {
    define('WRITEPATH', ROOTPATH . 'writable' . DIRECTORY_SEPARATOR);
}

if (!defined('APPPATH')) {
    define('APPPATH', ROOTPATH . 'app' . DIRECTORY_SEPARATOR);
}

if (!defined('SYSTEMPATH')) {
    define('SYSTEMPATH', ROOTPATH . 'system' . DIRECTORY_SEPARATOR);
}

/**
 * Mock the config() helper
 */
function config(string $name)
{
    static $configs = [];

    if (isset($configs[$name])) {
        return $configs[$name];
    }

    // Return mock config objects
    return match ($name) {
        'Cache' => new class {
            public string $prefix = '';
        },
        'Session' => new class {
            public string $savePath = '/tmp/sessions';
        },
        'App' => new class {
            public ?array $tenantSettings = null;
        },
        'Tenantable' => new class {
            public string $baseDomain = 'test.example.com';
            public bool $allowLocalhost = true;
            public array $bootstrappers = [];
        },
        default => null,
    };
}

/**
 * Mock the log_message() helper
 */
function log_message(string $level, string $message, array $context = []): void
{
    // Do nothing in tests, or write to a test log
}

/**
 * Mock the service() helper
 */
function service(?string $name = null, ...$params)
{
    static $services = [];

    if ($name === null) {
        return new class {
            public function getUri(): object
            {
                return new class {
                    public function getPath(): string
                    {
                        return '/';
                    }
                };
            }
        };
    }

    return $services[$name] ?? null;
}
