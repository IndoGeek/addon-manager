#!/usr/bin/env bash
#
# Addon Manager — one-command installer for the Pterodactyl Blueprint
# extension.
#
# Run from a checkout of this repository (recommended location):
#
#   cd /var/www/pterodactyl
#   sudo git clone https://github.com/indogeek/addon-manager.git
#   cd /var/www/pterodactyl/addon-manager
#   sudo mi install
#
# What it does, in order:
#   1. Checks for root/sudo and installable package manager support.
#   2. Locates your Pterodactyl panel directory.
#   3. Installs missing system tools (curl, wget, unzip, zip, git).
#   4. Installs the PHP cURL and Zip extensions for every installed PHP
#      version (ZipArchive and cURL are required by the extension).
#   5. Raises PHP-FPM pool limits (memory_limit, max_execution_time,
#      upload_max_filesize, post_max_size) and the CLI memory limit so large
#      modpacks (~400 MB+) can be downloaded without being killed by the
#      panel's PHP defaults. Restarts PHP-FPM.
#   6. Checks that the panel's web user can reach /var/lib/pterodactyl (and
#      the server data under volumes/) and grants ACL access when it cannot,
#      installing the acl package only if setfacl is missing. Required for
#      "local" target mode installs.
#   7. Installs the Blueprint extension framework if it is not present.
#   8. Installs this Addon Manager extension with `blueprint -install`
#      and publishes the panel assets.
#   9. Fixes file ownership so the panel's web user can read everything.
#
# The script is idempotent: running it again is safe. Install a fresh copy
# over an existing install by running:  MI_FORCE=1 sudo mi install
#
# Author: IndoGeek
# License: MIT

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
GITHUB_URL="https://github.com/indogeek/addon-manager"
DEFAULT_PANEL="/var/www/pterodactyl"
WEB_USER=""
WEB_GROUP=""
# Detected in detect_web_user() (after detect_panel) — never assume www-data:
# RHEL-family nginx runs as "nginx", RHEL Apache as "apache", and custom FPM
# pools can use anything. Override with MI_WEB_USER / MI_WEB_GROUP.

# Root that contains per-version PHP config directories (e.g. "/etc/php/8.3").
# Overridable so non-Debian layouts and tests can point elsewhere.
PHP_ETC_DIR="${PHP_ETC_DIR:-/etc/php}"

# Root Pterodactyl data directory whose server folders the panel web user needs
# to reach in "local" target mode. Overridable for testing/custom layouts.
PTERODACTYL_DATA_DIR="${PTERODACTYL_DATA_DIR:-/var/lib/pterodactyl}"

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------
info()  { printf '\033[0;34m[..]\033[0m %s\n' "$*"; }
ok()    { printf '\033[0;32m[ok]\033[0m %s\n' "$*"; }
warn()  { printf '\033[0;33m[!!]\033[0m %s\n' "$*"; }
die()   { printf '\033[0;31m[!!]\033[0m %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Web user detection
# ---------------------------------------------------------------------------
# The panel web user is whoever PHP-FPM actually runs as. We must NOT assume
# www-data: guessing wrong makes the installer chown panel files away from
# the real web user (breaking the panel) or grant ACLs to a user that will
# never touch them (installs fail with "Server file target root does not
# exist").
detect_web_user() {
  # 1. Explicit override wins.
  if [ -n "${MI_WEB_USER:-}" ]; then
    WEB_USER="$MI_WEB_USER"
    WEB_GROUP="${MI_WEB_GROUP:-$MI_WEB_USER}"
    ok "Web user overridden: ${WEB_USER}:${WEB_GROUP}"
    return 0
  fi

  # 2. Ask the panel: whoever owns the panel's storage dir demonstrably
  #    serves it — the most reliable source on every distro and pool layout.
  local ug
  ug="$(stat -c '%U:%G' "$PANEL/storage" 2>/dev/null || true)"
  if [ -n "$ug" ] && [ "${ug%%:*}" != "root" ]; then
    WEB_USER="${ug%%:*}"
    WEB_GROUP="${ug#*:}"
    ok "Detected panel web user: ${WEB_USER}:${WEB_GROUP} (from panel ownership)"
    return 0
  fi

  # 3. Fall back to the FPM pool config (Debian-style pools, then RHEL-style).
  local fconf pool_user pool_group
  for fconf in "$PHP_ETC_DIR"/*/fpm/pool.d/*.conf /etc/php-fpm.d/*.conf; do
    [ -f "$fconf" ] || continue
    pool_user="$(sed -n 's/^[[:space:]]*user[[:space:]]*=[[:space:]]*//p' "$fconf" | head -1 | tr -d '[:space:]')"
    pool_group="$(sed -n 's/^[[:space:]]*group[[:space:]]*=[[:space:]]*//p' "$fconf" | head -1 | tr -d '[:space:]')"
    if [ -n "$pool_user" ] && [ "$pool_user" != "root" ] && id "$pool_user" >/dev/null 2>&1; then
      WEB_USER="$pool_user"
      WEB_GROUP="${pool_group:-$pool_user}"
      ok "Detected FPM pool user: ${WEB_USER}:${WEB_GROUP} (from $fconf)"
      return 0
    fi
  done

  # 4. Last resort.
  WEB_USER="www-data"
  WEB_GROUP="www-data"
  warn "Could not detect the panel web user; defaulting to ${WEB_USER}."
  warn "If PHP-FPM runs as another user, re-run with:  sudo MI_WEB_USER=<user> mi install"
}

# ---------------------------------------------------------------------------
# Package manager detection
# ---------------------------------------------------------------------------
PKG_MANAGER=""
APT=0

detect_package_manager() {
  if command -v apt-get >/dev/null 2>&1; then
    PKG_MANAGER=apt
    APT=1
  elif command -v dnf >/dev/null 2>&1; then
    PKG_MANAGER=dnf
  elif command -v yum >/dev/null 2>&1; then
    PKG_MANAGER=yum
  else
    die "No supported package manager found (apt-get, dnf or yum)."
  fi
}

install_packages() {
  local names=("$@")
  if [ "${#names[@]}" -eq 0 ]; then
    return 0
  fi

  case "$PKG_MANAGER" in
    apt)
      apt-get update -qq >/dev/null
      apt-get install -y --no-install-recommends "${names[@]}" >/dev/null
      ;;
    dnf)
      dnf install -y "${names[@]}" >/dev/null
      ;;
    yum)
      yum install -y "${names[@]}" >/dev/null
      ;;
  esac
}

# ---------------------------------------------------------------------------
# Panel directory detection
# ---------------------------------------------------------------------------
PANEL=""

detect_panel() {
  # When the repo sits directly inside a panel, the parent directory is it.
  local parent
  parent="$(dirname -- "$SCRIPT_DIR")"
  if [ -f "$parent/artisan" ] && [ -f "$parent/blueprint.sh" ]; then
    PANEL="$parent"
    return 0
  fi

  if [ -d "${PANEL_DIR:-$DEFAULT_PANEL}" ] && [ -f "${PANEL_DIR:-$DEFAULT_PANEL}/artisan" ]; then
    PANEL="${PANEL_DIR:-$DEFAULT_PANEL}"
    return 0
  fi

  if [ -f "$DEFAULT_PANEL/artisan" ]; then
    PANEL="$DEFAULT_PANEL"
    return 0
  fi

  die "Could not find your Pterodactyl panel. Set it explicitly with:  PANEL_DIR=/path/to/panel sudo mi install"
}

# ---------------------------------------------------------------------------
# Installed PHP versions
# ---------------------------------------------------------------------------
php_versions() {
  if [ -d "$PHP_ETC_DIR" ]; then
    find "$PHP_ETC_DIR" -maxdepth 1 -mindepth 1 -type d -printf '%f\n' \
      2>/dev/null | sort -V
    return 0
  fi

  # Non-Debian layout: fall back to the version of the default PHP binary.
  local version
  version="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || true)"
  [ -n "$version" ] && printf '%s\n' "$version"
  return 0
}

# Checks whether an extension is loaded by a specific FPM ini (best effort).
php_has_extension() {
  local version="$1"
  local extension="$2"
  local ini="$PHP_ETC_DIR/$version/fpm/php.ini"
  local binary="/usr/bin/php$version"

  [ -x "$binary" ] || return 1
  [ -f "$ini" ] || return 1

  "$binary" -c "$ini" -m 2>/dev/null | grep -qix "$extension"
}

# ---------------------------------------------------------------------------
# System tools + PHP extensions
# ---------------------------------------------------------------------------
ensure_system_tools() {
  local missing=()
  for tool in curl wget unzip zip git; do
    command -v "$tool" >/dev/null 2>&1 || missing+=("$tool")
  done

  if [ "${#missing[@]}" -gt 0 ]; then
    info "Installing missing system tools: ${missing[*]}"
    install_packages "${missing[@]}"
    ok "System tools installed: ${missing[*]}"
  fi
}

ensure_php_extensions() {
  local versions
  versions="$(php_versions)"
  [ -n "$versions" ] || return 0

  local version extension apt_packages=() listed
  for version in $versions; do
    case "$PKG_MANAGER" in
      apt) apt_packages+=("php$version-curl" "php$version-zip") ;;
      dnf|yum) apt_packages=("php-curl" "php-zip"); listed=1 ;;
    esac
  done

  # Install the packages first; this is what actually enables the modules.
  if [ "${#apt_packages[@]}" -gt 0 ] && [ -z "${listed:-}" ]; then
    info "Installing PHP curl and zip extensions (${apt_packages[*]})"
    install_packages "${apt_packages[@]}" || warn "Could not install all PHP extension packages; will continue."
  elif [ -n "${listed:-}" ]; then
    info "Installing PHP curl and zip extensions (${apt_packages[*]})"
    install_packages "${apt_packages[@]}" || warn "Could not install PHP extension packages; will continue."
  fi

  # Verify per installed version where possible.
  for version in $versions; do
    for extension in curl zip; do
      if php_has_extension "$version" "$extension"; then
        ok "PHP $version: $extension extension loaded"
      else
        warn "Could not verify PHP $version has the '$extension' extension."
        warn "Run:  grep -n 'extension' $PHP_ETC_DIR/$version/fpm/php.ini"
      fi
    done
  done
}

# ---------------------------------------------------------------------------
# PHP-FPM + CLI limits (fixes large-download timeouts on big modpacks)
# ---------------------------------------------------------------------------
add_pool_setting() {
  local file="$1" key="$2" value="$3"

  if grep -qF "$key" "$file"; then
    ok "$(basename "$file"): $key already set"
    return 0
  fi

  printf '%s = %s\n' "$key" "$value" >> "$file"
  ok "$(basename "$file"): set $key = $value"
}

set_php_ini_value() {
  local file="$1" key="$2" value="$3"

  [ -f "$file" ] || return 0

  if grep -qE "^${key}[[:space:]]*=" "$file"; then
    sed -i -E "s/^${key}[[:space:]]*=.*/${key} = ${value}/" "$file"
  else
    printf '%s = %s\n' "$key" "$value" >> "$file"
  fi
  ok "php.ini: set ${key} = ${value}"
}

apply_php_limits() {
  local version fpm_dir pool_dir
  for version in $(php_versions); do
    fpm_dir="$PHP_ETC_DIR/$version/fpm"
    pool_dir="$fpm_dir/pool.d"

    if [ -d "$pool_dir" ]; then
      info "Applying PHP-FPM pool limits for PHP $version"
      local pool
      for pool in "$pool_dir"/*.conf; do
        [ -f "$pool" ] || continue
        add_pool_setting "$pool" "php_admin_value[memory_limit]" "512M"
        add_pool_setting "$pool" "php_admin_value[max_execution_time]" "3600"
        add_pool_setting "$pool" "php_admin_value[upload_max_filesize]" "5120M"
        add_pool_setting "$pool" "php_admin_value[post_max_size]" "5120M"
      done
    fi

    # CLI runs (blueprint, artisan, composer) rarely need limits and benefit
    # from headroom when the panel builds its frontend.
    set_php_ini_value "$fpm_dir/php.ini" "max_execution_time" "3600"
    set_php_ini_value "$PHP_ETC_DIR/$version/cli/php.ini" "memory_limit" "512M"
    set_php_ini_value "$PHP_ETC_DIR/$version/cli/php.ini" "max_execution_time" "0"

    # Only restart when this version actually provides an FPM service.
    local service="php$version-fpm"
    if systemctl list-unit-files --type=service 2>/dev/null | grep -q "$service"; then
      systemctl restart "$service"
      ok "Restarted $service"
    elif command -v systemctl >/dev/null 2>&1 && systemctl is-active php-fpm >/dev/null 2>&1; then
      systemctl restart php-fpm
      ok "Restarted php-fpm"
    fi
  done
}

# ---------------------------------------------------------------------------
# Server directory access (local target mode)
# ---------------------------------------------------------------------------
# In "local" target mode the extension writes modpack files straight into the
# server's directory under /var/lib/pterodactyl/volumes. The panel's web user
# therefore needs to traverse /var/lib/pterodactyl (and its volumes tree) and
# read/write the per-server directories. Some panels lock these paths down via
# ACLs ("other::---"), which makes every install/list fail with "Server file
# target root does not exist or is not a directory." This step detects that
# and grants access with POSIX ACLs, installing the acl package only when
# setfacl is missing. If /var/lib/pterodactyl is absent (e.g. a pure Wings
# panel with remote nodes) this is a no-op.
ensure_volume_access() {
  [ -d "$PTERODACTYL_DATA_DIR" ] || return 0

  if ! id "$WEB_USER" >/dev/null 2>&1; then
    warn "Web user '$WEB_USER' does not exist; skipping server directory permissions."
    return 0
  fi

  local probe="$PTERODACTYL_DATA_DIR"

  if [ -d "$PTERODACTYL_DATA_DIR/volumes" ]; then
    probe="$PTERODACTYL_DATA_DIR/volumes"
  fi

  if web_user_can_traverse "$probe"; then
    ok "$probe is already accessible to $WEB_USER"
    return 0
  fi

  info "Configuring server directory access for $WEB_USER..."

  if command -v setfacl >/dev/null 2>&1; then
    ok "setfacl already available"
  else
    info "Installing the acl package (setfacl is missing)..."
    install_packages acl
  fi

  setfacl -m "u:$WEB_USER:--x" "$PTERODACTYL_DATA_DIR"

  if [ -d "$PTERODACTYL_DATA_DIR/volumes" ]; then
    setfacl -m "u:$WEB_USER:--x" "$PTERODACTYL_DATA_DIR/volumes"
    setfacl -m "d:u:$WEB_USER:rwx" "$PTERODACTYL_DATA_DIR/volumes"

    local volume
    for volume in "$PTERODACTYL_DATA_DIR"/volumes/*/; do
      [ -d "$volume" ] || continue
      setfacl -m "u:$WEB_USER:rwx" "$volume"
      setfacl -m "d:u:$WEB_USER:rwx" "$volume"
    done
  fi

  if web_user_can_traverse "$probe"; then
    ok "Server directory access granted to $WEB_USER"
  else
    warn "Could not verify $WEB_USER access to $probe."
  fi
}

# Isolated so tests can substitute a fake check without invoking runuser.
web_user_can_traverse() {
  runuser -u "$WEB_USER" -- test -x "$1" 2>/dev/null
}

# ---------------------------------------------------------------------------
# Blueprint framework
# ---------------------------------------------------------------------------
ensure_blueprint() {
  if command -v blueprint >/dev/null 2>&1 && [ -d "$PANEL/.blueprint" ]; then
    ok "Blueprint framework already installed"
    return 0
  fi

  info "Installing the Blueprint extension framework..."
  cd "$PANEL"

  command -v wget >/dev/null 2>&1 || install_packages wget
  command -v unzip >/dev/null 2>&1 || install_packages unzip

  wget -q "https://github.com/BlueprintFramework/framework/releases/latest/download/release.zip" -O release.zip \
    || die "Failed to download Blueprint."
  unzip -o release.zip >/dev/null
  rm -f release.zip

  cat > "$PANEL/.blueprintrc" <<EOF
WEBUSER="${WEB_USER}";
OWNERSHIP="${WEB_USER}:${WEB_GROUP}";
USERSHELL="/bin/bash";
EOF

  chmod +x "$PANEL/blueprint.sh"
  bash "$PANEL/blueprint.sh" || die "Blueprint installation failed."

  ok "Blueprint framework installed"
}

# ---------------------------------------------------------------------------
# Extension install + publish
# ---------------------------------------------------------------------------
install_extension() {
  if [ -d "$PANEL/.blueprint/extensions/modpackinstaller" ] && [ "${MI_FORCE:-0}" != "1" ]; then
    ok "Addon Manager extension is already installed (MI_FORCE=1 to reinstall)"
    return 0
  fi

  cd "$PANEL"

  if [ -f "$SCRIPT_DIR/conf.yml" ]; then
    info "Installing extension from local checkout: $SCRIPT_DIR"
    if blueprint -install "$SCRIPT_DIR"; then
      ok "Extension installed from local checkout"
      return 0
    fi
    warn "Local install failed; falling back to the GitHub release URL."
  fi

  info "Installing extension from: $GITHUB_URL"
  blueprint -install "$GITHUB_URL" || die "Extension installation failed."
  ok "Extension installed from GitHub"
}

publish_extension() {
  cd "$PANEL"

  info "Publishing extension assets..."
  php artisan blueprint:publish
  php artisan view:clear
  php artisan config:cache

  info "Fixing file ownership (${WEB_USER}:${WEB_GROUP})..."
  chown -R "${WEB_USER}:${WEB_GROUP}" \
    "$PANEL/.blueprint" \
    "$PANEL/public" \
    "$PANEL/bootstrap/cache" \
    "$PANEL/storage" 2>/dev/null || warn "Could not chown some panel paths."

  ok "Panel assets published"
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
main() {
  info "=== Addon Manager: Pterodactyl extension installer ==="

  if [ "$(id -u)" -ne 0 ]; then
    die "This installer must be run with sudo or as root:  sudo mi install"
  fi

  detect_package_manager
  detect_panel
  ok "Pterodactyl panel located at: $PANEL"

  detect_web_user

  info "Ensuring system tools (curl, wget, unzip, zip, git)..."
  ensure_system_tools

  info "Ensuring PHP cURL and Zip extensions..."
  ensure_php_extensions

  info "Applying PHP-FPM and CLI limits for large modpack downloads..."
  apply_php_limits

  info "Ensuring the panel can reach the server data directories..."
  ensure_volume_access

  ensure_blueprint
  install_extension
  publish_extension

  cat <<DONE

\033[0;32mInstallation complete!\033[0m

Summary of what was set up:
  - PHP curl + zip extensions were installed (required by the extension).
  - PHP-FPM pools were raised to: memory_limit 512M, max_execution_time 3600s,
    upload_max_filesize 5120M, post_max_size 5120M — this prevents large
    modpacks (~400 MB+) from being killed mid-download.
  - Server data directories under /var/lib/pterodactyl were made accessible to
    the panel web user (${WEB_USER}) when they were locked down.
  - Blueprint framework was $(command -v blueprint >/dev/null 2>&1 && echo "already present" || echo "installed").
  - The Addon Manager extension was installed and published.

Next steps:
  1. Open your panel and go to  Admin -> Extensions -> Addon Manager
     (or just refresh — the extension appears on the server dashboard).
  2. Optional: add a CurseForge API key to $PANEL/.env:
       CURSEFORGE_API_KEY=your_key_here
     then run:  php artisan config:cache && systemctl restart php-fpm

Need help? See INSTALLATION.md or open an issue at:
  $GITHUB_URL/issues
DONE
}

# Allow running only the functions (for tests) with:  MI_SOURCE_ONLY=1
if [ "${MI_SOURCE_ONLY:-0}" != "1" ]; then
  main
fi