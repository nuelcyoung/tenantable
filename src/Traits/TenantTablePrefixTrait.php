<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Traits;

use nuelcyoung\tenantable\Services\TenantTableManager;

trait TenantTablePrefixTrait
{
    protected bool $isGlobalTable = false;

    public function getTable(): string
    {
        if ($this->isGlobalTable) {
            return $this->table;
        }

        $manager = TenantTableManager::getInstance();

        if (!$manager->hasTenant()) {
            throw new \RuntimeException(
                "TenantTablePrefixTrait: No tenant context set when resolving table '{$this->table}'. " .
                "Ensure TenantFilter has run or call TenantTableManager::getInstance()->setTenant() first."
            );
        }

        return $manager->getTable($this->table);
    }

    public function getBaseTableName(): string
    {
        return $this->table;
    }

    public function setGlobalTable(bool $isGlobal = true): static
    {
        $this->isGlobalTable = $isGlobal;
        return $this;
    }

    public function isGlobalTableFlag(): bool
    {
        return $this->isGlobalTable;
    }
}

abstract class TenantTablePrefixModel extends \CodeIgniter\Model
{
    use TenantTablePrefixTrait;

    protected bool $isGlobalTable = false;

    public function __construct(...$params)
    {
        parent::__construct(...$params);
    }

    public function find($id = null, $columns = '*')
    {
        if (!$this->isGlobalTable && !TenantTableManager::getInstance()->hasTenant()) {
            return null;
        }

        return parent::find($id, $columns);
    }

    public function first($columns = '*')
    {
        if (!$this->isGlobalTable && !TenantTableManager::getInstance()->hasTenant()) {
            return null;
        }

        return parent::first($columns);
    }

    public function findAll(int $limit = 0, int $offset = 0)
    {
        if (!$this->isGlobalTable && !TenantTableManager::getInstance()->hasTenant()) {
            return [];
        }

        return parent::findAll($limit, $offset);
    }

    public function countAllResults(bool $reset = true, bool $test = false): int
    {
        if (!$this->isGlobalTable && !TenantTableManager::getInstance()->hasTenant()) {
            return 0;
        }

        return parent::countAllResults($reset, $test);
    }
}