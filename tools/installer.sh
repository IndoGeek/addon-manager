#!/usr/bin/env bash
# Addon Manager — one-command installer for the Pterodactyl Blueprint extension.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# The tooling lives in tools/, so the extension checkout itself is its parent directory (MI_SRC overrides it, the
# same escape hatch tools/build.sh offers).
REPO_DIR="${MI_SRC:-$(dirname -- "$SCRIPT_DIR")}"
GITHUB_URL="https://github.com/indogeek/addon-manager"
DEFAULT_PANEL="/var/www/pterodactyl"
WEB_USER=""
WEB_GROUP=""
# Detected in detect_web_user() (after detect_panel) — never assume www-data: RHEL-family nginx runs as...

# Root that contains per-version PHP config directories (e.g. "/etc/php/8.3").
PHP_ETC_DIR="${PHP_ETC_DIR:-/etc/php}"

# Root Pterodactyl data directory whose server folders the panel web user needs to reach in "local" target mode.
PTERODACTYL_DATA_DIR="${PTERODACTYL_DATA_DIR:-/var/lib/pterodactyl}"

# --------------------------------------------------------------------------- Output helpers...
# Same contract as tools/build.sh: info/ok/warn are for the step output (logged per step, warnings surface in the
# summary), printf lines are for the handful of things worth printing outside the bar.
info()  { printf '\033[0;34m[..]\033[0m %s\n' "$*"; }
ok()    { printf '\033[0;32m[ok]\033[0m %s\n' "$*"; }
warn()  { printf '\033[0;33m[!!]\033[0m %s\n' "$*"; }
die()   { printf '\033[0;31m[!!]\033[0m %s\n' "$*" >&2; exit 1; }

# --------------------------------------------------------------------------- Terminal UI...
# The progress UI is deliberately the same one tools/build.sh draws: a single live bar line per step, the step name
# and elapsed time next to it, and every warning collected into one block at the end rather than scrolling past.
if [ -t 1 ]; then
  C_RESET=$'\e[0m';  C_DIM=$'\e[2m';    C_BOLD=$'\e[1m'
  C_GREEN=$'\e[38;5;114m'; C_CYAN=$'\e[38;5;81m'
  C_YELLOW=$'\e[38;5;179m'; C_RED=$'\e[38;5;203m'
  C_MAGENTA=$'\e[38;5;176m'; C_BLUE=$'\e[38;5;75m'
  TTY_UI=1
else
  C_RESET=""; C_DIM=""; C_BOLD=""; C_GREEN=""; C_CYAN=""
  C_YELLOW=""; C_RED=""; C_MAGENTA=""; C_BLUE=""
  TTY_UI=0
fi

BAR_WIDTH=24
SPIN_FRAMES=("⠋" "⠙" "⠹" "⠸" "⠼" "⠴" "⠦" "⠧" "⠇" "⠏")

STEPS=()             # step labels, filled by plan_steps
STEP_FUNCS=()        # one function per step, in the same order
TOTAL=0
WARNINGS=()          # unique warning lines captured out of the step logs
WARN_STEPS=()        # which step each warning came from
EXPANDED=0
BAR_OPEN=0           # 1 = a live bar line is on screen (not newline-terminated)
RUN_PID=""
SAVED_STTY=""
STEP_START=0
LOG=""

cleanup() {
  # Kill the whole step process tree — a step's children (rsync, bluepint, yarn) would otherwise survive as orphans.
  if [ -n "$RUN_PID" ]; then
    pkill -TERM -P "$RUN_PID" 2>/dev/null || true
    kill -TERM "$RUN_PID" 2>/dev/null || true
  fi
  if [ "$TTY_UI" = 1 ]; then
    if [ -n "$SAVED_STTY" ]; then
      stty "$SAVED_STTY" </dev/tty 2>/dev/null || true
    fi
    printf '\r\e[K\e[?25h'
  fi
  [ -n "$LOG" ] && rm -rf "$LOG"
}

# Installs are what leave a half-built panel behind, so an interrupted run also puts the frontend copy back.
installer_on_exit() {
  local status=$?
  on_failure_restore_assets "$status"
  cleanup
}

on_interrupt() {
  trap - EXIT INT TERM
  on_failure_restore_assets 130
  cleanup
  exit 130
}

bar_glyphs() {
  # $1 = 0..1000 progress in tenths of a percent, $2 = spinner frame index
  local prog=$1 spin=$2 out="" ratio i
  ratio=$(( prog * BAR_WIDTH / 1000 ))
  for (( i = 0; i < BAR_WIDTH; i++ )); do
    if (( i < ratio )); then
      out+="${C_GREEN}█"
    elif (( i == ratio && prog < 1000 )); then
      out+="${C_MAGENTA}${SPIN_FRAMES[$(( spin % ${#SPIN_FRAMES[@]} ))]}"
    else
      out+="${C_DIM}░"
    fi
  done
  printf '%s%s' "$out" "$C_RESET"
}

badge_seg() {
  # Compact warning badge rendered inside the bar line.
  local wc=${#WARNINGS[@]}
  if (( wc > 0 )); then
    printf '%s' " ${C_DIM}[w]${C_RESET} ${C_YELLOW}▲ ${wc}${C_RESET}"
  else
    printf '%s' " ${C_DIM}[w]${C_RESET} ${C_GREEN}✓${C_RESET}"
  fi
}

print_warnings_block() {
  local wc=${#WARNINGS[@]} i
  if (( wc == 0 )); then
    printf '%s  (no warnings)%s\n' "$C_DIM" "$C_RESET"
    return
  fi
  local n=0
  for i in "${!WARNINGS[@]}"; do
    (( n >= 12 )) && { printf '%s  … +%d more%s\n' "$C_DIM" "$(( wc - 12 ))" "$C_RESET"; break; }
    printf '  %s▲%s %s %s\n' "$C_YELLOW" "$C_RESET" "${WARN_STEPS[$i]}:" "${WARNINGS[$i]}"
    n=$(( n + 1 ))
  done
}

draw() {
  # $1 = completed steps, $2 = current step idx (or -1 when finished), $3 = tick. Everything on ONE line.
  local done=$1 cur=$2 tick=$3
  local prog=0 pct frac=0 elapsed=0

  if (( cur >= 0 )); then
    elapsed=$(( SECONDS - STEP_START ))
    # creep toward 95% of the current step so the bar always moves
    frac=$(( elapsed * 100 / (elapsed + 12) ))
    (( frac > 95 )) && frac=95
    prog=$(( (done * 1000 + frac * 10) / TOTAL ))
  else
    prog=1000
  fi
  (( prog > 1000 )) && prog=1000
  pct=$(( prog / 10 ))

  local frame=$(( tick % ${#SPIN_FRAMES[@]} )) status
  if (( cur >= 0 )); then
    status="${C_CYAN}▶${C_RESET} ${STEPS[$cur]} ${C_DIM}· step $(( cur + 1 ))/${TOTAL} · ${elapsed}s${C_RESET}"
  else
    status="${C_GREEN}✓${C_RESET} done"
  fi

  # ONE write — CR + erase-line + content (badge included).
  printf '\r\e[2K %s %s%3d%%%s  %s%s' \
    "$(bar_glyphs "$prog" "$frame")" "$C_BOLD" "$pct" "$C_RESET" \
    "$status" "$(badge_seg)"
  BAR_OPEN=1
}

harvest_warnings() {
  # Pulls the lines worth surfacing out of a step log: our own warn() output plus real build failures. Kept narrow on
  # purpose — a step's routine narration stays in its log instead of being repeated in the summary.
  local file="$1" idx="$2"
  [ -s "$file" ] || return 0

  local line
  while IFS= read -r line; do
    [ -n "$line" ] || continue
    if ! printf '%s\n' "${WARNINGS[@]:-}" | grep -qxF "$line"; then
      WARNINGS+=("$line")
      WARN_STEPS+=("${STEPS[$idx]:-install}")
    fi
  done < <(sed 's/\x1b\[[0-9;?]*[a-zA-Z]//g' "$file" \
      | grep -aE '^\[!!\]|ERROR in |Module build failed|error Command failed' \
      | sed -E 's/^\[!!\] //' \
      | awk '{ if (length($0) > 110) { s = substr($0, 1, 110); sub(/ [^ ]*$/, "", s); print s " …" } else print }' \
      | sort -u || true)
}

plan_steps() {
  # Labels and the functions behind them, in run order. The post-install sync is skipped when the caller owns the
  # build (mi build installs first, then runs its own pipeline), so the bar never shows a step that will not run.
  STEPS=(
    "System tools"
    "PHP extensions"
    "PHP-FPM limits"
    "Server directory access"
    "Blueprint framework"
  )
  STEP_FUNCS=(
    ensure_system_tools
    ensure_php_extensions
    apply_php_limits
    ensure_volume_access
    ensure_blueprint
  )

  if [ "${MI_SKIP_SYNC:-0}" != "1" ]; then
    STEPS+=("Panel frontend copy")
    STEP_FUNCS+=(snapshot_panel_assets)
  fi

  STEPS+=("Extension install" "Publish assets")
  STEP_FUNCS+=(install_extension publish_extension)

  if [ "${MI_SKIP_SYNC:-0}" != "1" ]; then
    STEPS+=("Sync + rebuild" "Verify frontend")
    STEP_FUNCS+=(sync_extension_from_checkout verify_panel_frontend)
  fi

  TOTAL=${#STEPS[@]}
}

run_steps() {
  LOG="$(mktemp -d)"

  SECONDS=0
  if [ "$TTY_UI" = 1 ]; then
    printf '\e[?25l'   # hide cursor while the bar is live
    # cbreak mode: read single keys without blocking, no echo
    SAVED_STTY=$(stty -g </dev/tty 2>/dev/null || true)
    stty -echo -icanon min 0 time 1 </dev/tty 2>/dev/null || true
  fi

  local tick=0 idx step_log status key
  for idx in "${!STEPS[@]}"; do
    STEP_START=$SECONDS
    step_log="$LOG/step_$idx.log"
    : > "$step_log"

    if [ "$TTY_UI" != 1 ]; then
      printf '%s▸%s step %d/%d · %s\n' "$C_BLUE" "$C_RESET" "$(( idx + 1 ))" "$TOTAL" "${STEPS[$idx]}"
    fi

    # The step runs in the background with its own stdin, so the key reader below never fights it for the terminal.
    "${STEP_FUNCS[$idx]}" > "$step_log" 2>&1 </dev/null &
    RUN_PID=$!

    while kill -0 "$RUN_PID" 2>/dev/null; do
      if [ "$TTY_UI" = 1 ]; then
        key=""
        IFS= read -r -t 0.07 -n 1 key </dev/tty || true
        if [ "$key" = "w" ] || [ "$key" = "W" ]; then
          EXPANDED=$(( 1 - EXPANDED ))
          if (( EXPANDED == 1 )); then
            draw "$idx" "$idx" "$tick"     # commit the current frame
            printf '\n'                     # open the panel below it
            print_warnings_block
            BAR_OPEN=0
          fi
        fi
        if (( tick % 2 == 0 )); then
          (( tick % 6 == 0 )) && harvest_warnings "$step_log" "$idx"
          draw "$idx" "$idx" "$tick"
        fi
        tick=$(( tick + 1 ))
      else
        sleep 0.5
      fi
    done

    status=0
    wait "$RUN_PID" || status=$?
    RUN_PID=""
    harvest_warnings "$step_log" "$idx"

    if [ "$status" -ne 0 ]; then
      if [ "$TTY_UI" = 1 ]; then
        printf '\n\e[?25h'   # commit the bar line, restore the cursor
      fi
      printf '%s✗ step failed: %s%s\n' "$C_RED" "${STEPS[$idx]}" "$C_RESET"
      printf '%s── last output ──────────────────────────%s\n' "$C_DIM" "$C_RESET"
      tail -n 20 "$step_log" || true
      exit "$status"
    fi
  done

  if [ "$TTY_UI" = 1 ] && (( ! BAR_OPEN )); then
    draw "$TOTAL" -1 0     # a final frame when the last step's bar was already committed
  fi
}

# --------------------------------------------------------------------------- Web user detection...
detect_web_user() {
  # 1. Explicit override wins.
  if [ -n "${MI_WEB_USER:-}" ]; then
    WEB_USER="$MI_WEB_USER"
    WEB_GROUP="${MI_WEB_GROUP:-$MI_WEB_USER}"
    ok "Web user overridden: ${WEB_USER}:${WEB_GROUP}"
    return 0
  fi

  # 2.
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

# --------------------------------------------------------------------------- Package manager detection...
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

# --------------------------------------------------------------------------- Panel directory detection...
PANEL=""

detect_panel() {
  # When the repo sits directly inside a panel, the parent directory is it.
  if [ -f "$REPO_DIR/artisan" ] && [ -f "$REPO_DIR/blueprint.sh" ]; then
    PANEL="$REPO_DIR"
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

# --------------------------------------------------------------------------- Installed PHP versions...
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

# --------------------------------------------------------------------------- System tools + PHP extensions...
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

# --------------------------------------------------------------------------- PHP-FPM + CLI limits (fixes...
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

    # CLI runs (blueprint, artisan, composer) rarely need limits and benefit from headroom when the panel builds...
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

# --------------------------------------------------------------------------- Server directory access (local...
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

# --------------------------------------------------------------------------- Blueprint framework...
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

# --------------------------------------------------------------------------- Panel frontend safety net...
# The panel's frontend build deletes every JS asset in public/assets before it compiles, so a compile error leaves the
# whole UI blank rather than just the extension. These helpers keep a copy of the working assets and put them back when
# the install ends without a usable bundle.
# The path is derived from the shell's PID rather than stored in a variable: the steps run in background subshells, so
# a variable set inside one would never reach the parent that owns the failure trap. $$ stays the main shell's PID.
PANEL_ASSET_BACKUP="/tmp/mi-panel-frontend-$$"

panel_asset_count() {
  find "$PANEL/public/assets" -maxdepth 1 -type f -name '*.js' 2>/dev/null | wc -l | tr -d ' '
}

snapshot_panel_assets() {
  rm -rf "$PANEL_ASSET_BACKUP"   # a previous run that died must not decide this one's fallback

  local file rel saved=0
  while IFS= read -r -d '' file; do
    rel="${file#"$PANEL"/}"
    mkdir -p "$PANEL_ASSET_BACKUP/$(dirname "$rel")" 2>/dev/null || true
    if cp -a "$file" "$PANEL_ASSET_BACKUP/$rel" 2>/dev/null; then saved=$(( saved + 1 )); fi
  done < <(find "$PANEL/public/assets" -type f \( -name '*.js' -o -name '*.map' \) -print0 2>/dev/null)

  if [ "$saved" -gt 0 ]; then
    info "Kept a copy of the current panel frontend ($saved file(s)) in case the rebuild fails"
    return 0
  fi

  # Nothing to fall back to: the panel had no bundle to begin with (a first install on an already-broken panel).
  rm -rf "$PANEL_ASSET_BACKUP"
  info "No panel frontend to copy yet — nothing to fall back on"
}

panel_asset_backup_ready() {
  [ -d "$PANEL_ASSET_BACKUP" ] && [ -n "$(ls -A "$PANEL_ASSET_BACKUP" 2>/dev/null)" ]
}

restore_panel_assets() {
  panel_asset_backup_ready || return 0

  local rel dest
  while IFS= read -r -d '' rel; do
    dest="$PANEL/${rel#./}"
    mkdir -p "$(dirname "$dest")" 2>/dev/null || true
    cp -a "$PANEL_ASSET_BACKUP/${rel#./}" "$dest" 2>/dev/null || true
  done < <(cd "$PANEL_ASSET_BACKUP" && find . -type f -print0 2>/dev/null)

  chown -R "${WEB_USER}:${WEB_GROUP}" "$PANEL/public/assets" 2>/dev/null || true
  rm -rf "$PANEL_ASSET_BACKUP"
}

clear_panel_asset_backup() {
  rm -rf "$PANEL_ASSET_BACKUP"
}

# A failed install (or Ctrl+C) must not leave a half-built panel behind. The exit status is passed in explicitly by the
# trap rather than read from $?, which any command in between would clobber.
on_failure_restore_assets() {
  local status="${1:-0}"
  [ "$status" -eq 0 ] && return 0

  if panel_asset_backup_ready; then
    warn "Installation did not finish — restoring the panel frontend that was in place before it"
    restore_panel_assets
  fi

  return 0
}

# --------------------------------------------------------------------------- Extension install + publish...
install_extension() {
  if [ -d "$PANEL/.blueprint/extensions/modpackinstaller" ] && [ "${MI_FORCE:-0}" != "1" ]; then
    ok "Addon Manager extension is already installed (MI_FORCE=1 to reinstall)"
    return 0
  fi

  cd "$PANEL"

  # Blueprint only imports extensions from a .blueprint archive inside the panel directory (an outside path is
  # rejected), so package the checkout into one and install that — the same shape as the released download, built
  # from the code you cloned rather than from whatever the last release happens to be.
  if [ -f "$REPO_DIR/conf.yml" ]; then
    local identifier archive
    identifier="$(sed -n 's/^[[:space:]]*identifier:[[:space:]]*"\([^"]*\)".*/\1/p' "$REPO_DIR/conf.yml" | head -1)"
    [ -n "$identifier" ] || identifier="modpackinstaller"
    archive="$identifier.blueprint"

    info "Packaging the local checkout: $REPO_DIR"
    rm -f "$PANEL/$archive"

    # The tooling is deliberately not shipped: Blueprint's installer rewrites placeholder tokens in *every* file it
    # packages, and these scripts are ours to run from the checkout, not part of the extension the panel loads.
    if ( cd "$REPO_DIR" && zip -qr "$PANEL/$archive" . \
        -x '.git/*' -x '.git' -x 'node_modules/*' -x '*.blueprint' -x '.build-cache.json' \
        -x 'mi' -x 'tools/*' -x 'tests/*' -x '.github/*' ); then
      info "Installing extension from local checkout"
      if blueprint -install "$archive"; then
        rm -f "$PANEL/$archive"
        ok "Extension installed from local checkout"
        return 0
      fi
      warn "Local install failed; falling back to the GitHub release URL."
    else
      warn "Packaging the checkout failed; falling back to the GitHub release URL."
    fi

    rm -f "$PANEL/$archive"
  fi

  info "Installing extension from: $GITHUB_URL"
  blueprint -install "$GITHUB_URL" || die "Extension installation failed."
  ok "Extension installed from GitHub"
}

publish_extension() {
  cd "$PANEL"

  info "Publishing extension assets..."

  # Blueprint renamed these commands between versions (blueprint:publish is gone in the current betas), so run
  # whichever this panel actually has. The extension still serves without either, since its files are already in
  # place and the panel build that follows republishes the assets.
  local commands
  commands="$(php artisan list --raw 2>/dev/null || true)"

  if printf '%s\n' "$commands" | grep -q '^blueprint:publish'; then
    php artisan blueprint:publish
  elif printf '%s\n' "$commands" | grep -q '^bp:meta'; then
    php artisan bp:meta
  else
    info "No extension-publish command on this Blueprint version — skipping"
  fi

  php artisan view:clear
  php artisan config:cache
  php artisan bp:cache >/dev/null 2>&1 || true

  info "Fixing file ownership (${WEB_USER}:${WEB_GROUP})..."
  chown -R "${WEB_USER}:${WEB_GROUP}" \
    "$PANEL/.blueprint" \
    "$PANEL/public" \
    "$PANEL/bootstrap/cache" \
    "$PANEL/storage" 2>/dev/null || warn "Could not chown some panel paths."

  ok "Panel assets published"
}

# --------------------------------------------------------------------------- Post-install sync + verification...
# Blueprint rewrites placeholder tokens (the literal {version}, {name}, {target}, …) in every file it packages, and it
# runs the panel's own frontend build before we can correct anything — so a first install can ship rewritten sources
# plus a failed webpack run. Syncing the checkout over the installed copy is what makes a fresh install end up like a
# dev-tree build.
sync_extension_from_checkout() {
  if [ ! -f "$REPO_DIR/tools/build.sh" ]; then
    info "No build pipeline in this checkout — skipping the post-install sync"
    return 0
  fi

  info "Syncing the checkout over the installed copy and rebuilding the panel frontend..."

  if env PANEL_DIR="$PANEL" bash "$REPO_DIR/tools/build.sh"; then
    ok "Checkout synced and the panel frontend rebuilt"
    return 0
  fi

  warn "The post-install sync failed — read the build output above for the cause."
  return 1
}

# Confirms the panel still has a usable frontend bundle. webpack deletes every asset before compiling, so a failed
# build leaves server pages blank; when the bundle is missing the pre-install copy is put back and we stop loudly.
verify_panel_frontend() {
  local count
  count="$(panel_asset_count)"

  if [ "$count" -gt 0 ]; then
    ok "Panel frontend assets present ($count file(s))"
    return 0
  fi

  warn "The panel has no frontend bundle (public/assets/*.js) — server pages would render blank."

  if [ -n "$PANEL_ASSET_BACKUP" ]; then
    warn "Restoring the frontend that was in place before this install..."
    restore_panel_assets
    ok "Previous panel frontend restored — the panel keeps working, minus this extension's dashboard UI."
  fi

  printf '\n\033[0;31m[!!]\033[0m The extension was installed, but the panel frontend build produced no bundle.\n\n' >&2
  printf '     Rebuild it once the cause above is fixed:\n\n' >&2
  printf '         cd %s && yarn run build:production\n\n' "$PANEL" >&2

  return 1
}

# --------------------------------------------------------------------------- Main...
main() {
  if [ "$(id -u)" -ne 0 ]; then
    die "This installer must be run with sudo or as root:  sudo mi install"
  fi

  printf '%s▲%s %sAddon Manager%s — Pterodactyl Blueprint extension installer\n\n' \
    "$C_CYAN" "$C_RESET" "$C_BOLD" "$C_RESET"

  # Preflight stays outside the bar: it is the only narration worth printing, and a failure here must never be hidden
  # behind a progress line.
  detect_package_manager
  detect_panel
  ok "Pterodactyl panel located at: $PANEL"
  detect_web_user
  printf '\n'

  # Anything that exits abnormally after the snapshot is taken puts the panel frontend back.
  trap 'installer_on_exit' EXIT
  trap on_interrupt INT TERM

  plan_steps
  run_steps

  local warn_n=${#WARNINGS[@]} dur=$SECONDS i

  if [ "$TTY_UI" = 1 ]; then
    printf '\n\e[?25h'   # commit the bar line exactly once, restore the cursor
  else
    printf '\n'
  fi

  if (( warn_n > 0 )); then
    printf '%s── warnings (%d) ─────────────────────────%s\n' "$C_YELLOW" "$warn_n" "$C_RESET"
    for i in "${!WARNINGS[@]}"; do
      printf '  %s▲%s %s\n' "$C_YELLOW" "$C_RESET" "${WARNINGS[$i]}"
    done
  fi

  clear_panel_asset_backup

  printf '%s ✓ Installation complete%s  %s◷ %ds · %d steps%s\n\n' \
    "$C_GREEN" "$C_RESET" "$C_BLUE" "$dur" "$TOTAL" "$C_RESET"

  cat <<DONE
What was set up:
  - PHP curl + zip extensions ensured (required by the extension).
  - PHP-FPM pools raised to memory_limit 512M, max_execution_time 3600s,
    upload_max_filesize 5120M, post_max_size 5120M — large modpacks (~400 MB+)
    no longer die mid-download.
  - Server data directories under /var/lib/pterodactyl made reachable by the
    panel web user (${WEB_USER}) where they were locked down.
  - Blueprint framework $(command -v blueprint >/dev/null 2>&1 && echo 'already present' || echo 'installed'), the Addon Manager
    extension installed and published, and the panel frontend rebuilt.

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