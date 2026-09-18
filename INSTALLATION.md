# Installation Guide

![Addon Manager](assets/banner.webp)

This guide walks a normal user through installing the **Addon Manager**
extension onto a **Pterodactyl Panel** that already has the
[Blueprint](https://blueprint.zip) extension framework. The whole process is
designed to be a handful of commands, and the bundled installer (run via
`mi install`) handles the tricky parts — PHP extensions, PHP-FPM limits for
large downloads, server directory permissions, Blueprint, and the frontend
build — automatically.

> **You need `sudo` (root) access to the server that runs your Pterodactyl
> panel.** Every command below that changes the system must run as root.

---

## 1. Prerequisites

Your panel server must already have:

| Requirement                  | Notes                                                |
| ---------------------------- | ---------------------------------------------------- |
| **Pterodactyl Panel** 1.8+   | A working panel install at `/var/www/pterodactyl`    |
| **PHP** 8.1+ with PHP-FPM    | The version your panel uses (e.g. `php8.3-fpm`)      |
| **Node.js** 18+ and **yarn** | Needed by the panel to compile frontend assets       |
| **composer**                 | Ships with the panel                                 |
| **Internet access**          | From the panel server (to reach Modrinth/CurseForge) |

The installer checks for the missing system bits below and installs them
automatically, so you don't have to pre-install them:

- `curl`, `wget`, `unzip`, `zip`, `git`
- PHP **cURL** and **Zip** extensions (required by the extension)
- **Blueprint** framework (skipped if you already have it)

---

## 2. Clone this repository into your panel directory

The recommended location is directly inside your Pterodactyl directory:

```bash
cd /var/www/pterodactyl
sudo git clone https://github.com/indogeek/addon-manager.git
cd addon-manager
sudo ln -sf "$PWD/mi" /usr/local/bin/mi   # installs the mi developer CLI
```

If your panel lives somewhere else, clone into that directory instead — the
installer locates the panel automatically when the repo sits inside it.

---

## 3. Run the installer

```bash
cd /var/www/pterodactyl/addon-manager
sudo mi install
```

(Without the `mi` symlink: `sudo bash tools/installer.sh` — same thing.)

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
   web user can't reach the per-server folders and every install
   fails with `Server file target root does not exist or is not a directory.`
   The installer **detects the web user automatically** (from the panel's own
   file ownership, falling back to the PHP-FPM pool config) — `www-data` on
   Debian/Ubuntu, `nginx` or `apache` on RHEL-family installs. It installs
   the **acl** package only if `setfacl` is missing, then grants that user
   traverse rights on `/var/lib/pterodactyl` (and `volumes/`) plus read/write
   access to each server directory. Skipped
   entirely when `/var/lib/pterodactyl` doesn't exist (e.g. a panel using
   remote Wings nodes). Override the detection with
   `sudo MI_WEB_USER=<user> mi install` if your setup is unusual.
6. Installs the **Blueprint** framework if it is not already present.
7. Installs this extension with `blueprint -install` (from the local checkout,
   falling back to the GitHub release URL) and runs:
   `php artisan blueprint:publish`, `view:clear` and `config:cache`.
8. **Re-syncs your checkout over the installed copy and rebuilds the panel
   frontend.** This matters because Blueprint rewrites placeholder tokens in
   every file it packages and runs the panel's own build before the installer
   can correct anything; the sync is what makes a first install end up
   identical to a dev-tree build. It is the same pipeline `mi build` runs.
9. **Verifies the panel still has a frontend bundle.** The panel's build
   deletes every `public/assets/*.js` before it compiles, so a single compile
   error would otherwise leave the whole UI blank. The installer keeps a copy
   of the working assets before installing and puts them back if the rebuild
   produces no bundle, instead of reporting success over a blank panel.
   Skipped when the caller runs its own build (`mi build` installs first, then syncs).
10. Fixes file ownership so the panel's detected web user can read the built assets.

It draws the same live progress bar as `mi build` — one line per step while it
runs, with warnings collected into a single block at the end instead of
scrolling past. Press `w` during a run to expand the warnings so far.

The script is **safe to re-run** — every step is idempotent. To force a
reinstall of the extension use:

```bash
MI_FORCE=1 sudo mi install
```

---

## 4. Verify the installation

1. Open your panel in a browser.
2. Go to **Admin → Extensions** and confirm **Addon Manager** is listed and
   enabled.
3. Open any server → the **Addon Manager** tab should now appear so you can
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
# ^ On RHEL-family panels replace www-data with the FPM user (nginx/apache);
#   check with: stat -c '%U:%G' /var/www/pterodactyl/storage
sudo chmod +x blueprint.sh
sudo bash blueprint.sh

# 4. Server directory access (only if /var/lib/pterodactyl exists and issues
#    like "Server file target root does not exist" appear). Replace www-data
#    below with your panel's web user if it differs (RHEL: nginx/apache) —
#    detect it with: stat -c '%U:%G' /var/www/pterodactyl/storage
sudo apt install -y acl                        # or: sudo dnf/yum install -y acl
sudo setfacl -m u:www-data:--x /var/lib/pterodactyl
sudo setfacl -m u:www-data:--x /var/lib/pterodactyl/volumes
sudo setfacl -m d:u:www-data:rwx /var/lib/pterodactyl/volumes
for d in /var/lib/pterodactyl/volumes/*/; do
  [ -d "$d" ] && sudo setfacl -m u:www-data:rwx "$d" && sudo setfacl -m d:u:www-data:rwx "$d"
done

# 5. Install this extension (from the clone or the GitHub URL)
sudo /usr/local/bin/blueprint -install /var/www/pterodactyl/addon-manager

# 6. Publish assets
cd /var/www/pterodactyl
sudo php artisan blueprint:publish
sudo php artisan view:clear
sudo php artisan config:cache
sudo chown -R www-data:www-data .blueprint public bootstrap/cache storage
# ^ use your panel's web user if it is not www-data (see step 4)
```

---

## Troubleshooting

| Symptom                                                         | Likely cause / fix                                                                                                                                                                                         |
| --------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Large modpack fails with `Unable to install the modpack.`       | Panel PHP-FPM `max_execution_time` (default 30 s) killed the download. The installer sets `3600`; verify with `grep max_execution_time /etc/php/*/fpm/pool.d/*.conf` and restart PHP-FPM.                  |
| `Class "ZipArchive" not found`                                  | PHP **zip** extension missing → `sudo apt install php<ver>-zip`.                                                                                                                                           |
| `curl` errors while installing                                  | PHP **curl** extension missing → `sudo apt install php<ver>-curl`.                                                                                                                                         || `blueprint: command not found` | Blueprint isn't installed → run step 3 above or `sudo mi install`. |
| `Server file target root does not exist or is not a directory.` | The panel web user can't reach the server data dirs. Run `sudo mi install` (it grants ACL access) or follow manual step 4. Verify with: `sudo -u "$(stat -c %U /var/www/pterodactyl/storage)" test -d /var/lib/pterodactyl && echo ok`. |
| Panel shows a white screen after install                        | The panel's frontend build wiped `public/assets/*.js` and then failed to compile. Re-run `sudo mi build` from your checkout (or `cd /var/www/pterodactyl && yarn run build:production`) — see *White / blank panel page* below. `mi smoke` reports whether the bundle is present. |
| Install succeeds but no mods on the server                      | You installed before this fix or the mrpack has no server files — re-run the installer after updating to the latest version.                                                                               |
| Install reports success but server files are missing            | Make sure the extension code is up to date (this repo's `ModrinthProvider` resolves `modrinth.index.json` mod files).                                                                                      |

### White / blank panel page

The panel's frontend build deletes every JS asset in `public/assets` (`yarn run
clean`) **before** webpack compiles. If compilation fails, there is no bundle
left to serve, so the *entire* panel — not just this extension — renders blank.
The HTML still answers `200`, which is why it can go unnoticed.

```bash
cd /var/www/pterodactyl
ls public/assets/*.js        # no output = the bundle is gone
yarn run build:production    # rebuilds it; read the error if it fails again
```

Running `sudo mi build` from your checkout does the same thing and additionally
re-syncs the extension sources first, so prefer that when the failure came from
an install. `sudo mi smoke` tells you whether a bundle is present, and
`sudo mi remove` reports the same when uninstalling.

A common cause on a fresh install is a **Blueprint placeholder collision**:
Blueprint rewrites literal tokens such as `{version}`, `{name}`, `{target}` and
`{root}` in every file it packages, so a variable named `version` used as JSX
(`key={version}`) is replaced by the extension version and the file stops
compiling. `mi check` scans the repository for those tokens, and the installer
re-syncs the sources afterwards, so both the cause and the damage are covered.

Find the panel's PHP-FPM service name with:

```bash
ls /etc/php/*/fpm/pool.d/
systemctl list-unit-files | grep fpm
```

---

## Updating

```bash
cd /var/www/pterodactyl/addon-manager
sudo git pull
sudo mi install               # reinstalls the updated extension
```

---

## Uninstalling

Removes the extension from the panel so you can leave it out entirely or build
it again from scratch:

```bash
cd /var/www/pterodactyl/addon-manager   # or .blueprint/dev for a dev tree
sudo mi remove                          # asks for confirmation first
sudo mi remove -n                       # dry run: show what would go
sudo mi remove --yes                    # no prompt (scripts, CI)
```

What it removes:

- the installed extension (via `blueprint -remove`) — its files, admin page,
  controller, client routes, styles and published assets,
- the leftover copies a build writes outside the extension folder
  (`config/modpackinstaller.php`, `public/assets/extensions/modpackinstaller`, …),
- the panel's frontend bundle is rebuilt without the extension, and the view,
  config and cache stores are flushed as part of that.

Afterwards `mi remove` checks the panel still has a frontend bundle and tells
you how to rebuild it if not, so a break in that build can never leave you
staring at a blank panel with no next step.

A clone under `.blueprint/dev` is **kept** by default — a developer's working
tree can live there and deleting one unasked would be destructive. `mi remove`
warns when it is still present; pass `--dev-tree` to drop it when you want a
completely clean panel before installing again:

```bash
sudo mi remove --dev-tree
```

What it deliberately **keeps**: system tools, PHP extensions (curl/zip), the
PHP-FPM limits, **Blueprint** itself, this checkout, and the global `mi`
command. Mods, plugins, worlds and packs already installed on a game server are never
touched, and neither are the extension's install records — they live outside
the panel in `/var/lib/pterodactyl/modpack-installer`, so a rebuild brings your
Installed list straight back. Add `--dev-tree` to also drop
`/var/www/pterodactyl/.blueprint/dev`, which is kept by default because a dev
clone may live there.

Afterwards, `sudo mi install` (or `mi build` in a dev tree) restores everything.

---

## Developing the extension

Everything above installs and updates the extension from a clone at
`/var/www/pterodactyl/addon-manager` — that is all a panel owner ever needs.

Only **extension developers** changing the code need Blueprint's developer tree:

1. Turn on Blueprint's developer mode **once**: **Admin → Extensions → Blueprint
   → set `developer` to `true`** (verify with `blueprint -info` →
   `Developer: true`).
2. Clear the dev directory and clone the repo there:
   ```bash
   cd /var/www/pterodactyl
   sudo rm -rf .blueprint/dev/* .blueprint/dev/.gitkeep   # or: sudo blueprint -wipe
   sudo git clone https://github.com/indogeek/addon-manager.git .blueprint/dev
   ```
3. Deploy from inside the clone. `mi build` regenerates `root.css`, syncs the
   install-time files and runs `blueprint -build` for you; `mi watch` rebuilds on
   every change:
   ```bash
   cd /var/www/pterodactyl/.blueprint/dev
   sudo ln -sf "$PWD/mi" /usr/local/bin/mi   # one-time, if not already installed
   mi build
   ```

See **[CONTRIBUTING.md](CONTRIBUTING.md)** for the full developer workflow.

---

Made for the community enjoy 😁
