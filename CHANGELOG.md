# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-10-06

Two breaking changes. Public query wrappers were removed from the tenant models, and tenant isolation now rides CodeIgniter's own model events instead of overrides that shadowed its API. A handful of cross-tenant data leaks are fixed. Everything new ships behind a flag that defaults to the old behaviour.

### Added

- `php spark tenants:doctor`, a deploy gate for tenancy misconfiguration. It checks the session handler, cache handler, queue connection group, async-provisioning wiring (queue present, job handler registered, `status` column migrated), writable paths, prefix-mode tenant count against a new `$prefixTenantWarningThreshold` (default 200), and whether the running CodeIgniter version is inside the range the package's internals are tested against. None of these mistakes throw on their own; they surface later as users randomly logged out, jobs that vanish, and tenants stuck in provisioning. The command exits non-zero on critical findings and can fail a pipeline, `--strict` also fails on warnings, and `--json` emits the findings for a dashboard. The checks live in `Support\Diagnostics` and share their implementations with the boot-time guard and the queue bootstrapper, so a report cannot disagree with what the runtime does.
- `tenants:run` fan-out controls. `--parallel=N` (1 to 32) keeps a pool of N tenant processes alive and buffers each tenant's output so a failure stays attributable to its tenant; a forward migration re-enters `tenants:run` for its single tenant, so the package's migrator and per-tenant version table still do the work. `--in-process` skips process spawning entirely for an allow-list of five commands (`migrate`, `migrate:status`, `migrate:rollback`, `migrate:refresh`, `db:seed`); anything that caches, writes fixed-name files, or calls `exit()` stays out, because a long-lived process would carry that state into the next tenant. `--resume-from=ID` skips tenants below an ID, and every run writes `writable/tenantable/last_run.json` naming each failed tenant, its truncated output, and the ID to resume from. A failing tenant still does not abort the run, and command-name validation is unchanged.
- Tenant-aware queued jobs. A worker is a separate process with no request, no host header and no filter chain, so a job pushed under a tenant previously ran with no tenant context at all: reads returned nothing, writes failed closed, or in database mode they ran against whatever connection the worker happened to hold. `tenant_push($queue, $job, $data)` stamps the active tenant into the payload and `nuelcyoung\tenantable\Queue\TenantableJob` restores it, running `handle()` inside `tenancy_run()` and tearing the context down afterwards so a long-lived worker never hands the next job the previous tenant's connection. `central_push()` pushes work that must run untenanted, and `TenantableQueue::pushForTenant()` fans work out from a central scheduler. The stamp is never caller-controlled: a `_tenantable_tenant_id` already in the payload is overwritten by the pushing context and the discrepancy logged, and a job whose tenant no longer resolves fails rather than running its body untenanted. `Traits\TenantAwareJob` provides the same behaviour for job classes that already extend something else. `codeigniter4/queue` remains a `suggest`, not a dependency; the handler is duck-typed, and pushing without a queue throws `QueueUnavailableException` instead of silently dropping a tenant's work.
- `QueueSystem` bootstrapper. In database isolation the default connection group is repointed at the active tenant, so a queue configured against that same group writes jobs into the tenant's database, where no worker looks and no queue table exists. Nothing errors and the job is simply lost. The bootstrapper detects the combination, logs it once per process with the fix (give the queue its own central `dbGroup`), and drops the shared queue handler on tenant switches so one tenant's connection is never reused for another's.
- Asynchronous tenant provisioning behind `Config\Tenantable::$provisionAsync` (default off). Creating a tenant ran `CREATE DATABASE` plus every tenant migration inline in an `afterInsert` callback, which meant multi-second signup requests with DDL inside whatever transaction the request held. With the flag on, new tenants are stamped `provisioning` and `Jobs\ProvisionTenantJob` does the work on the queue. A new `status` column (bundled migration, defaults to `ready`) makes the gap visible, and `TenantManager` refuses a tenant that is not ready with `TenantNotReadyException`, which extends `TenantInactiveException` so applications already rendering an "unavailable" page need no change. `tenantCreated` fires when provisioning completes, the first moment the tenant can serve a request. An unreachable queue falls back to inline provisioning with a loud error rather than stranding the tenant in `provisioning`, and a provisioning failure marks the tenant `failed` and re-throws instead of retrying DDL against a half-created database. With the flag off the provisioning path is unchanged.
- `Support\SharedInfrastructure`, one place that decides whether a deployment can survive a second web server. The file session handler and the file cache handler are reported as critical (sessions vanish when a request lands on another node; a per-node cache keeps serving a tenant that was deactivated elsewhere until the resolver TTL expires), the dummy cache handler as a warning. `tenants:install` and `tenants:setup` end with a "Multi-node readiness" report, and the new `Config\Tenantable::$requireSharedInfrastructure` (default off) turns the same findings into an `UnsafeInfrastructureException` at tenant boot, in production only, since a developer's machine is one node and local disk is correct there.
- `Support\FrameworkState`, a single choke point for every access to CodeIgniter's internal shared-instance stores. `TenantDatabaseManager`, `CacheSystem` and `RedisSystem` each reached into `Config\Database::$instances` and `Services::$instances` with raw Reflection, so a framework release that moved either would have left stale connections pointing at the previous tenant's database. Public APIs are now preferred (`Database::getConnections()`, `Services::resetSingle()`), with a Reflection fallback that throws `FrameworkCompatibilityException` on any structural mismatch rather than failing silently.
- Tenant-scoped validation rules `is_unique_for_tenant` and `is_not_unique_for_tenant` for row-level isolation. CodeIgniter's `is_unique` builds its query straight off the connection, so it never passes through the model events that add the `tenant_id` predicate. That enforced uniqueness across every tenant, blocking values other tenants had taken and disclosing their existence through the error message. The new rules take the same parameters, add the active tenant to the lookup, fail closed when no tenant is active, and span tenants during a superadmin bypass (matching `TenantableTrait`). An optional fourth parameter overrides the tenant column. They register through the package Registrar, with messages in `Language/en/Validation.php`, so no app config is required. Prefix and database isolation are unaffected; there the connection already carries the tenant and the framework's own rules resolve correctly.
- PostgreSQL support for database-per-tenant provisioning. Tenantable connects to a configurable maintenance database (`$postgresAdminDatabase`, default `postgres`) and uses CodeIgniter's PostgreSQL-aware Forge flow to create tenant databases. The central `tenant_domains.ssl_state` migration uses a PostgreSQL-compatible `VARCHAR` while preserving the existing MySQL and MariaDB `ENUM` schema.
- Per-tenant migration tracking for prefix mode. The in-process prefix migrator (`TenantDatabaseManager::migrateTenantTables()`) runs each tenant's migrations against the shared connection with `TenantTableManager` scoped to that tenant, and records applied versions per tenant in the package-owned `tenant_migrations` table. CI4's shared `migrations` table is keyed by namespace only, so it cannot distinguish tenants sharing one database.
- Prefix mode auto-provisions new tenants. Creating a tenant (`tenants:create` or any `TenantModel` insert) runs the configured tenant migration namespaces through the prefix migrator, gated by `$autoMigrateTenant` and also exposed as `TenantDatabaseManager::provisionPrefixTenant()` for backfills. `tenants:create` reports how many migrations were applied and prints the `tenants:setup` backfill command when nothing was.
- A bundled sessions-table migration for tenant databases (`$shipTenantSessionsTable`, default off). CodeIgniter 4 has no ready-to-run sessions migration of its own; it ships only the `php spark make:migration --session` generator. In database isolation the database session handler needs the table in every tenant database. The migration mirrors CI4's own `--session` template (MySQL and Postgres variants), honours `Config\Session::$matchIP` for the primary key, names the table after `Config\Session::$savePath` (fallback `ci_sessions`), and is idempotent. `tenants:install` asks about it in database mode (`--sessions-table` for non-interactive runs), and both tenant provisioning and `tenants:run migrate` resolve their namespace list through the new `Config\Tenantable::tenantMigrationNamespaces()`.
- `TenantSecurityFilter`, moved out of the middleware namespace so it can see route metadata the filters already resolved. The `tenant_security` alias is unchanged, so no app config needs editing. Registered alongside it: a `tenant_origin` identification strategy, an optional `tenantAuthorizer` config hook, and a central assets route with `TenantAssetsController` and the `tenant_asset()` helper behind `$tenantAssetsEnabled`.
- A GitHub Actions workflow running PHPUnit on PHP 8.1, 8.2 and 8.3, `phpstan/phpstan` in `require-dev` with a level 5 config, and `test-coverage` and `phpstan` composer scripts.
- README badges for CI, PHPStan, latest version and total downloads, plus a quick-start section and a "Why Tenantable?" section.

### Changed

- Tenant guards now ride CodeIgniter 4's native model events instead of overriding its public API, so framework signature changes can no longer break the package. `TenantTablePrefixTrait` dropped its `find()`, `first()`, `findAll()`, `insert()`, `insertBatch()`, `update()`, `updateBatch()`, `save()` and `delete()` overrides: reads without a tenant context short-circuit through the documented `beforeFind` `returnData` contract, and writes fail closed from `beforeInsert`, `beforeInsertBatch`, `beforeUpdate`, `beforeUpdateBatch` and `beforeDelete` with `MissingTenantContextException`. `TenantableModel` dropped its `find()` and `first()` overrides and its constructor in favour of `initialize()`; without a tenant context `findAll()` now fails safe to `[]`, where it previously threw. The only remaining overrides are the operations CI4 fires no events for (`countAllResults()` and `replace()`), plus `updateBatch()`, since CI4's batch UPDATE ignores builder `where()` clauses, and the prefix trait's `builder()`, which is the prefix mechanism itself.
- `TenantableModel` registers its callbacks from `initialize()` instead of `__construct()`. **If your model extends `TenantableModel` and defines its own `initialize()`, call `parent::initialize()` from it or the model runs unscoped.**
- Models using `TenantTablePrefixTrait` no longer need to declare `implements TenantPrefixAware`. The trait already supplies both contract methods, so the binding check is duck-typed and the interface is optional. Models that already declare it keep working.
- Table-prefix isolation now applies the tenant's `DBPrefix` to the shared default connection when tenancy boots, rather than only when the first prefix-aware model happened to bind. Isolation no longer depends on model instantiation order: plain `CodeIgniter\Model` classes, raw `$db->table()` builders and validation rules all resolve to the active tenant's tables with no model changes at all. Cached model instances are dropped on a tenant switch so no builder is left compiled against the previous tenant's prefix.
- Documented that prefix isolation requires **no** model changes, replacing the previous guidance that validation rules embedding a table name had to be rebuilt at runtime via `getTable()`. The connection-level `DBPrefix` already covers `is_unique`, raw builders, joins and dotted selects.
- The minimum CodeIgniter requirement is 4.4 and the package works on 4.4 and up.
- `tenants:create --domain` registers the domain in the `tenant_domains` table as the primary domain, instead of the legacy `tenants.domain` column.
- `tenants:make-migration` scaffolds the `tenant_id` column (`INT UNSIGNED NOT NULL`), an index on it, and a foreign key to the tenants table (`CASCADE`) when the resolved isolation mode is `row`. The generated stub previously contained only `id` and timestamps, so row-isolation tables were created without the column the row strategy depends on. Prefix and database modes get no tenant column.
- `identify_tenant:strategy=â€¦` is the canonical identification filter. The per-strategy aliases remain supported, but their filter classes are deprecated.
- The `composer.json` description now states plainly what the package does, and its keywords were cleaned up.

### Removed

- Convenience query wrappers from `TenantModel` (`getActiveTenants()`, `findBySubdomain()`, `findByDomain()`, `subdomainExists()`, `findWithSettings()`, `updateSettings()`, `getDisplayName()`) and `TenantDomainModel` (`findByDomain()`, `findVerifiedByDomain()`, `findByTenantId()`, `getPrimaryDomain()`). Use CodeIgniter 4's native model API directly, for example `$model->where('subdomain', $s)->first()` or `$model->where('is_active', 1)->findAll()`, so application code depends only on the framework's documented API. `markVerified()` and `normalizeDomain()` remain; they guard domain-verification state rather than answering queries. Internal call sites (`tenants:create`, `TenantResolverCache`) were migrated to native chains.
- The deprecated `updateBatchOwned()` alias from `TenantableModel` and `TenantableTrait`. Call `updateBatch()` directly; it is tenant-scoped natively.
- `src/Middleware/TenantSecurityMiddleware.php`, replaced by `Filters/TenantSecurityFilter.php` under the same alias.

### Fixed

- `TenantableTrait` deletes without a tenant context now throw `MissingTenantContextException` instead of silently proceeding. CI4's `delete()` discards the `beforeDelete` callback's return value, so the previous `$data['return'] = false` guard was a no-op on current CI4 and the delete ran unscoped.
- Table-prefix isolation now actually queries the per-tenant tables. CodeIgniter's `Model` reads `$this->table` directly and never calls `getTable()`, so prefix models ran every query against the un-prefixed shared table, which either errored outright or silently shared one table across all tenants. `TenantTablePrefixTrait` resolves `$this->table` to the active tenant's physical table inside `builder()` and invalidates the cached builder when the active tenant changes. Without a tenant context, reads return empty results, writes throw, and direct builder access fails loudly. Nothing falls back to the un-prefixed table.
- `TenantableTrait` wired its callbacks through `initializeTenantableTrait()`, but CodeIgniter has no trait auto-discovery, so the documented usage (`use TenantableTrait;` on a plain `Model`) never called it and the model ran completely unscoped: reads leaked every tenant, inserts were not stamped, and updates and deletes ran unscoped. **If your model defines its own `initialize()`, call `$this->initializeTenantableTrait()` from it.**
- An IDOR in `TenantableTrait` inserts: a caller-supplied `tenant_id` was honoured over the active tenant, allowing rows to be written into another tenant. The tenant context now always wins on insert, matching `TenantableModel`, and conflicting values are overwritten with a warning logged.
- `insertBatch()` and `updateBatch()` are tenant-enforced on both `TenantableModel` and `TenantableTrait`. CI4 fires `beforeInsertBatch` and `beforeUpdateBatch`, not the single-row events, for batch operations, so batch writes previously bypassed tenant stamping, `tenant_id` immutability and fail-closed handling entirely.
- `updateBatch()` is now tenant-scoped. CI4's batch UPDATE ignores builder `where()` clauses and matches rows through the constraint columns, so the callback adds the tenant column to that constraint via `BaseBuilder::onConstraint()` and stamps every row with the active tenant. The generated WHERE becomes `table.tenant_id = _u.tenant_id AND table.id = _u.id`, so foreign rows never match and become no-ops. This also makes `tenant_id` immutable, since constraint columns are excluded from the SET list. No extra query is issued.
- Switching tenants while a prefix model has un-executed chained clauses (`$model->where(...)` with no query run yet) now throws instead of silently discarding them, which would have run a broader query than the caller composed against the new tenant's table. Executed queries reset the builder, so normal per-tenant fan-out loops are unaffected.
- Sessions are bound to the tenant that created them (`SessionTenantGuard`, gated by `$bindSessionsToTenant`, default on). A session presented to a different tenant is destroyed. Isolation previously depended entirely on storage layout, so switching `isolationMode` away from `database`, giving a shared `ci_sessions` table and a cookie domain spanning subdomains, allowed a tenant A session to authenticate on tenant B.
- `SessionSystem` and `EarlyTenantDetector` no longer rewrite `Session::$savePath` for non-file handlers. For `DatabaseHandler`, `savePath` is the table name, and for Redis or Memcached it is a connection string; overwriting it with a directory path corrupted those handlers or silently left sessions shared across tenants.
- Both session code paths now set the same per-tenant session cookie name (`tenant_{id}_session`, gated by `$perTenantSessionCookies`, default on). Previously only `EarlyTenantDetector` renamed the cookie, so requests resolved by the filter fell back to the shared default cookie, flip-flopping users between two sessions.
- `$rejectUnboundSessions` (default on) destroys a session carrying no tenant stamp instead of adopting it. Set it to `false` for one release after switching isolation modes if you would rather let pre-stamp sessions through once.
- Redis database-per-tenant no longer wraps its index around. Past the 16 logical databases Redis offers, `($tenantId - 1) % ($maxDatabase + 1)` would have seated two tenants in one database. Overflow tenants now stay on the application database with key-prefix isolation, logged at error level on every boot. The mode is deprecated and scheduled for removal: the `tenant:{id}:` key prefix has no tenant ceiling, works on Redis Cluster (which supports database 0 only), and does not fight connection pooling.
- `SessionSystem`, `CacheSystem` and `RedisSystem` capture the app's original settings once per boot/shutdown cycle. Previously every in-process tenant switch re-captured the previous tenant's values as the originals, so shutdown restored a tenant's save path, cache prefix or Redis settings instead of the app's own.
- A legitimately blank `session.savePath`, as SETUP.md advises with early detection, is restored as blank on shutdown. Previously `''` was treated as nothing to restore and the tenant path leaked past shutdown.
- `RedisSystem` and `CacheSystem` undo tenant scoping when booted for central context (`central()`, `runCentral()`). Previously central work kept using the previous tenant's Redis keyspace and discarded the app's own cache prefix.
- `FrameworkState` writes to static stores with `ReflectionProperty::setValue(null, $value)`. Passing a single argument for a static property is deprecated as of PHP 8.3.
- `ConfigSystem` no longer takes tenant boot down with a `TypeError` when the application has no `baseURL` configured; the subdomain rewrite falls back to its defaults.
- `tenants:setup --mode=prefix` previously ran CI4's `MigrationRunner` against a namespace its discovery can never resolve, a silent no-op that printed green success per tenant while creating nothing. It now uses the in-process prefix migrator with per-tenant tracking and warns when a namespace yields no migration files.
- `tenants:run migrate` in prefix mode runs the in-process prefix migrator instead of spawning `spark migrate` subprocesses, whose shared migration tracking would have skipped every tenant after the first.
- `tenants:make-migration` emits `TenantTableManager::getTable()`-based `createTable()` and `dropTable()` calls when the isolation mode is `prefix`. It previously hard-coded the un-prefixed table name, so a generated migration created one shared table instead of per-tenant tables.
- The in-process tenant migrator records the same version token as CodeIgniter's migration runner and wraps each migration in a transaction, preventing double application across `tenants:create`, `tenants:run migrate` and `php spark migrate`.
- Row-mode `countAllResults()`, and therefore `paginate()` totals, is tenant-scoped instead of counting across all tenants.
- `TenantTablePrefixModel` is now in its own file so it is PSR-4 autoloadable. Previously `extends TenantTablePrefixModel` could fatal on a clean install.
- `createDatabase()` rejects unsupported and unknown drivers instead of emitting invalid DDL.
- `TenantableTrait` logs a warning when a `tenant_id` change attempt is stripped on update, matching `TenantableModel`.
- The tenant bypass flag is cleared on `pre_system` in all SAPIs, preventing state bleed in long-running runtimes such as Swoole, RoadRunner and queue workers.
- `EarlyTenantDetector` is no longer registered twice: the installer relies on `PackageEvents::register()` and no longer injects a duplicate `pre_system` listener.
- CodeIgniter 4.4 compatibility: tenant `is_active` checks are truthy-based, because the Model `$casts` feature only exists from 4.5, and `TenantModel` encodes and decodes the `settings` JSON itself on 4.4 while still using native casts on 4.5 and up.
- Corrected docs: `tenant_request` reads the `X-Tenant` header and matches it by subdomain, `tenant_url()` is subdomain-oriented, and the security filter's responsibilities are described where it now lives.

### Security

Everything in this section is a cross-tenant data exposure or an IDOR. If you are on 1.5.x, upgrade before shipping to production.

- Session binding, session save-path handling, and per-tenant cookie naming (three entries above) together close the session-level exposure. A shared session table plus a subdomain-spanning cookie is enough to authenticate as another tenant on 1.5.x.
- The `TenantableTrait` insert IDOR, the unscoped batch writes, and the unscoped `delete()` above mean a model using `TenantableTrait` can write into, overwrite and delete another tenant's rows. Models using `TenantableModel` were scoped for single-row operations only.
- Prefix isolation was not isolating. Every query from a `TenantTablePrefixModel` or `TenantTablePrefixTrait` model ran against the shared un-prefixed table on 1.5.x.
- `is_unique[table.column]` enforced uniqueness across all tenants in row mode and disclosed other tenants' values in its error message. Use `is_unique_for_tenant` instead.

## [1.5.1] - 2025-01-20

### Changed

- Updated file-level docblocks to reference Tenantable.

## [1.5.0] - 2025-01-20

### Added

- Added `tenants:install` command for interactive and non-interactive setup.
- Added `Config\Registrar` auto-discovery for filter aliases.
- Added install patcher for idempotent wiring of `Config/Filters.php` and `Config/Events.php`.

## [1.4.0] - 2025-01-18

### Added

- Added custom domain support for tenant resolution.
- Added resolver cache for improved performance.
- Streamlined bootstrapping across the package.

### Changed

- Removed local DNS setup section from SETUP.md.

## [1.3.0] - 2025-01-17

### Added

- Added `PackageEvents` bootstrap system.
- Added Support classes for tenant utilities.
- Added inline migration runner for `tenants:run` in database mode.
- Added support for third-party migrations in database-per-tenant mode (e.g., Shield).

### Fixed

- Consistently use `\Config\Database::connect()` across the package.

## [1.2.0] - 2025-01-16

### Fixed

- Made `TenantModel` casts nullable.

## [1.1.1] - 2025-01-15

### Added

- Added `tenants:create` command.
- Improved setup robustness.

## [1.1.0] - 2025-01-15

### Added

- Added `tenants:make-migration` command.
- Overhauled documentation.
- Added automatic filter registration.

### Changed

- Removed stored database credentials in favor of config-driven naming.
- Added auto-provisioning for tenant databases.

## [1.0.0] - 2025-01-14

### Added

- Initial release of Tenantable.
- Subdomain, domain, path, and request-data tenant identification strategies.
- Row-level, table prefix, and database-per-tenant isolation strategies.
- Tenant model scaffolders and helper functions.
- Superadmin bypass support.
- MIT license.

[Unreleased]: https://github.com/nuelcyoung/tenantable/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/nuelcyoung/tenantable/compare/v1.5.1...v2.0.0
[1.5.1]: https://github.com/nuelcyoung/tenantable/compare/v1.5.0...v1.5.1
[1.5.0]: https://github.com/nuelcyoung/tenantable/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/nuelcyoung/tenantable/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/nuelcyoung/tenantable/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/nuelcyoung/tenantable/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/nuelcyoung/tenantable/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/nuelcyoung/tenantable/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/nuelcyoung/tenantable/releases/tag/v1.0.0
