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

    // Events::trigger() consults CI_DEBUG when a listener throws; a real app
    // defines it during bootstrap.
    if (!defined('CI_DEBUG')) {
        define('CI_DEBUG', false);
    }

    // CodeIgniter\Database\Config::connect() reads this when no group is
    // named; 'testing' selects the `tests` group, matching a real app run
    // under `spark test`.
    if (!defined('ENVIRONMENT')) {
        define('ENVIRONMENT', 'testing');
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

    // Minimal Autoloader stand-in (the real one requires app constants such
    // as COMPOSER_PATH). Implements only the namespace management methods
    // the package uses.
    class FakeAutoloader
    {
        /** @var array<string, list<string>> */
        private array $namespaces = [];

        public function getNamespace(?string $prefix = null): array
        {
            if ($prefix === null) {
                return $this->namespaces;
            }

            return $this->namespaces[trim($prefix, '\\')] ?? [];
        }

        /** @param string|array<string, string> $namespace */
        public function addNamespace($namespace, ?string $path = null): self
        {
            if (is_array($namespace)) {
                foreach ($namespace as $ns => $nsPath) {
                    $this->addNamespace($ns, (string) $nsPath);
                }

                return $this;
            }

            $ns = trim((string) $namespace, '\\');
            $this->namespaces[$ns] ??= [];
            if ($path !== null && ! in_array($path, $this->namespaces[$ns], true)) {
                $this->namespaces[$ns][] = $path;
            }

            return $this;
        }

        /** @param string|list<string> $namespace */
        public function removeNamespace($namespace): self
        {
            foreach ((array) $namespace as $ns) {
                unset($this->namespaces[trim((string) $ns, '\\')]);
            }

            return $this;
        }
    }

    // Database config stand-in. Extends the framework's own connection
    // manager so shared connections land in the real
    // CodeIgniter\Database\Config::$instances store, which is what
    // Support\FrameworkState evicts from. A hand-rolled mock would let that
    // eviction path pass tests while doing nothing in a real app.
    //
    // A real app declares one property per connection group; tests register
    // throwaway groups at runtime, hence #[AllowDynamicProperties].
    #[\AllowDynamicProperties]
    class Database extends \CodeIgniter\Database\Config
    {
        public string $defaultGroup = 'tests';

        /** @var array<string, mixed> */
        public array $tests = [
            'DBDriver' => 'SQLite3',
            'database' => ':memory:',
            'DBPrefix' => '',
        ];

        /** @var array<string, mixed> */
        public array $default = [
            'DBDriver' => 'SQLite3',
            'database' => ':memory:',
            'DBPrefix' => '',
        ];

        /**
         * Package code connects to per-tenant groups that no test registers.
         * Those fall back to the in-memory default instead of throwing, which
         * is what the previous stand-in did for every group.
         *
         * @param array|\CodeIgniter\Database\BaseConnection|string|null $group
         */
        public static function connect($group = null, bool $getShared = true): \CodeIgniter\Database\BaseConnection
        {
            if ($group instanceof \CodeIgniter\Database\BaseConnection || is_array($group)) {
                return parent::connect($group, $getShared);
            }

            $dbConfig = config(self::class);
            $group ??= $dbConfig->defaultGroup;

            if (! isset($dbConfig->{$group})) {
                $group = $dbConfig->defaultGroup;
            }

            return parent::connect($group, $getShared);
        }
    }

    // Mock CI4 Services for tests that touch the cache layer.
    //
    // Shared instances live in a $instances store keyed exactly like
    // CodeIgniter\Config\BaseService's, and resetSingle() drops one entry,
    // the public API Support\FrameworkState prefers. Without both, the
    // package's service-eviction path could never be exercised here.
    class Services
    {
        /** @var array<string, object> */
        protected static array $instances = [];

        /**
         * Autoloader stand-in so package code resolving migration namespaces
         * via the service autoloader can be exercised in tests.
         */
        public static function autoloader(): object
        {
            return self::$instances['autoloader'] ??= new FakeAutoloader();
        }

        /** Mirrors BaseService::resetSingle(). */
        public static function resetSingle(string $name): void
        {
            unset(self::$instances[strtolower($name)]);
        }

        /** Mirrors BaseService::get() for the handful of mocked services. */
        public static function get(string $name): ?object
        {
            if (! method_exists(self::class, $name)) {
                return null;
            }

            return self::$name();
        }

        public static function cache(?object $config = null, bool $getShared = true): object
        {
            $shared = self::$instances['cache'] ?? null;

            if ($shared === null || ! $getShared) {
                $instance = new class {
                    public ?object $config = null;
                    private array $store = [];

                    public function get(string $key): mixed
                    {
                        return $this->store[$key] ?? null;
                    }

                    public function save(string $key, mixed $value, int $ttl = 60): bool
                    {
                        $this->store[$key] = $value;
                        return true;
                    }

                    public function delete(string $key): bool
                    {
                        unset($this->store[$key]);
                        return true;
                    }

                    public function increment(string $key, int $offset = 1): mixed
                    {
                        $this->store[$key] = (int) ($this->store[$key] ?? 0) + $offset;
                        return $this->store[$key];
                    }

                    public function decrement(string $key, int $offset = 1): mixed
                    {
                        $this->store[$key] = (int) ($this->store[$key] ?? 0) - $offset;
                        return $this->store[$key];
                    }

                    public function clean(): bool
                    {
                        $this->store = [];
                        return true;
                    }

                    public function getCacheInfo(): ?array
                    {
                        return null;
                    }

                    public function getMetaData(string $key): ?array
                    {
                        return null;
                    }
                };

                $instance->config = $config;

                if (! $getShared) {
                    return $instance;
                }

                self::$instances['cache'] = $instance;
            }

            return self::$instances['cache'];
        }

        public static function reset(): void
        {
            self::$instances = [];
        }
    }
}

namespace CodeIgniter\Queue {
    /**
     * codeigniter4/queue is a `suggest`, not a dependency, so the package's
     * base job class has no parent in this repo. This stand-in mirrors the
     * only surface Queue\TenantableJob builds on.
     */
    abstract class BaseJob
    {
        /** @var array<string, mixed> */
        protected array $data = [];

        /** @param array<string, mixed> $data */
        public function __construct(array $data = [])
        {
            $this->data = $data;
        }
    }
}

namespace CodeIgniter\Queue\Interfaces {
    interface JobInterface
    {
        public function process();
    }
}

namespace {
    // Mock CI4 helper functions
    if (!function_exists('config')) {
        function config(string $name)
        {
            // The database config is a singleton in a real app: the framework's
            // own connect() registers connection groups on it, so a fresh
            // object per call would lose them between calls.
            static $database = null;

            return match ($name) {
                'Database',
                \Config\Database::class => $database ??= new \Config\Database(),
                'Cache' => new class {
                    public string $prefix = '';
                },
                'Session' => new class {
                    public string $savePath = '/tmp/sessions';
                },
                'App' => new class {
                    // Every CI4 app has a baseURL; tenant subdomain rewriting
                    // reads it, so the stand-in must carry one too.
                    public string $baseURL = 'http://localhost/';
                    public ?array $tenantSettings = null;
                },
                'Tenantable',
                \nuelcyoung\tenantable\Config\Tenantable::class => new \nuelcyoung\tenantable\Config\Tenantable(),
                default => null,
            };
        }
    }

    // CLI helpers call these; a full app bootstrap loads them from Common.php.
    if (!function_exists('is_cli')) {
        function is_cli(): bool
        {
            return PHP_SAPI === 'cli' || defined('STDIN');
        }
    }

    if (!function_exists('is_windows')) {
        function is_windows(?bool $mock = null): bool
        {
            return DIRECTORY_SEPARATOR === '\\';
        }
    }

    // CI4 framework helper loaded by Common.php in a full app bootstrap;
    // error paths in the database layer call it. Identity is enough here.
    if (!function_exists('clean_path')) {
        function clean_path(string $path): string
        {
            return $path;
        }
    }

    if (!function_exists('render_backtrace')) {
        function render_backtrace(array $backtrace): string
        {
            return '';
        }
    }

    if (!function_exists('log_message')) {
        function log_message(string $level, string $message, array $context = []): void
        {
            // Silent unless a test opted into capture: several package
            // guarantees are "degrade, but log loudly", and those need to be
            // assertable.
            \nuelcyoung\tenantable\Tests\Support\LogCapture::record($level, $message, $context);
        }
    }

    // Called by CodeIgniter\Controller::initController() when loading helpers.
    if (!function_exists('helper')) {
        function helper($filenames): void
        {
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

            // Route through the Services stand-in so shared instances honour
            // the same store the package's service eviction clears.
            if ($params === []) {
                return \Config\Services::get($name);
            }

            if (method_exists(\Config\Services::class, $name)) {
                return \Config\Services::$name(...$params);
            }

            return null;
        }
    }

    // Events::initialize() includes APPPATH/Config/Events.php on the first
    // on()/trigger() call. A package repo has no app skeleton, so mark the
    // registry initialized: tests register their listeners programmatically.
    (new \ReflectionProperty(\CodeIgniter\Events\Events::class, 'initialized'))
        ->setValue(null, true);

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
