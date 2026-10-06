<?php

/**
 * This file is part of the Tenantable.
 *
 * (c) Nuel Young Chukwunalu <nuelmega@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace nuelcyoung\tenantable\Models;

use CodeIgniter\Model;
use nuelcyoung\tenantable\Services\TenantDatabaseManager;

    /**
     * Base model for central (non-tenant) tables. The shared default
     * connection is swapped for the central one; injected connections and
     * custom $DBGroup models are left alone.
     */
abstract class GlobalModel extends Model
{
    protected function initialize(): void
    {
        if ($this->DBGroup === null && $this->db === \Config\Database::connect()) {
            $this->db = TenantDatabaseManager::getCentralConnection();
        }
    }
}
