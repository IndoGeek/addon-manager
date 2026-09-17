<div align="center">

[![Pterodactyl 1.8+](https://img.shields.io/badge/pterodactyl-1.8%2B-1059D6?logo=pterodactyl&logoColor=white)](https://pterodactyl.io)
[![Modrinth](https://img.shields.io/badge/modrinth-available-00AF5C?logo=modrinth&logoColor=white)](https://modrinth.com)
[![CurseForge](https://img.shields.io/badge/curseforge-supported-F16436?logo=curseforge&logoColor=white)](https://www.curseforge.com)
[![GitHub Releases](https://img.shields.io/badge/github-releases-007EC6?logo=github&logoColor=white)](https://github.com/indogeek/modpack-installer/releases)
[![GitHub Downloads](https://img.shields.io/github/downloads/indogeek/modpack-installer/total?logo=github&logoColor=white)](https://github.com/indogeek/modpack-installer/releases)
[![GitHub Forks](https://img.shields.io/github/forks/indogeek/modpack-installer?logo=github&logoColor=white)](https://github.com/indogeek/modpack-installer/network/members)
[![GitHub Stars](https://img.shields.io/github/stars/indogeek/modpack-installer?logo=github&logoColor=white)](https://github.com/indogeek/modpack-installer/stargazers)
[![MIT License](https://img.shields.io/badge/license-MIT-blue?logo=github&logoColor=white)](LICENSE)

</div>

# Modpack Installer

A powerful Pterodactyl Panel extension for browsing and installing Minecraft modpacks directly from **Modrinth** and **CurseForge** with a modern, user-friendly interface.

## Features

- **Multi-Provider Support** – Browse and install from both Modrinth and CurseForge
- **Advanced Filtering** – Search by game version, loader, category, and more
- **One-Click Installation** – Seamlessly install modpacks to your server
- **Modern UI** – Clean, responsive interface built with React and TypeScript
- **Server Management** – View, update, and uninstall installed modpacks
- **Secure** – API keys stored server-side, never exposed to clients

## Requirements

- **Pterodactyl Panel** v1.8+
- **Blueprint** (Pterodactyl extension framework)
- **PHP** 8.1+
- **Node.js** 18+ (used by Blueprint to compile frontend assets during install)

## Installation

> **You need `sudo` (root) access on the server running your Pterodactyl panel.**
>
> Full, step-by-step installation instructions are in **[INSTALLATION.md](INSTALLATION.md)** — this includes prerequisites, what the installer configures automatically, manual fallback commands, and troubleshooting.

### Quick start (recommended)

Clone this repository **into your Pterodactyl directory** and run the one-command installer:

```bash
cd /var/www/pterodactyl
sudo git clone https://github.com/indogeek/modpack-installer.git
cd /var/www/pterodactyl/modpack-installer
sudo bash installer.sh
```

This installs the extension at `/var/www/pterodactyl/modpack-installer/installer.sh`. It:

- installs missing system tools and the PHP **curl**/**:zip** extensions,
- raises your PHP-FPM limits (`memory_limit 512M`, `max_execution_time 3600`, `upload_max_filesize`/`post_max_size 5120M`) so large modpacks (~400 MB+) don't time out mid-download,
- installs **Blueprint** if you don't have it yet,
- installs this extension with `blueprint -install` and publishes the panel assets.

### Optional: CurseForge API key

Modrinth works out of the box. To enable CurseForge, set `CURSEFORGE_API_KEY` in your panel's `.env`, then run `php artisan config:cache` and restart PHP-FPM — details in **[INSTALLATION.md](INSTALLATION.md)**.

## Usage

### For Panel Administrators

1. Log in to your Pterodactyl Panel
2. Navigate to **Admin Panel** → **Extensions** → **Modpack Installer**
3. Configure optional settings (if needed)

### For Server Owners

1. Go to your server dashboard
2. Click the **Modpack Installer** tab
3. **Browse** modpacks from Modrinth or CurseForge
4. **Filter** by game version, loader, and category
5. Click **Install** to deploy a modpack to your server
6. Manage installed modpacks from the **Installed** tab

## Configuration

### Environment Variables

| Variable                            | Required | Description                                                                         |
| ----------------------------------- | -------- | ----------------------------------------------------------------------------------- |
| `CURSEFORGE_API_KEY`                | No       | Your CurseForge API key for catalog access                                          |
| `MODPACK_INSTALLER_SERVER_TARGET`   | No       | Server target mode: `local` (default) or `wings`                                    |
| `MODPACK_INSTALLER_SERVER_ROOT`     | No       | Custom server root path (defaults to `/var/lib/pterodactyl/volumes`)                |
| `MODPACK_INSTALLER_MAX_DOWNLOAD_MB` | No       | Max download size in MB (no limit by default)                                       |
| `MODPACK_INSTALLER_DATA_DIR`        | No       | Install records storage path (defaults to `/var/lib/pterodactyl/modpack-installer`) |

## Development

Like every Blueprint extension, **Modpack Installer is built and installed from inside your Pterodactyl directory** — run `blueprint -install` as described in **[INSTALLATION.md](INSTALLATION.md)**. No separate build/`resync` step or private `build.sh` script is required.

Contributors can run the extension's manual tests:

```bash
php tests/manual/catalog-service-test.php
php tests/manual/installation-orchestrator-test.php
```

## Architecture

- **Backend** – PHP service layer with modular provider architecture
- **Frontend** – React + TypeScript with responsive Tailwind-based styling
- **Providers** – Pluggable provider system for Modrinth and CurseForge APIs
- **Storage** – Server-side file management with backup capabilities

## Supported Mod Loaders

- Fabric
- Forge
- Quilt
- NeoForge
- LiteLoader
- Cauldron

## API Providers

### Modrinth ✓

- **Status**: Always available (no configuration needed)
- **Features**: Search, filtering, versioning
- **Rate Limit**: 300 req/min

### CurseForge ✓

- **Status**: Optional (requires API key)
- **Features**: Search, filtering, versioning
- **Rate Limit**: Depends on API tier

## Troubleshooting

### CurseForge shows "not available"

- Verify `CURSEFORGE_API_KEY` is set in `.env`
- Restart PHP-FPM: `systemctl restart php8.2-fpm`
- Check logs: `tail -f /var/log/php8.2-fpm.log`

### Installation fails

- Ensure sufficient disk space on the server
- Check server permissions: `ls -la /var/lib/pterodactyl/volumes/`
- Verify file write permissions for the panel user

### Modpacks not showing

- Try clearing browser cache
- Verify internet connectivity on panel server
- Check if provider APIs are reachable

## Contributing

Contributions are welcome! Please feel free to submit issues and pull requests.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Author

**IndoGeek** – Creating tools for the Minecraft community.

## Support

For issues, questions, or suggestions:

- 🐛 [Report Issues](https://github.com/indogeek/modpack-installer/issues)
- 💬 [Discussions](https://github.com/IndoGeek/modpack-installer/discussions/1#discussion-10823816)
- 📧 Contact: tanumoy.maity12@gmail.com

---

Made to help the Community..
