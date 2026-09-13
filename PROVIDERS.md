# Modpack Providers

The installer resolves modpacks through pluggable providers. Providers are
selected deterministically by the provider registry based on the source
string entered in the dashboard.

## Source formats

| Provider      | Source format                  | Example                                   |
| ------------- | ------------------------------ | ----------------------------------------- |
| Mock (dev)    | `mock://example-pack`          | `mock://example-pack`                     |
| Mock (dev)    | `mock://example-pack@<version>`| `mock://example-pack@1.1.0`               |
| Modrinth      | `modrinth://<slug-or-id>`      | `modrinth://prominence-2-rpg`             |
| Modrinth      | `modrinth://<slug-or-id>@<version-id>` | `modrinth://prominence-2-rpg@abc123` |
| Modrinth      | `https://modrinth.com/modpack/<slug>` | `https://modrinth.com/modpack/prominence-2-rpg` |
| CurseForge    | `curseforge://<project-id>`    | `curseforge://314768`                     |

Appending `@<version-id>` to a source pins the exact project version. Unpinned
sources keep resolving to the latest released version. Pins are validated
strictly (slug/id characters only); the resolved version is checked to actually
belong to the project before it is used.

The mock provider is intentionally limited to `mock://example-pack` (plus the
pinned `mock://example-pack@1.0.0` / `mock://example-pack@1.1.0` fixtures) and
is used for deterministic development tests.

## Configuration

### Modrinth

Modrinth's public API does not require a key. No configuration is needed for
basic project metadata and package lookup.

### CurseForge

CurseForge requires an API key. The key is read server-side only:

```dotenv
CURSEFORGE_API_KEY=your_key_here
```

Requirements:

- Set the key in the Pterodactyl panel server environment (for example
  `.env`), not in the browser.
- Never place the key in React code, frontend bundles, repository files, or
  client-side configuration.
- The key is sent to CurseForge as the `X-Api-Key` request header and is
  never included in responses, logs, or error messages.
- If the key is missing, CurseForge sources return a clear configuration
  error instead of failing the whole extension. Other providers keep working.

Get an API key at <https://console.curseforge.com/>.

## Provider behavior

### Modrinth

- Uses the official Modrinth API (`api.modrinth.com`); no HTML scraping.
- Only `modpack` projects are accepted.
- Metadata: project id, title, the selected version (latest released version
  for unpinned sources, or the exact pinned version), its Minecraft version
  and loader (first supported value), description, icon, and the canonical
  `modrinth://<slug-or-id>` (or pinned) source.
- A pinned `@<version-id>` source is resolved with the version-by-id endpoint
  and is validated to belong to the requested project before it is used; a
  missing or unrelated version produces a clean, static error.
- Packages download the primary `.mrpack` file and normalize it into a
  server-ready archive before installation:
  - `overrides/` files are deployed at the server root;
  - `server-overrides/` files win over `overrides/` on conflicts;
  - `client-overrides/` and the `modrinth.index.json` manifest are skipped.
- The normalized archive is deployed by the existing installation engine and
  cleaned up after preview/install.
- `getPackage` pins the actually-resolved version id in the returned source
  (`modrinth://<slug-or-id>@<version-id>`) even for unpinned requests, so an
  installed pack records exactly what is deployed and can be diffed against
  later versions for update tracking.

### CurseForge

- Uses the official CurseForge API (`api.curseforge.com`) with the configured
  API key.
- Only numeric project ids are accepted.
- Metadata: project id, name, latest Release file (falling back to the newest
  file), its Minecraft version and loader, description, icon, and canonical
  `curseforge://<project-id>` source.
- Packages use the file's public `downloadUrl` when available. The resolved
  file, server pack expansion included, is what defines the installable file:
  a client-pack archive is rejected, and a server-pack archive is deployed
  as-is.
- When the installable file has no public download URL, `getPackage`/install
  are refused with a clear error and the provider reports
  `manualDownloadInfo()`: normalized guidance (name, version, official
  download/project URLs, reason) that the metadata endpoint surfaces as
  `manual_download`. The dashboard renders this instead of installing. Updates
  surface the same requirement.
- `getPackage` records the actually-resolved file id in the returned source
  (`curseforge://<project-id>@<file-id>`) so that an installed pack can be
  compared against later versions for update tracking.

## Current package limitations

- The installation engine deploys an archive's files directly. It does not
  resolve mod dependencies from a manifest.
  - Modrinth `.mrpack` files are reduced to their `overrides` /
    `server-overrides` content (configuration and server files), which is the
    part a server actually needs. Individual mod files are not downloaded.
  - CurseForge client modpacks (archives containing `manifest.json`) require
    mod file resolution and are rejected with a clear error. Install a
    CurseForge server pack instead.
- CurseForge clients switching between "Overwrite" and "Skip existing"
  policies is fine, but the engine never resolves mod dependencies from a
  manifest (see above).
- CurseForge files without a public download URL can never be installed or
  updated automatically; the dashboard shows official download guidance and
  the API refuses with a clear error (`manual_download`).
- Packages depend on the server already having the matching game/loader
  runtime; the installer does not provision the server binary.

## Catalog

The catalog is a read-only browsing/search layer that is separate from project
metadata and package download. Providers implement a
`CatalogProvider` contract and are advertised to the dashboard through
`/catalog/providers` with an availability flag.

### Query parameters

`GET /catalog` accepts (all optional except where noted):

| Parameter      | Meaning                                         | Validation / limits                          |
| -------------- | ----------------------------------------------- | -------------------------------------------- |
| `query`        | Free-text search                                | ≤ 128 chars                                  |
| `provider`     | Catalog provider (default `modrinth`)           | `[a-z0-9-]{1,32}`                            |
| `game_version` | Minecraft version facet                         | `[0-9A-Za-z._-]{1,32}`                       |
| `loader`       | Loader facet (`fabric`, `forge`, ...)           | lowercased `[a-z0-9-]{1,32}`                 |
| `category`     | Category facet                                  | lowercased `[a-z0-9-]{1,32}`                 |
| `sort`         | `relevance` (default), `downloads`, `follows`, `newest`, `updated` | enum                 |
| `page`         | 1-based page, default 1                         | 1..10000                                     |
| `limit`        | Page size, default 20                           | 1..50                                        |

Invalid parameters return `422`. The response echoes the active provider, the
filters as applied, and the sort; item fields are nullable when a provider has
no equivalent. Filtered strings are strict slugs/versions so they can be
embedded in upstream query facets without path injection.

### Provider support

- **Mock (dev)** – deterministic in-memory catalog for development and tests.
  Clearly labeled "Mock (development)"; items never fake popularity metrics,
  icons, or project URLs.
- **Modrinth** – full real search against the official `v2/search` endpoint.
  Facets: `project_type:modpack`, `versions:<minecraft>`, and `categories` for
  loader/category. `sort` maps 1:1 to Modrinth `index` values. Downloads,
  follows, versions, loaders, and latest version are carried through.
- **CurseForge** – the contract is implemented (and it is always marked
  unavailable) but catalog search is **not implemented yet**. The stub never
  makes an upstream request and never sends the API key; missing configuration
  reports a clear `CURSEFORGE_API_KEY` error.

Searches are not cached; every request is answered live by the selected
provider. Upstream 429/5xx/timeouts surface as `503` retryable errors, malformed
or rejected upstream responses as `502`, and client input problems as `422`.
The dashboard treats an empty result as a valid response, never an error.

### Version listings

`GET /catalog/versions` lists the published versions of one project so the
dashboard can offer exact-version selection:

| Parameter      | Meaning                                         | Validation / limits                          |
| -------------- | ----------------------------------------------- | -------------------------------------------- |
| `provider`     | Catalog provider (default `modrinth`)           | `[a-z0-9-]{1,32}`                            |
| `project`      | Project slug or id (required)                   | `[A-Za-z0-9_-]{1,64}`                        |
| `game_version` | Minecraft version filter                        | `[0-9A-Za-z._-]{1,32}`                       |
| `loader`       | Loader filter (`fabric`, `forge`, ...)          | lowercased `[a-z0-9-]{1,32}`                 |

Invalid parameters return `422`; an unknown/empty project returns an empty
`versions` list (not an error). The response echoes the provider, the applied
filters, and the versions:

```json
{
  "data": {
    "provider": "modrinth",
    "filters": { "game_version": "1.21.1", "loader": "fabric" },
    "versions": [
      {
        "version_id": "abc123",
        "version_number": "2.0.1",
        "version_name": "Prominence 2 RPG 2.0.1",
        "game_versions": ["1.21.1"],
        "loaders": ["fabric"],
        "source": "modrinth://prominence-2-rpg@abc123"
      }
    ]
  }
}
```

Each version carries an installable `source` **pinned** to that exact version
(`@<version-id>`), so the dashboard can hand the version straight to the
metadata/preview/install endpoints. Non-public upstream versions (drafts,
scheduled, unlisted, withheld) are excluded server-side. The mock provider
returns deterministic pinned versions for its fixtures.

### Dashboard flow

The dashboard opens each modpack in a details dialog: Minecraft version and
loader filters reload `/catalog/versions`, the chosen version's pinned source
is validated through `/metadata` (which resolves the exact version
server-side), and the existing preview/install endpoints are called with that
pinned source.

## Development

Manual tests use fake HTTP responses and local fixture archives. They never
contact Modrinth or CurseForge:

```bash
php tests/manual/modrinth-provider-test.php
php tests/manual/curseforge-provider-test.php
php tests/manual/provider-registry-test.php
```
