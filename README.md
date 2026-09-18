<div align="center">

[![Pterodactyl 1.8+](https://img.shields.io/badge/pterodactyl-1.8%2B-1059D6?logo=pterodactyl&logoColor=white)](https://pterodactyl.io)
[![Modrinth](https://img.shields.io/badge/modrinth-available-00AF5C?logo=modrinth&logoColor=white)](https://modrinth.com)
[![CurseForge](https://img.shields.io/badge/curseforge-supported-F16436?logo=curseforge&logoColor=white)](https://www.curseforge.com)
[![GitHub Releases](https://img.shields.io/badge/github-releases-007EC6?logo=github&logoColor=white)](https://github.com/indogeek/addon-manager/releases)
[![GitHub Downloads](https://img.shields.io/github/downloads/indogeek/addon-manager/total?logo=github&logoColor=white)](https://github.com/indogeek/addon-manager/releases)
[![GitHub Forks](https://img.shields.io/github/forks/indogeek/addon-manager?logo=github&logoColor=white)](https://github.com/indogeek/addon-manager/network/members)
[![GitHub Stars](https://img.shields.io/github/stars/indogeek/addon-manager?logo=github&logoColor=white)](https://github.com/indogeek/addon-manager/stargazers)
[![MIT License](https://img.shields.io/badge/license-MIT-blue?logo=github&logoColor=white)](LICENSE)

</div>

# Addon Manager

![Addon Manager](assets/banner.webp)

A powerful Pterodactyl Panel extension for browsing and installing Minecraft content — modpacks, mods, plugins, datapacks, resource packs and shaders — from **Modrinth** and **CurseForge** with a modern, user-friendly interface.

## Features

- **Multi-Provider Support** – Modrinth works out of the box; CurseForge with an API key
- **Every Content Type** – Modpacks, mods, plugins, data packs, resource packs and shaders
- **Correct Placement** – Each type lands where the server actually reads it (see the table below)
- **Version-Aware Picking** – Loader and Minecraft version dropdowns that only offer combinations published upstream, with recommended dependencies listed for one-shot installs
- **Side-by-Side Installs** – Content files install individually and are named for the version you picked, so several builds coexist
- **Safe Removal** – Uninstalling deletes the recorded file only, never the whole `mods/` (or `plugins/`, …) directory
- **Advanced Filtering** – Search by game version, loader, category and provider, in a grid or list view
- **Live Downloads** – Per-file progress with stage reporting (download → unarchive → manifest → fetch → deploy) and a cancel button per install
- **Server Management** – Installed tab with integrity checks, plus update, restore and uninstall
- **Secure** – API keys stored server-side, never exposed to the client

### Where each content type lands

| Type | Destination inside the server |
| ---- | ----------------------------- |
| Modpack | server root — files, overrides and its `mods/` come from the manifest |
| Mod | `mods/` |
| Plugin | `plugins/` |
| Data pack | `world/datapacks/` |
| Resource pack | `resourcepacks/` |
| Shader | `shaderpacks/` |

Downloaded files are renamed from your selections (e.g. `example-mod-fabric-1-20-1.jar`),
so every installed file is self-describing.

## Requirements

- **Pterodactyl Panel** v1.8+
- **Blueprint** (Pterodactyl extension framework)
- **PHP** 8.1+
- **Node.js** 18+ (used by Blueprint to compile frontend assets during install)

## Installation

> **You need `sudo` (root) access on the server running your Pterodactyl panel.**
>
> Full details are in **[INSTALLATION.md](INSTALLATION.md)**.

### Quick start (recommended)

Clone this repository **into your Pterodactyl directory**, install the `mi` developer CLI, and run the installer:

```bash
cd /var/www/pterodactyl
sudo git clone https://github.com/indogeek/addon-manager.git
cd addon-manager
sudo ln -sf "$PWD/mi" /usr/local/bin/mi
sudo mi install
```

`mi install` (the former `installer.sh`) automatically:

- installs missing system tools and the PHP **curl**/**zip** extensions,
- raises your PHP-FPM limits so large modpacks (~400 MB+) don't time out mid-download,
- installs **Blueprint** if you don't have it yet,
- installs this extension with `blueprint -install` and publishes the panel assets.

## Development

The repo ships **`mi`**, a small developer CLI covering the whole workflow:

```bash
sudo ln -sf "$PWD/mi" /usr/local/bin/mi   # once
mi help                                   # all commands; mi <cmd> --help for flags

mi test [<name>]       # manual test suites (55 suites, ~12s)
mi lint                # php -l + blade compile + style + TypeScript
mi audit [path...]     # static check: catch clauses that can never match
mi check               # lint + audit + test — run before pushing
mi build               # deploy to the panel (auto-detects your setup)
mi install             # full panel install (wraps tools/installer.sh)
mi css                 # regenerate root.css from components/styles
mi release [mmp]       # bump version from commits + write CHANGELOG.md
mi coverage            # app classes with no test coverage
mi smoke               # post-deploy health check against the panel
mi db:reset [--all]    # wipe records/history/catalog cache
mi watch               # rebuild automatically on file changes
mi version             # extension version + identifier from conf.yml
```

Flags accepted everywhere: `--help`, `--quiet` / `-q`, `--json`,
`--no-color` (or `NO_COLOR=1`), and `--dry-run` / `-n` on `build`, `release`
and `db:reset`. The ones you reach for most:

```bash
mi test -x -v              # stop at the first failure, full output
mi test --json             # CI-friendly summary (failures go to stderr)
mi test -l                 # just list the suites that would run
mi lint --only syntax      # one stage: syntax | blade | style | ts
mi check --fast            # lint + audit, skip the suites
mi css --check             # fail when root.css is stale (CI runs this)
mi coverage --min 80       # coverage gate
mi build -n                # show the deploy target, change nothing
mi smoke --url https://panel.example.com
```

`mi check` is exactly what the pre-push hook runs (`mi install-hooks`), and it
is what CI runs — so a passing local check means a passing pipeline.

Which deploy command you use depends on **why** you cloned the repo:

- **You just want to install the extension** — clone into `/var/www/pterodactyl` and run `mi install` (see [Installation](#installation) above). No Blueprint developer mode required.
- **You are developing the extension** — turn on Blueprint's developer mode **once** (**Admin → Extensions → Blueprint → set `developer` to `true`**), clear and clone the repo into `/var/www/pterodactyl/.blueprint/dev`, then `mi build` (regenerates css, syncs the install-time files and runs `blueprint -build`) or `mi watch` to rebuild on every change. Only this layout needs developer mode.
- **Any other checkout** (e.g. `/home/you/addon-manager`) — `mi build` deploys via the rsync pipeline (`tools/build.sh`) into the panel at `/var/www/pterodactyl`.

`root.css` is **generated** from `components/styles/*.css` — edit the modular files, never `root.css`. `mi css` or any `mi build` regenerates it.

### Optional: CurseForge API key

Modrinth works out of the box. To enable CurseForge, set `CURSEFORGE_API_KEY` in your panel's `.env`, then run `php artisan config:cache` and restart PHP-FPM — details in **[INSTALLATION.md](INSTALLATION.md)**.

## Usage

### For Panel Administrators

1. Log in to your Pterodactyl Panel
2. Navigate to **Admin Panel** → **Extensions** → **Addon Manager**
3. Configure optional settings (if needed)

### For Server Owners

1. Go to your server dashboard
2. Click the **Addon Manager** tab
3. **Browse** content from Modrinth or CurseForge
4. **Filter** by game version, loader, and category
5. Pick a version, choose any recommended dependencies, and click **Install**
6. Manage installed content from the **Installed** tab

## Configuration

### Environment Variables

| Variable                            | Required | Description                                                                         |
| ----------------------------------- | -------- | ----------------------------------------------------------------------------------- |
| `CURSEFORGE_API_KEY`                | No       | Your CurseForge API key for catalog access                                          |
| `MODPACK_INSTALLER_SERVER_TARGET`   | No       | Server target mode: `local` (default) or `wings`                                    |
| `MODPACK_INSTALLER_SERVER_ROOT`     | No       | Custom server root path (defaults to `/var/lib/pterodactyl/volumes`)                |
| `MODPACK_INSTALLER_MAX_DOWNLOAD_MB` | No       | Max download size in MB (no limit by default)                                       |
| `MODPACK_INSTALLER_DATA_DIR`        | No       | Install records storage path (defaults to `/var/lib/pterodactyl/modpack-installer`) |

## Contributing

See **[CONTRIBUTING.md](CONTRIBUTING.md)** for setup, the commit convention (it drives the version number), and the PR checklist. Run `mi check` (lint + all test suites) before pushing — CI runs the same.

Security issues: **[SECURITY.md](SECURITY.md)** — please report privately.

## Architecture

- **Backend** – PHP service layer with modular provider architecture
- **Frontend** – React + TypeScript with responsive Tailwind-based styling
- **Providers** – Pluggable provider system for Modrinth and CurseForge APIs
- **Storage** – Server-side file management with backup capabilities

## Supported Loaders

Mods and modpacks publish a loader, so their version window offers only the
combinations that exist upstream: **Fabric, Forge, Quilt, NeoForge, LiteLoader,
Cauldron, Rift** (CurseForge's set) plus whatever Modrinth lists for a project.

Plugins, data packs, resource packs and shaders on **CurseForge** are tagged with
Minecraft versions only — CurseForge does not record which server software a
plugin targets (Paper, Spigot, Purpur, Folia, Sponge, …), so those windows show
the Minecraft version dropdown alone rather than a filter that would return
nothing. On Modrinth the platform list comes from the project's own metadata.

## API Providers

### Modrinth ✓

- **Status**: Always available (no configuration needed)
- **Content**: Modpacks, mods, plugins, data packs, resource packs, shaders
- **Rate Limit**: 300 req/min

### CurseForge ✓

- **Status**: Optional (requires API key)
- **Content**: Modpacks, mods, plugins (Bukkit class), data packs, resource packs, shaders
- **Rate Limit**: Depends on API tier
- **Loaders**: recorded for mods and modpacks only, as above
- **Manual installs**: a project whose author disabled third-party downloads can be
  browsed, but the window says so instead of offering a download that cannot resolve

## Troubleshooting

### CurseForge shows "not available"

- Verify `CURSEFORGE_API_KEY` is set in `.env`
- Restart PHP-FPM: `systemctl restart php8.2-fpm`
- Check logs: `tail -f /var/log/php8.2-fpm.log`

### Installation fails

- Ensure sufficient disk space on the server
- Check server permissions: `ls -la /var/lib/pterodactyl/volumes/`
- Verify file write permissions for the panel user

### The catalog is empty

- Clear the filters (the provider's category list is per content type — picking a
  mod category while the Mods tab is on CurseForge is fine, but a plugin category
  is not offered for mods)
- Verify internet connectivity from the panel server and that the provider API is reachable
- For CurseForge, confirm the API key is set and the config cache was rebuilt

### A version window shows a single dropdown

That is correct for CurseForge plugins, data packs, resource packs and shaders:
CurseForge does not record a loader for those classes, so there is nothing to pick.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Author

**IndoGeek** – Creating tools for the Minecraft community.

## Support

For issues, questions, or suggestions:

- 🐛 [Report Issues](https://github.com/indogeek/addon-manager/issues)
- 💬 [Discussions](https://github.com/IndoGeek/addon-manager/discussions/1#discussion-10823816)
- 📧 Contact: tanumoy.maity12@gmail.com

---

Made for the community enjoy 😁
