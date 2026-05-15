<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Models;

use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Model;
use CodeIgniter\Validation\ValidationInterface;
use nuelcyoung\tenantable\Services\TenantDatabaseManager;

abstract class GlobalModel extends Model
{
    public function __construct(?ConnectionInterface $db = null, ?ValidationInterface $validation = null)
    {
        $db ??= TenantDatabaseManager::getCentralConnection();
        parent::__construct($db, $validation);
    }
}