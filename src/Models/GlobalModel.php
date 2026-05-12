<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Models;

use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Model;
use CodeIgniter\Validation\ValidationInterface;
use nuelcyoung\tenantable\Services\TenantDatabaseManager;

/**
 * GlobalModel
 *
 * Base class for models whose underlying table lives in the central (non-tenant)
 * database. Permanently bound to the 'central' database group, so queries reach
 * the central DB regardless of whether a tenant context is active.
 *
 * Use for models that represent global/shared records:
 *   - The Tenant model itself
 *   - Plans, central feature flags
 *   - Cross-tenant accounting or analytics tables
 *
 * In row-mode and prefix-mode (single physical DB) the 'central' group is
 * lazily aliased to the default group, so this base class behaves identically
 * to a plain CodeIgniter\Model. In database-per-tenant mode it diverges from
 * default and always reaches the central DB.
 */
abstract class GlobalModel extends Model
{
    public function __construct(?ConnectionInterface $db = null, ?ValidationInterface $validation = null)
    {
        $db ??= TenantDatabaseManager::getCentralConnection();
        parent::__construct($db, $validation);
    }
}
