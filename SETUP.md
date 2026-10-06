# Setup guide — Tenantable

This guide walks you through integrating Tenantable into a CodeIgniter 4 application. It assumes you have already installed CodeIgniter 4.4 or newer and have a working database connection.

For the conceptual overview, architecture trade-offs, and a high-level feature list, see [`README.md`](README.md).

---

## Table of contents

1. [Install](#1-install)
2. [Publish and configure](#2-publish-and-configure)
3. [Provision the tenants table](#3-provision-the-tenants-table)
4. [Configure tenant isolation](#4-configure-tenant-isolation)
5. [Register tenant identification filters](#5-register-tenant-identification-filters)
6. [Models](#6-models)
7. [Security filter](#7-security-filter)
8. [CLI and background jobs](#8-cli-and-background-jobs)
9. [Helper functions](#9-helper-functions)
10. [Early tenant detection (optional)](#10-early-tenant-detection-optional)
11. [Verify](#11-verify)
12. [Local development](#12-local-development)
13. [Troubleshooting](#13-troubleshooting)

---

## 1. Install

```bash
composer require nuelcyoung/tenantable
php spark tenants:install
```

`tenants:install` is the recommended first-run setup. It does the following:

1. Asks for base domain, isolation mode, and identification strategy (or accepts flags — see below).
2. Writes `app/Config/Tenantable.php` (a thin subclass of the package config).
3. Idempotently patches `app/Config/Filters.php` to add the tenant identification and security filters to `$globals`.
4. Idempotently patches `app/Config/Events.php` to register `PackageEvents::register()` (which wires early detection itself, gated by `earlyDetectionStrategy`).
5. Scaffolds `app/Database/Migrations/Tenant/` with a sample migration.
6. Runs `tenants:setup` to migrate the central tenants tables.

Re-running it is safe. Every step checks for prior state before making changes.

Non-interactive usage:

```bash
php spark tenants:install \
    --base-domain=example.com \
    --mode=prefix \
    --strategy=domain_or_subdomain \
    --yes
```

Flags: `--base-domain`, `--mode`, `--strategy`, `--early-detection`, `--no-migrate`, `--no-sample`, `--no-events`, `--no-filters`, `--force`, `--yes`.

### Spark commands auto-registered via composer

| Command | Purpose |
|---------|---------|
| `tenants:install` | One-shot first-run setup (config, filters, events, migrations) |
| `tenants:setup` | Provision storage for the configured isolation mode |
| `tenants:create` | Create a new tenant (with auto-provisioning in database mode) |
| `tenants:list` | List all tenants, or filter with `--active` / `--inactive` |
| `tenants:run` | Run any Spark command once per tenant |
| `tenants:make-model` | Scaffold a tenant-scoped or global model |
| `tenants:make-migration` | Scaffold a tenant migration file |

The helper file `src/Helpers/tenantable_helper.php` is auto-loaded, so `tenant_id()`, `tenant()`, `central()`, and the rest are globally available.

Filter aliases (`tenant`, `tenant_subdomain`, `tenant_domain`, `tenant_domain_or_subdomain`, `tenant_path`, `tenant_request`, `tenant_security`, `identify_tenant`) are auto-registered into `Config\Filters::$aliases` via the package's `Config\Registrar`. You do not need to map them yourself.

---

## 2. Publish and configure

`tenants:install` writes `app/Config/Tenantable.php` for you. If you skipped the installer (or want to start fresh), copy it manually:

```bash
cp vendor/nuelcyoung/tenantable/src/Config/Tenantable.php app/Config/Tenantable.php
```

Open `app/Config/Tenantable.php` and adjust the settings below.

### Core settings

```php
public string $baseDomain = 'example.com';
```

The `baseDomain` tells the subdomain filter how to extract the tenant identifier from the host. For example, with `baseDomain = 'example.com'`, a request to `acme.example.com` resolves to tenant `acme`.

> Local development: if you use Laravel Herd, Valet, or any tool that serves sites under `.test` / `.local` TLDs, set this to your dev domain (for example, `myapp.test`). The package handles dev TLDs correctly. See [Local development](#12-local-development) below.

### Isolation mode

Choose one of three strategies:

```php
// 'row' | 'prefix' | 'database'
// Leave null to derive from $separateDatabasePerTenant
public ?string $isolationMode = null;

public bool   $separateDatabasePerTenant = false;
public string $defaultDatabaseGroup      = 'default';
```

When `$isolationMode` is `null`, the package derives it automatically: `$separateDatabasePerTenant = true` becomes `'database'`, otherwise `'row'`.

### Database-per-tenant settings

Only relevant when `$isolationMode = 'database'`:

```php
// Auto-provision: CREATE DATABASE + run migrations on tenant insert
public bool $autoCreateDatabase = true;
public bool $autoMigrateTenant  = true;

// Custom DB naming (default: tenant_{id})
// Receives the tenant row array, returns the database name string
public $databaseNameGenerator = null;

// PostgreSQL only: an existing database used to issue CREATE DATABASE.
public string $postgresAdminDatabase = 'postgres';
```

### Tenant migrations namespace

Required for `prefix` and `database` modes. Points to your app's per-tenant migrations:

```php
public ?string $tenantMigrationsNamespace = 'App\Database\Migrations\Tenant';
```

### Third-party migrations (e.g. Shield)

If you use packages like CodeIgniter Shield that have their own migrations, you can run them on each tenant database automatically. No need to copy migration files.

```php
public array $tenantMigrationsNamespaces = [
    'CodeIgniter\Shield\Database\Migrations',
];
```

The primary `$tenantMigrationsNamespace` is always included. This array adds additional namespaces. Leave it empty (`[]`) if you only need your own tenant migrations.

### Sessions table (database session handler)

CodeIgniter 4's database session handler requires a session table, but the framework never creates it for you: it bundles no ready-to-run migration, only the `php spark make:migration --session` generator that scaffolds one into your app. In database isolation even a generated migration is not enough. The `DatabaseHandler` reads its table over the default connection, which points at a different database per tenant, so **every tenant database needs its own sessions table**.

```php
public bool $shipTenantSessionsTable = true;
```

When enabled (database mode only), tenant provisioning and `tenants:run migrate` also run the package's bundled sessions migration on each tenant database. It mirrors the schema `make:migration --session` generates, honours `Config\Session::$matchIP`, and names the table after `Config\Session::$savePath` (falling back to the conventional `ci_sessions`). It is idempotent (`IF NOT EXISTS`), so it is safe alongside an app that already ships its own sessions migration. `tenants:install` asks this question when you pick database mode.

### Identification method

Which filter strategy to use for resolving tenants from requests:

```php
// Options: 'tenant_subdomain', 'tenant_domain', 'tenant_domain_or_subdomain',
//          'tenant_path', 'tenant_request'
public string $identificationMethod = 'tenant_subdomain';
```

### Model namespaces

Where `tenants:make-model` places generated models:

```php
public string $tenantModelsNamespace = 'App\Models\Tenant';
public string $globalModelsNamespace = 'App\Models';
```

### Behaviour and security

```php
public array  $superadminGroups = ['superadmin'];
public array  $bypassRoutes    = ['health', '_health'];
public bool   $allowLocalhost  = false; // set true only for local development
public bool   $strictTenantIsolation = true;

public bool    $throwExceptions = false;
public ?string $notFoundView    = null;   // View shown when tenant not found
public ?string $inactiveView    = null;   // View shown when tenant is inactive

public ?int $fallbackTenantId = null;

public bool $bindSessionsToTenant    = true;
public bool $rejectUnboundSessions   = true;
public bool $perTenantSessionCookies = true;

public bool $requireSharedInfrastructure = false;
```

A few options worth calling out:

- `$strictTenantIsolation` — Retained for configuration compatibility. Tenant-aware row models always refuse to run if no tenant is active or if the tenant column is missing. Use `Model::withoutTenant()` or a `GlobalModel` for deliberate cross-tenant access.
- `$bindSessionsToTenant` — Stamps the active tenant ID into every session and destroys any session presented to a different tenant. This is what keeps sessions isolated when they live in shared storage (database/Redis session handlers in row and prefix modes, or any handler after switching isolation modes). Leave enabled.
- `$rejectUnboundSessions` — Enabled by default. Sessions with no tenant stamp are destroyed instead of adopted, preventing legacy shared sessions from being reused across tenants.
- `$perTenantSessionCookies` — Names the session cookie `tenant_{id}_session` so a browser never sends tenant A's cookie to tenant B when the cookie domain spans subdomains. Toggling it logs every user out once (the cookie is renamed).
- `$tenantAuthorizer` — Optional callback `(RequestInterface $request, array $tenant): bool` for membership checks. It is required when using `tenant_path` or `tenant_request`; those selectors are otherwise rejected because request input is not authorization.
- `$developmentTenantId` — Auto-activates this tenant ID for requests from `localhost`. Useful when you want tenant context locally without setting up DNS. Set `$allowLocalhost = true` only for local development.
- `$requireSharedInfrastructure` — Off by default. When on, a production environment still storing sessions or cache on node-local disk throws at tenant boot instead of serving traffic. See [Running on more than one node](#running-on-more-than-one-node).
- `$trustedHostPatterns` — Patterns used to validate the HTTP host. Defaults are derived from `$baseDomain`; malformed hosts and loopback hosts are rejected unless `$allowLocalhost` is explicitly enabled. Set to `['*']` only if you explicitly want to disable host validation (not recommended).

### Running on more than one node

Tenant state has to be reachable from whichever node the load balancer picks
next. Three things decide that, and only the first two can be checked
automatically:

| Concern | Single node | Two or more nodes |
|---------|-------------|-------------------|
| Sessions | `FileHandler` | `DatabaseHandler`, `RedisHandler`, or `MemcachedHandler` |
| Cache | `file` handler | `redis`, `predis`, or `memcached` |
| Tenant uploads | `WRITEPATH/uploads` | a shared mount (NFS, EFS, S3-backed) behind `WRITEPATH/uploads` |

Local disk does not fail loudly here. File sessions log a user out whenever the
next request lands on another node. A file cache keeps every node's copy of the
tenant resolver independent, so deactivating a tenant or moving its domain on
one node leaves the others serving the previous answer until the TTL expires.
Uploads written through one node 404 on the rest.

Per-tenant paths do not change this. Tenantable gives each tenant its own
session directory and upload directory, which multiplies the directories
without making any of them reachable from another machine.

`php spark tenants:install` and `php spark tenants:setup` end with a
**Multi-node readiness** report listing whatever they found, marked `[critical]`
or `[warning]`. Single-node deployments can ignore it.

To make the mistake impossible instead of merely visible:

```php
public bool $requireSharedInfrastructure = true;
```

With the flag on, a **production** environment that still uses the file session
handler or the file cache handler throws `UnsafeInfrastructureException` at
tenant boot rather than serving traffic. Non-production environments are never
blocked — a developer's machine is one node, and local disk is correct there.

In database isolation mode the database session handler needs a `ci_sessions`
table in every tenant database; set `$shipTenantSessionsTable = true` (or answer
yes during `tenants:install`) and Tenantable's packaged migration provides it.

### Caching

```php
public bool $cacheTenantData = true;
public int  $cacheTtl        = 3600;     // seconds

public int $resolverCacheTtl   = 300;
public string $resolverCachePrefix = 'tenant_resolver';
```

Custom domains are resolved only after they are marked verified. Registering a
domain creates an unverified record; complete your DNS or HTTPS ownership check
and call `TenantDomainModel::markVerified()` before serving traffic for it.

### Redis

`RedisSystem` is an optional bootstrapper — it is not in the default
`$bootstrappers` list. Add it when your Redis config (`Config\Redis`) is used
directly by application code and needs per-tenant scoping:

```php
public array $bootstrappers = [
    // ...
    'redis' => \nuelcyoung\tenantable\Bootstrap\RedisSystem::class,
];
```

Booting a tenant rewrites `Config\Redis::$default['prefix']` to
`tenant:{id}:` and mirrors it onto `Config\Cache::$redis['prefix']`. Shutdown
restores the application's own settings. A tenant may override the prefix
through `settings.redis.prefix`, but only with a value that still starts with
its own `tenant:{id}:` — a tenant cannot point itself at another tenant's
keyspace.

**Key prefixes are the only recommended isolation for Redis.**

`setUseDatabasePerTenant(true)` additionally moves each tenant onto its own
logical Redis database (tenant 1 → DB 0, tenant 2 → DB 1, …). This mode is
**deprecated** and will be removed in v2.0:

- Redis ships 16 logical databases by default (`maxDatabase`, index 0–15), and
  raising the server limit is discouraged upstream — Redis Cluster supports
  database 0 only. The mode therefore caps a deployment at a fixed, small
  number of tenants.
- Past that cap, tenant `N > maxDatabase` stays on the application's own
  database and is isolated by key prefix alone. The package logs this at
  **error** level on every boot rather than wrapping the index around, which
  would silently seat two tenants in one database.
- `SELECT`-ing a database is per-connection state, so it fights with connection
  pooling and with any Redis client shared outside the framework.

The `tenant:{id}:` prefix already gives full logical separation, works on
Cluster, and has no tenant ceiling. Leave database-per-tenant off.

### Subdomain validation rules

```php
public array $subdomainRules = [
    'min_length' => 2,
    'max_length' => 50,
    'pattern'    => '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/',
];
```

### Bootstrap systems

The subsystems initialised when a tenant is booted. Remove any you do not need (for example, `session` when using JWT, `storage` when using S3):

```php
public array $bootstrappers = [
    'database' => \nuelcyoung\tenantable\Bootstrap\Systems\DatabaseSystem::class,
    'table'    => \nuelcyoung\tenantable\Bootstrap\Systems\TableSystem::class,
    'cache'    => \nuelcyoung\tenantable\Bootstrap\Systems\CacheSystem::class,
    'storage'  => \nuelcyoung\tenantable\Bootstrap\Systems\StorageSystem::class,
    'session'  => \nuelcyoung\tenantable\Bootstrap\Systems\SessionSystem::class,
    'logging'  => \nuelcyoung\tenantable\Bootstrap\Systems\LoggingSystem::class,
    'config'   => \nuelcyoung\tenantable\Bootstrap\Systems\ConfigSystem::class,
];
```

---

## 3. Provision the tenants table

Run once per environment to create the central `tenants` table:

```bash
php spark tenants:setup
```

This runs the package's internal migration (`CreateTenantsTable`) against the `default` DB group.

---

## 4. Configure tenant isolation

After the tenants table exists, set up isolation for your chosen mode.

### Row mode (`isolationMode = 'row'`)

The simplest mode: shared tables with a `tenant_id` column.

No additional setup is required beyond Step 3. Run your normal app migrations to add `tenant_id` columns to tenant-scoped tables:

```bash
php spark migrate
```

---

### Prefix mode (`isolationMode = 'prefix'`)

Shared database, per-tenant table prefixes (`tenant_{id}_students`, and so on).

Prerequisites:

- Set `$tenantMigrationsNamespace` in config (for example, `'App\Database\Migrations\Tenant'`).
- Tenant migrations must reference tables via `TenantTableManager` so prefixes resolve correctly.

Provision:

New tenants are provisioned automatically: creating a tenant (via `tenants:create` or any `TenantModel` insert) runs your tenant migration namespaces through the in-process prefix migrator, which seeds `TenantTableManager` per tenant and tracks applied versions per tenant in the package-owned `tenant_migrations` table. Disable with `$autoMigrateTenant = false`.

For existing tenants (or with auto-migration off), backfill manually:

```bash
# Runs your tenant migrations once per tenant with TenantTableManager seeded
php spark tenants:setup --mode=prefix

# Restrict to specific tenants
php spark tenants:setup --mode=prefix --tenants=1,3
```

Create a tenant migration:

```bash
php spark tenants:make-migration CreateStudentsTable --table=students
```

Then edit the generated file to reference tables via `TenantTableManager`:

```php
// app/Database/Migrations/Tenant/2024-01-15-000001_CreateStudentsTable.php
$tableManager = \nuelcyoung\tenantable\Services\TenantTableManager::getInstance();
$this->forge->createTable($tableManager->getTable('students'));
```

---

### Database mode (`isolationMode = 'database'`)

Complete database-per-tenant isolation.

Prerequisites:

- Set `$isolationMode = 'database'` (or `$separateDatabasePerTenant = true`).
- Set `$tenantMigrationsNamespace` (for example, `'App\Database\Migrations\Tenant'`).
- Your DB user must have `CREATE` privileges on MySQL/MariaDB, or `CREATEDB` on PostgreSQL.
- PostgreSQL needs an accessible maintenance database (`postgres` by default; configure `$postgresAdminDatabase` when needed).
- Install PHP's `ext-pgsql` when using PostgreSQL.

How auto-provisioning works:

When a tenant is created via `TenantModel::insert()`, the package automatically:

1. Creates the tenant database (`tenant_{id}` by default).
2. Runs your tenant migrations (from `$tenantMigrationsNamespace`).
3. Fires the `tenantCreated` event.

Set `$autoCreateDatabase = false` to disable this and manage databases manually.

Create a tenant:

```bash
php spark tenants:create foodblog "Food Blog"
php spark tenants:create acme "Acme Corp" --domain=acme.com
php spark tenants:create demo "Demo Tenant" --inactive
```

Create tenant migrations:

```bash
php spark tenants:make-migration CreateUsersTable
php spark tenants:make-migration CreatePostsTable --table=posts
php spark tenants:make-migration AddStatusToOrders --table=orders
```

Include third-party package migrations:

If you use Shield (or any CI4 package with migrations), add its namespace to run on each tenant database:

```php
// app/Config/Tenantable.php
public array $tenantMigrationsNamespaces = [
    'CodeIgniter\Shield\Database\Migrations',    // Shield's users, auth_identities, etc.
];
```

Each new tenant database then automatically gets:

- Your custom tables (from `App\Database\Migrations\Tenant`).
- Shield's auth tables (from `CodeIgniter\Shield\Database\Migrations`).
- Any other package migrations you list.

Manual provisioning:

```bash
# CREATE DATABASE + run tenant migrations
php spark tenants:setup --mode=database --create-db

# Restrict to specific tenants
php spark tenants:setup --mode=database --create-db --tenants=1,3
```

Database naming:

The database name is derived dynamically (default: `tenant_{id}`). Nothing database-related is stored in the `tenants` table. Override with a custom generator:

```php
// Default: tenant_1, tenant_2, ...
public $databaseNameGenerator = null;

// Custom: myapp_acme, myapp_globex, ...
public $databaseNameGenerator = fn(array $tenant) => 'myapp_' . $tenant['subdomain'];
```

Credentials:

Driver, host, port, username, and password are inherited from `Config\Database::$default` (your `.env`). Only the database name changes per tenant.

> Security: credentials are never stored in the tenants table. Storing DB passwords inside the DB they unlock is a security foot-gun. Use one application-wide DB user with privileges on every tenant DB, and keep its credentials in `.env` or your secret manager.

If you need multi-server sharding (different host per tenant), register one CI4 DB group per shard in `Config\Database` and switch via your own logic.

### Switching isolation modes

Changing `$isolationMode` changes where tenant data — including **sessions** — physically lives. Moving off `database` mode merges what used to be per-tenant session storage (e.g. each tenant DB's `ci_sessions` table) into one shared store, so session cookies issued before the switch may suddenly resolve in another tenant's context.

Tenantable defends this at runtime: every session is stamped with the tenant it belongs to (`$bindSessionsToTenant`), and a session presented to a different tenant is destroyed. Still, treat a mode switch as a session-invalidation event:

1. Plan the data migration for your tables first (the package migrates schema, not data, between modes).
2. Before going live in the new mode, invalidate all existing sessions:
   - file handler — delete everything under `writable/session/`;
   - database handler — `TRUNCATE ci_sessions` in every database that has one;
   - Redis — delete the session keys for your configured prefix.
3. Alternatively (or additionally), set `$rejectUnboundSessions = true` for a release cycle: any session created before stamping existed is destroyed on first use instead of being adopted, forcing a clean re-login. Turn it back off once pre-switch sessions have aged out.
4. Expect all users to log in again — that is the correct outcome.

---

## 5. Register tenant identification filters

Tenantable resolves the current tenant using filters. Apply the filter that matches your identification strategy.

The canonical filter is **`identify_tenant`** with a `strategy=` argument
(`subdomain`, `domain`, `domain_or_subdomain`, `path`, `request_data`), e.g.
`'identify_tenant:strategy=domain'`. The per-strategy aliases below are
convenience wrappers and remain supported, but their underlying filter classes
are deprecated — prefer `identify_tenant:strategy=…` in new code.

### Available filters

| Filter alias | Strategy | Identifies by | Example |
|---|---|---|---|
| `tenant` / `tenant_subdomain` | `subdomain` | URL subdomain | `acme.example.com` |
| `tenant_domain` | `domain` | Custom domain (`tenant_domains`) | `acme.com` |
| `tenant_domain_or_subdomain` | `domain_or_subdomain` | Domain first, fallback to subdomain | `acme.com` or `acme.example.com` |
| `tenant_path` | `path` | First URL path segment | `/acme/dashboard` |
| `tenant_request` | `request_data` | Header `X-Tenant`, `?tenant=`, or `tenant` body field | `X-Tenant: acme` |

The `tenant_request` value is matched against the tenant **subdomain**, not a numeric ID.

All filters are auto-registered by the package via `Config\Tenantable::registerFilters()`.

### Apply globally (most common)

In `app/Config/Filters.php`:

```php
public array $globals = [
    'before' => [
        // Pick ONE — matches your identification strategy
        'tenant_subdomain' => ['except' => ['health', '_health']],
    ],
];
```

### Mix strategies per route group

```php
// In app/Config/Routes.php
$routes->group('', ['filter' => 'tenant_subdomain'], function ($routes) {
    // Web routes — identified by subdomain
});
$routes->group('api', ['filter' => 'tenant_request'], function ($routes) {
    // API routes — identified by header or query param
});
```

### What the filter does

The filter's `before()` hook:

1. Identifies the tenant from the request.
2. Sets the tenant in `TenantManager`.
3. Calls `TenantBootstrap::initialize()->boot()`, which wires every subsystem listed in `Config\Tenantable::$bootstrappers` (database swap, table prefix, cache prefix, storage paths, session save path, log channel, tenant-scoped config).

---

## 6. Models

### Scaffolding with the CLI

```bash
# Row mode (tenant_id column) — default
php spark tenants:make-model Student

# Prefix mode (tenant_1_students, tenant_2_students, ...)
php spark tenants:make-model Student --prefix --table=students

# Global / central table (Plan, AdminUser, etc.)
php spark tenants:make-model Plan --global

# Override namespace
php spark tenants:make-model Student --namespace=App\Models\Tenancy
```

### What gets generated

| Flag | Extends | Notes |
|---|---|---|
| *(none)* | `TenantableModel` | Auto WHERE / INSERT `tenant_id` |
| `--prefix` | `CodeIgniter\Model` + `TenantTablePrefixTrait` | Resolves table name per tenant |
| `--global` | `GlobalModel` | Bound to `central` DB group |

### GlobalModel behaviour

`GlobalModel` permanently targets the `central` DB group:

- In row / prefix mode, the `central` group is lazily aliased to `default` by `TenantDatabaseManager::ensureCentralGroup()` — it just works.
- In database mode, it stays pinned to the original DB even when the default group is swapped to a tenant. Use it for tables like `tenants`, `plans`, and global users.

### Superadmin bypass

```php
use App\Models\Tenant\StudentModel;

// Preferred — restores bypass state via finally, exception-safe
$all = StudentModel::withoutTenant(fn () => (new StudentModel())->findAll());

// Or manual toggle — always pair enable/disable yourself. PackageEvents also
// clears the bypass flag on pre_system, so it can't leak into the next request.
StudentModel::enableTenantBypass();
$all = (new StudentModel())->findAll();
StudentModel::disableTenantBypass();
```

---

## 7. Security filter

Register `TenantSecurityFilter` (the `tenant_security` alias) as a **before**
filter after your tenant identification filter to:

- Enforce tenant presence on all requests.
- Strip tampered `tenant_id` / `school_id` / `org_id` fields from POST/GET (IDOR guard).

```php
// app/Config/Filters.php
public array $globals = [
    'before' => [
        'tenant_subdomain' => ['except' => ['health', '_health']],
        'tenant_security'  => ['except' => ['health', '_health']],
    ],
];
```

The `tenant_security` alias is auto-registered. No manual alias setup is needed.

> Subsystem shutdown and bypass-flag clearing happen automatically in
> `PackageEvents` on `post_system`/`pre_system` — not in the filter's
> `after()` — so you do **not** need to register `tenant_security` as an
> `after` filter.

---

## 8. CLI and background jobs

CLI requests bypass `TenantFilter` (it returns early on `CLIRequest`). To run code with tenant context outside HTTP:

### Programmatic tenant boot

```php
use nuelcyoung\tenantable\Bootstrap\TenantBootstrap;

TenantBootstrap::getInstance()
    ->initialize()
    ->bootForTenant($tenantId);
```

### Fan-out runner

Run any Spark command once per tenant:

```bash
# Run tenant migrations on every tenant database
php spark tenants:run migrate

# Rollback migrations on specific tenants
php spark tenants:run migrate:rollback --tenants=1,3

# Run a custom command per tenant
php spark tenants:run db:seed --seeder=DemoSeeder --tenants=1,3
```

How it works by mode:

| Mode | `migrate` | Other commands (incl. rollback/status) |
|------|-----------|----------------------------------------|
| database | In-process against each tenant's DB using `TenantDatabaseManager` — respects all configured migration namespaces | Subprocess with `TENANTABLE_TENANT_ID` env var |
| prefix | In-process prefix migrator — creates each tenant's prefixed tables in the shared DB, tracked per tenant in `tenant_migrations` | Subprocess with `TENANTABLE_TENANT_ID` env var |
| row | Subprocess with `TENANTABLE_TENANT_ID` env var | Subprocess with `TENANTABLE_TENANT_ID` env var |

In database and prefix modes, `tenants:run migrate` automatically runs migrations from both `$tenantMigrationsNamespace` and `$tenantMigrationsNamespaces` (for example, Shield) for each tenant. No manual DB switching is needed.

#### Running the fan-out faster

By default every tenant gets its own subprocess, one after another. That means
a full framework boot per tenant, which dominates the run past a few hundred
tenants. Two flags change that, and a third makes a failed run resumable.

```bash
# Eight tenants at a time
php spark tenants:run migrate --parallel=8

# No subprocess at all — allow-listed commands only
php spark tenants:run migrate --in-process

# Pick up where the last run failed
php spark tenants:run migrate --resume-from=57
```

**`--parallel=N`** (1–32) keeps up to N tenant processes alive at once. Each
tenant's output is buffered and printed when that tenant finishes: interleaving
several tenants' live output would make a failure impossible to attribute. A
forward migration re-enters `tenants:run` for its single tenant rather than
calling `spark migrate` directly, so the package's migrator and its per-tenant
version table still do the work.

Speedup is close to linear until the database becomes the bottleneck — N
concurrent connections doing DDL is a real load, so pick N against the
database, not the CPU count.

**`--in-process`** skips process spawning entirely and loops
`bootForTenant()` inside the running process. It is restricted to an
allow-list — `migrate`, `migrate:status`, `migrate:rollback`,
`migrate:refresh`, `db:seed` — because a command that caches, writes
fixed-name files, or calls `exit()` would carry state from one tenant into the
next. Tenant context is torn down after each tenant either way. `--parallel`
is ignored here: there is only one process.

**`--resume-from=ID`** skips tenants below that ID. The tenant list is ordered
by ID on every run, so the flag means the same thing each time.

Every run writes `writable/tenantable/last_run.json`:

```json
{
  "command": "migrate",
  "mode": "database",
  "parallel": 8,
  "succeeded": 118,
  "failed": 2,
  "resume_from": 57,
  "failures": [
    { "tenant_id": 57, "name": "Acme", "exit_code": 1, "output": "..." }
  ]
}
```

A run across hundreds of tenants scrolls its failures off the terminal long
before it finishes, so the report is the durable copy: which tenants failed,
what they printed (truncated per tenant), and the ID to resume from. A failing
tenant never aborts the run — the remaining tenants still get their turn.

### Queued jobs

A worker is a separate process with no request, no host header, and no filter
chain, so nothing in it knows which tenant a job belongs to. Push through
`tenant_push()` and the active tenant travels with the payload:

```php
// In a tenant request — the job is stamped with the current tenant.
tenant_push('emails', 'invoice', ['invoice_id' => $id]);
```

Write the job against `TenantableJob`:

```php
namespace App\Jobs;

use nuelcyoung\tenantable\Queue\TenantableJob;

class Invoice extends TenantableJob
{
    protected function handle(array $data): mixed
    {
        // tenant_id() is the tenant that pushed the job. Models, connection
        // and table prefix are already scoped to it.
        return (new \App\Models\Tenant\InvoiceModel())->find($data['invoice_id']);
    }
}
```

Register it the usual way in `app/Config/Queue.php`:

```php
public array $jobHandlers = [
    'invoice' => \App\Jobs\Invoice::class,
];
```

The worker boots that tenant before `handle()` runs and tears the context down
afterwards, whether the job succeeds or throws — a long-lived worker never
hands the next job the previous tenant's connection. `handle()` receives the
payload with the package's own key removed, so it only sees what was pushed.

`codeigniter4/queue` is a **suggestion, not a dependency**. Install it with
`composer require codeigniter4/queue`; without a queue, `tenant_push()` throws
`QueueUnavailableException` rather than dropping the job silently. Any handler
exposing `push($queue, $job, $data)` works — pass your own to
`new TenantableQueue($handler)`.

| Call | Runs as |
|------|---------|
| `tenant_push($queue, $job, $data)` | The tenant active at push time (central if none) |
| `central_push($queue, $job, $data)` | No tenant, even inside a tenant request |
| `(new TenantableQueue())->pushForTenant($id, ...)` | The named tenant — for central schedulers fanning work out |

A job pushed with no tenant runs centrally, so existing central jobs keep
working unchanged.

Two things worth knowing:

- **The tenant stamp is not caller-controlled.** A `_tenantable_tenant_id`
  already present in the payload is overwritten by the pushing context and the
  discrepancy is logged. Otherwise anyone able to enqueue a job could run it
  against another tenant's data. A payload key of your own named `tenant_id`
  keeps its meaning.
- **A job whose tenant no longer resolves fails.** Deleted or deactivated
  tenants throw out of the job instead of running the body untenanted, so the
  queue marks the job failed rather than executing it against whatever context
  the worker happened to hold.

Only `push()`, `pushCentral()` and `pushForTenant()` stamp. Anything else you
call on `TenantableQueue` is proxied to the underlying handler untouched — a
delayed or bulk enqueue has to stamp itself:

```php
$queue = new TenantableQueue();
$queue->later(TenantableQueue::stamp($data, tenant_id()), ...);
```

#### Provisioning tenants off the request

By default, creating a tenant provisions it inline: `CREATE DATABASE` plus
every tenant migration, inside the `afterInsert` callback of whatever request
made the tenant. On a signup form that is seconds of DDL the user waits
through, run inside whatever transaction the request had open.

Move it to the queue:

```php
// app/Config/Tenantable.php
public bool $provisionAsync = true;
```

```php
// app/Config/Queue.php
public array $jobHandlers = [
    'tenantable:provision' => \nuelcyoung\tenantable\Jobs\ProvisionTenantJob::class,
];
```

```bash
php spark migrate --all          # adds the `status` column to `tenants`
php spark queue:work tenantable  # the queue the provisioning job is pushed to
```

Creating a tenant now returns as soon as the row is written. The row carries a
`status` column:

| Status | Meaning |
|--------|---------|
| `provisioning` | The row exists; its database or tables do not yet. |
| `ready` | Provisioned and servable. This is the default for every existing row. |
| `failed` | Provisioning ran and failed. Needs an operator. |

**A tenant that is not `ready` does not resolve.** `TenantManager` throws
`TenantNotReadyException` for it — which extends `TenantInactiveException`, so
an application that already renders an "unavailable" page keeps working with no
code change. Without that gate the tenant would resolve while its database was
still being created and every query would fail.

The `tenantCreated` event fires when provisioning finishes rather than at
insert, because that is the first moment the tenant can serve a request.

Two failure modes worth knowing:

- **No queue reachable.** If the push fails, the tenant is provisioned inline
  instead and the reason is logged at error level. A slow signup beats a tenant
  stuck in `provisioning` forever.
- **Provisioning throws.** The tenant is marked `failed` and the exception is
  re-thrown so the queue records the job as failed. It is deliberately not
  retried: a half-created database or a broken migration needs a person, and a
  retry loop would keep re-running DDL against it.

Rows written before this migration — and every row in an installation that
never enables the flag — default to `ready`, so nothing about existing
resolution changes. With `$provisionAsync = false` the provisioning path is
exactly what it was: inline, in the request, no status stamping, no queue.

#### Keep the queue table central

In **database** isolation mode the default connection group is repointed at the
active tenant's database. A queue configured against that same group would
therefore write jobs into the tenant database, where no worker looks and no
queue table exists — nothing errors, the job is simply lost.

Give the queue its own group:

```php
// app/Config/Database.php — a group tenancy never rewrites
public array $central_queue = [ /* ... central credentials ... */ ];

// app/Config/Queue.php
public array $database = ['dbGroup' => 'central_queue', /* ... */];
```

The bundled `QueueSystem` bootstrapper detects the shared-group case and logs
it at error level once per process. It does not rewrite the setting: which
connection holds the queue is the application's decision.

---

## 9. Helper functions

| Function | Returns |
|---|---|
| `tenant_id()` | Current tenant ID or `null` |
| `tenant()` | Current tenant row (array) or `null` |
| `tenant_subdomain()` | Current subdomain or `null` |
| `has_tenant()` | `true` if a tenant is resolved |
| `tenant_url($path, $subdomain?)` | Subdomain-form tenant URL `https://{subdomain}.{baseDomain}/{path}` (scheme follows `App::$baseURL`). For path/domain strategies, build URLs yourself. |
| `central(callable)` | Run a callback in central (no-tenant) context, restore previous tenant after |
| `tenancy_run(int $tenantId, callable)` | Run a callback inside a specific tenant context, then clean up |
| `tenant_push($queue, $job, $data)` | Queue a job that runs in the tenant that pushed it |
| `central_push($queue, $job, $data)` | Queue a job that runs with no tenant context |
| `can_bypass_tenant()` | `true` if the authenticated user is in any `$superadminGroups` |

### `central()` usage

`central()` is the DX wrapper around `TenantBootstrap::runCentral()`. Use it inside a tenant request when you need to query a central table without the tenant DB swap or row filter interfering:

```php
$plan = central(fn () => (new \App\Models\PlanModel())->find($planId));
```

### `tenancy_run()` usage

Use `tenancy_run()` in background jobs, queue workers, or anywhere you need to act as a specific tenant:

```php
$report = tenancy_run($tenantId, function () {
    return (new InvoiceModel())->where('status', 'unpaid')->findAll();
});
```

The function bootstraps the tenant, runs your callback, then shuts down and clears context in a `finally` block so state does not leak.

---

## 10. Early tenant detection

Early detection establishes tenant context in `pre_system`, before CodeIgniter
initialises sessions, cache, and storage. A common reason to use it is a custom
session handler in `Events.php` that needs `tenant_id` immediately.

It is wired automatically by `PackageEvents::register()` (added to
`app/Config/Events.php` by the installer) and controlled entirely by config —
**do not register a `pre_system` listener yourself**, or `detect()` will run
twice per request:

```php
// app/Config/Tenantable.php
// 'subdomain' | 'domain' | 'domain_or_subdomain' | 'off'
public string $earlyDetectionStrategy = 'domain_or_subdomain';
```

Set it to `'off'` to disable early detection. In the normal flow the tenant
filter runs in `before` and the booted `SessionSystem`, `CacheSystem`, and
`StorageSystem` configure paths and prefixes for you, so early detection only
matters when something must be tenant-scoped before the filter chain runs.

When early detection is enabled and you use the **file** session handler, you
can leave `session.savePath` blank in `Config\Session.php` — the detector sets
it dynamically (and shutdown restores whatever value you had, including blank).
For database/Redis/Memcached handlers `savePath` is a table name or connection
string; the package never rewrites it. Those handlers are isolated by the
per-tenant cookie name (`$perTenantSessionCookies`) and the tenant stamp
enforced by `$bindSessionsToTenant`.

Register early detection whenever another `before` filter (for example
Shield's `session` auth filter) may start the session before the tenant filter
runs — session config mutated after the session has started has no effect for
that request.

---

## 11. Verify

```bash
# Create a tenant
php spark tenants:create demo "Demo Tenant"

# List tenants
php spark tenants:list
php spark tenants:list --active

# Smoke-test the fan-out runner
php spark tenants:run env

# Check the deployment for tenancy misconfiguration
php spark tenants:doctor
```

If `tenants:create` provisions the database and runs migrations, and `tenants:list` prints your configured tenants, you are wired up correctly.

### Health check

```bash
php spark tenants:doctor
```

None of the mistakes this catches throw on their own. They surface later as
users randomly logged out, jobs that vanish, tenants stuck in provisioning, and
uploads that 404 on one node out of three. `tenants:doctor` checks for them and
**exits non-zero on anything critical**, so it can gate a deploy:

```yaml
# CI, before the release step
- run: php spark tenants:doctor
```

What it inspects:

| Check | Critical when |
|-------|---------------|
| Session handler | Sessions are on local disk (`FileHandler`) |
| Cache handler | The cache is on local disk (`file`); the `dummy` handler is a warning |
| Queue connection | A database queue shares the connection group tenancy repoints |
| Async provisioning | `$provisionAsync` is on with no queue, no registered job handler, or no `status` column |
| Writable paths | Tenant uploads or fan-out reports have nowhere to go |
| Prefix-mode scale | Warning past `$prefixTenantWarningThreshold` (default 200) |
| Framework version | Warning outside the CI4 range the package's internals are tested against |

Options:

- `--strict` — also exit non-zero on warnings.
- `--json` — emit the findings as JSON for a dashboard or a log pipeline.

Every finding carries an id, what is wrong, and the fix. A clean run prints
`No problems found.` and exits 0.

---

## 12. Local development

When developing locally with tools like Laravel Herd or Valet, your site is typically served under a `.test` domain (for example, `myapp.test`). Subdomain-based tenancy works with these dev TLDs — you just need to ensure DNS resolves tenant subdomains.

### Configure baseDomain

Set `baseDomain` to your local dev domain:

```php
// app/Config/Tenantable.php
public string $baseDomain = 'myapp.test';
```

Or via environment variable (takes precedence over config):

```ini
# .env
TENANT_BASE_DOMAIN = myapp.test
```

### DNS for subdomains

Your base domain (for example, `myapp.test`) resolves automatically, but subdomain URLs like `acme.myapp.test` need DNS configuration.

| Platform | Wildcard DNS | Setup |
|----------|--------------|-------|
| macOS (Herd/Valet) | Built-in | No extra setup needed |
| Windows (Herd) | Not built-in | Add entries to your `hosts` file, or install [Acrylic DNS Proxy](https://mayakron.altervista.org/support/acrylic/Home.htm) for `*.myapp.test` wildcard support |
| Linux | Not built-in | Use `dnsmasq` with `address=/.myapp.test/127.0.0.1` |

### Quick local hosts file example (Windows)

If you do not want to run a local DNS proxy, add one line per tenant to `C:\Windows\System32\drivers\etc\hosts`:

```
127.0.0.1  myapp.test
127.0.0.1  acme.myapp.test
127.0.0.1  demo.myapp.test
```

Flush your DNS cache after editing:

```powershell
ipconfig /flushdns
```

### Skip DNS with path-based identification

If setting up wildcard DNS is inconvenient, switch to path-based identification during local development:

```php
public string $identificationMethod = 'tenant_path';
```

Then visit `/acme/dashboard` instead of `acme.myapp.test/dashboard`.

---

## 13. Troubleshooting

### "Tenant not found" on every request

- Check that `$baseDomain` matches the domain you are browsing to.
- Verify the tenant exists in the `tenants` table and `is_active` is `1`.
- If you are using a custom domain, make sure it is registered in `tenant_domains` (not just `tenants.domain`, which is no longer the primary store).
- Check `$trustedHostPatterns` if host validation is rejecting the request.

### Models return data from the wrong tenant (row mode)

- Confirm the model uses `TenantableTrait` or extends `TenantableModel`.
- Confirm a tenant is actually resolved (`has_tenant()` returns `true`).
- Enable `$strictTenantIsolation = true` to fail closed instead of silently querying all rows.

### Prefix mode tables are not prefixed

- Confirm the model extends `TenantTablePrefixModel` (or uses `TenantTablePrefixTrait`).
- Confirm `TenantTableManager` has been seeded. The filter and CLI commands do this automatically; manual setups need to call `setTenant()`.

### Database mode does not create tenant databases

- Confirm `$isolationMode = 'database'` and `$autoCreateDatabase = true`.
- Confirm the DB user in `.env` has `CREATE` privileges.
- Check the application logs for migration errors after `CREATE DATABASE`.

### CLI commands act on the central database

CLI requests have no tenant context by default. Wrap the work in `tenancy_run()` or use `tenants:run` to target a tenant.

---

That is the full setup. If something still feels unclear, open an issue with the command you ran and the error you saw.
