<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Events;

/**
 * TenantCreated
 *
 * Fired after a new tenant row is successfully inserted into the database.
 * Hook into this event to provision tables, seed data, create storage directories, etc.
 *
 * Usage (app/Config/Events.php):
 *   Events::on('tenantCreated', function(\nuelcyoung\tenantable\Events\TenantCreated $e) {
 *       // Run tenant migrations, create directories, etc.
 *   });
 */
class TenantCreated
{
    public function __construct(
        public readonly int   $tenantId,
        public readonly array $tenant
    ) {}
}
