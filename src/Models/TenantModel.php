<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Models;

use nuelcyoung\tenantable\Events\TenantCreated;
use nuelcyoung\tenantable\Events\TenantUpdated;
use nuelcyoung\tenantable\Events\TenantDeleted;

class TenantModel extends GlobalModel
{
    protected $table            = 'tenants';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'subdomain',
        'name',
        'domain',
        'is_active',
        'settings',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged  = true;

    protected array $casts = [
        'is_active' => 'boolean',
        'settings'  => '?array',
    ];

    protected array $castHandlers = [];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'subdomain' => [
            'rules'  => 'permit_empty|min_length[2]|max_length[50]|alpha_dash|is_unique[tenants.subdomain,id,{id}]',
            'errors' => [
                'alpha_dash' => 'Tenantable.validation.subdomainAlphaDash',
                'is_unique'  => 'Tenantable.validation.subdomainUnique',
            ],
        ],
        'name' => [
            'rules'  => 'required|min_length[2]|max_length[255]',
            'errors' => [
                'required' => 'Tenantable.validation.nameRequired',
            ],
        ],
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    protected $allowCallbacks = true;
    protected $beforeInsert   = [];
    protected $afterInsert    = ['dispatchCreated'];
    protected $beforeUpdate   = ['captureBeforeUpdate'];
    protected $afterUpdate    = ['dispatchUpdated'];
    protected $beforeFind     = [];
    protected $afterFind      = [];
    protected $beforeDelete   = ['captureBeforeDelete'];
    protected $afterDelete    = ['dispatchDeleted'];

    private array $beforeUpdateSnapshots = [];
    private array $beforeDeleteSnapshots = [];

    protected function dispatchCreated(array $data): array
    {
        if (!empty($data['id'])) {
            $tenant = $this->find((int) $data['id']);

            if ($tenant !== null) {
                $config  = config(\nuelcyoung\tenantable\Config\Tenantable::class);
                $manager = new \nuelcyoung\tenantable\Services\TenantDatabaseManager(
                    $config->separateDatabasePerTenant,
                    $config->defaultDatabaseGroup,
                );
                $manager->provisionTenant($tenant);

                \CodeIgniter\Events\Events::trigger('tenantCreated', new TenantCreated(
                    (int) $data['id'],
                    $tenant
                ));
            }
        }

        return $data;
    }

    protected function captureBeforeUpdate(array $data): array
    {
        if (!empty($data['id'])) {
            $tenant = $this->find((int) $data['id']);
            if ($tenant !== null) {
                $this->beforeUpdateSnapshots[(int) $data['id']] = $tenant;
            }
        }

        return $data;
    }

    protected function dispatchUpdated(array $data): array
    {
        if (!empty($data['id'])) {
            $id     = (int) $data['id'];
            $tenant = $this->find($id);
            $before = $this->beforeUpdateSnapshots[$id] ?? [];

            if ($tenant !== null) {
                $changed = array_diff_assoc(
                    array_intersect_key($tenant, $data['data'] ?? []),
                    $before
                );

                \CodeIgniter\Events\Events::trigger('tenantUpdated', new TenantUpdated(
                    $id,
                    $tenant,
                    $changed,
                    $before
                ));
            }

            unset($this->beforeUpdateSnapshots[$id]);
        }

        return $data;
    }

    protected function captureBeforeDelete(array $data): array
    {
        if (!empty($data['id'])) {
            $tenant = $this->find((int) $data['id']);
            if ($tenant !== null) {
                $this->beforeDeleteSnapshots[(int) $data['id']] = $tenant;
            }
        }

        return $data;
    }

    protected function dispatchDeleted(array $data): array
    {
        if (!empty($data['id'])) {
            $id     = (int) $data['id'];
            $tenant = $this->beforeDeleteSnapshots[$id] ?? [];

            \CodeIgniter\Events\Events::trigger('tenantDeleted', new TenantDeleted($id, $tenant));

            unset($this->beforeDeleteSnapshots[$id]);
        }

        return $data;
    }

    public function getActiveTenants(): array
    {
        return $this->where('is_active', true)->findAll();
    }

    public function findBySubdomain(string $subdomain): ?array
    {
        return $this->where('subdomain', $subdomain)->first();
    }

    public function findByDomain(string $domain): ?array
    {
        return $this->where('domain', $domain)->first();
    }

    public function subdomainExists(string $subdomain, ?int $excludeId = null): bool
    {
        $builder = $this->where('subdomain', $subdomain);

        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }

        return $builder->countAllResults() > 0;
    }

    public function findWithSettings(int $id): ?array
    {
        return $this->find($id);
    }

    public function updateSettings(int $id, array $settings): bool
    {
        return $this->update($id, ['settings' => $settings]);
    }

    public function getDisplayName(array $tenant): string
    {
        return $tenant['name'] ?? $tenant['subdomain'] ?? 'Unknown';
    }

    public static function getDatabaseName(array $tenant): string
    {
        $config    = config(\nuelcyoung\tenantable\Config\Tenantable::class);
        $generator = $config->databaseNameGenerator;

        if (is_callable($generator)) {
            return $generator($tenant);
        }

        return 'tenant_' . $tenant['id'];
    }
}