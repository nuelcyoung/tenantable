<?php

declare(strict_types=1);

namespace {
    // Load composer autoloader
    require_once __DIR__ . '/../vendor/autoload.php';

    // Define CI4 constants
    if (!defined('ROOTPATH')) {
        define('ROOTPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
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
}

namespace Config {
    // Mock CI4 Modules config class needed by Events
    class Modules
    {
        public bool $enabled = false;

        public function shouldDiscover(string $type): bool
        {
            return false;
        }
    }
}

namespace {
    // Mock CI4 helper functions
    if (!function_exists('config')) {
        function config(string $name)
        {
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
    }

    if (!function_exists('log_message')) {
        function log_message(string $level, string $message, array $context = []): void
        {
            // Silent in tests
        }
    }

    if (!function_exists('service')) {
        function service(?string $name = null, ...$params)
        {
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

            return null;
        }
    }

    // Create writable directories if needed
    $dirs = [
        WRITEPATH,
        WRITEPATH . 'session',
        WRITEPATH . 'uploads',
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
