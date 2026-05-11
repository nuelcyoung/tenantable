# Setup Guide — Tenantable

Step-by-step setup for integrating Tenantable into a CodeIgniter 4 application.

For the conceptual overview and architecture trade-offs, see `README.md`.

---

## 1. Install

```bash
composer require nuelcyoung/tenantable
```

The package auto-registers four Spark commands via `composer.json#extra.codeigniter4.commands`:

| Command | Purpose |
|---------|---------|
| `tenants:setup` | Provision storage for the configured isolation mode |
| `tenants:list` | List all (or `--active` / `--inactive`) tenants |
| `tenants:run` | Run any Spark command once per tenant |
| `tenants:make-model` | Scaffold a tenant-scoped or global model |

The helper file `src/Helpers/tenantable_helper.php` is auto-loaded — `tenant_id()`, `tenant()`, `central()`, etc. are globally available.

---

## 2. Publish the package config

Copy `vendor/nuelcyoung/tenantable/src/Config/Tenantable.php` to `app/Config/Tenantable.php` and adjust:

```php
public string $baseDomain = 'example.com';

// 'row' | 'prefix' | 'database' — leave null to derive from $separateDatabasePerTenant
public ?string $isolationMode = 'row';

public bool   $separateDatabasePerTenant = false;
public string $defaultDatabaseGroup      = 'default';

// Required for 'prefix' and 'database' modes
public ?string $tenantMigrationsNamespace = 'App\Database\TenantMigrations';

// Used by tenants:make-model
public string $tenantModelsNamespace = 'App\Models\Tenant';
public string $globalModelsNamespace = 'App\Models';

public array $superadminGroups = ['superadmin'];

public array $bypassRoutes = ['api/*', 'health', '_health'];

public bool $allowLocalhost = true;
```

Other knobs worth knowing: `$throwExceptions`, `$notFoundView`, `$inactiveView`, `$cacheTenantData`, `$cacheTtl`, `$subdomainRules`, and `$bootstrappers` (the registered subsystems — remove any you don't need, e.g. `session` when using JWT, `storage` when using S3).

---

## 3. Provision the tenants table

Run once per environment:

```bash
php spark tenants:setup
```

This runs the package's internal migration (`CreateTenantsTable`) against the `default` DB group and prints next steps for your chosen mode.

For prefix / database modes:

```bash
# Prefix mode — runs your tenant migrations once per tenant with the table manager seeded
php spark tenants:setup --mode=prefix

# Database mode — optionally CREATE DATABASE, then migrate each tenant DB
# Uses your default DB credentials (.env / Config\Database::$default) for the
# admin connection. The application's DB user must have CREATE privileges.
php spark tenants:setup --mode=database --create-db

# Restrict to specific tenants
php spark tenants:setup --tenants=1,3
```

Prefix-mode migrations must reference tables via the table manager so prefixes resolve correctly:

```php
$tableManager = \nuelcyoung\tenantable\Services\TenantTableManager::getInstance();
$this->forge->createTable($tableManager->getTable('students'));
```

### Database-per-tenant credentials

The `tenants` table stores **only** `database_name`. Driver, host, port, username,
and password are inherited from `Config\Database::$default` (your `.env`) every time
`TenantDatabaseManager` swaps in a tenant connection.

This is deliberate: storing DB passwords inside the DB they unlock is a security
foot-gun. Use one application-wide DB user (with privileges on every tenant DB) and
keep its credentials in `.env` / your secret manager.

If you need multi-server sharding (different host per tenant), register one CI4 DB
group per shard in `Config\Database` and switch via your own logic — do not put
credentials back in the `tenants` table.

---

## 4. Register the tenant filter

In `app/Config/Filters.php`:

```php
public array $aliases = [
    // ...existing aliases
    'tenant' => \nuelcyoung\tenantable\Filters\TenantFilter::class,
];

public array $globals = [
    'before' => [
        'tenant' => ['except' => ['health', 'api/*']],
    ],
];
```

The default `tenant` alias resolves via subdomain. Pick a different identification strategy by swapping the class (or registering it under a different alias):

| Filter class | Identifies by |
|---|---|
| `Filters\SubdomainFilter` (default `tenant`) | `school1.example.com` |
| `Filters\DomainFilter` | Custom domain stored in `tenants.domain` |
| `Filters\DomainOrSubdomainFilter` | Domain first, falls back to subdomain |
| `Filters\PathFilter` | First URI segment, e.g. `/school1/dashboard` |
| `Filters\RequestDataFilter` | Header / query / body field |

The filter's `before()` calls `TenantBootstrap::initialize()->boot()`, which wires every subsystem listed in `Config\Tenantable::$bootstrappers` (database swap, table prefix, cache prefix, storage paths, session save path, log channel, tenant-scoped config).

### Security middleware (recommended)

Register `TenantSecurityMiddleware` after `tenant` to enforce tenant presence, strip tampered `tenant_id` POST/GET fields, and shut down booted subsystems at end of request:

```php
public array $aliases = [
    'tenant'         => \nuelcyoung\tenantable\Filters\TenantFilter::class,
    'tenant_security'=> \nuelcyoung\tenantable\Middleware\TenantSecurityMiddleware::class,
];

public array $globals = [
    'before' => [
        'tenant'          => ['except' => ['health', 'api/*']],
        'tenant_security' => ['except' => ['health', 'api/*']],
    ],
    'after' => [
        'tenant_security' => ['except' => ['health', 'api/*']],
    ],
];
```

---

## 5. Models

Generate the right shape with the scaffolder:

```bash
# row mode (tenant_id column) — default
php spark tenants:make-model Student

# prefix mode (tenant_1_students, tenant_2_students, ...)
php spark tenants:make-model Student --prefix --table=students

# global / central table (Plan, AdminUser, etc.)
php spark tenants:make-model Plan --global

# override the namespace once
php spark tenants:make-model Student --namespace=App\Models\Tenancy
```

What you get:

| Flag | Extends | Notes |
|---|---|---|
| (none) | `nuelcyoung\tenantable\Models\TenantableModel` | Auto WHERE/INSERT tenant_id |
| `--prefix` | `CodeIgniter\Model` + `TenantTablePrefixTrait` | Resolves table name per tenant |
| `--global` | `nuelcyoung\tenantable\Models\GlobalModel` | Bound to `central` DB group |

`GlobalModel` permanently targets the `central` DB group. In row/prefix mode that group is lazily aliased to `default` by `TenantDatabaseManager::ensureCentralGroup()`, so it just works. In database-per-tenant mode it stays pinned to the original DB even when the default group is swapped to a tenant — use it for `tenants`, `plans`, `users`, etc.

---

## 6. Optional: Early tenant detection

Only needed if you must establish tenant context *before* CodeIgniter's bootstrap (e.g. a custom session handler initialized in `Events.php` that needs `tenant_id` immediately).

```php
// app/Config/Events.php
use nuelcyoung\tenantable\Bootstrap\EarlyTenantDetector;

Events::on('pre_system', [EarlyTenantDetector::class, 'detect'], 1);
```

In the normal flow, `TenantFilter` runs in `before` and the booted `SessionSystem` / `CacheSystem` / `StorageSystem` configure paths and prefixes for you — early detection is **not required**. Only enable it when you have evidence that the default flow runs too late for your code.

When enabling early detection, leave `session.savePath` blank in `Config\Session.php` — the detector sets it dynamically.

---

## 7. CLI / queues / background jobs

CLI requests bypass `TenantFilter` (it returns early on `CLIRequest`). To run code with tenant context outside HTTP:

```php
use nuelcyoung\tenantable\Bootstrap\TenantBootstrap;

TenantBootstrap::getInstance()
    ->initialize()
    ->bootForTenant($tenantId);
```

Or use the built-in fan-out:

```bash
# Runs `php spark migrate` once per active tenant
php spark tenants:run migrate

# Specific tenants only
php spark tenants:run db:seed --seeder=DemoSeeder --tenants=1,3
```

`tenants:run` exposes the current tenant ID to each sub-process via the `TENANTABLE_TENANT_ID` env variable — pick it up in your command if needed.

---

## 8. Helper functions

| Function | Returns |
|---|---|
| `tenant_id()` | Current tenant ID or `null` |
| `tenant()` | Current tenant row or `null` |
| `tenant_subdomain()` | Current subdomain or `null` |
| `has_tenant()` | `true` if a tenant is resolved |
| `tenant_url($path, $subdomain = null)` | Tenant-scoped URL (scheme follows `App::$baseURL`) |
| `central(callable)` | Run a callback in central (no-tenant) context, restore previous tenant after |
| `can_bypass_tenant()` | `true` if the authenticated user is in any `$superadminGroups` |

`central()` is the DX wrapper around `TenantBootstrap::runCentral()`. Use it inside a tenant request when you need to query a central table without the tenant DB swap / row filter interfering:

```php
$plan = central(fn () => (new \App\Models\PlanModel())->find($planId));
```

---

## 9. Superadmin bypass

```php
use App\Models\Tenant\StudentModel;

if (can_bypass_tenant()) {
    $all = StudentModel::withoutTenant(fn () => (new StudentModel())->findAll());
}

// Or manual toggle (cleared automatically by TenantSecurityMiddleware::after())
StudentModel::enableTenantBypass();
$all = (new StudentModel())->findAll();
StudentModel::disableTenantBypass();
```

`withoutTenant()` is preferred — it restores the previous bypass state via `finally`, so an exception inside the callback can't leave bypass enabled.

---

## 10. Verify

```bash
php spark tenants:list           # Shows the tenants table
php spark tenants:list --active  # Filter

# Smoke-test the fan-out runner
php spark tenants:run env
```

If `tenants:list` prints the configured tenants and `tenants:run` reports a per-tenant exit code summary, you're wired up correctly.
