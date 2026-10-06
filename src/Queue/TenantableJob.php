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

namespace nuelcyoung\tenantable\Queue;

use CodeIgniter\Queue\BaseJob;
use CodeIgniter\Queue\Interfaces\JobInterface;
use nuelcyoung\tenantable\Traits\TenantAwareJob;

/**
 * Base class for jobs that must run in the tenant that pushed them. Extend
 * it instead of CodeIgniter\Queue\BaseJob and implement handle(); push via
 * tenant_push(). To skip the base class, use TenantAwareJob directly.
 */
abstract class TenantableJob extends BaseJob implements JobInterface
{
    use TenantAwareJob;

    /**
     * Final on purpose: the tenant context is the guarantee this class makes;
     * an override could run tenant work in the wrong context.
     */
    final public function process(): mixed
    {
        return $this->runInTenantContext(
            $this->data,
            fn (array $data): mixed => $this->handle($data)
        );
    }

    /**
     * The job body, running inside its tenant.
     *
     * @param array<string, mixed> $data The pushed payload, without the
     *                                   package's tenant key.
     */
    abstract protected function handle(array $data): mixed;
}
