<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Models;

use nuelcyoung\tenantable\Events\TenantDomainChanged;

class TenantDomainModel extends GlobalModel
{
    protected $table            = 'tenant_domains';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'tenant_id',
        'domain',
        'is_primary',
        'is_verified',
        'verified_at',
        'ssl_state',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged  = true;

    protected array $casts = [
        'is_primary'  => 'boolean',
        'is_verified' => 'boolean',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'tenant_id' => 'required|integer',
        'domain'    => 'required|max_length[255]',
    ];

    protected $allowCallbacks = true;
    protected $afterInsert    = ['dispatchDomainCreated'];
    protected $beforeUpdate   = ['captureBeforeUpdate'];
    protected $afterUpdate    = ['dispatchDomainUpdated'];
    protected $beforeDelete   = ['captureBeforeDelete'];
    protected $afterDelete    = ['dispatchDomainDeleted'];

    /** @var array<int, array> */
    private array $beforeUpdateSnapshots = [];

    /** @var array<int, array> */
    private array $beforeDeleteSnapshots = [];

    public function findByDomain(string $domain): ?array
    {
        return $this->where('domain', $domain)->first();
    }

    public function findByTenantId(int $tenantId): array
    {
        return $this->where('tenant_id', $tenantId)->findAll();
    }

    public function getPrimaryDomain(int $tenantId): ?array
    {
        return $this->where('tenant_id', $tenantId)
            ->where('is_primary', 1)
            ->first();
    }

    // -------------------------------------------------------------------------
    // Event dispatchers
    // -------------------------------------------------------------------------

    protected function dispatchDomainCreated(array $data): array
    {
        if (empty($data['id'])) {
            return $data;
        }

        $row = $this->find((int) $data['id']);
        if ($row === null) {
            return $data;
        }

        \CodeIgniter\Events\Events::trigger(
            'tenantDomainChanged',
            new TenantDomainChanged(
                (int) $row['tenant_id'],
                (string) $row['domain'],
                'created',
                $row
            )
        );

        return $data;
    }

    protected function captureBeforeUpdate(array $data): array
    {
        if (!empty($data['id'])) {
            $row = $this->find((int) $data['id']);
            if ($row !== null) {
                $this->beforeUpdateSnapshots[(int) $data['id']] = $row;
            }
        }

        return $data;
    }

    protected function dispatchDomainUpdated(array $data): array
    {
        if (empty($data['id'])) {
            return $data;
        }

        $id     = (int) $data['id'];
        $before = $this->beforeUpdateSnapshots[$id] ?? null;
        $row    = $this->find($id);

        if ($row !== null) {
            $payload = $row;
            if ($before !== null && ($before['domain'] ?? null) !== ($row['domain'] ?? null)) {
                $payload['_previous_domain'] = $before['domain'];
            }

            \CodeIgniter\Events\Events::trigger(
                'tenantDomainChanged',
                new TenantDomainChanged(
                    (int) $row['tenant_id'],
                    (string) $row['domain'],
                    'updated',
                    $payload
                )
            );
        }

        unset($this->beforeUpdateSnapshots[$id]);

        return $data;
    }

    protected function captureBeforeDelete(array $data): array
    {
        if (!empty($data['id'])) {
            $row = $this->find((int) $data['id']);
            if ($row !== null) {
                $this->beforeDeleteSnapshots[(int) $data['id']] = $row;
            }
        }

        return $data;
    }

    protected function dispatchDomainDeleted(array $data): array
    {
        if (empty($data['id'])) {
            return $data;
        }

        $id  = (int) $data['id'];
        $row = $this->beforeDeleteSnapshots[$id] ?? null;

        if ($row !== null) {
            \CodeIgniter\Events\Events::trigger(
                'tenantDomainChanged',
                new TenantDomainChanged(
                    (int) $row['tenant_id'],
                    (string) $row['domain'],
                    'deleted',
                    $row
                )
            );
        }

        unset($this->beforeDeleteSnapshots[$id]);

        return $data;
    }
}
