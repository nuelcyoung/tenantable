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

use nuelcyoung\tenantable\Events\TenantDomainChanged;
use nuelcyoung\tenantable\Services\TenantResolverCache;

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
        'ssl_state' => 'permit_empty|in_list[none,pending,active,failed]',
    ];

    protected $allowCallbacks = true;
    protected $beforeInsert   = ['normalizeDomainBeforeWrite', 'forceUnverifiedOnInsert'];
    protected $afterInsert    = ['dispatchDomainCreated'];
    protected $beforeUpdate   = ['normalizeDomainBeforeWrite', 'protectVerificationState', 'captureBeforeUpdate'];
    protected $afterUpdate    = ['dispatchDomainUpdated'];
    protected $beforeDelete   = ['captureBeforeDelete'];
    protected $afterDelete    = ['dispatchDomainDeleted'];

    /** @var array<int, array> */
    private array $beforeUpdateSnapshots = [];

    /** @var array<int, array> */
    private array $beforeDeleteSnapshots = [];

    private bool $allowVerificationStateChange = false;

    /**
     * Mark a domain verified after the application has proved ownership via
     * DNS or HTTPS. Resolver lookups ignore all other rows.
     */
    public function markVerified(int $id): bool
    {
        $this->allowVerificationStateChange = true;

        try {
            return $this->update($id, [
                'is_verified' => 1,
                'verified_at' => date('Y-m-d H:i:s'),
            ]);
        } finally {
            $this->allowVerificationStateChange = false;
        }
    }

    /** Normalize a DNS host and reject URLs, ports, IPs, and malformed labels. */
    public static function normalizeDomain(string $domain): string
    {
        $domain = rtrim(strtolower(trim($domain)), '.');

        if ($domain === '' || strlen($domain) > 253 || filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            throw new \InvalidArgumentException("Tenantable: invalid custom domain '{$domain}'.");
        }

        foreach (explode('.', $domain) as $label) {
            if ($label === '' || strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                throw new \InvalidArgumentException("Tenantable: invalid custom domain '{$domain}'.");
            }
        }

        return $domain;
    }

    protected function normalizeDomainBeforeWrite(array $data): array
    {
        if (isset($data['data']['domain'])) {
            $data['data']['domain'] = self::normalizeDomain((string) $data['data']['domain']);
        }

        return $data;
    }

    protected function forceUnverifiedOnInsert(array $data): array
    {
        if (isset($data['data'])) {
            $data['data']['is_verified'] = 0;
            $data['data']['verified_at'] = null;
        }

        return $data;
    }

    protected function protectVerificationState(array $data): array
    {
        if ($this->allowVerificationStateChange || ! isset($data['data'])) {
            return $data;
        }

        if (array_key_exists('domain', $data['data'])) {
            $data['data']['is_verified'] = 0;
            $data['data']['verified_at'] = null;
        } else {
            unset($data['data']['is_verified'], $data['data']['verified_at']);
        }

        return $data;
    }

    // Event dispatchers

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
        if (empty($data['id'])) {
            // Bulk updates do not provide a row id, so no after-event can
            // identify the old host mapping. Flush the resolver namespace.
            TenantResolverCache::getInstance()->flush();
        }

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
        if (empty($data['id'])) {
            TenantResolverCache::getInstance()->flush();
        }

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
