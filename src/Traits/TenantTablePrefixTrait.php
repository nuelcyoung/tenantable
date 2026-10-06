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

namespace nuelcyoung\tenantable\Traits;

use nuelcyoung\tenantable\Contracts\TenantPrefixAware;
use nuelcyoung\tenantable\Exceptions\MissingTenantContextException;
use nuelcyoung\tenantable\Services\TenantDatabaseManager;
use nuelcyoung\tenantable\Services\TenantTableManager;

/**
 * Table-prefix isolation for models, built on CodeIgniter 4's native APIs.
 * The active tenant's DBPrefix is applied to the model's connection; reads
 * without a tenant fail safe to empty results and writes throw.
 */
trait TenantTablePrefixTrait
{
    protected bool $isGlobalTable = false;

    private bool $tenantPrefixCallbacksRegistered = false;

    /**
     * Wire the tenant guards and bind the model to the table manager; call
     * initializeTenantTablePrefixTrait() from a custom initialize().
     */
    protected function initialize(): void
    {
        $this->initializeTenantTablePrefixTrait();
    }

    public function initializeTenantTablePrefixTrait(): void
    {
        if ($this->tenantPrefixCallbacksRegistered) {
            return;
        }
        $this->tenantPrefixCallbacksRegistered = true;

        // Reads: fail-safe empty via the native beforeFind short-circuit.
        $this->beforeFind[] = 'tenantPrefixBeforeFind';

        // Writes: refuse without a tenant context.
        $this->beforeInsert[]      = 'tenantPrefixGuardInsert';
        $this->beforeInsertBatch[] = 'tenantPrefixGuardInsertBatch';
        $this->beforeUpdate[]      = 'tenantPrefixGuardUpdate';
        $this->beforeUpdateBatch[] = 'tenantPrefixGuardUpdateBatch';
        $this->beforeDelete[]      = 'tenantPrefixGuardDelete';

        if ($this->isGlobalTable) {
            $this->useCentralConnectionIfDefault();

            return;
        }

        TenantTableManager::getInstance()->bindModel($this);
    }

    /**
     * Reads return empty when no tenant is active, short-circuiting the query
     * via the beforeFind contract without hitting the database.
     */
    protected function tenantPrefixBeforeFind(array $data): array
    {
        if ($this->hasTenantContext()) {
            return $data;
        }

        $data['returnData'] = true;
        $data['data']       = ($data['singleton'] ?? false) ? null : [];

        return $data;
    }

    protected function tenantPrefixGuardInsert(array $data): array
    {
        $this->assertTenantContextForWrite('insert');

        return $data;
    }

    protected function tenantPrefixGuardInsertBatch(array $data): array
    {
        $this->assertTenantContextForWrite('insertBatch');

        return $data;
    }

    protected function tenantPrefixGuardUpdate(array $data): array
    {
        $this->assertTenantContextForWrite('update');

        return $data;
    }

    protected function tenantPrefixGuardUpdateBatch(array $data): array
    {
        $this->assertTenantContextForWrite('updateBatch');

        return $data;
    }

    protected function tenantPrefixGuardDelete(array $data): array
    {
        $this->assertTenantContextForWrite('delete');

        return $data;
    }

    /**
     * Apply the tenant's DBPrefix to this model's connection and drop the
     * cached builder. Called on bind and every tenant switch.
     *
     * @internal
     */
    public function syncTenantPrefix(string $prefix): void
    {
        if ($this->isGlobalTable) {
            return;
        }

        $this->db->setPrefix($prefix);
        $this->builder = null;
    }

    /**
     * Throw when clauses were chained onto this model for the outgoing
     * tenant; the switch drops the cached builder, so they'd vanish silently.
     *
     * @internal
     */
    public function assertNoPendingTenantClauses(): void
    {
        if ($this->isGlobalTable || ! $this->builderHasPendingClauses()) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Tenantable: tenant context switched while query clauses were pending on "%s". '
            . 'Execute or reset the query before switching tenants — pending clauses '
            . 'are not carried across tenants.',
            $this->table,
        ));
    }

    /** The physical table for the active tenant (e.g. tenant_1_students). */
    public function getTable(): string
    {
        if ($this->isGlobalTable) {
            return $this->getBaseTableName();
        }

        return TenantTableManager::getInstance()->getTable($this->getBaseTableName());
    }

    /** The table name as declared on the model, before prefixing. */
    public function getBaseTableName(): string
    {
        return $this->table;
    }

    public function setGlobalTable(bool $isGlobal = true): static
    {
        $this->isGlobalTable = $isGlobal;

        if ($isGlobal) {
            $this->useCentralConnectionIfDefault();
        } else {
            TenantTableManager::getInstance()->bindModel($this);
        }

        return $this;
    }

    public function isGlobalTableFlag(): bool
    {
        return $this->isGlobalTable;
    }

    /**
     * Swap the shared default connection for the unprefixed central one.
     * Injected connections and custom $DBGroup models are left alone.
     */
    private function useCentralConnectionIfDefault(): void
    {
        if ($this->DBGroup === null && $this->db === \Config\Database::connect()) {
            $this->db      = TenantDatabaseManager::getCentralConnection();
            $this->builder = null;
        }
    }

    /** True when the cached builder has un-executed query state. */
    protected function builderHasPendingClauses(): bool
    {
        if (! $this->builder instanceof \CodeIgniter\Database\BaseBuilder) {
            return false;
        }

        try {
            return $this->builder->getCompiledSelect(false)
                !== $this->db->table($this->getBaseTableName())->getCompiledSelect(false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function hasTenantContext(): bool
    {
        return $this->isGlobalTable || TenantTableManager::getInstance()->hasTenant();
    }

    protected function assertTenantContextForWrite(string $operation): void
    {
        if (!$this->hasTenantContext()) {
            throw MissingTenantContextException::forTablePrefixModel(static::class, $operation);
        }
    }
}
