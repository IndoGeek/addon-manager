# Security Policy

## Supported versions

Only the latest release (see `CHANGELOG.md`) receives security fixes.

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

This extension downloads archives from third-party APIs (Modrinth,
CurseForge) and extracts them onto game-server volumes — a supply-chain or
path-traversal bug here is high impact. Report privately:

- **GitHub Security Advisories**: *Security* tab → *Report a vulnerability*
  on this repository (preferred), or
- **Email**: the maintainer contact listed on the
  [repository profile](https://github.com/IndoGeek).

Include reproduction steps, affected versions, and any malicious archive
that triggers the issue. You can expect an initial response within 7 days.

## Scope

In scope: anything the extension executes or extracts (archive handling,
path validation, ownership/permission changes, API response parsing), the
admin settings page, and the client API routes.

Out of scope: vulnerabilities in Pterodactyl panel or Blueprint themselves —
report those upstream.

## Known design notes

- Archives are validated (`ArchiveValidator`) before extraction
  (`ArchiveExtractor`) — see `tests/manual/archive-security-test.php` and
  `provider-security-test.php` for the enforced invariants.
- The extension never elevates privileges beyond what the installer grants;
  volume writes go through the `pterodactyl` group, not root.
