# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `php spark tenants:doctor` — a deploy gate for tenancy misconfiguration. It checks the session handler, cache handler, queue connection group, async-provisioning wiring (queue present, job handler registered, `status` column migrated), writable paths, prefix-mode tenant count against a new `$prefixTenantWarningThreshold` (default 200), and whether the running CodeIgniter version is inside the range the package's internals are tested against. None of these mistakes throw on their own — they surface later as users randomly logged out, jobs that vanish, and tenants stuck in provisioning — so the command exits non-zero on critical findings and can fail a pipeline. `--strict` also fails on warnings; `--json` emits the findings for a dashboard. The checks live in `Support\Diagnostics` and share their implementations with the boot-time guard and the queue bootstrapper, so a report can never disagree with what the runtime does.
- `tenants:run` fan-out controls. `--parallel=N` (1–32) keeps a pool of N tenant processes alive, buffering each tenant's output so a failure stays attributable to its tenant; a forward migration re-enters `tenants:run` for its single tenant so the package's migrator and per-tenant version table still do the work. `--in-process` skips process spawning entirely for an allow-list of five commands (`migrate`, `migrate:status`, `migrate:rollback`, `migrate:refresh`, `db:seed`) — anything that caches, writes fixed-name files, or calls `exit()` stays out, because a long-lived process would carry that state into the next tenant. `--resume-from=ID` skips tenants below an ID, and every run now writes `writable/tenantable/last_run.json` naming each failed tenant, its (truncated) output, and the ID to resume from. A failing tenant has never aborted the run and still does not; command-name validation is unchanged.
- Tenant-aware queued jobs. A worker is a separate process with no request, no host header and no filter chain, so a job pushed under a tenant previously ran with no tenant context at all — reads returned nothing, writes failed closed, or (in database mode) ran against whatever connection the worker happened to hold. `tenant_push($queue, $job, $data)` stamps the active tenant into the payload and `nuelcyoung\tenantable\Queue\TenantableJob` restores it, running `handle()` inside `tenancy_run()` and tearing the context down afterwards so a long-lived worker never hands the next job the previous tenant's connection. `central_push()` pushes work that must run untenanted; `TenantableQueue::pushForTenant()` fans work out from a central scheduler. The stamp is never caller-controlled: a `_tenantable_tenant_id` already in the payload is overwritten by the pushing context and the discrepancy logged, and a job whose tenant no longer resolves fails rather than running the body untenanted. `Traits\TenantAwareJob` provides the same behaviour for job classes that already extend something else. `codeigniter4/queue` remains a `suggest`, not a dependency — the handler is duck-typed, and pushing without a queue throws `QueueUnavailableException` instead of silently dropping a tenant's work.
- `QueueSystem` bootstrapper. In database isolation the default connection group is repointed at the active tenant, so a queue configured against that same group writes jobs into the tenant's database, where no worker looks and no queue table exists — nothing errors, the job is simply lost. The bootstrapper detects the combination and logs it once per process with the fix (give the queue its own central `dbGroup`), and drops the shared queue handler on tenant switches so one tenant's connection is never reused for another's.
- Asynchronous tenant provisioning behind `Config\Tenantable::$provisionAsync` (default off). Creating a tenant ran `CREATE DATABASE` plus every tenant migration inline in an `afterInsert` callback — multi-second signup requests, with DDL inside whatever transaction the request had open. With the flag on, new tenants are stamped `provisioning` and `Jobs\ProvisionTenantJob` does the work on the queue. A new `status` column (bundled migration, defaults to `ready`) makes the gap visible, and `TenantManager` refuses a tenant that is not ready with `TenantNotReadyException` — which extends `TenantInactiveException`, so applications already rendering an "unavailable" page need no change. `tenantCreated` fires when provisioning completes, the first moment the tenant can serve a request. An unreachable queue falls back to inline provisioning with a loud error rather than stranding the tenant in `provisioning`; a provisioning failure marks the tenant `failed` and re-throws instead of retrying DDL against a half-created database. With the flag off the provisioning path is unchanged.
- `Support\SharedInfrastructure` — one place that decides whether a deployment can survive a second web server. The file session handler and the file cache handler are reported as critical (sessions vanish when a request lands on another node; a per-node cache keeps serving a tenant that was deactivated elsewhere until the resolver TTL expires), the dummy cache handler as a warning. `tenants:install` and `tenants:setup` now end with a "Multi-node readiness" report, and the new `Config\Tenantable::$requireSharedInfrastructure` (default off) turns the same findings into an `UnsafeInfrastructureException` at tenant boot — in production only, since a developer's machine is one node and local disk is correct there.
- `Support\FrameworkState` — a single choke point for every access to CodeIgniter's internal shared-instance stores. `TenantDatabaseManager`, `CacheSystem` and `RedisSystem` each reached into `Config\Database::$instances` and `Services::$instances` with raw Reflection; a framework release that moved either would have left stale connections pointing at the previous tenant's database. Public APIs are now preferred (`Database::getConnections()`, `Services::resetSingle()`) with a Reflection fallback that throws `FrameworkCompatibilityException` on any structural mismatch — a missing class, a missing property, or a store that stopped being static — rather than failing silently.
- Tenant-scoped validation rules `is_unique_for_tenant` and `is_not_unique_for_tenant` for row-level isolation. CodeIgniter's `is_unique` builds its query straight off the connection, so it never passes through the model events that add the `tenant_id` predicate — meaning `is_unique[posts.slug]` silently enforced uniqueness across *every* tenant, blocking values other tenants had taken and disclosing their existence through the error message. The new rules take the same parameters, add the active tenant to the lookup, fail closed when no tenant is active, and span tenants during a superadmin bypass (matching `TenantableTrait`). An optional fourth parameter overrides the tenant column. Registered automatically via the package Registrar, with messages shipped in `Language/en/Validation.php`; no app config required. The rules reject arrays and objects before querying and cast everything else to string, which is exactly what `StrictRules\Rules::is_unique` adds over the non-strict rule, so one class behaves identically whichever rule set the app registers. Prefix and database isolation are unaffected — there the connection already carries the tenant, so the framework's own rules resolve correctly.

### Fixed

- Redis database-per-tenant no longer wraps its index around. Beyond the 16 logical databases Redis offers, `($tenantId - 1) % ($maxDatabase + 1)` would have seated two tenants in one database; the mode now keeps overflow tenants on the application database with key-prefix isolation and logs it at error level on every boot. Database-per-tenant is deprecated for removal in v2 — the `tenant:{id}:` key prefix has no tenant ceiling, works on Redis Cluster (which supports database 0 only), and does not fight connection pooling. Documented in SETUP.md.
- `FrameworkState` writes to static stores with `ReflectionProperty::setValue(null, $value)`. Passing a single argument for a static property is deprecated as of PHP 8.3 and becomes an error in a future release.
- `ConfigSystem` no longer takes tenant boot down with a `TypeError` when the application has no `baseURL` configured; the subdomain rewrite falls back to its defaults instead.
- Table-prefix isolation now applies the tenant's `DBPrefix` to the shared default connection when tenancy boots, rather than only when the first prefix-aware model happened to bind. Isolation no longer depends on model instantiation order: plain `CodeIgniter\Model` classes, raw `$db->table()` builders, and validation rules all resolve to the active tenant's tables with no model changes at all. Cached model instances are dropped on a tenant switch so no builder is left compiled against the previous tenant's prefix.

### Changed

- Models using `TenantTablePrefixTrait` no longer need to declare `implements TenantPrefixAware`. The trait already supplies both contract methods, so the binding check is duck-typed and the interface is optional. Models that already declare it keep working.
- Documented that prefix isolation requires **no** model changes, replacing the incorrect guidance that validation rules embedding a table name had to be rebuilt at runtime via `getTable()`. The connection-level `DBPrefix` already covers `is_unique`, raw builders, joins, and dotted selects.

### Removed

- Convenience query wrappers from `TenantModel` (`getActiveTenants()`, `findBySubdomain()`, `findByDomain()`, `subdomainExists()`, `findWithSettings()`, `updateSettings()`, `getDisplayName()`) and `TenantDomainModel` (`findByDomain()`, `findVerifiedByDomain()`, `findByTenantId()`, `getPrimaryDomain()`). Use CodeIgniter 4's native model API directly — e.g. `$model->where('subdomain', $s)->first()`, `$model->where('is_active', 1)->findAll()` — so application code depends only on the framework's documented API, not a package-specific query layer. `markVerified()` and `normalizeDomain()` remain (they guard domain-verification state, they are not queries). Internal call sites (`tenants:create`, `TenantResolverCache`) were migrated to native chains.
- Deprecated `updateBatchOwned()` alias from `TenantableModel` and `TenantableTrait`. Call `updateBatch()` directly — it is tenant-scoped natively.

### Changed

- Tenant guards now ride CodeIgniter 4's native model events instead of overriding CI4's public model API, so framework signature changes can no longer break the package. `TenantTablePrefixTrait` dropped its `find()`, `first()`, `findAll()`, `insert()`, `insertBatch()`, `update()`, `updateBatch()`, `save()`, and `delete()` overrides: reads without a tenant context short-circuit through the documented `beforeFind` `returnData` contract (null/`[]`, unchanged behaviour), and writes fail closed from `beforeInsert`/`beforeInsertBatch`/`beforeUpdate`/`beforeUpdateBatch`/`beforeDelete` callbacks with the same `MissingTenantContextException`. `TenantableModel` likewise dropped its `find()`/`first()` overrides and constructor in favour of the `initialize()` hook; without a tenant context `findAll()` now fails safe to `[]` (previously threw), matching `find()`/`first()`, the trait, and the prefix strategy. The only remaining overrides are the operations CI4 fires no events for: `countAllResults()` and `replace()`, plus `updateBatch()` (CI4's batch UPDATE ignores builder `where()` clauses) and the prefix trait's `builder()` (the prefix mechanism itself).
- `TenantableModel` now registers its callbacks from `initialize()` instead of `__construct()`. If your model extends `TenantableModel` and defines its own `initialize()`, call `parent::initialize()` from it or the model runs unscoped.

### Security

- `TenantableTrait` deletes without a tenant context now throw `MissingTenantContextException` instead of silently proceeding. CI4's `delete()` discards the `beforeDelete` callback's return value, so the previous `$data['return'] = false` guard was a no-op on current CI4 and the delete ran unscoped.
- Table-prefix isolation now actually queries the per-tenant tables. CodeIgniter's `Model` reads `$this->table` directly and never calls `getTable()`, so prefix models (`TenantTablePrefixModel` / `TenantTablePrefixTrait`) previously ran **every** query against the un-prefixed shared table — either erroring outright or silently sharing one table across all tenants, the opposite of the promised isolation. `TenantTablePrefixTrait` now resolves `$this->table` to the active tenant's physical table inside `builder()` (the choke point every read and write funnels through) and invalidates the cached builder when the active tenant changes. Fail-safe behaviour without tenant context: reads return empty results, writes throw the new `MissingTenantContextException`, and direct builder access fails loudly — nothing ever falls back to the un-prefixed table. Covered by a new end-to-end suite (`TenantPrefixIsolationTest`) running real CRUD against a real database with a poison-pill shared table.
- Switching tenants while a prefix model has un-executed chained clauses (`$model->where(...)` with no query run yet) now throws instead of silently discarding the pending clauses — which would have run a broader query than the caller composed against the new tenant's table. Executed queries reset the builder, so normal per-tenant fan-out loops are unaffected.
- `TenantableTrait` now wires its own callbacks through `initialize()`. CodeIgniter has no trait auto-discovery, so the documented usage (`use TenantableTrait;` on a plain `Model`) previously never called `initializeTenantableTrait()` — leaving the model completely unscoped: reads leaked every tenant, inserts were not stamped, and updates/deletes ran unscoped. If your model defines its own `initialize()`, call `$this->initializeTenantableTrait()` from it.
- Fixed an IDOR in `TenantableTrait` inserts: a caller-supplied `tenant_id` was honoured over the active tenant, allowing rows to be written into another tenant. The tenant context now always wins on insert (matching `TenantableModel`), and conflicting values are overwritten with a warning logged.
- `insertBatch()` and `updateBatch()` are now tenant-enforced on both `TenantableModel` and `TenantableTrait`. CI4 fires `beforeInsertBatch`/`beforeUpdateBatch` (not the single-row events) for batch operations, so batch writes previously bypassed tenant stamping, tenant_id immutability, and fail-closed handling entirely. Both are stamped from the active tenant (context wins per row) and throw without tenant context.
- `updateBatch()` is now tenant-scoped. CI4's batch UPDATE ignores builder `where()` clauses — it matches rows through the constraint columns — so the `beforeUpdateBatch` callback adds the tenant column to that constraint via `BaseBuilder::onConstraint()` and stamps every row with the active tenant. The generated WHERE becomes `table.tenant_id = _u.tenant_id AND table.id = _u.id`, so foreign rows never match and become no-ops instead of cross-tenant writes, mirroring `update()`'s silent scoping. This also makes `tenant_id` immutable for free: constraint columns are excluded from the SET list. No extra query is issued.
- Sessions are now bound to the tenant they were created under (`SessionTenantGuard`, gated by the new `$bindSessionsToTenant` config flag, default on). A session presented to a different tenant is destroyed. Previously session isolation depended entirely on storage layout, so switching `isolationMode` away from `database` (shared `ci_sessions` table + a cookie domain spanning subdomains) allowed a tenant-A session to authenticate on tenant B.
- `SessionSystem` and `EarlyTenantDetector` no longer rewrite `Session::$savePath` for non-file handlers. For `DatabaseHandler` `savePath` is the table name (and for Redis/Memcached a connection string); overwriting it with a directory path corrupted those handlers or silently left sessions shared across tenants.
- Both session code paths now set the same per-tenant session cookie name (`tenant_{id}_session`, gated by the new `$perTenantSessionCookies` flag, default on). Previously only `EarlyTenantDetector` renamed the cookie, so requests resolved by the filter fell back to the shared default cookie — flip-flopping users between two sessions and re-exposing the cross-subdomain cookie.
- New `$rejectUnboundSessions` flag (default off) destroys sessions that carry no tenant stamp instead of adopting them — recommended for one release cycle after switching isolation modes.

### Added

- PostgreSQL support for database-per-tenant provisioning. Tenantable now connects to a configurable maintenance database (`$postgresAdminDatabase`, default `postgres`) and uses CodeIgniter's PostgreSQL-aware Forge flow to create tenant databases. The central `tenant_domains.ssl_state` migration uses a PostgreSQL-compatible `VARCHAR` while preserving the existing MySQL/MariaDB `ENUM` schema.
- Per-tenant migration tracking for prefix mode: the new in-process prefix migrator (`TenantDatabaseManager::migrateTenantTables()`) runs each tenant's migrations against the shared connection with `TenantTableManager` scoped to that tenant, and records applied versions per tenant in the package-owned `tenant_migrations` table. CI4's shared `migrations` table is keyed by namespace only, so it cannot distinguish tenants sharing one database.
- Prefix mode now auto-provisions new tenants: creating a tenant (`tenants:create` or any `TenantModel` insert) runs the configured tenant migration namespaces through the prefix migrator, gated by `$autoMigrateTenant` (also exposed as `TenantDatabaseManager::provisionPrefixTenant()` for backfills). `tenants:create` reports how many migrations were applied and prints the `tenants:setup` backfill command when nothing was.
- Bundled a sessions-table migration for tenant databases (`$shipTenantSessionsTable`, default off). CodeIgniter 4 has no ready-to-run sessions migration of its own; it provides only the `php spark make:migration --session` generator. In database isolation the database session handler needs the table in every tenant database. The migration mirrors CI4's own `--session` template (MySQL and Postgres variants), honours `Config\Session::$matchIP` for the primary key, names the table after `Config\Session::$savePath` (fallback `ci_sessions`), and is idempotent. `tenants:install` now asks about it in database mode (`--sessions-table` for non-interactive runs), and both tenant provisioning and `tenants:run migrate` resolve their namespace list through the new `Config\Tenantable::tenantMigrationNamespaces()`.
- Added `support` section to `composer.json` with issue and source URLs.
- Added `phpstan/phpstan` to `require-dev`.
- Added `test-coverage` and `phpstan` composer scripts.
- Added GitHub Actions workflow running PHPUnit on PHP 8.1, 8.2, and 8.3.
- Added README badges for CI, PHPStan, latest version, and total downloads.
- Added quick-start section and "Why Tenantable?" pitch to README.

### Changed

- Improved `composer.json` description to focus on benefits.
- Cleaned up `composer.json` keywords and fixed typos.
- Lowered the minimum CodeIgniter requirement to 4.4 and made the package work on 4.4+ (see Fixed).
- `tenants:create --domain` now registers the domain in the `tenant_domains` table (as the primary domain) instead of the legacy `tenants.domain` column.
- Documented `identify_tenant:strategy=…` as the canonical identification filter; the per-strategy aliases remain supported but their filter classes are deprecated.
- `tenants:make-migration` now scaffolds the `tenant_id` column (`INT UNSIGNED NOT NULL`), an index on it, and a foreign key to the tenants table (`CASCADE`) when the resolved isolation mode is `row`. Previously the generated stub contained only `id` and timestamps, so row-isolation tables were created without the column the row strategy depends on. Prefix/database modes get no tenant column, as those strategies isolate by table or schema.
- Documented prefix-mode caveats in the README: validation rules embedding a table name (`is_unique[students.email]`) resolve against the literal un-prefixed name — build them at runtime via `$model->getTable()` — and chained queries must be executed or reset before switching tenants.

### Fixed

- `tenants:setup --mode=prefix` previously ran CI4's `MigrationRunner` against a namespace its discovery can never resolve (`app/Database/Migrations/Tenant` is not a registered namespace root, and the locator appends `/Database/Migrations/` to whatever it finds) — a silent no-op that printed green success per tenant while creating nothing; even with discovery working, the shared `migrations` tracking table would have marked tenant 1's run as applied for every other tenant. It now uses the in-process prefix migrator with per-tenant tracking and warns when a namespace yields no migration files.
- `tenants:run migrate` in prefix mode now runs the in-process prefix migrator instead of spawning `spark migrate` subprocesses, whose shared migration tracking would have skipped every tenant after the first (and whose namespace discovery found no tenant migrations at all).
- `tenants:make-migration` now emits `TenantTableManager::getTable()`-based `createTable()`/`dropTable()` calls when the resolved isolation mode is `prefix`. Previously it hard-coded the un-prefixed table name, so a generated migration created one shared table instead of per-tenant tables — contradicting the shipped `CreateExampleTable` stub and `tenants:setup`'s own printed guidance.
- `TenantableTrait` now logs a warning when a `tenant_id` change attempt is stripped on update, matching `TenantableModel` (previously silent).
- `TenantTablePrefixModel` is now in its own file so it is PSR-4 autoloadable (previously `extends TenantTablePrefixModel` could fatal on a clean install).
- Row-mode `countAllResults()` (and therefore `paginate()` totals) is now tenant-scoped instead of counting across all tenants.
- `EarlyTenantDetector` is no longer registered twice: the installer relies on `PackageEvents::register()` and no longer injects a duplicate `pre_system` listener.
- `createDatabase()` rejects unsupported and unknown drivers instead of emitting invalid DDL.
- The in-process tenant migrator records the same version token as CodeIgniter's migration runner and wraps each migration in a transaction, preventing double-application across `tenants:create` / `tenants:run migrate` and `php spark migrate`.
- The tenant bypass flag is cleared on `pre_system` in all SAPIs, preventing state bleed in long-running runtimes (Swoole/RoadRunner/queue workers).
- CodeIgniter 4.4 compatibility: tenant `is_active` checks are now truthy-based (the Model `$casts` feature only exists from 4.5, so on 4.4 `is_active` is an int), and `TenantModel` encodes/decodes the `settings` JSON itself on 4.4 while still using native casts on 4.5+.
- Corrected docs: `tenant_request` reads the `X-Tenant` header (matched by subdomain), security-middleware responsibilities, and `tenant_url()` is subdomain-oriented.
- `SessionSystem`, `CacheSystem`, and `RedisSystem` now capture the app's original settings once per boot/shutdown cycle. Previously every in-process tenant switch (`bootForTenant()`, `central()`, long-running workers) re-captured the *previous tenant's* values as the "originals", so shutdown restored a tenant's save path / cache prefix / Redis settings instead of the app's own.
- A legitimately blank `session.savePath` (as SETUP.md advises with early detection) is now restored as blank on shutdown; previously `''` was treated as "nothing to restore" and the tenant path leaked past shutdown.
- `RedisSystem` and `CacheSystem` now undo tenant scoping when booted for central context (`central()` / `runCentral()`); previously central work kept using the previous tenant's Redis keyspace, and the app's own cache prefix was discarded.

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

[Unreleased]: https://github.com/nuelcyoung/tenantable/compare/v1.5.1...HEAD
[1.5.1]: https://github.com/nuelcyoung/tenantable/compare/v1.5.0...v1.5.1
[1.5.0]: https://github.com/nuelcyoung/tenantable/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/nuelcyoung/tenantable/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/nuelcyoung/tenantable/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/nuelcyoung/tenantable/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/nuelcyoung/tenantable/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/nuelcyoung/tenantable/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/nuelcyoung/tenantable/releases/tag/v1.0.0
