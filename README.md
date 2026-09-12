# Modpack Installer

A modular modpack installation extension for Pterodactyl powered by
Blueprint.

## Goals

- One-click modpack installation
- Multiple modpack providers
- Pterodactyl compatibility
- Blueprint compatibility
- Panel theme compatibility
- Safe and reliable installations
- Extensible provider architecture

## Status

🚧 Early development

## Development

This project is developed as a Blueprint extension.

The source repository is maintained separately from the Pterodactyl
installation. Blueprint's `.blueprint/dev` directory is used only as the
development deployment target.

## Providers

The installer ships with a mock provider for development plus Modrinth and
CurseForge providers. See [PROVIDERS.md](PROVIDERS.md) for source formats,
configuration, catalog support, and current package limitations.

## Catalog

The dashboard is catalog-first: it searches, filters, sorts, and pages through
modpacks from a normalised catalog endpoint (`/metadata`-style client route
`/api/client/extensions/modpackinstaller/catalog`). Provider browsing is
read-only and never asks a provider to download or install anything.

- Real Modrinth catalog search is served directly from `api.modrinth.com`.
- CurseForge exposes a catalog provider contract but is listed as unavailable
  until search support is implemented; it never claims live results.
- Searches are **not cached**: every request is answered live by the selected
  provider, and upstream calls go through the same pinned HTTP client as the
  rest of the extension. See [ARCHITECTURE.md](ARCHITECTURE.md) for the
  filter/sort mapping and error handling.

## Server Target

Installer files are written to a server's file tree through a pluggable
"server file target". In development (and in the test suite) this is the
local filesystem; in production the installer can talk directly to the
Pterodactyl Wings daemon for the server's node.

See [TARGETS.md](TARGETS.md) for both modes, configuration, security
considerations, and current limitations.

## Security & Reliability

Untrusted input (modpack sources, provider payloads, downloaded archives,
client-supplied options) is validated at every boundary:

- **Downloads** reject non-HTTP schemes, credentialed URLs, and any host that
  resolves to a private, loopback, link-local, multicast, CGNAT, NAT64,
  benchmark, or documentation address. Redirect hops are resolved and
  re-validated individually, capped at 5, and each hop's body is size-bounded.
- **Archives** must be valid ZIPs with relative-only, normalized paths. Path
  traversal, absolute paths, drive letters, NUL bytes, backslashes, duplicate
  entries, file/directory collisions, symbolic links and special device
  entries are rejected before extraction; entropy (archive size, per-entry
  size, extracted size, entry count) is bounded both before and during
  extraction.
- **Deployment** only writes through the server file target with re-validated
  relative paths, honors backup/rollback for every overwrite, and never lets
  best-effort cleanup mask an installation outcome.
- **Concurrency** is serialized per server with a filesystem lock: second
  installs for the same server receive `503 Service Unavailable` while one is
  already running, and stale locks are reclaimed automatically.
- **Errors** returned to clients are static and never include hosts, URLs,
  tokens, or the CurseForge API key.

Optional knobs (server-side environment variables):

| Variable                            | Meaning                                        |
|-------------------------------------|------------------------------------------------|
| `MODPACK_INSTALLER_MAX_DOWNLOAD_MB` | Max modpack download in MiB (default 2048).   |
| `CURSEFORGE_API_KEY`                | CurseForge API key (`Providers` document).    |

See [ARCHITECTURE.md](ARCHITECTURE.md) for the hardening model and
[DEVELOPMENT.md](DEVELOPMENT.md) for how the guarantees are verified.

## License

TBD
