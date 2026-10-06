# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 2.0.x   | Yes       |
| 1.5.x   | No        |
| < 1.5   | No        |

Tenantable 1.5.x and earlier contain cross-tenant data exposure defects. See
[GHSA-vqhw-9c6v-pm25](https://github.com/nuelcyoung/tenantable/security/advisories/GHSA-vqhw-9c6v-pm25)
and the Security section of [CHANGELOG.md](CHANGELOG.md). Upgrade to 2.0.

## Reporting a vulnerability

Report privately through GitHub's "Report a vulnerability" button on the
Security tab of the repository, which opens a private advisory visible only to
the maintainer.

Please include the package version, the isolation mode in use (`row`, `prefix`,
or `database`), the CodeIgniter version, and a reproduction if you have one. A
failing test in the style of the existing suites in `tests/` is the most useful
thing you can send.

You can expect an acknowledgement within a few days. I will tell you when a fix
is ready, and I will credit you in the advisory unless you would rather not be
named.

## Scope

In scope: cross-tenant data access, tenant context escape, unauthenticated
access to a tenant's routes or storage, and privilege escalation across tenants.

Out of scope: an application that deliberately disables isolation (a
`GlobalModel` or `withoutTenant()` used where tenant scoping was intended), and
vulnerabilities in CodeIgniter itself. Report those to the framework's
maintainers.
