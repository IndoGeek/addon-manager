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

Functional: catalog browsing with multi-value filters, exact-version
selection, network-hardened installation, and an installed-modpack lifecycle
(update to latest / uninstall). Providers: Modrinth (live search + install),
CurseForge (catalog search when configured, metadata/install with
manual-download guidance for packs without a public download URL), and a
development-only Mock provider that is always hidden from the production
dashboard.

## Development

This project is developed as a Blueprint extension.

The source repository is maintained separately from the Pterodactyl
installation. Blueprint's `.blueprint/dev` directory is used only as the
development deployment target.

## Providers

The installer ships with a mock provider for development plus Modrinth and
CurseForge providers. See [PROVIDERS.md](PROVIDERS.md) for source formats,
configuration, catalog support, and current package limitations.

CurseForge packs whose installable file has no public download URL are never
installed automatically; the dashboard shows normalized manual-download
guidance instead.

## Catalog

The dashboard is catalog-first: it searches, filters, sorts, and pages through
modpacks from a normalised catalog endpoint (`/metadata`-style client route
`/api/client/extensions/modpackinstaller/catalog`). Provider browsing is
read-only and never asks a provider to download or install anything. Selecting a
modpack opens a details dialog that lists its published versions
(`/catalog/versions`), resolves the chosen version to an exact pinned source
(`modrinth://<project>@<version-id>`), and feeds that source into the existing
metadata/install pipeline.

- Catalog searches accept multi-value filters (`game_versions`, `loaders`,
  `categories`, `environments` as comma-separated arrays): OR within a group,
  AND across groups. Providers advertise their supported facets and
  capabilities through `/catalog/providers`, and the dashboard renders the
  filter panel from that data instead of hard-coding options.
- Real Modrinth catalog search is served directly from `api.modrinth.com`,
  including environment filtering.
- CurseForge catalog search is implemented and live once `CURSEFORGE_API_KEY`
  is configured. Since the CurseForge search API takes a single value per
  facet, multi-value selections are applied honestly as an upstream first
  value plus a provider-side post-filter; totals are conservative when
  post-filtering occurs, and environment filtering is unsupported (an honest
  empty result).
- Development-only providers are never shown to users; the `default_provider`
  advertised by `/catalog/providers` is always a real, available provider.
- Searches are **not cached**: every request is answered live by the selected
  provider, and upstream calls go through the same pinned HTTP client as the
  rest of the extension. See [ARCHITECTURE.md](ARCHITECTURE.md) for the
  filter/sort mapping and error handling.
- Project details can also be fetched directly via `GET /catalog/project`
  (`provider` + `project`).

## Installed modpacks lifecycle

Every successful install is recorded in an extension-owned store. The dashboard
lists the installed modpacks for the current server and offers:

- **Update to latest** – re-resolves the same project's latest version, deploys
  it through the installation engine (with backup/rollback), and updates the
  record only on success. Already up-to-date returns a controlled `409`.
- **Uninstall** (two-step confirm) – removes only the files the record owns
  and nothing else; missing files are tolerated and directories are never
  deleted. The record is removed only when the whole removal succeeds.

Records are stored as JSON in `MODPACK_INSTALLER_DATA_DIR` (default
`/var/lib/pterodactyl/modpack-installer`), keyed by server UUID, written with an
exclusive lock and an atomic rename. See [ARCHITECTURE.md](ARCHITECTURE.md) for
the persistence, ownership, and concurrency model.

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
  install/update/uninstall operations for the same server receive
  `503 Service Unavailable` while one is already running, and stale locks are
  reclaimed automatically.
- **Errors** returned to clients are static and never include hosts, URLs,
  tokens, or the CurseForge API key.

Optional knobs (server-side environment variables):

| Variable                            | Meaning                                        |
|-------------------------------------|------------------------------------------------|
| `MODPACK_INSTALLER_MAX_DOWNLOAD_MB` | Max modpack download in MiB (default 2048).   |
| `MODPACK_INSTALLER_DATA_DIR`        | Installed-modpack record store dir (default `/var/lib/pterodactyl/modpack-installer`). |
| `CURSEFORGE_API_KEY`                | CurseForge API key (`Providers` document).    |

See [ARCHITECTURE.md](ARCHITECTURE.md) for the hardening model and
[DEVELOPMENT.md](DEVELOPMENT.md) for how the guarantees are verified.

## License

TBD
