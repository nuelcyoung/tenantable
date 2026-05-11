# Tenantable - Multitenant Package for CodeIgniter 4

A robust multitenant package for CodeIgniter 4 that provides flexible tenant identification and automatic tenant isolation.

## Features

- **Flexible Tenant Identification** — Subdomain, domain, path, or request data
- **Multiple Isolation Strategies** — Row-level, table prefix, or database-per-tenant
- **Automatic Provisioning** — Database auto-created and migrated on tenant creation
- **Automatic Tenant Context** — Models automatically respect tenant boundaries
- **Superadmin Bypass** — Built-in support for platform admins
- **CLI Support** — Fan-out commands, scaffolding, and setup CLI tools

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
```

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
public $baseDomain = 'example.com';
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
```

### How It Works

The database name is **derived dynamically** — it is never stored in the tenants table. By default the convention is `tenant_{id}` (e.g. `tenant_1`, `tenant_5`).

When you insert a new tenant:
```php
$tenantModel->insert(['name' => 'Acme Corp', 'subdomain' => 'acme']);
```

The package automatically:
1. Inserts the row into the `tenants` table
2. Creates the database: `CREATE DATABASE IF NOT EXISTS tenant_1`
3. Runs your tenant migrations against the new database
4. Fires the `tenantCreated` event

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

## License

MIT
