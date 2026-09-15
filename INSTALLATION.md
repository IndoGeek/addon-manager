# Installation Guide

This guide walks a normal user through installing the **Modpack Installer**
extension onto a **Pterodactyl Panel** that already has the
[Blueprint](https://blueprint.zip) extension framework. The whole process is
designed to be a handful of commands, and our `installer.sh` script handles the
tricky parts — PHP extensions, PHP-FPM limits for large downloads, server
directory permissions, Blueprint, and the frontend build — automatically.

> **You need `sudo` (root) access to the server that runs your Pterodactyl
> panel.** Every command below that changes the system must run as root.

---

## 1. Prerequisites

Your panel server must already have:

| Requirement | Notes |
|---|---|
| **Pterodactyl Panel** 1.8+ | A working panel install at `/var/www/pterodactyl` |
| **PHP** 8.1+ with PHP-FPM | The version your panel uses (e.g. `php8.3-fpm`) |
| **Node.js** 18+ and **yarn** | Needed by the panel to compile frontend assets |
| **composer** | Ships with the panel |
| **Internet access** | From the panel server (to reach Modrinth/CurseForge) |

The installer checks for the missing system bits below and installs them
automatically, so you don't have to pre-install them:

- `curl`, `wget`, `unzip`, `zip`, `git`
- PHP **cURL** and **Zip** extensions (required by the extension)
- **Blueprint** framework (skipped if you already have it)

---

## 2. Clone this repository into your panel directory

The recommended location is directly inside your Pterodactyl directory, so the
final layout is:

```
/var/www/pterodactyl/                       <- your panel
└── modpack-installer/                      <- this repository
    └── installer.sh                        <- the installer script
```

```bash
cd /var/www/pterodactyl
sudo git clone https://github.com/indogeek/modpack-installer.git
```

If your panel lives somewhere else, clone into that directory instead — the
installer locates the panel automatically when the repo sits inside it.

---

## 3. Run the installer

```bash
cd /var/www/pterodactyl/modpack-installer
sudo bash installer.sh
```

`sudo` is required because the script changes system configuration.

### What the installer does

1. Checks it is running with root/sudo and locates your panel.
2. Installs any missing system tools.
3. Installs the PHP **curl** and **zip** extensions for every installed PHP
   version — without these, the extension can't `curl`-download modpacks or
   read `.zip`/`.mrpack` archives.
4. **Raises PHP-FPM limits** so large modpacks install reliably:
   - `memory_limit` → `512M`
   - `max_execution_time` → `3600` (1 hour)
   - `upload_max_filesize` → `5120M`
   - `post_max_size` → `5120M`

   These overrides are added to every PHP-FPM pool on the machine and the
   service is restarted. This is the fix for modpacks that fail partway through
   (e.g. `Unable to install the modpack.` past ~70%): the panel's default
   `max_execution_time = 30` was killing the download. The installer sets the
   pool (not just `php.ini`) values so nothing else can silently cap them.

5. **Grants the panel web user access to the server data directories.** Some
   panels lock `/var/lib/pterodactyl` down with ACLs (`other::---`), so the
   web user (`www-data`) can't reach the per-server folders and every install
   fails with `Server file target root does not exist or is not a directory.`
   The installer installs the **acl** package only if `setfacl` is missing,
   then grants `www-data` traverse rights on `/var/lib/pterodactyl` (and
   `volumes/`) plus read/write access to each server directory. Skipped
   entirely when `/var/lib/pterodactyl` doesn't exist (e.g. a panel using
   remote Wings nodes).
6. Installs the **Blueprint** framework if it is not already present.
7. Installs this extension with `blueprint -install` (from the local checkout,
   falling back to the GitHub release URL) and runs:
   `php artisan blueprint:publish`, `view:clear` and `config:cache`.
8. Fixes file ownership so the panel's web user can read the built assets.

The script is **safe to re-run** — every step is idempotent. To force a
reinstall of the extension use:

```bash
MI_FORCE=1 sudo bash installer.sh
```

---

## 4. Verify the installation

1. Open your panel in a browser.
2. Go to **Admin → Extensions** and confirm **Modpack Installer** is listed and
   enabled.
3. Open any server → the **Modpack Installer** tab should now appear so you can
   browse Modrinth/CurseForge and install a pack.

---

## 5. Optional: enable CurseForge (API key)

Modrinth works out of the box. CurseForge needs an API key:

```bash
sudo tee -a /var/www/pterodactyl/.env <<'EOF'
CURSEFORGE_API_KEY=your_api_key_here
EOF
cd /var/www/pterodactyl
sudo php artisan config:cache
sudo systemctl restart php8.3-fpm        # or your panel's PHP-FPM version
```

---

## Manual installation (without the script)

If you prefer to run the steps by hand (or need to debug), here is the exact
sequence the installer performs:

```bash
# 1. System tools + PHP extensions (adjust PHP version to yours)
sudo apt update
sudo apt install -y curl wget unzip zip git php8.3-curl php8.3-zip

# 2. PHP-FPM pool limits (fixes large-download timeouts)
echo "php_admin_value[memory_limit] = 512M"       | sudo tee -a /etc/php/8.3/fpm/pool.d/www.conf
echo "php_admin_value[max_execution_time] = 3600" | sudo tee -a /etc/php/8.3/fpm/pool.d/www.conf
echo "php_admin_value[upload_max_filesize] = 5120M" | sudo tee -a /etc/php/8.3/fpm/pool.d/www.conf
echo "php_admin_value[post_max_size] = 5120M"     | sudo tee -a /etc/php/8.3/fpm/pool.d/www.conf
sudo systemctl restart php8.3-fpm

# 3. Blueprint (skip if `blueprint -version` works)
cd /var/www/pterodactyl
sudo wget https://github.com/BlueprintFramework/framework/releases/latest/download/release.zip -O release.zip
sudo unzip -o release.zip
echo 'WEBUSER="www-data";
OWNERSHIP="www-data:www-data";
USERSHELL="/bin/bash";' | sudo tee .blueprintrc >/dev/null
sudo chmod +x blueprint.sh
sudo bash blueprint.sh

# 4. Server directory access (only if /var/lib/pterodactyl exists and issues
#    like "Server file target root does not exist" appear)
sudo apt install -y acl                        # or: sudo dnf/yum install -y acl
sudo setfacl -m u:www-data:--x /var/lib/pterodactyl
sudo setfacl -m u:www-data:--x /var/lib/pterodactyl/volumes
sudo setfacl -m d:u:www-data:rwx /var/lib/pterodactyl/volumes
for d in /var/lib/pterodactyl/volumes/*/; do
  [ -d "$d" ] && sudo setfacl -m u:www-data:rwx "$d" && sudo setfacl -m d:u:www-data:rwx "$d"
done

# 5. Install this extension (from the clone or the GitHub URL)
sudo /usr/local/bin/blueprint -install /var/www/pterodactyl/modpack-installer

# 6. Publish assets
cd /var/www/pterodactyl
sudo php artisan blueprint:publish
sudo php artisan view:clear
sudo php artisan config:cache
sudo chown -R www-data:www-data .blueprint public bootstrap/cache storage
```

---

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Large modpack fails with `Unable to install the modpack.` | Panel PHP-FPM `max_execution_time` (default 30 s) killed the download. The installer sets `3600`; verify with `grep max_execution_time /etc/php/*/fpm/pool.d/*.conf` and restart PHP-FPM. |
| `Class "ZipArchive" not found` | PHP **zip** extension missing → `sudo apt install php<ver>-zip`. |
| `curl` errors while installing | PHP **curl** extension missing → `sudo apt install php<ver>-curl`. |
| `blueprint: command not found` | Blueprint isn't installed → run step 3 above or `sudo bash installer.sh`. |
| `Server file target root does not exist or is not a directory.` | The panel web user can't reach the server data dirs. Run `sudo bash installer.sh` (it grants ACL access) or follow manual step 4. Verify with: `sudo -u www-data test -d /var/lib/pterodactyl && echo ok`. |
| Panel shows a white screen after install | Frontend wasn't rebuilt/cleared → `blueprint -r` in the panel dir, then `php artisan blueprint:publish && php artisan view:clear`. |
| Install succeeds but no mods on the server | You installed before this fix or the mrpack has no server files — re-run the installer after updating to the latest version. |
| Install reports success but server files are missing | Make sure the extension code is up to date (this repo's `ModrinthProvider` resolves `modrinth.index.json` mod files). |

Find the panel's PHP-FPM service name with:

```bash
ls /etc/php/*/fpm/pool.d/
systemctl list-unit-files | grep fpm
```

---

## Updating

```bash
cd /var/www/pterodactyl/modpack-installer
sudo git pull
sudo bash installer.sh        # reinstalls the updated extension
```

---

Made with ❤️ for Pterodactyl Panel.