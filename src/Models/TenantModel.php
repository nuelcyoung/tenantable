<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Models;

use CodeIgniter\Model;
use nuelcyoung\tenantable\Events\TenantCreated;
use nuelcyoung\tenantable\Events\TenantUpdated;
use nuelcyoung\tenantable\Events\TenantDeleted;

/**
 * TenantModel
 *
 * Manages tenant records in the database and fires lifecycle events
 * that the application can subscribe to for provisioning/cleanup.
 *
 * Lifecycle Events:
 *   tenantCreated   → TenantCreated   (provision tables, storage dirs, etc.)
 *   tenantUpdated   → TenantUpdated   (react to plan/subdomain/status changes)
 *   tenantDeleted   → TenantDeleted   (delete tables, wipe files, etc.)
 *
 * FIX 3.3 – updateSettings() passes plain array; ORM cast handles JSON.
 * FIX 3.4 – Removed dead $deletedField = 'deleted_at'.
 */
class TenantModel extends Model
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
        'domain',             // For DomainFilter / DomainOrSubdomainFilter
        'database_name',
        'database_host',
        'database_username',
        'database_password',
        'is_active',
        'settings',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged  = true;

    protected array $casts = [
        'is_active' => 'boolean',
        'settings'  => 'array',
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

    /**
     * Snapshot of tenant data stored before an update (for TenantUpdated event).
     * @var array<int, array>  [id => oldData]
     */
    private array $beforeUpdateSnapshots = [];

    /**
     * Snapshot of tenant data stored before a delete (for TenantDeleted event).
     * @var array<int, array>  [id => rowData]
     */
    private array $beforeDeleteSnapshots = [];

    // -------------------------------------------------------------------------
    // Lifecycle event callbacks
    // -------------------------------------------------------------------------

    /**
     * Fire TenantCreated after a successful insert.
     */
    protected function dispatchCreated(array $data): array
    {
        if (!empty($data['id'])) {
            $tenant = $this->find((int) $data['id']);

            if ($tenant !== null) {
                \CodeIgniter\Events\Events::trigger('tenantCreated', new TenantCreated(
                    (int) $data['id'],
                    $tenant
                ));
            }
        }

        return $data;
    }

    /**
     * Snapshot the current tenant data before it is overwritten by the update.
     */
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

    /**
     * Fire TenantUpdated after a successful update.
     */
    protected function dispatchUpdated(array $data): array
    {
        if (!empty($data['id'])) {
            $id     = (int) $data['id'];
            $tenant = $this->find($id);
            $before = $this->beforeUpdateSnapshots[$id] ?? [];

            if ($tenant !== null) {
                // Diff to expose only what actually changed
                $changed = array_diff_assoc(
                    array_intersect_key($tenant, $data['data'] ?? []),
                    $before
                );

                \CodeIgniter\Events\Events::trigger('tenantUpdated', new TenantUpdated(
                    $id,
                    $tenant,
                    $changed
                ));
            }

            unset($this->beforeUpdateSnapshots[$id]);
        }

        return $data;
    }

    /**
     * Snapshot the tenant row before deletion so TenantDeleted has the data.
     */
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

    /**
     * Fire TenantDeleted after a successful delete.
     */
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

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    /** @return array<int, array> */
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

    /** Back-compat alias — ORM cast decodes 'settings' on every find() now. */
    public function findWithSettings(int $id): ?array
    {
        return $this->find($id);
    }

    /**
     * FIX 3.3 – Pass plain array; ORM 'array' cast handles serialisation.
     */
    public function updateSettings(int $id, array $settings): bool
    {
        return $this->update($id, ['settings' => $settings]);
    }

    public function getDisplayName(array $tenant): string
    {
        return $tenant['name'] ?? $tenant['subdomain'] ?? 'Unknown';
    }

    public function validateDatabaseConfig(array $config): bool
    {
        foreach (['database_host', 'database_username', 'database_name'] as $field) {
            if (empty($config[$field])) {
                return false;
            }
        }

        return true;
    }
}
