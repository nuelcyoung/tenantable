# Tenantable - Multitenant Package for CodeIgniter 4

A robust multitenant package for CodeIgniter 4 that provides flexible tenant identification and automatic tenant isolation.

## Features

- **Flexible Tenant Identification** — Subdomain, domain, path, or request data
- **Multiple Isolation Strategies** — Row-level, table prefix, or database-per-tenant
- **Automatic Provisioning** — Database auto-created and migrated on tenant creation
- **Third-Party Migration Support** — Run Shield (or any package) migrations per-tenant automatically
- **Automatic Tenant Context** — Models automatically respect tenant boundaries
- **Superadmin Bypass** — Built-in support for platform admins
- **CLI Support** — Create tenants, scaffold migrations/models, fan-out commands

## Requirements

- PHP 8.1+
- CodeIgniter 4.0+

## Architecture Options

This package supports **3 isolation strategies**:

| Strategy | How It Works | Pros | Cons |
|----------|-------------|------|------|
| **tenant_id** | Shared tables with `tenant_id` column | Simple to implement | Risk of leakage |
| **Table Prefix** | Separate tables per tenant (`tenant_1_students`) | No leakage possible | More complex setup |
| **Separate DB** | Different database per tenant | Complete isolation | Most complex |

---

## Installation

```bash
composer require nuelcyoung/tenantable
php spark tenants:install
```

`tenants:install` is interactive on first run. It asks for the base domain, isolation mode, and identification strategy; then publishes `app/Config/Tenantable.php`, wires `app/Config/Filters.php` and `app/Config/Events.php` idempotently, scaffolds `app/Database/Migrations/Tenant/`, and runs `tenants:setup` to create the central tenants tables. Re-running it is safe.

For non-interactive / CI installs:

```bash
php spark tenants:install --base-domain=example.com --mode=prefix --strategy=domain_or_subdomain --yes
```

Filter aliases (`tenant_subdomain`, `tenant_domain`, `tenant_domain_or_subdomain`, `tenant_path`, `tenant_request`, `tenant_security`, `identify_tenant`) are auto-registered through CI4's `Config\Registrar` discovery — you don't need to add them to `Config\Filters::$aliases` yourself.

For the full step-by-step walkthrough, see [SETUP.md](SETUP.md).

---

## Tenant Identification

Tenantable identifies which tenant a request belongs to using **filters**. You choose your strategy by applying the corresponding filter to your routes.

### Available Strategies

| Filter Alias | Class | Identifies by | Example |
|---|---|---|---|
| `tenant` / `tenant_subdomain` | `SubdomainFilter` | URL subdomain | `acme.example.com` |
| `tenant_domain` | `DomainFilter` | Custom domain (stored in `tenants.domain`) | `acme.com` |
| `tenant_domain_or_subdomain` | `DomainOrSubdomainFilter` | Domain first, falls back to subdomain | `acme.com` or `acme.example.com` |
| `tenant_path` | `PathFilter` | First URL path segment | `/acme/dashboard` |
| `tenant_request` | `RequestDataFilter` | Header, query param, or body field | `X-Tenant-ID: acme` |

### How to Configure

Register your chosen filter in `app/Config/Filters.php`:

```php
// Option A: Apply globally
public array $globals = [
    'before' => [
        'tenant_subdomain' => ['except' => ['health', 'api/*']],
    ],
];

// Option B: Apply per route group (you can mix strategies)
// In Routes.php:
$routes->group('app', ['filter' => 'tenant_subdomain'], function ($routes) {
    // Web routes identified by subdomain
});
$routes->group('api', ['filter' => 'tenant_request'], function ($routes) {
    // API routes identified by header/query param
});
```

All filters are auto-registered by the package. The default `tenant` alias maps to `SubdomainFilter`.

---

## Strategy 1: Table Prefix (Recommended)

**Best for**: Most applications. No tenant_id leakage risks.

### How It Works
```
students table → tenant_1_students, tenant_2_students, ...
classes table → tenant_1_classes, tenant_2_classes, ...
```

### Setup

1. **Run Migration**
```bash
php spark migrate -g tenantable
```

2. **Configure Filters**
```php
// app/Config/Filters.php
public array $globals = [
    'before' => [
        'tenant_subdomain' => ['except' => ['health', 'api/*']],
    ],
];
```

3. **Use the Model**
```php
use nuelcyoung\tenantable\Traits\TenantTablePrefixModel;

class StudentModel extends TenantTablePrefixModel
{
    protected $table = 'students';
}

// Automatic: queries tenant_1_students when tenant_id = 1
$students = $studentModel->findAll();
```

### Configuration
```php
// app/Config/Tenantable.php
public $prefixFormat = 'tenant_{id}_{table}'; // Default format
public $baseDomain = 'example.com'; // Your production domain (or 'myapp.test' for local dev)
```

---

## Strategy 2: Shared Database with tenant_id

**Best for**: Simple applications, few tenants.

### Setup

1. **Add tenant_id to tables**
```bash
php spark make:migration add_tenant_id
```

2. **Use the Trait**
```php
use nuelcyoung\tenantable\Traits\TenantableTrait;

class StudentModel extends Model
{
    use TenantableTrait;
    protected $table = 'students';
}
```

### Warning: Leakage Risks

Using `tenant_id` has security concerns:
- Forgetting to add trait to a model
- Raw SQL queries bypassing trait
- Joins missing tenant_id
- IDOR attacks

**Use TenantTablePrefixTrait instead to eliminate these risks.**

---

## Strategy 3: Separate Database Per Tenant

**Best for**: Enterprise, strict compliance needs.

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

// Optional: custom database naming convention (default: tenant_{id})
public $databaseNameGenerator = null;

// Include third-party package migrations per-tenant (e.g. Shield)
public array $tenantMigrationsNamespaces = [
    'CodeIgniter\Shield',    // Shield's auth tables in every tenant DB
];
```

### How It Works

The database name is **derived dynamically** — it is never stored in the tenants table. By default the convention is `tenant_{id}` (e.g. `tenant_1`, `tenant_5`).

When you create a tenant:
```bash
php spark tenants:create acme "Acme Corp"
```

Or programmatically:
```php
$tenantModel->insert(['name' => 'Acme Corp', 'subdomain' => 'acme']);
```

The package automatically:
1. Inserts the row into the `tenants` table
2. Creates the database: `CREATE DATABASE IF NOT EXISTS tenant_1`
3. Runs your tenant migrations (from `$tenantMigrationsNamespace`)
4. Runs third-party migrations (from `$tenantMigrationsNamespaces` — e.g. Shield)
5. Fires the `tenantCreated` event

On each request, the filter identifies the tenant and swaps `Config\Database::$default` to point at the tenant's database. All models transparently query the correct DB.

### DB Credentials

Connection credentials (host, user, password, port) come from your `.env` / `Config\Database::$default`. Only the database name changes per tenant. Your DB user must have `CREATE` privileges.

Credentials are **never stored in the tenants table** — storing secrets inside the database they unlock is a security foot-gun.

### Custom Naming

```php
// Default: tenant_1, tenant_2, ...
public $databaseNameGenerator = null;

// Custom: myapp_acme, myapp_globex, ...
public $databaseNameGenerator = fn(array $tenant) => 'myapp_' . $tenant['subdomain'];
```

### Usage
```php
// Automatically switches to tenant's database
$school = tenant(); // Connects to tenant_1
$students = $studentModel->findAll(); // Queries tenant_1.students
```

---

## Usage Examples

### Helper Functions
```php
// Get current tenant ID
$tenantId = tenant_id();

// Get tenant data
$tenant = tenant();

// Check if tenant context exists
if (has_tenant()) {
    // Safe to query
}

// Generate tenant URL
$url = tenant_url('dashboard');

// Check if admin can bypass
if (can_bypass_tenant()) {
    // Access all tenants
}
```

### Manual Tenant Setting
```php
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Services\TenantTableManager;

TenantManager::getInstance()->setTenantById(1);
TenantTableManager::getInstance()->setTenant(1, 'school1');
```

### Bypassing (Superadmin)
```php
// Temporarily bypass for specific query
Model::withoutTenant(function() {
    return Model::findAll(); // All tenants
});

// Or
Model::enableTenantBypass();
// queries...
Model::disableTenantBypass();
```

---

## CLI Commands

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

# Run migrations on all tenant databases (database mode)
php spark tenants:run migrate

# Rollback specific tenants
php spark tenants:run migrate:rollback --tenants=1,3
```

---

## Package Structure

```
src/
├── Config/
│   └── Tenantable.php
├── Database/
│   └── Migrations/
│       └── CreateTenantsTable.php
├── Exceptions/
│   ├── TenantInactiveException.php
│   └── TenantNotFoundException.php
├── Filters/
│   ├── BaseTenantFilter.php
│   ├── SubdomainFilter.php
│   ├── DomainFilter.php
│   ├── DomainOrSubdomainFilter.php
│   ├── PathFilter.php
│   ├── RequestDataFilter.php
│   └── TenantFilter.php
├── Helpers/
│   └── tenantable_helper.php
├── Middleware/
│   └── TenantSecurityMiddleware.php
├── Models/
│   ├── GlobalModel.php
│   ├── TenantModel.php
│   └── TenantableModel.php
├── Services/
│   ├── TenantManager.php
│   ├── TenantDatabaseManager.php
│   └── TenantTableManager.php
└── Traits/
    ├── TenantTablePrefixTrait.php
    └── TenantableTrait.php
```

---

## Database Schema

The `tenants` table:

| Field | Type | Description |
|-------|------|-------------|
| id | INT | Primary key (auto-increment) |
| subdomain | VARCHAR(50) | Unique subdomain for SubdomainFilter |
| domain | VARCHAR(255) | Custom domain for DomainFilter |
| name | VARCHAR(255) | Display name |
| is_active | BOOLEAN | Tenant status |
| settings | JSON | Custom key-value settings |
| created_at | DATETIME | Created timestamp |
| updated_at | DATETIME | Updated timestamp |

> **Note:** The database name is **not stored** in the table. It is derived at runtime via `Config\Tenantable::$databaseNameGenerator` (default: `tenant_{id}`).

---

## Migration for Existing Apps

### Option A: Table Prefix (Recommended)

1. Create tenants in `tenants` table
2. Create new prefixed tables for each tenant:
   - `tenant_1_students` (copy of students)
   - `tenant_2_students`
3. Delete old shared tables
4. Update models to use `TenantTablePrefixModel`

### Option B: Add tenant_id

1. Add `tenant_id` column to all tables
2. Backfill with correct tenant IDs
3. Use `TenantableTrait` in models

---

## Security Features

- **TenantContext Middleware** - Enforces tenant on all requests
- **IDOR Protection** - Validates tenant_id in requests
- **Global Table Protection** - Mark tables as exempt from prefixing
- **Audit Logging** - Log bypass attempts

---

## Local Development

When developing locally with **Laravel Herd**, **Valet**, or similar tools that serve sites under `.test` / `.local` TLDs, subdomain-based tenancy works out of the box — just set `baseDomain` to your local domain:

```php
// app/Config/Tenantable.php
public string $baseDomain = 'myapp.test';
```

Or via environment variable:

```ini
TENANT_BASE_DOMAIN = myapp.test
```

Then access tenants at `acme.myapp.test`, `demo.myapp.test`, etc.

### DNS for Subdomains

| Platform | Wildcard DNS | Setup |
|----------|-------------|-------|
| **macOS** (Herd/Valet) | ✅ Built-in | No extra setup needed |
| **Windows** (Herd) | ❌ Not built-in | Add entries to `hosts` file, or install [Acrylic DNS Proxy](https://mayakron.altervista.org/support/acrylic/Home.htm) for `*.myapp.test` wildcard |
| **Linux** | ❌ Not built-in | Use `dnsmasq` with `address=/.myapp.test/127.0.0.1` |

See [SETUP.md — Local Development](SETUP.md#12-local-development) for detailed instructions.

---

## License

MIT
