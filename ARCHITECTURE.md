# Architecture

## Overview

Modpack Installer is designed as a modular Blueprint extension for
Pterodactyl.

The system is divided into several logical layers:

    Pterodactyl
         │
         ▼
    Blueprint
         │
         ▼
    Modpack Installer UI
         │
         ▼
    Application/API Layer
         ├── Catalog
         └── Installation Engine
         │
         ├── Downloader
         ├── Archive Handler
         ├── File Deployer
         └── Server Integration
         │
         ▼
    Modpack Providers
         ├── Catalog
         ├── Modrinth
         ├── CurseForge
         └── Future Providers

## Design Goals

### Pterodactyl compatibility

The extension should integrate with Pterodactyl rather than replacing
or modifying the panel unnecessarily.

### Blueprint compatibility

Use Blueprint's supported extension mechanisms wherever possible.

### Theme compatibility

The extension must not assume a specific third-party panel theme.

### Extension compatibility

The extension must avoid modifying shared global UI structures in ways
that could break other Blueprint extensions.

### Provider independence

The installation engine must not depend directly on a particular
modpack provider.

Provider-specific capabilities are surfaced through optional capability
interfaces, and the controller/UI branch on the interface, not on a provider
name. **Manual download** (`ManualDownloadProvider`) is one such capability:
providers that can resolve a project/file but whose installable archive has no
public download URL return normalized guidance (never keys, paths, or raw API
bodies), which the metadata endpoint exposes as a nullable `manual_download`
field and the dashboard renders instead of installing.

### Managed installations

Successful installs are recorded in an extension-owned JSON store
(`InstallRecordStore`, `InstallRecord`) so the dashboard can update and
uninstall installed modpacks without trusting the client:

- **Persistence.** Records live in `MODPACK_INSTALLER_DATA_DIR`
  (default `/var/lib/pterodactyl/modpack-installer`) under `installs.json`,
  keyed by server UUID. Writes take an exclusive `flock` on a separate lock
  file and commit via a same-directory temporary file + `rename`, so concurrent
  mutating requests and crashed writers never yield a torn file. Records are
  hydrated with strict validation; corrupt or hostile entries are ignored.
- **Record content.** Normalized source (pinned to the actually-resolved
  version), display name, version, Minecraft version/loader, timestamps, and an
  ownership manifest of the server-relative paths the engine created/overwrote.
  Ownership paths are re-validated with `ServerRelativePath`
  both when written and when read back.
- **Update.** The base source (unpinned project) is re-resolved by the provider
  to the latest version and deployed through the full installation engine
  (backup/rollback). If the resolved version equals the recorded version the
  request returns a controlled `409`. The record (including ownership) is
  swapped only after the engine reports success; files orphaned by the previous
  version are left in place and simply drop off the manifest.
- **Uninstall.** `OwnershipRemover` deletes exactly the owned relative paths,
  skipping missing entries deterministically and never touching directories or
  unrelated files. The record is deleted only when the entire removal succeeds;
  any removal error aborts without mutating the store.
- **Concurrency.** Update/uninstall share the same per-server installation lock
  as installs; the ownership store is additionally consistent under concurrent
  writes via the lock + atomic rename above. Installed records for one server
  never affect another.

### Catalog

Browsing and search is a read-only contract (`CatalogProvider`) separate from
project metadata and package download, so no catalog path ever downloads or
deploys anything. The UI loads the provider list, search results, version
listings, and project details as plain JSON; it never embeds
provider-specific API logic.

- **Provider discovery.** `GET /catalog/providers` advertises every provider
  with an availability flag, a machine-readable `state` (`available`,
  `not_configured`, ...), whether it is `development_only`, a human
  `unavailable_reason`, its filter `capabilities` (which groups the
  `query`/`game_versions`/`loaders`/`categories`/`environment`/`sort` input
  pipeline supports), and its `facets` (the exact option lists for each
  group). It also returns a `default_provider` that the controller picks as
  the first available **non-development-only** provider. The dashboard hides
  development-only providers and renders its toolbar, filter panel, and sort
  controls from these payloads rather than hard-coding provider behavior.
- **Request input.** `query`, `sort`, `page`, and `limit` stay scalar-typed,
  length-capped, and enum/pattern-checked the moment they enter the
  controller. The filter groups are **multi-value**:
  `game_versions`, `loaders`, `categories`, and `environments` are sent as
  comma-separated values and parsed into bounded string arrays
  (≤ 32 values each, strict slugs/versions). Semantics are OR within a group
  and AND across groups. Rejections are `422` with static messages.
- **Upstream.** The only upstream hosts are constants (`api.modrinth.com` for
  search, the existing provider API bases). Client-supplied values travel only
  inside query parameters/facets, never in request URLs. Responses are
  type-checked before mapping: malformed hits lists, bad totals, and invalid
  item shapes become controlled `502` errors. Rate limits, upstream 5xx, and
  timeouts map to retryable `503` errors.
- **Per-provider filter honesty.** Modrinth maps each filter group to a
  separate search facet, so multi-value groups are applied upstream as-is.
  CurseForge's search API accepts a single value per facet, so the provider
  sends one (stable) value upstream and applies the remaining values of each
  group as a **provider-side post-filter** — never the frontend. When
  post-filtering occurs the reported total is conservative
  (`offset + count(filtered)`), so a later page never fabricates results.
  CurseForge has no environment concept and returns an honest empty result
  when `environments` is requested. Missing CurseForge configuration marks
  the provider `not_configured`, refuses search with a static message, and
  never sends the API key.
- **Version listings.** `GET /catalog/versions` is a separate read-only
  endpoint (`provider` + required `project` + optional multi-value
  `game_versions` / `loaders` filters, all strict slugs/versions) that lists a
  project's versions. Upstream versions are filtered to public statuses and
  mapped to `CatalogVersion` value objects whose `source` is **pinned** to the
  exact version (`modrinth://<project>@<version-id>`). The Modrinth catalog
  provider encodes the filter arrays as JSON for the `game_versions`/`loaders`
  query parameters; non-array entries are rejected as `502`.
- **Project details.** `GET /catalog/project` (`provider` + `project`) maps a
  single project/project-id to the same normalized `CatalogItem` shape as
  search results, so the dashboard can reference a pack without re-searching.
- **Source resolution.** The installation engine's `ModrinthProvider` parses the
  pin, fetches the exact version, and asserts it belongs to the requested
  project before use. The dashboard therefore resolves a chosen version to a
  normalized pinned source that the existing metadata/install pipeline
  consumes unchanged.
- **No caching.** Catalog responses are not cached; every request is answered
  live by the selected provider to avoid serving stale or cross-tenant data.
- **Error hygiene.** Catalog errors are static and never include hosts, URLs,
  provider API keys, or internal exception details; unexpected failures are
  reported to the panel logs and surfaced as a generic `500`.

### Security

Downloaded archives and files must be treated as untrusted input.
Installation must validate paths, archives and provider responses.

#### Threat model and hardening

Every input boundary treats its data as untrusted and is validated:

- **Request input.** Modpack sources are scalar-typed
  and length-capped in the controller before they reach a provider or the
  installation engine. Rejections are `422 Bad Request` with static messages.
- **Provider payloads.** Structured (JSON) provider responses are type-checked
  at parse time; malformed lists or missing required fields become controlled
  errors, never crashes. The CurseForge API key is transmitted only inside the
  request header and never appears in URLs, logs, or error messages.
- **Downloads.** The downloader refuses non-HTTP(S) schemes, URLs containing
  credentials, and hosts whose DNS records point at private or reserved
  address space (loopback, link-local, ULA, multicast, CGNAT `100.64/10`,
  documentation and benchmark ranges, NAT64 `64:ff9b::/96`, IPv4-mapped IPv6).
  Addresses are resolved first and then pinned with `CURLOPT_RESOLVE`, so a
  hostname can never be re-resolved by libcurl to a different (internal)
  address. Redirects are followed only hop-by-hop with per-hop re-validation
  (scheme, host, credentials, address), capped at 5 hops, and every hop body
  is streamed against a byte budget. Connection and total timeouts bound the
  request. Download size is capped (`MODPACK_INSTALLER_MAX_DOWNLOAD_MB`,
  default 2 GiB) and enforced during the transfer, so no oversized file is
  ever written to disk.
- **Archives.** A validation pass runs before extraction and rejects path
  traversal, absolute paths, Windows drive letters, NULs, backslashes,
  duplicate or conflicting entries, symbolic links, special device types, and
  abuse that exceeds the configured size/entry budget. Extraction is
  per-entry and bounded at runtime, and a failed extraction is fully cleaned
  up. The validator's limits are the extractor's limits.
- **Workspaces.** Extracted content stays inside a per-install workspace under
  the shared temporary root. The package-root resolver asserts the selected
  override directory remains inside that workspace, and the deployment
  planner re-checks that every planned source file is contained in it.
- **Deployment.** Every relative path is re-validated against absolute paths,
  traversal, NULs, and drive letters immediately before backup or write.
  Writes go through the server file target (`local` filesystem or Wings),
  never through direct filesystem access on the Panel. Overwritten files are
  backed up and restored on failure; rollback is best-effort and its
  failures are reported, never swallowed silently.
- **Concurrency.** A per-server filesystem lock serializes installs. A second
  install for the same server fails fast (`503 Service Unavailable`),
  stale locks are reclaimed after a timeout, and a lock can only be released
  by the request that holds it (token check).
- **Error hygiene.** Client-facing messages are static and never embed hosts,
  URLs, paths, tokens, the daemon key, the server UUID, or the CurseForge key.
  Unexpected internal errors are reported to the panel logs and surfaced as a
  generic `500` message.

#### Known limitations

- The downloader's success/redirect path uses real public hosts and is
  therefore verified during deployment rather than in the hermetic suite.
- The Modrinth catalog's live search path is likewise forced to a constant
  public API host and is verified during deployment; the hermetic suite covers
  request construction, facet mapping, normalization, and error mapping with
  fake responses.
- CurseForge catalog search sends a single value per facet upstream; any
  additional values in the same group are applied with a provider-side
  post-filter and the total is reported conservatively. Environment filtering
  is not supported by CurseForge and yields an honest empty result.
- The development-only Mock catalog provider is intentionally hidden from the
  production dashboard; `/catalog/providers` still advertises it so the
  hermetic suite and local setups can exercise it.
- Panel log lines from `report()` are only as sanitized as their inputs;
  provider HTTP failure details are mapped to static messages before
  bubbling up. (CurseForge authorization headers are never logged.)
- Since the server file target has no recursive directory listing surface
  (see TARGETS.md), uninstall removes files but never prunes now-empty
  directories; empty folders may remain after uninstall.
