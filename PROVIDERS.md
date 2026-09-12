# Modpack Providers

The installer resolves modpacks through pluggable providers. Providers are
selected deterministically by the provider registry based on the source
string entered in the dashboard.

## Source formats

| Provider      | Source format                  | Example                                   |
| ------------- | ------------------------------ | ----------------------------------------- |
| Mock (dev)    | `mock://example-pack`          | `mock://example-pack`                     |
| Modrinth      | `modrinth://<slug-or-id>`      | `modrinth://prominence-2-rpg`             |
| Modrinth      | `https://modrinth.com/modpack/<slug>` | `https://modrinth.com/modpack/prominence-2-rpg` |
| CurseForge    | `curseforge://<project-id>`    | `curseforge://314768`                     |

The mock provider is intentionally limited to the exact `mock://example-pack`
source and is used for deterministic development tests.

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
- Metadata: project id, title, latest released version (falling back to the
  newest version), its Minecraft version and loader (first supported value),
  description, icon, and canonical `modrinth://<slug-or-id>` source.
- Packages download the primary `.mrpack` file and normalize it into a
  server-ready archive before installation:
  - `overrides/` files are deployed at the server root;
  - `server-overrides/` files win over `overrides/` on conflicts;
  - `client-overrides/` and the `modrinth.index.json` manifest are skipped.
- The normalized archive is deployed by the existing installation engine and
  cleaned up after preview/install.

### CurseForge

- Uses the official CurseForge API (`api.curseforge.com`) with the configured
  API key.
- Only numeric project ids are accepted.
- Metadata: project id, name, latest Release file (falling back to the newest
  file), its Minecraft version and loader, description, icon, and canonical
  `curseforge://<project-id>` source.
- Packages use the file's public `downloadUrl` when available.

## Current package limitations

- The installation engine deploys an archive's files directly. It does not
  resolve mod dependencies from a manifest.
  - Modrinth `.mrpack` files are reduced to their `overrides` /
    `server-overrides` content (configuration and server files), which is the
    part a server actually needs. Individual mod files are not downloaded.
  - CurseForge client modpacks (archives containing `manifest.json`) require
    mod file resolution and are rejected with a clear error. Install a
    CurseForge server pack instead.
- CurseForge files without a public download URL are rejected with a clear
  error (the pack author has not enabled mod distribution).
- Packages depend on the server already having the matching game/loader
  runtime; the installer does not provision the server binary.

## Development

Manual tests use fake HTTP responses and local fixture archives. They never
contact Modrinth or CurseForge:

```bash
php tests/manual/modrinth-provider-test.php
php tests/manual/curseforge-provider-test.php
php tests/manual/provider-registry-test.php
```