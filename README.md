![Tenantable Banner](https://banners.beyondco.de/Tenantable.png?theme=light&packageManager=composer+require&packageName=nuelcyoung%2Ftenantable&pattern=autumn&style=style_1&description=Codeigniter4+multi+tenant+made+easy&md=1&showWatermark=1&fontSize=100px&images=server)

[![Tests](https://github.com/nuelcyoung/tenantable/actions/workflows/tests.yml/badge.svg)](https://github.com/nuelcyoung/tenantable/actions)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%205-brightgreen.svg)](https://github.com/nuelcyoung/tenantable)
[![Latest Version](https://img.shields.io/packagist/v/nuelcyoung/tenantable.svg)](https://packagist.org/packages/nuelcyoung/tenantable)
[![Total Downloads](https://img.shields.io/packagist/dt/nuelcyoung/tenantable.svg)](https://packagist.org/packages/nuelcyoung/tenantable)
[![License](https://img.shields.io/packagist/l/nuelcyoung/tenantable.svg)](LICENSE)

> A flexible multitenancy package for CodeIgniter 4. Identify tenants by subdomain, custom domain, path, or request header, and isolate them with row-level scoping, table prefixes, or a dedicated database per tenant.

## Quick start

```bash
composer require nuelcyoung/tenantable
php spark tenants:install
```

Then apply a tenant filter to your routes:

```php
// app/Config/Filters.php
public array $globals = [
    'before' => [
        'tenant_subdomain' => ['except' => ['health', '_health']],
    ],
];
```

For the full walkthrough, see [SETUP.md](SETUP.md).

## Why Tenantable?

Most SaaS projects outgrow a single-database setup eventually, but moving to multitenancy is usually painful. Tenantable tries to remove the friction:

- Zero-config tenant detection from subdomains, domains, paths, or request data.
- Three isolation strategies so you can start simple and upgrade later.
- Automatic provisioning (in database mode) that creates a tenant's database and runs its migrations in one step.
- Shield-ready migrations that run third-party package migrations per tenant automatically.

## Features

- Flexible tenant identification by subdomain, custom domain, path segment, or request data.
- Three isolation strategies: row-level `tenant_id`, table prefix, or database-per-tenant.
- Automatic provisioning: in database mode a tenant's database is created and migrated when the tenant is created; in prefix mode the tenant's prefixed tables are migrated on creation (gated by `$autoMigrateTenant`). Backfill existing tenants with `tenants:setup`.
- Third-party migration support: run Shield or other package migrations on each tenant database.
- Automatic tenant context: models respect tenant boundaries once a tenant is resolved.
- Superadmin bypass: platform admins can query across tenants when needed.
- CLI scaffolding and commands for creating tenants, models, migrations, and fan-out jobs.

## Requirements

- PHP 8.1 or newer
- CodeIgniter 4.4 or newer
- `ext-pgsql` when using PostgreSQL

## Installation

```bash
composer require nuelcyoung/tenantable
php spark tenants:install
```

`tenants:install` is interactive on the first run. It asks for your base domain, isolation mode, identification strategy, and (in database mode) whether to ship the bundled sessions-table migration; then publishes `app/Config/Tenantable.php`, patches `app/Config/Filters.php` and `app/Config/Events.php` idempotently, scaffolds `app/Database/Migrations/Tenant/`, and runs `tenants:setup` to create the central tenants tables. Re-running it is safe.

For CI or non-interactive installs:

```bash
php spark tenants:install --base-domain=example.com --mode=prefix --strategy=domain_or_subdomain --yes
```

Filter aliases (`tenant`, `tenant_subdomain`, `tenant_domain`, `tenant_domain_or_subdomain`, `tenant_path`, `tenant_request`, `tenant_security`, `identify_tenant`) are auto-registered through CI4's `Config\Registrar` discovery. You do not need to add them to `Config\Filters::$aliases` yourself.

---

## Architecture options

Tenantable supports three isolation strategies. Pick the one that matches your security needs and operational comfort.

| Strategy | How it works | Pros | Cons |
|----------|--------------|------|------|
| `tenant_id` | Shared tables with a `tenant_id` column | Simple to implement | Risk of data leakage if you miss a query |
| Table prefix | Separate tables per tenant (`tenant_1_students`) | No leakage between tenants | More moving parts |
| Separate database | Each tenant gets its own database | Complete isolation | Heaviest operational overhead |

---

## Tenant identification

Tenantable resolves the current tenant using filters. You choose the strategy by applying the right filter to your routes.

The canonical filter is **`identify_tenant`**, configured with a `strategy=` argument:

```php
'identify_tenant:strategy=subdomain'           // by subdomain
'identify_tenant:strategy=domain'              // by custom domain
'identify_tenant:strategy=domain_or_subdomain' // domain, then subdomain
'identify_tenant:strategy=path'                // by first path segment
'identify_tenant:strategy=request_data'        // by X-Tenant header / ?tenant= / tenant body field
```

### Available filters

The per-strategy aliases below are convenience wrappers around `identify_tenant`
and remain supported, but the underlying filter classes are deprecated — prefer
`identify_tenant:strategy=…` in new code.

| Filter alias | Strategy | Identifies by | Example |
|---|---|---|---|
| `tenant` / `tenant_subdomain` | `subdomain` | URL subdomain | `acme.example.com` |
| `tenant_domain` | `domain` | Custom domain stored in `tenant_domains` | `acme.com` |
| `tenant_domain_or_subdomain` | `domain_or_subdomain` | Domain first, falls back to subdomain | `acme.com` or `acme.example.com` |
| `tenant_path` | `path` | First URL path segment | `/acme/dashboard` |
| `tenant_request` | `request_data` | Header `X-Tenant`, `?tenant=`, or `tenant` body field | `X-Tenant: acme` |

The `tenant_request` value is matched against the tenant **subdomain**, not a numeric ID.

`tenant_path` and `tenant_request` use request-controlled values. They are not
authorization. Configure `Config\Tenantable::$tenantAuthorizer` and verify the
authenticated user's membership before the request is bootstrapped; without
that callback these two strategies fail closed with `403`.
Run the authentication filter before the tenant filter, or make the callback
validate the token directly.

```php
use CodeIgniter\HTTP\RequestInterface;

public $tenantAuthorizer = static function (RequestInterface $request, array $tenant): bool {
    return auth()->user()?->canAccessTenant((int) $tenant['id']) === true;
};
```

### How to configure

Register your chosen filter in `app/Config/Filters.php`:

```php
// Option A: apply globally
public array $globals = [
    'before' => [
        'tenant_subdomain' => ['except' => ['health', '_health']],
    ],
];

// Option B: apply per route group (you can mix strategies)
// In app/Config/Routes.php:
$routes->group('app', ['filter' => 'tenant_subdomain'], function ($routes) {
    // Web routes identified by subdomain
});
$routes->group('api', ['filter' => 'tenant_request'], function ($routes) {
    // API routes identified by header or query param
});
```

The default `tenant` alias maps to `SubdomainFilter`.

---

## Strategy 1: table prefix (recommended)

Best for: most applications. It removes the leakage risks that come with `tenant_id` scoping.

### How it works

```
students table → tenant_1_students, tenant_2_students, ...
classes table  → tenant_1_classes, tenant_2_classes, ...
```

### Setup

1. Run the central migration:

```bash
php spark migrate -g tenantable
```

2. Configure the filter:

```php
// app/Config/Filters.php
public array $globals = [
    'before' => [
        'tenant_subdomain' => ['except' => ['health', '_health']],
    ],
];
```

3. Write ordinary models:

```php
class StudentModel extends CodeIgniter\Model
{
    protected $table = 'students';
}

// With tenant_id = 1, this queries tenant_1_students automatically.
$students = $studentModel->findAll();
```

Prefix isolation needs **no model changes at all**. The active tenant's
prefix is applied to the connection as CodeIgniter's own `DBPrefix`, and the
query builder prepends it at compile time — so plain models, raw
`$db->table('students')` builders, and validation rules like
`is_unique[students.email]` all resolve to the tenant's table on their own.
Write table names un-prefixed everywhere and let the connection do the work.

Optionally add the trait to get clearer errors when no tenant is active —
reads return empty and writes throw `MissingTenantContextException`, instead
of the "table doesn't exist" you get from the fail-closed sentinel prefix:

```php
use nuelcyoung\tenantable\Traits\TenantTablePrefixTrait;

class StudentModel extends CodeIgniter\Model
{
    use TenantTablePrefixTrait;

    protected $table = 'students';
}
```

If your model defines its own `initialize()`, call
`$this->initializeTenantTablePrefixTrait()` from it — it overrides the
trait's otherwise.

### Configuration

```php
// app/Config/Tenantable.php
public $prefixFormat = 'tenant_{id}_{table}'; // Default format
public $baseDomain = 'example.com';            // Your production domain
```

### Caveats

- Central tables (the shared `sessions` table, a settings store) must run on their own un-prefixed connection group, since the default connection carries the tenant's prefix. Point them at the `central` group, or mark their models `setGlobalTable()`.
- Finish (or reset) a chained query before switching tenants. Clauses chained onto a model (`$model->where(...)`) belong to the tenant they were built under; switching tenants while clauses are pending throws instead of silently running a broader query against the new tenant's table.

---

## Strategy 2: shared database with tenant_id

Best for: simple applications with few tenants and a small team.

### Setup

1. Add `tenant_id` to your tables:

```bash
php spark make:migration add_tenant_id
```

2. Use the trait:

```php
use nuelcyoung\tenantable\Traits\TenantableTrait;

class StudentModel extends Model
{
    use TenantableTrait;
    protected $table = 'students';
}
```

The trait registers its tenant callbacks from `initialize()`, so no manual
wiring is needed. One caveat: if your model defines its **own**
`initialize()` method, it overrides the trait's — call the trait's
initializer from it, or the model runs unscoped:

```php
protected function initialize(): void
{
    $this->initializeTenantableTrait();
    // ... your own setup
}
```

Behaviour notes:

- The active tenant context always wins on insert — a user-supplied
  `tenant_id` is overwritten and logged, never honoured.
- `tenant_id` is immutable: `update()` strips it from the update data (a
  warning is logged), and `updateBatch()` pins it to the match constraint,
  so it can never be written to a different value.
- `update()`, `delete()` and `updateBatch()` only touch rows owned by the
  active tenant. Foreign rows are silently no-ops, not errors.
- `insertBatch()` stamps every row with the active tenant.
- `countAllResults()` (and therefore `paginate()` totals) is tenant-scoped,
  and returns `0` when no tenant is active.

CodeIgniter fires no model event around `countAllResults()`, so that one
method is carried by an override; everything else above rides CI4's native
model events.

### Validation: use the tenant-scoped rules

CodeIgniter's `is_unique` and `is_not_unique` build their query directly
from the connection, so they never pass through the model events the trait
uses to add the `tenant_id` predicate. Under row-level isolation
`is_unique[posts.slug]` therefore checks **every tenant's rows**: one tenant
taking a slug blocks it for all the others, and the error message reveals
that someone else already has it.

Use the scoped replacements instead. They take the same parameters, and add
the active tenant to the lookup:

```php
protected $validationRules = [
    'slug'      => 'required|is_unique_for_tenant[posts.slug,id,{id}]',
    'author_id' => 'required|is_not_unique_for_tenant[users.id]',
];
```

The rules are registered automatically — no `Config\Validation` changes
needed. Behaviour worth knowing:

- **Fail-closed.** With no active tenant the rule fails rather than widening
  to every tenant's rows.
- **Bypass-aware.** Inside a superadmin bypass the check spans tenants, matching
  what the trait does to queries.
- A fourth parameter overrides the tenant column for models that do not use
  the configured default: `is_unique_for_tenant[posts.slug,id,{id},account_id]`.
- **Strict or non-strict, same behaviour.** The rules reject arrays and
  objects before querying and cast everything else to string — precisely
  what `StrictRules\Rules::is_unique` adds over the non-strict rule — so
  registering either framework rule set gives identical semantics here.

Validation is a usability layer, not the guarantee — two concurrent requests
can both pass it. Back it with a composite unique index:

```php
$this->forge->addUniqueKey(['tenant_id', 'slug']);
```

Prefix and database isolation need none of this. There the tenant is carried
by the connection, so the framework's own `is_unique` already resolves to the
right table — use it directly.

### Warning: leakage risks

`tenant_id` scoping is easy to set up but easy to get wrong:

- Forgetting to add the trait to a model exposes all tenants.
- Raw SQL bypasses the trait.
- Joins can miss the `tenant_id` condition.
- Validation rules bypass the trait (see above).
- IDOR attacks become possible if you trust user-supplied IDs.

Table-prefix isolation removes those risks entirely: the tenant lives in the
connection, so raw SQL, joins, and validation are all scoped without the
model's cooperation.

---

## Strategy 3: separate database per tenant

Best for: enterprise apps or situations where compliance demands strict isolation.

### Configuration

```php
// app/Config/Tenantable.php
public bool   $separateDatabasePerTenant = true;
public ?string $isolationMode            = 'database';

// Point to your tenant-specific migrations
public ?string $tenantMigrationsNamespace = 'App\Database\Migrations\Tenant';

// Auto-provisioning (both true by default)
public bool $autoCreateDatabase = true;   // CREATE DATABASE on tenant insert
public bool $autoMigrateTenant  = true;   // Run migrations after creation

// PostgreSQL only: an existing database used to issue CREATE DATABASE.
public string $postgresAdminDatabase = 'postgres';

// Optional: custom database naming convention (default: tenant_{id})
public $databaseNameGenerator = null;

// Include third-party package migrations per tenant (e.g. Shield)
public array $tenantMigrationsNamespaces = [
    'CodeIgniter\Shield\Database\Migrations',
];

// Ship the bundled sessions-table migration into each tenant DB.
// CI4 only generates a session migration on request (php spark
// make:migration --session); the database session handler needs its
// table (ci_sessions) in every tenant database.
public bool $shipTenantSessionsTable = true;
```

### How it works

The database name is derived dynamically. It is never stored in the tenants table. The default convention is `tenant_{id}` (for example, `tenant_1`, `tenant_5`).

When you create a tenant:

```bash
php spark tenants:create acme "Acme Corp"
```

Or programmatically:

```php
$tenantModel->insert(['name' => 'Acme Corp', 'subdomain' => 'acme']);
```

The package does the following automatically:

1. Inserts the row into the `tenants` table.
2. Creates the tenant database (`tenant_1` by default).
3. Runs your tenant migrations from `$tenantMigrationsNamespace`.
4. Runs the bundled sessions-table migration when `$shipTenantSessionsTable` is enabled.
5. Runs third-party migrations from `$tenantMigrationsNamespaces` (for example, Shield).
6. Fires the `tenantCreated` event.

On each request, the filter identifies the tenant and swaps `Config\Database::$default` to point at the tenant's database. All models then query the correct DB transparently.

### Database credentials

Connection credentials (host, user, password, port) come from your `.env` file and `Config\Database::$default`. Only the database name changes per tenant. Your DB user needs `CREATE` privileges on MySQL/MariaDB, or `CREATEDB` plus access to `$postgresAdminDatabase` on PostgreSQL.

Credentials are never stored in the tenants table. Storing secrets inside the database they unlock is a security foot-gun.

### Custom naming

```php
// Default: tenant_1, tenant_2, ...
public $databaseNameGenerator = null;

// Custom: myapp_acme, myapp_globex, ...
public $databaseNameGenerator = fn(array $tenant) => 'myapp_' . $tenant['subdomain'];
```

### Usage

```php
// Automatically switches to the tenant's database
$school = tenant();                    // Connects to tenant_1
$students = $studentModel->findAll();  // Queries tenant_1.students
```

---

## Usage examples

### Helper functions

```php
// Get the current tenant ID
$tenantId = tenant_id();

// Get the full tenant row
$tenant = tenant();

// Check if a tenant context exists
if (has_tenant()) {
    // Safe to query
}

// Generate a tenant URL (subdomain form: https://{subdomain}.{baseDomain}/dashboard).
// Intended for subdomain tenancy; for path/domain strategies build URLs yourself.
$url = tenant_url('dashboard');

// Check if the current user can bypass tenant scoping
if (can_bypass_tenant()) {
    // Access all tenants
}

// Run code inside a specific tenant context
$result = tenancy_run(1, function () {
    return (new StudentModel())->findAll();
});

// Run code against the central database, then restore tenant context
$plan = central(fn () => (new PlanModel())->find($planId));
```

### Manual tenant setup

```php
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Services\TenantTableManager;

TenantManager::getInstance()->setTenantById(1);
TenantTableManager::getInstance()->setTenant(1, 'school1');
```

### Bypassing tenant scoping (superadmin)

```php
// Temporarily bypass for a specific query
Model::withoutTenant(function () {
    return Model::findAll(); // All tenants
});

// Or toggle manually
Model::enableTenantBypass();
// queries...
Model::disableTenantBypass();
```

---

## CLI commands

| Command | Purpose |
|---------|---------|
| `tenants:setup` | Provision the central tenants table |
| `tenants:create <subdomain> <name>` | Create a tenant (auto-provisions DB in database mode) |
| `tenants:list` | List tenants (`--active`, `--inactive`) |
| `tenants:run <command>` | Run a Spark command per tenant (auto-targets tenant DB in database mode) |
| `tenants:make-model <name>` | Scaffold a tenant or global model |
| `tenants:make-migration <name>` | Scaffold a tenant migration file |

### Examples

```bash
# Initial setup
php spark tenants:setup

# Create tenants
php spark tenants:create foodblog "Food Blog"
php spark tenants:create acme "Acme Corp" --domain=acme.com

# Scaffold migrations
php spark tenants:make-migration CreatePostsTable --table=posts
php spark tenants:make-migration CreateCategoriesTable

# Scaffold models
php spark tenants:make-model Post
php spark tenants:make-model Post --prefix --table=posts

# Run tenant migrations for every tenant (database and prefix modes)
php spark tenants:run migrate

# Rollback specific tenants
php spark tenants:run migrate:rollback --tenants=1,3
```

---

## Package structure

```
src/
├── Bootstrap/
│   ├── Systems/
│   │   ├── CacheSystem.php
│   │   ├── ConfigSystem.php
│   │   ├── DatabaseSystem.php
│   │   ├── LoggingSystem.php
│   │   ├── SessionSystem.php
│   │   ├── StorageSystem.php
│   │   └── TableSystem.php
│   ├── EarlyTenantDetector.php
│   ├── PackageEvents.php
│   ├── RedisSystem.php
│   ├── TenantAwareInterface.php
│   └── TenantBootstrap.php
├── Commands/
│   ├── TenantsCreate.php
│   ├── TenantsInstall.php
│   ├── TenantsList.php
│   ├── TenantsMakeMigration.php
│   ├── TenantsMakeModel.php
│   ├── TenantsRun.php
│   └── TenantsSetup.php
├── Config/
│   ├── Registrar.php
│   └── Tenantable.php
├── Controllers/
│   └── TenantAssetsController.php
├── Database/
│   └── Migrations/
│       ├── 2024-01-01-000001_CreateTenantsTable.php
│       ├── 2026-05-15-000000_AlterTenantsIsActiveNotNull.php
│       ├── 2026-05-15-000001_CreateTenantDomainsTable.php
│       └── 2026-05-15-000002_BackfillTenantDomainsFromTenants.php
├── Events/
│   ├── TenantCreated.php
│   ├── TenantDeleted.php
│   ├── TenantDomainChanged.php
│   ├── TenantUpdated.php
│   ├── TenancyEnded.php
│   └── TenancyInitialized.php
├── Exceptions/
│   ├── MissingTenantContextException.php
│   ├── TenantInactiveException.php
│   └── TenantNotFoundException.php
├── Filters/
│   ├── BaseTenantFilter.php
│   ├── DomainFilter.php
│   ├── DomainOrSubdomainFilter.php
│   ├── IdentifyTenant.php
│   ├── OriginHeaderFilter.php
│   ├── PathFilter.php
│   ├── RequestDataFilter.php
│   ├── SubdomainFilter.php
│   ├── TenantFilter.php
│   └── TenantSecurityFilter.php
├── Helpers/
│   └── tenantable_helper.php
├── Models/
│   ├── GlobalModel.php
│   ├── TenantableModel.php
│   ├── TenantDomainModel.php
│   └── TenantModel.php
├── Services/
│   ├── TenantDatabaseManager.php
│   ├── TenantManager.php
│   ├── TenantResolverCache.php
│   └── TenantTableManager.php
├── Support/
│   ├── InstallPatcher.php
│   └── TenantContextState.php
└── Traits/
    ├── TenantableTrait.php
    └── TenantTablePrefixTrait.php
```

---

## Database schema

The `tenants` table stores the core tenant record:

| Field | Type | Description |
|-------|------|-------------|
| `id` | INT | Primary key (auto-increment) |
| `subdomain` | VARCHAR(50) | Unique subdomain for `SubdomainFilter` |
| `name` | VARCHAR(255) | Display name |
| `is_active` | BOOLEAN | Tenant status |
| `settings` | JSON | Custom key-value settings |
| `created_at` | DATETIME | Created timestamp |
| `updated_at` | DATETIME | Updated timestamp |

Custom domains live in the `tenant_domains` table, which links back to `tenants.id`. Keeping domains in a separate table lets one tenant own multiple domains.

> The database name is not stored in either table. It is derived at runtime via `Config\Tenantable::$databaseNameGenerator` (default: `tenant_{id}`).

---

## Migration for existing apps

### Option A: table prefix (recommended)

1. Create tenant records in the `tenants` table.
2. Create new prefixed tables for each tenant:
   - `tenant_1_students` (copy of students)
   - `tenant_2_students`
3. Delete the old shared tables.
4. Update models to use `TenantTablePrefixModel`.

### Option B: add tenant_id

1. Add a `tenant_id` column to all tenant-scoped tables.
2. Backfill the column with the correct tenant IDs.
3. Use `TenantableTrait` in your models.

---

## Security features

- Tenant context middleware enforces tenant presence on requests.
- IDOR protection strips tampered `tenant_id`, `school_id`, or `org_id` fields from POST/GET data.
- Global table protection marks tables as exempt from prefixing.
- Audit logging tracks bypass attempts.
- Strict tenant isolation (opt-in) makes row-mode models refuse to run when no tenant is active.

---

## Local development

When developing locally with Laravel Herd, Valet, or similar tools that serve sites under `.test` / `.local` TLDs, subdomain-based tenancy works once DNS resolves the subdomains. Set `baseDomain` to your local domain:

```php
// app/Config/Tenantable.php
public string $baseDomain = 'myapp.test';
```

Or via environment variable:

```ini
TENANT_BASE_DOMAIN = myapp.test
```

Then access tenants at `acme.myapp.test`, `demo.myapp.test`, and so on.

### DNS for subdomains

| Platform | Wildcard DNS | Setup |
|----------|--------------|-------|
| macOS (Herd/Valet) | Built-in | No extra setup needed |
| Windows (Herd) | Not built-in | Add entries to your `hosts` file, or install [Acrylic DNS Proxy](https://mayakron.altervista.org/support/acrylic/Home.htm) for `*.myapp.test` wildcard support |
| Linux | Not built-in | Use `dnsmasq` with `address=/.myapp.test/127.0.0.1` |

See [SETUP.md — Local development](SETUP.md#12-local-development) for detailed instructions.

---

## Testing

```bash
# Run the full test suite
composer test

# Run with code coverage
composer test-coverage

# Run static analysis
composer phpstan
```

Tests live in `tests/Unit` and `tests/Feature`. See [CHANGELOG.md](CHANGELOG.md) for release history.

---

## License

MIT
