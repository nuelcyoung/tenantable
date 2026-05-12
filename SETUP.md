# Setup Guide — Tenantable

Step-by-step integration for a CodeIgniter 4 application.

For the conceptual overview, architecture trade-offs, and usage examples, see [`README.md`](README.md).

---

## Table of Contents

1. [Install](#1-install)
2. [Publish & Configure](#2-publish--configure)
3. [Provision the Tenants Table](#3-provision-the-tenants-table)
4. [Configure Tenant Isolation](#4-configure-tenant-isolation)
5. [Register Tenant Identification Filters](#5-register-tenant-identification-filters)
6. [Models](#6-models)
7. [Security Middleware](#7-security-middleware)
8. [CLI & Background Jobs](#8-cli--background-jobs)
9. [Helper Functions](#9-helper-functions)
10. [Early Tenant Detection (Optional)](#10-early-tenant-detection-optional)
11. [Verify](#11-verify)

---

## 1. Install

```bash
composer require nuelcyoung/tenantable
```

The package auto-registers these Spark commands via `composer.json`:

| Command | Purpose |
|---------|---------|
| `tenants:setup` | Provision storage for the configured isolation mode |
| `tenants:create` | Create a new tenant (with auto-provisioning in database mode) |
| `tenants:list` | List all (or `--active` / `--inactive`) tenants |
| `tenants:run` | Run any Spark command once per tenant |
| `tenants:make-model` | Scaffold a tenant-scoped or global model |
| `tenants:make-migration` | Scaffold a tenant migration file |

The helper file `src/Helpers/tenantable_helper.php` is auto-loaded — `tenant_id()`, `tenant()`, `central()`, etc. are globally available.

---

## 2. Publish & Configure

Copy the package config to your app:

```bash
cp vendor/nuelcyoung/tenantable/src/Config/Tenantable.php app/Config/Tenantable.php
```

Open `app/Config/Tenantable.php` and adjust the settings below.

### Core Settings

```php
public string $baseDomain = 'example.com';
```

### Isolation Mode

Choose one of three strategies:

```php
// 'row' | 'prefix' | 'database'
// Leave null to derive from $separateDatabasePerTenant
public ?string $isolationMode = null;

public bool   $separateDatabasePerTenant = false;
public string $defaultDatabaseGroup      = 'default';
```

> **Tip:** When `$isolationMode` is `null`, the package derives it automatically:
> `$separateDatabasePerTenant = true` → `'database'`, otherwise → `'row'`.

### Database-per-Tenant Settings

Only relevant when `$isolationMode = 'database'`:

```php
// Auto-provision: CREATE DATABASE + run migrations on tenant insert
public bool $autoCreateDatabase = true;
public bool $autoMigrateTenant  = true;

// Custom DB naming (default: tenant_{id})
// Receives the tenant row array, returns the database name string
public $databaseNameGenerator = null;
```

### Tenant Migrations Namespace

Required for `prefix` and `database` modes. Points to your app's per-tenant migrations:

```php
public ?string $tenantMigrationsNamespace = 'App\Database\Migrations\Tenant';
```

### Third-Party Migrations (e.g. Shield)

If you use packages like CodeIgniter Shield that have their own migrations, you can run them on each tenant database automatically — no need to copy migration files:

```php
public array $tenantMigrationsNamespaces = [
    'CodeIgniter\Shield',
];
```

The primary `$tenantMigrationsNamespace` is always included. This array adds **additional** namespaces. Leave it empty (`[]`) if you only need your own tenant migrations.

### Identification Method

Which filter strategy to use for resolving tenants from requests:

```php
// Options: 'tenant_subdomain', 'tenant_domain', 'tenant_domain_or_subdomain',
//          'tenant_path', 'tenant_request'
public string $identificationMethod = 'tenant_subdomain';
```

### Model Namespaces

Where `tenants:make-model` places generated models:

```php
public string $tenantModelsNamespace = 'App\Models\Tenant';
public string $globalModelsNamespace = 'App\Models';
```

### Behaviour & Security

```php
public array  $superadminGroups = ['superadmin'];
public array  $bypassRoutes    = ['api/*', 'health', '_health'];
public bool   $allowLocalhost  = true;

public bool    $throwExceptions = false;
public ?string $notFoundView    = null;   // View shown when tenant not found
public ?string $inactiveView    = null;   // View shown when tenant is inactive

public ?int $fallbackTenantId = null;
```

### Caching

```php
public bool $cacheTenantData = true;
public int  $cacheTtl        = 3600;     // seconds
```

### Subdomain Validation Rules

```php
public array $subdomainRules = [
    'min_length' => 2,
    'max_length' => 50,
    'pattern'    => '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/',
];
```

### Bootstrap Systems

The subsystems initialised when a tenant is booted. Remove any you don't need (e.g. `session` when using JWT, `storage` when using S3):

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

## 3. Provision the Tenants Table

Run once per environment to create the central `tenants` table:

```bash
php spark tenants:setup
```

This runs the package's internal migration (`CreateTenantsTable`) against the `default` DB group.

---

## 4. Configure Tenant Isolation

After the tenants table exists, set up isolation for your chosen mode.

### Row Mode (`isolationMode = 'row'`)

The simplest mode — shared tables with a `tenant_id` column.

**No additional setup required** beyond Step 3. Run your normal app migrations to add `tenant_id` columns to tenant-scoped tables:

```bash
php spark migrate
```

---

### Prefix Mode (`isolationMode = 'prefix'`)

Shared database, per-tenant table prefixes (`tenant_{id}_students`, etc.).

**Prerequisites:**
- Set `$tenantMigrationsNamespace` in config (e.g. `'App\Database\Migrations\Tenant'`)
- Tenant migrations **must** reference tables via `TenantTableManager` so prefixes resolve correctly

**Provision:**

```bash
# Runs your tenant migrations once per tenant with TenantTableManager seeded
php spark tenants:setup --mode=prefix

# Restrict to specific tenants
php spark tenants:setup --mode=prefix --tenants=1,3
```

**Create a tenant migration:**

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

### Database Mode (`isolationMode = 'database'`)

Complete database-per-tenant isolation.

**Prerequisites:**
- Set `$isolationMode = 'database'` (or `$separateDatabasePerTenant = true`)
- Set `$tenantMigrationsNamespace` (e.g. `'App\Database\Migrations\Tenant'`)
- Your DB user must have `CREATE` privileges

**How auto-provisioning works:**

When a tenant is created via `TenantModel::insert()`, the package automatically:
1. Creates the database (`CREATE DATABASE IF NOT EXISTS tenant_{id}`)
2. Runs your tenant migrations (from `$tenantMigrationsNamespace`)
3. Fires the `tenantCreated` event

Set `$autoCreateDatabase = false` to disable this and manage databases manually.

**Create a tenant:**

```bash
php spark tenants:create foodblog "Food Blog"
php spark tenants:create acme "Acme Corp" --domain=acme.com
php spark tenants:create demo "Demo Tenant" --inactive
```

**Create tenant migrations:**

```bash
php spark tenants:make-migration CreateUsersTable
php spark tenants:make-migration CreatePostsTable --table=posts
php spark tenants:make-migration AddStatusToOrders --table=orders
```

**Include third-party package migrations:**

If you use Shield (or any CI4 package with migrations), add its namespace to run on each tenant database:

```php
// app/Config/Tenantable.php
public array $tenantMigrationsNamespaces = [
    'CodeIgniter\Shield',    // Shield's users, auth_identities, etc.
];
```

This means each new tenant database automatically gets:
- Your custom tables (from `App\Database\Migrations\Tenant`)
- Shield's auth tables (from `CodeIgniter\Shield`)
- Any other package migrations you list

**Manual provisioning:****

```bash
# CREATE DATABASE + run tenant migrations
php spark tenants:setup --mode=database --create-db

# Restrict to specific tenants
php spark tenants:setup --mode=database --create-db --tenants=1,3
```

**Database naming:**

The database name is **derived dynamically** (default: `tenant_{id}`) — nothing database-related is stored in the `tenants` table. Override with a custom generator:

```php
// Default: tenant_1, tenant_2, ...
public $databaseNameGenerator = null;

// Custom: myapp_acme, myapp_globex, ...
public $databaseNameGenerator = fn(array $tenant) => 'myapp_' . $tenant['subdomain'];
```

**Credentials:**

Driver, host, port, username, and password are inherited from `Config\Database::$default` (your `.env`). Only the database name changes per tenant.

> **Security:** Credentials are never stored in the tenants table. Storing DB passwords inside the DB they unlock is a security foot-gun. Use one application-wide DB user with privileges on every tenant DB and keep its credentials in `.env` / your secret manager.

If you need multi-server sharding (different host per tenant), register one CI4 DB group per shard in `Config\Database` and switch via your own logic.

---

## 5. Register Tenant Identification Filters

Tenantable identifies which tenant a request belongs to using **filters**. Apply the filter that matches your identification strategy.

### Available Filters

| Filter Alias | Class | Identifies By | Example |
|---|---|---|---|
| `tenant` / `tenant_subdomain` | `SubdomainFilter` | URL subdomain | `acme.example.com` |
| `tenant_domain` | `DomainFilter` | Custom domain (`tenants.domain`) | `acme.com` |
| `tenant_domain_or_subdomain` | `DomainOrSubdomainFilter` | Domain first, fallback to subdomain | `acme.com` or `acme.example.com` |
| `tenant_path` | `PathFilter` | First URL path segment | `/acme/dashboard` |
| `tenant_request` | `RequestDataFilter` | Header, query param, or body field | `X-Tenant-ID: acme` |

All filters are auto-registered by the package via `Config\Tenantable::registerFilters()`.

### Apply Globally (Most Common)

In `app/Config/Filters.php`:

```php
public array $globals = [
    'before' => [
        // Pick ONE — matches your identification strategy
        'tenant_subdomain' => ['except' => ['health', 'api/*']],
    ],
];
```

### Mix Strategies Per Route Group

```php
// In app/Config/Routes.php
$routes->group('', ['filter' => 'tenant_subdomain'], function ($routes) {
    // Web routes — identified by subdomain
});
$routes->group('api', ['filter' => 'tenant_request'], function ($routes) {
    // API routes — identified by header/query param
});
```

### What the Filter Does

The filter's `before()` hook:
1. Identifies the tenant from the request
2. Sets the tenant in `TenantManager`
3. Calls `TenantBootstrap::initialize()->boot()` which wires every subsystem listed in `Config\Tenantable::$bootstrappers` (database swap, table prefix, cache prefix, storage paths, session save path, log channel, tenant-scoped config)

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

### What Gets Generated

| Flag | Extends | Notes |
|---|---|---|
| *(none)* | `TenantableModel` | Auto WHERE / INSERT `tenant_id` |
| `--prefix` | `CodeIgniter\Model` + `TenantTablePrefixTrait` | Resolves table name per tenant |
| `--global` | `GlobalModel` | Bound to `central` DB group |

### GlobalModel Behaviour

`GlobalModel` permanently targets the `central` DB group:
- In **row / prefix mode**, the `central` group is lazily aliased to `default` by `TenantDatabaseManager::ensureCentralGroup()` — it just works.
- In **database mode**, it stays pinned to the original DB even when the default group is swapped to a tenant. Use it for tables like `tenants`, `plans`, `users`, etc.

### Superadmin Bypass

```php
use App\Models\Tenant\StudentModel;

// Preferred — restores bypass state via finally, exception-safe
$all = StudentModel::withoutTenant(fn () => (new StudentModel())->findAll());

// Or manual toggle (cleared by TenantSecurityMiddleware::after())
StudentModel::enableTenantBypass();
$all = (new StudentModel())->findAll();
StudentModel::disableTenantBypass();
```

---

## 7. Security Middleware

Register `TenantSecurityMiddleware` after your tenant filter to:
- Enforce tenant presence on all requests
- Strip tampered `tenant_id` / `school_id` / `org_id` fields from POST/GET (IDOR guard)
- Shut down all bootstrapped subsystems at end of request
- Clear bypass flags to prevent state bleed

```php
// app/Config/Filters.php
public array $globals = [
    'before' => [
        'tenant_subdomain' => ['except' => ['health', 'api/*']],
        'tenant_security'  => ['except' => ['health', 'api/*']],
    ],
    'after' => [
        'tenant_security'  => ['except' => ['health', 'api/*']],
    ],
];
```

The `tenant_security` alias is auto-registered. No manual alias setup needed.

---

## 8. CLI & Background Jobs

CLI requests bypass `TenantFilter` (it returns early on `CLIRequest`). To run code with tenant context outside HTTP:

### Programmatic Tenant Boot

```php
use nuelcyoung\tenantable\Bootstrap\TenantBootstrap;

TenantBootstrap::getInstance()
    ->initialize()
    ->bootForTenant($tenantId);
```

### Fan-Out Runner

Run any Spark command once per tenant:

```bash
# Runs `php spark migrate` once per active tenant
php spark tenants:run migrate

# Specific tenants only
php spark tenants:run db:seed --seeder=DemoSeeder --tenants=1,3
```

`tenants:run` exposes the current tenant ID to each sub-process via the `TENANTABLE_TENANT_ID` env variable — pick it up in your command if needed.

---

## 9. Helper Functions

| Function | Returns |
|---|---|
| `tenant_id()` | Current tenant ID or `null` |
| `tenant()` | Current tenant row (array) or `null` |
| `tenant_subdomain()` | Current subdomain or `null` |
| `has_tenant()` | `true` if a tenant is resolved |
| `tenant_url($path, $subdomain?)` | Tenant-scoped URL (scheme follows `App::$baseURL`) |
| `central(callable)` | Run a callback in central (no-tenant) context, restore previous tenant after |
| `can_bypass_tenant()` | `true` if the authenticated user is in any `$superadminGroups` |

### `central()` Usage

`central()` is the DX wrapper around `TenantBootstrap::runCentral()`. Use it inside a tenant request when you need to query a central table without the tenant DB swap / row filter interfering:

```php
$plan = central(fn () => (new \App\Models\PlanModel())->find($planId));
```

---

## 10. Early Tenant Detection (Optional)

Only needed if you must establish tenant context **before** CodeIgniter's filter chain runs (e.g. a custom session handler initialised in `Events.php` that needs `tenant_id` immediately).

```php
// app/Config/Events.php
use nuelcyoung\tenantable\Bootstrap\EarlyTenantDetector;

Events::on('pre_system', [EarlyTenantDetector::class, 'detect'], 1);
```

> **Note:** In the normal flow, `TenantFilter` runs in `before` and the booted `SessionSystem` / `CacheSystem` / `StorageSystem` configure paths and prefixes for you — early detection is **not required**. Only enable it when you have evidence that the default flow runs too late.

When enabling early detection, leave `session.savePath` blank in `Config\Session.php` — the detector sets it dynamically.

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
```

If `tenants:create` provisions the database and runs migrations, and `tenants:list` prints your configured tenants, you're wired up correctly.
