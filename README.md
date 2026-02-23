# Tenantable - Multitenant Package for CodeIgniter 4

A robust multitenant package for CodeIgniter 4 that provides subdomain-based tenant identification and automatic tenant isolation.

## Features

- **Subdomain-based Tenant Detection** - Automatically identifies tenants from URL subdomains
- **Multiple Isolation Strategies** - Choose what works best for your needs
- **Automatic Tenant Context** - Models automatically respect tenant boundaries
- **Superadmin Bypass** - Built-in support for platform admins
- **CLI Support** - Gracefully handles CLI requests

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
use nuelcyoung\tenantable\Filters\TenantFilter;

class Filters extends Config\Filters
{
    public $filters = [
        'tenant' => ['before' => ['/*'], 'except' => ['health', 'api/*']],
    ];
}
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
public $separateDatabasePerTenant = true;
```

### Tenant Database Config
```sql
tenants table stores:
- database_host
- database_username  
- database_password
- database_name
```

### Usage
```php
// Automatically switches to tenant's database
$school = tenant(); // Connects to school_db
$students = $studentModel->findAll(); // Queries school_db.students
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
│   └── TenantFilter.php
├── Helpers/
│   └── tenantable_helper.php
├── Middleware/
│   └── TenantSecurityMiddleware.php
├── Models/
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
| id | INT | Primary key |
| subdomain | VARCHAR(50) | Unique subdomain |
| name | VARCHAR(255) | Tenant name |
| database_name | VARCHAR(100) | Separate DB name |
| database_host | VARCHAR(255) | DB server |
| database_username | VARCHAR(100) | DB user |
| database_password | VARCHAR(255) | DB password |
| is_active | BOOLEAN | Tenant status |
| settings | JSON | Custom settings |
| created_at | DATETIME | Created |
| updated_at | DATETIME | Updated |

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
