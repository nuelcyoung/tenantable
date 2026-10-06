<?php

declare(strict_types=1);

// PHPStan stubs for CodeIgniter 4 app-level classes that exist at runtime
// in a consuming application but are not shipped with this package.

namespace Config {
    use CodeIgniter\Cache\CacheInterface;
    use CodeIgniter\Database\BaseConnection;
    use CodeIgniter\Database\Forge;
    use CodeIgniter\Database\MigrationRunner;
    use CodeIgniter\HTTP\IncomingRequest;
    use CodeIgniter\Autoloader\Autoloader;

    class Database
    {
        /**
         * @param string|array<string, mixed> $group
         */
        public static function connect($group = 'default', bool $getShared = true): BaseConnection
        {
            return new class extends BaseConnection {
                public function reconnect(): void {}
                public function getVersion(): string { return ''; }
                public function setDatabase(string $databaseName): bool { return true; }
                protected function close(): void {}
                protected function execute(string $sql)
                {
                    return false;
                }
                protected function fieldData(string $table): array { return []; }
                protected function indexData(string $table): array { return []; }
                protected function foreignKeyData(string $table): array { return []; }
                protected function _fieldData(string $table): array { return []; }
                protected function _indexData(string $table): array { return []; }
                protected function _foreignKeyData(string $table): array { return []; }
                public function affectedRows(): int { return 0; }
            };
        }

        /**
         * @param mixed $group
         */
        public static function forge($group = null): Forge
        {
            return new Forge(self::connect($group));
        }
    }

    class Services
    {
        public static function cache(?object $config = null, bool $getShared = true): CacheInterface
        {
            return new class implements CacheInterface {
                public function get(string $key): mixed { return null; }
                public function save(string $key, mixed $value, ?int $ttl = null): bool { return true; }
                public function delete(string $key): bool { return true; }
                public function increment(string $key, int $offset = 1): mixed { return 1; }
                public function decrement(string $key, int $offset = 1): mixed { return 1; }
                public function clean(): bool { return true; }
                public function getCacheInfo(): ?array { return null; }
                public function getMetaData(string $key): ?array { return null; }
                public function isSupported(): bool { return true; }
            };
        }

        public static function migrations(?object $config = null, ?object $db = null): MigrationRunner
        {
            return new MigrationRunner(new \Config\Migrations(), self::connect());
        }

        public static function autoloader(bool $getShared = true): Autoloader
        {
            return new Autoloader();
        }

        public static function request(?object $config = null, bool $getShared = true): \CodeIgniter\HTTP\RequestInterface
        {
            return new IncomingRequest(new \Config\App(), new \CodeIgniter\HTTP\URI('/'), 'php://memory', new \CodeIgniter\HTTP\UserAgent());
        }
    }

    class App
    {
        public string $baseURL = 'http://localhost/';
        public string $indexPage = '';
    }

    class Migrations
    {
        public string $enabled = 'true';
        public string $table = 'migrations';
        public string $timestampFormat = 'YmdHis';
    }

    class Tenantable extends \nuelcyoung\tenantable\Config\Tenantable
    {
    }
}

namespace CodeIgniter\Queue {
    /**
     * Stand-in for codeigniter4/queue, which is a `suggest` rather than a
     * dependency. Only the surface Queue\TenantableJob builds on.
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

namespace CodeIgniter\CLI {
    class CLI
    {
        public static function isCli(): bool
        {
            return PHP_SAPI === 'cli';
        }
    }
}
