[![Minecraft](https://img.shields.io/badge/Minecraft-1.16.5+-62B47A?style=for-the-badge&logo=data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48cmVjdCB3aWR0aD0iMTAwIiBoZWlnaHQ9IjEwMCIgZmlsbD0iIzYyQjQ3QSIvPjwvc3ZnPg==)](https://www.minecraft.net)
[![Downloads](https://img.shields.io/github/downloads/indogeek/modpack-installer/total?style=for-the-badge&logo=github&color=1f6feb)](https://github.com/indogeek/modpack-installer/releases)
[![License](https://img.shields.io/badge/License-MIT-blue?style=for-the-badge)](LICENSE)
[![CurseForge](https://img.shields.io/badge/CurseForge-Supported-orange?style=for-the-badge&logo=curseforge)](https://www.curseforge.com)
[![Modrinth](https://img.shields.io/badge/Modrinth-Supported-green?style=for-the-badge&logo=modrinth)](https://modrinth.com)

# Modpack Installer

A powerful Pterodactyl Panel extension for browsing and installing Minecraft modpacks directly from **Modrinth** and **CurseForge** with a modern, user-friendly interface.

## Features

- 🎮 **Multi-Provider Support** – Browse and install from both Modrinth and CurseForge
- 🔍 **Advanced Filtering** – Search by game version, loader, category, and more
- 📦 **One-Click Installation** – Seamlessly install modpacks to your server
- 🎨 **Modern UI** – Clean, responsive interface built with React and TypeScript
- ⚙️ **Server Management** – View, update, and uninstall installed modpacks
- 🔐 **Secure** – API keys stored server-side, never exposed to clients

## Requirements

- **Pterodactyl Panel** v1.8+
- **Blueprint** (Pterodactyl extension framework)
- **PHP** 8.1+
- **Node.js** 18+ (for development/building)

## Installation

### 1. Install Blueprint (if not already installed)

Follow the [Blueprint installation guide](https://blueprint.pterodactyl.io).

### 2. Install the Modpack Installer Extension

```bash
cd /var/www/pterodactyl
blueprint -install https://github.com/indogeek/modpack-installer
```

### 3. Publish Assets

```bash
php artisan blueprint:publish
php artisan view:clear
```

### 4. Configure API Keys (Optional)

The extension works out-of-the-box with Modrinth. To enable CurseForge support, you'll need a CurseForge API key:

1. **Get Your API Key**
   - Visit [CurseForge Developer Portal](https://console.curseforge.com)
   - Create a new application
   - Copy your API key

2. **Set the Environment Variable**
   
   Add to your `.env` file:
   ```
   CURSEFORGE_API_KEY=your_api_key_here
   ```

3. **Restart Your Panel**
   ```bash
   systemctl restart php8.2-fpm  # or your PHP version
   ```

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

| Variable | Required | Description |
|----------|----------|-------------|
| `CURSEFORGE_API_KEY` | No | Your CurseForge API key for catalog access |
| `MODPACK_INSTALLER_SERVER_TARGET` | No | Server target mode: `local` (default) or `wings` |
| `MODPACK_INSTALLER_SERVER_ROOT` | No | Custom server root path (defaults to `/var/lib/pterodactyl/volumes`) |
| `MODPACK_INSTALLER_MAX_DOWNLOAD_MB` | No | Max download size in MB (no limit by default) |
| `MODPACK_INSTALLER_DATA_DIR` | No | Install records storage path (defaults to `/var/lib/pterodactyl/modpack-installer`) |

## Development

### Build CSS

```bash
node tools/build-css.mjs
```

### Deploy Changes

```bash
./build.sh
```

### Run Tests

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
- 💬 [Discussions](https://github.com/indogeek/modpack-installer/discussions)
- 📧 Contact: support@indogeek.dev

---

Made with ❤️ for Pterodactyl Panel
