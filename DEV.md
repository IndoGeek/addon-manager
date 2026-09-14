# DEV.md — Troubleshooting Guide

Every known failure mode for the Modpack Installer extension, with symptoms,
root causes, and fixes. Read the top section once; use the rest as a lookup
table when something breaks.

---

## 0. Environment map (memorize these paths)

| What | Path |
|---|---|
| Source repo (source of truth) | `/home/tanmay/Code/modpack-installer` |
| Panel installation | `/var/www/pterodactyl` |
| Blueprint dev tree (sync target) | `/var/www/pterodactyl/.blueprint/dev` |
| Installed extension tree | `/var/www/pterodactyl/.blueprint/extensions/modpackinstaller` |
| Extension components symlink (JS/TSX) | `/var/www/pterodactyl/resources/scripts/blueprint/extensions/modpackinstaller` → `.blueprint/extensions/modpackinstaller/components` |
| **Extension CSS import (plain copy, NOT a symlink)** | `/var/www/pterodactyl/resources/scripts/blueprint/css/imported/modpackinstaller.css` |
| CSS import chain | `resources/scripts/index.tsx` → `blueprint/css/extensions.css` → `imported/modpackinstaller.css` |
| Compiled assets the browser loads | `/var/www/pterodactyl/public/assets/` (e.g. `bundle.<hash>.js`) |
| Webpack cache | `/var/www/pterodactyl/.build-cache.json` |
| Panel routes (Blueprint client router) | `/var/www/pterodactyl/routes/blueprint/client.php` + `resources/scripts/blueprint/extends/routers/routes.ts` |
| Laravel logs | `/var/www/pterodactyl/storage/logs/laravel-YYYY-MM-DD.log` |
| Install records (data dir) | `/var/lib/pterodactyl/modpack-installer` (override: `MODPACK_INSTALLER_DATA_DIR`) |
| Panel .env | `/var/www/pterodactyl/.env` (root-readable) |

**Ownership facts that matter:**

- `.blueprint/dev`, `.blueprint/extensions/*`, `public/assets`, `.build-cache.json`
  are owned by `www-data`.
- You run as `tanmay`. Passwordless sudo is limited to exactly three commands:
  `rsync`, `chown`, `rm`. Plain `sudo cp`, `sudo cat /var/www/pterodactyl/.env`,
  and `sudo <anything else>` **will ask for a password and hang scripts**.

**Deploy = one command:**

```bash
./build.sh
```

It does: `sync.sh` (rsync repo → dev tree) → refresh the CSS import copy →
chown dance → `NODE_OPTIONS=--openssl-legacy-provider yarn run build:production`
inside the panel → restore `www-data` ownership. Never run the pieces by hand
unless you are debugging build.sh itself.

---

## 1. Frontend troubleshooting

### 1.1 UI changes don't appear in the browser (THE big one)

**Symptom:** build succeeds, browser shows old layout/styles; zooming out
"fixes" the layout; mobile is vertically stretched.

**Root cause (hit #1, hit it twice already):** the CSS the browser uses is
`resources/scripts/blueprint/css/imported/modpackinstaller.css` — a **plain
file copy** of `root.css`. `sync.sh` does NOT update it and the webpack build
does NOT read `root.css` directly. If you skip the copy step, webpack happily
bundles a weeks-old stylesheet and exits 0.

**Diagnose:**

```bash
# Is the imported copy identical to the repo source?
diff <(sha256sum < /home/tanmay/Code/modpack-installer/root.css) \
     <(sha256sum < /var/www/pterodactyl/resources/scripts/blueprint/css/imported/modpackinstaller.css) \
  && echo MATCH || echo STALE

# When was each last touched?
stat -c '%y %n' \
  /home/tanmay/Code/modpack-installer/root.css \
  /var/www/pterodactyl/resources/scripts/blueprint/css/imported/modpackinstaller.css
```

**Fix:** run `./build.sh` (it refreshes the copy). Manually:

```bash
sudo -n rsync -a /home/tanmay/Code/modpack-installer/root.css \
  /var/www/pterodactyl/resources/scripts/blueprint/css/imported/modpackinstaller.css
sudo -n chown www-data:www-data \
  /var/www/pterodactyl/resources/scripts/blueprint/css/imported/modpackinstaller.css
# then rebuild (see 1.3)
```

Use `rsync`, not `cp` — `sudo cp` is not passwordless here.

### 1.2 Is the browser even loading the new bundle?

The bundle filename embeds a **content hash** (`bundle.3fafd6f6.js`). A real
rebuild changes the hash; browsers then fetch the new URL automatically.

```bash
ls -lt /var/www/pterodactyl/public/assets/bundle.*.js   # newest first
# New CSS marker really inside the served bundle?
grep -o 'your-new-rule {[^}]*}' /var/www/pterodactyl/public/assets/bundle.*.js
```

If the hash didn't change after a build → nothing was rebuilt → see 1.3/1.4.

Hard-verify in the browser (Safari/Chrome devtools):
1. Network tab → the loaded `bundle.<hash>.js` must match `ls` above.
2. Elements tab → computed styles on `.modpackinstaller-controls-row` etc.
3. Hard reload (Safari: ⌘⌥R / disable cache) — but only AFTER the hash check
   passes; a changed filename is itself the cache-bust.

### 1.3 Build fails with `Permission denied` during `clean` or EACCES on `.build-cache.json`

`public/assets` and `.build-cache.json` are `www-data`-owned; the build runs
as `tanmay`. build.sh handles this with a chown-swap + trap-restore. If you
run webpack manually and it dies halfway:

```bash
sudo -n chown -R tanmay:tanmay /var/www/pterodactyl/public/assets /var/www/pterodactyl/.build-cache.json
# build...
sudo -n chown -R www-data:www-data /var/www/pterodactyl/public/assets /var/www/pterodactyl/.build-cache.json
```

Never leave `public/assets` owned by `tanmay` — the web server must read it.

### 1.4 `error Command failed` / OpenSSL errors in the build

Node needs the legacy OpenSSL provider for the panel's webpack:

```bash
cd /var/www/pterodactyl
NODE_OPTIONS=--openssl-legacy-provider yarn run build:production
```

Other build errors:

| Error | Fix |
|---|---|
| `Cannot find module` in webpack output | `cd /var/www/pterodactyl && sudo -n rsync -a .../node_modules` isn't the way — check the panel's `node_modules` exists; reinstall with yarn there |
| `find: cannot delete ... Permission denied` during clean | see 1.3 |
| Webpack OOM / killed | close other jobs; retry |

The extension's own devDependencies live in the **repo** (`npm ci` there is
only for editor tooling / tsc). The real build uses the **panel's**
`node_modules`.

### 1.5 Page renders but component is blank / "route exists but nothing shows"

1. Is the component registered? Check
   `resources/scripts/blueprint/extends/routers/routes.ts` for the
   `Modpackinstaller*ServerRouteEnd` block pointing at
   `@blueprint/extensions/modpackinstaller/ModpackInstaller`.
2. Is the symlink alive?
   `ls -la resources/scripts/blueprint/extensions/` — should point to
   `../../../../.blueprint/extensions/modpackinstaller/components`. If broken,
   re-create it or re-run the Blueprint install step.
3. JSX syntax error in the TSX → webpack build fails loudly. Read the build
   output, don't assume exit 0.
4. Console error `axios is not defined`-type issues → the panel bundles its
   own axios; make sure the component imports it (it does) and the build used
   the panel's node_modules.

### 1.6 TypeScript check

```bash
cd /home/tanmay/Code/modpack-installer
npx tsc --noEmit --jsx react --esModuleInterop --strict --skipLibCheck \
  --module esnext --moduleResolution bundler --target es2020 \
  components/ModpackInstaller.tsx
```

Known-benign findings: `TS2307 Cannot find module 'axios'` (resolved by the
panel's node_modules at build time) and a few `TS7006 implicit any` on
callback params. Neither blocks the babel/webpack build. Anything else is a
real regression — fix before committing.

### 1.7 Toolbar/mobile layout looks wrong

Order of investigation (from actual experience — do not skip ahead):

1. **First** prove deployment correctness (1.1 + 1.2). Every "CSS is broken"
   so far was actually "old CSS is deployed".
2. Then, in devtools, read the **computed** styles of:
   `.modpackinstaller-controls-row`, `.modpackinstaller-search-box`,
   `.modpackinstaller-installed-toggle`, `.modpackinstaller-toolbar-row`,
   `.modpackinstaller-filters-toggle`, `.modpackinstaller-view-toggle button`.
3. Specificity traps to remember inside `root.css`:
   - `.modpackinstaller-card button` (0,1,1) **beats** `.installed-toggle`
     (0,1,0). Avoid class+element rules that touch buttons/inputs inside the
     card — they will override the compact toolbar styles on mobile.
   - Media-query order matters: later rules win at equal specificity.
4. Global Pterodactyl/Tailwind styles can leak in. If so, scope the fix under
   `.modpackinstaller-...` — never emit bare `button {}` / `input {}` rules.
5. Test at 375 / 390 / 430 / 768 px + desktop; check for horizontal overflow
   with `document.documentElement.scrollWidth > window.innerWidth` in console.

### 1.8 CSS changes to root.css aren't enough / split-file confusion

- `components/styles/*.css` (base/search/buttons/...) is an internal
  organization used by nothing at runtime; `root.css` is the live stylesheet
  injected through the panel build. If you edit a file under
  `components/styles/` and nothing happens — that's why. Keep `root.css` and
  the split files in sync, or treat `root.css` as the single source.

### 1.9 Icons/fonts load, page half-broken, 404s in console

```bash
ls -la /var/www/pterodactyl/public/assets/extensions/   # symlink must resolve
readlink -f /var/www/pterodactyl/public/assets/extensions/modpackinstaller
```

Broken symlink → re-create:
`ln -s ../../../.blueprint/extensions/modpackinstaller/assets <target>`
(must be owned by root/www-data; use sudo rsync into place if needed).

---

## 2. Backend (PHP) troubleshooting

### 2.0 How backend PHP code reaches the panel (read this first)

The panel does NOT load `app/` from the repo or from `.blueprint/dev`. It
loads a **copy** placed inside the panel's own app tree:

```
repo app/*.php  (namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\...)
   │  blueprint install (one-time)
   ▼
/var/www/pterodactyl/app/BlueprintFramework/Extensions/modpackinstaller/   ← what PHP actually loads

repo client.php
   │  blueprint install (one-time)
   ▼
/var/www/pterodactyl/routes/blueprint/client/modpackinstaller.php          ← what the router loads
```

`.blueprint/dev/app/` and `.blueprint/dev/client.php` (what `./sync.sh`
updates) are just development scratch copies — editing them changes nothing
at runtime.

**Autoloading:** the panel's `composer.json` maps `Pterodactyl\` → `app/`,
so the extension namespace resolves by path through the same PSR-4 rule as
core panel classes. No separate autoload entry exists or is needed.

**Consequence:** after editing ANY php file in the repo, the edit is invisible
to the panel until the file is copied into
`app/BlueprintFramework/Extensions/modpackinstaller/` (and `client.php` into
`routes/blueprint/client/`). Verify what is actually loaded:

```bash
# repo vs loaded copy — must print nothing (identical)
diff -r /home/tanmay/Code/modpack-installer/app \
        /var/www/pterodactyl/app/BlueprintFramework/Extensions/modpackinstaller

# routes file — must print nothing
diff /home/tanmay/Code/modpack-installer/client.php \
     /var/www/pterodactyl/routes/blueprint/client/modpackinstaller.php

# prove the class really autoloads from the panel
cd /var/www/pterodactyl && php -r \
  "require 'vendor/autoload.php'; var_dump(class_exists('Pterodactyl\\BlueprintFramework\\Extensions\\modpackinstaller\\ModpackController'));"
```

If they differ, copy the changed files in with the whitelisted tools:

```bash
sudo -n rsync -a --delete /home/tanmay/Code/modpack-installer/app/ \
  /var/www/pterodactyl/app/BlueprintFramework/Extensions/modpackinstaller/
sudo -n rsync -a /home/tanmay/Code/modpack-installer/client.php \
  /var/www/pterodactyl/routes/blueprint/client/modpackinstaller.php
sudo -n chown -R www-data:www-data \
  /var/www/pterodactyl/app/BlueprintFramework/Extensions/modpackinstaller \
  /var/www/pterodactyl/routes/blueprint/client/modpackinstaller.php
```

(`--delete` keeps stale, renamed, or removed classes from lingering in the
loaded tree — a classic source of "I deleted this class but it still errors".)

### 2.1 API endpoints 404 (`/api/client/extensions/modpackinstaller/...`)

Route loading chain (all three must be intact):

```
routes/blueprint/client.php            ← auto-includes EVERY .php in client/
   └─ client/modpackinstaller.php      ← copy of repo client.php
        └─ ModpackController methods   ← loaded from app/BlueprintFramework/Extensions/...
```

1. Does the routes copy exist and parse?
   `php -l /var/www/pterodactyl/routes/blueprint/client/modpackinstaller.php`
2. Does it match the repo? (diff command in 2.0). If you edited `client.php`
   in the repo, sync.sh alone will NOT update the routes copy — rsync it per
   2.0, then:
   ```bash
   cd /var/www/pterodactyl && php artisan route:clear && php artisan config:clear
   ```
3. Confirm registration:
   `php artisan route:list | grep modpackinstaller` — every repo route must
   appear. A route missing here but present in the file usually means the
   controller class failed to autoload (see 2.2).
4. Still 404 → check the HTTP verb and path prefix. Client routes are mounted
   under `/api/client/extensions/modpackinstaller`; server-scoped routes
   additionally carry `/servers/{server}` and require the server middleware
   in `client.php` — a request to the wrong prefix 404s by design.

### 2.2 `Class not found` / `Target [interface] not resolvable` / white 500s

1. **Which copy is broken?** Run the diff in 2.0. Nine times out of ten the
   repo is fine and the loaded copy is stale or half-copied.
2. **New file just added?** PSR-4 resolves new files by path automatically —
   but the panel uses `optimize-autoloader`. If a new class still isn't found:
   ```bash
   cd /var/www/pterodactyl && php artisan optimize:clear
   ```
   Only if that still fails, regenerate the composer autoloader
   (`composer dump-autoload` in the panel dir — needs full sudo, do it
   consciously, chown back to `www-data` afterwards).
3. **Namespace/path mismatch** — the file must live at exactly
   `app/BlueprintFramework/Extensions/modpackinstaller/<Subdirs>/<Class>.php`
   matching its namespace. One wrong capitalization = class not found.
4. **Interface/dependency errors** (`Target [...] is not instantiable`) —
   usually a constructor-injected service missing from the registry wiring in
   `ModpackController.php` or a renamed interface that only exists in the repo
   copy (again: run the 2.0 diff).
5. **Syntax error in any loaded file** → every request 500s with the file in
   the log. Find and fix:
   ```bash
   tail -50 /var/www/pterodactyl/storage/logs/laravel-$(date +%F).log
   git ls-files '*.php' | xargs -n1 php -l   # in the repo
   ```

### 2.3 HTTP error codes are meaningful — decode before debugging

| Code | Meaning | Where to look |
|---|---|---|
| 422 | Input rejected (bad query/paths/filters) — static messages | Your request params |
| 502 | Upstream (Modrinth/CurseForge) returned garbage/malformed | Upstream status, panel log |
| 503 | Upstream rate-limit/5xx/timeout (retryable), OR install lock held | Retry later; check per-server lock timeout |
| 500 | Unexpected internal error — details in Laravel log | `storage/logs/laravel-*.log` |
| 409 | Update refused: recorded version already equals latest | Expected behavior |

All client-facing messages are static by design — real causes live in the
log, not the response body.

### 2.4 Installs fail / hang

1. **Per-server filesystem lock:** a second install fails fast with 503 while
   one runs; a crashed request leaves a lock until the timeout reclaims it.
   Wait or restart php-fpm to clear a stuck request.
2. **Downloads:** size cap `MODPACK_INSTALLER_MAX_DOWNLOAD_MB` (default 2 GiB).
   Oversize → controlled failure. Private-network hosts are refused by design
   (SSRF hardening) — do not test against `localhost` URLs.
3. **Deployment to the server** goes through Wings. If the panel can download
   but the server files never change, check:
   - Wings is up: `curl -s https://<node>:8080` from the node,
   - panel↔wings token/config unchanged,
   - `MODPACK_INSTALLER_SERVER_TARGET` / `MODPACK_INSTALLER_SERVER_ROOT`
     env overrides are sane (they exist for tests; unset in prod).
4. **Backups/rollback messages** — best-effort rollback failures are reported
   in the result; orphaned files from a previous version are expected after
   update (see ARCHITECTURE.md).

### 2.5 Installed-modpacks list empty / corrupt

```bash
ls -la /var/lib/pterodactyl/modpack-installer/
cat /var/lib/pterodactyl/modpack-installer/installs.json | php -r 'json_decode(stream_get_contents(STDIN)); echo json_last_error()===JSON_ERROR_NONE ? "valid json\n" : "CORRUPT\n";'
```

- Corrupt/hostile records are ignored on read (by design) — the list goes
  empty but nothing crashes. Fix the JSON or delete the offending record
  (records are per-server; deletion only affects the dashboard view, never
  server files).
- Permission errors → the dir must be writable by the **php-fpm user**
  (`www-data`). `sudo -n chown -R www-data:www-data /var/lib/pterodactyl/modpack-installer`
- Uninstall leaves empty directories behind (known limitation — Wings has no
  recursive listing surface). Not a bug.

### 2.6 Catalog / providers misbehaving

1. `GET /catalog/providers` is the contract the UI renders from. Inspect it:
   ```bash
   curl -s -H "Authorization: Bearer <client-api-key>" \
     https://<panel>/api/client/extensions/modpackinstaller/catalog/providers | php -m json
   # (or just open in browser while logged in — cookies work)
   ```
2. Provider `not_configured` for CurseForge → set `CURSEFORGE_API_KEY` in the
   panel `.env`, then `php artisan config:clear`. The key never appears in
   URLs/logs/responses — if you think you see it, that's a bug, report it.
3. Mock provider is `development_only` and **hidden** from the dashboard by
   design. It still answers `/catalog/providers` for tests.
4. Searches are **never cached** — if results look stale, the problem is the
   upstream or your filters, not a cache.

### 2.7 Reading the logs (start here for any 500)

```bash
tail -100 /var/www/pterodactyl/storage/logs/laravel-$(date +%F).log
grep -i modpack /var/www/pterodactyl/storage/logs/laravel-*.log | tail -20
```

Remember: log lines may contain sanitized-but-sensitive context; never paste
them (or `.env`) into issues/chats.

### 2.8 PHP lint + hermetic tests (run before every commit)

```bash
# lint every tracked php file
git ls-files '*.php' | xargs -n1 php -l

# full manual suite
for f in tests/manual/*-test.php; do php "$f"; done
```

Tests are hermetic (no network, no panel boot) except the two documented
deployment-time checks (downloader success path, live Modrinth search). The
install-record tests point `MODPACK_INSTALLER_DATA_DIR` at a temp dir — do
not run them as root against the real data dir.

Run ONE test while iterating on a single class — it's seconds, not minutes:

```bash
php tests/manual/catalog-query-test.php        # query validation pipeline
php tests/manual/modrinth-catalog-provider-test.php
php tests/manual/install-record-store-test.php # store locking/atomicity
```

A failing test prints `PASS` lines up to the first `FAIL` and exits non-zero
— the last PASS tells you exactly which assertion broke.

Tests that exercise extension namespaced classes register an
`spl_autoload_register` callback mapping the namespace onto `app/`, so they
test **repo code directly** — no sync/copy needed to run them. If a test
passes but the panel misbehaves, the loaded copy is stale (see 2.0).

### 2.9 Debugging by layer — where each failure lives

The backend is strictly layered. Find the layer, then read only that code:

```
ModpackController.php                 ← HTTP boundary: input typing/capping, JSON shape, status mapping
   ├── Services/Catalog/*             ← search/version queries, sorting, paging, provider dispatch
   │     └── Providers/Catalog/*      ← upstream HTTP (Modrinth/CurseForge), facet mapping, post-filter
   ├── Providers/*                    ← package metadata + source resolution (modrinth://<project>@<version>)
   └── Services/Installation/*        ← orchestrator: lock → workspace → download → validate → deploy
         ├── Services/Download/*      ← SSRF-hardened downloader (DNS pinning, hop caps, byte budgets)
         ├── Services/Archive/*       ← archive validation + extraction
         ├── Services/Deployment/*    ← planned file writes, backup/rollback
         └── Services/Wings/*         ← server file target over Wings
```

| Symptom | Most likely layer | Check |
|---|---|---|
| 422 on a request you think is valid | Controller input validation | Read the matching scalar/pattern check at the top of the controller action; static messages tell you which check fired |
| Search returns 502 | `Providers/Catalog/*` upstream mapping | Upstream changed its JSON shape? Log (2.7) has the `report()` entry |
| Search returns 503 | Upstream rate-limit/timeout | Retry; if persistent, Modrinth status page |
| Search returns honest-but-wrong results | `CatalogService` filters/sort OR CurseForge post-filter | Verify with the mock provider test; check OR-within/AND-across semantics |
| `modrinth://` resolution fails | `Providers/ModrinthProvider` | Version must belong to project — check the pinned source string |
| Install dies at "validating archive" | `Services/Archive/*` validator | The rejected entry type is logged; validator limits = extractor limits |
| Install dies at download | `Services/Download/*` | Non-HTTPS, private DNS, redirect hop > 5, or byte budget exceeded — all by design |
| Files never appear on the server | `Services/Deployment` or `Services/Wings` | Panel downloaded fine? Then it's Wings comms (2.4.3) |
| Update says 409 always | `Management/InstallRecord*` | Recorded version == resolved version; force by uninstalling or waiting for upstream |

### 2.10 Reproducing a backend bug without the panel

Most classes can be driven standalone — they only need the autoloader:

```bash
cd /home/tanmay/Code/modpack-installer
php -r "
  spl_autoload_register(function($c){
    if (strpos($c,'Pterodactyl\\\\BlueprintFramework\\\\Extensions\\\\modpackinstaller\\\") === 0) {
      require __DIR__.'/app/'.strtr(substr($c,58),'\\\\','/').'.php';
    }
  });
  // then instantiate the class under test and poke it
"
```

Simpler: copy the autoloader block from any `tests/manual/*-test.php` and
build a scratch script. For anything touching the filesystem, set
`MODPACK_INSTALLER_DATA_DIR=/tmp/xyz` first — never point tests at the real
`/var/lib/pterodactyl/modpack-installer`.

Network classes (`Downloader`, `RedirectResolver`, catalog providers) have
host constants baked in for safety; hermetic tests feed them fake responses.
To exercise the real network path, deploy and use the live endpoints — that
is the documented deployment-time validation, not something to hack locally.

### 2.11 Editing the controller safely

`ModpackController.php` is 1200+ lines and security-critical. Rules that
prevent the common regressions:

- Every request input stays **scalar-typed and length-capped** before use.
  Copy the existing `(string)` casts / `preg_match` patterns when adding
  params — don't invent a looser style.
- Multi-value filter groups (`game_versions`, `loaders`, `categories`,
  `environments`) arrive comma-separated; parse through the existing bounded
  array helpers (≤32 values, strict slugs).
- All client-facing messages are static strings. Never interpolate hosts,
  URLs, keys, paths, or exception text into responses — `report()` to the log
  and return the generic message.
- After editing: `php -l`, run the relevant manual tests, then deploy the
  copy per 2.0 — the panel does not see repo edits directly.

---

## 3. Deployment & Blueprint troubleshooting

### 3.1 `./sync.sh` failures

- Password prompt / `sudo: a password is required` → you used a non-whitelisted
  command. sync.sh only uses `rsync` and `chown`; keep it that way.
- `rsync: [receiver] mkstemp ... failed` → destination owned wrong:
  `sudo -n chown -R www-data:www-data /var/www/pterodactyl/.blueprint/dev`
- sync.sh **deletes** files not in the repo (`--delete`) — that's intentional.
  Anything you created only in the dev tree is gone after sync; keep real work
  in the repo.

### 3.2 `./build.sh` failures

See 1.3 (permissions) and 1.4 (openssl). build.sh's trap restores ownership
even on failure — if you ctrl-C mid-build, ownership may stay with `tanmay`;
restore manually:
```bash
sudo -n chown -R www-data:www-data /var/www/pterodactyl/public/assets /var/www/pterodactyl/.build-cache.json
```

### 3.3 `blueprint -build` / `sudo blueprint`

Not usable on this box (no passwordless sudo beyond rsync/chown/rm). The
supported flow is `./build.sh`. Don't "fix" build.sh by switching back to
`blueprint -build`.

### 3.4 Two copies of the extension?

There are exactly two runtime copies and that's correct:
- `.blueprint/dev` — sync target (dev scratch),
- `.blueprint/extensions/modpackinstaller` — what the panel actually loads
  (components via symlink, `dashboard.css` copy of root.css for the admin
  page, routers, app/).

The **dashboard UI** CSS comes from the webpack build (via the imported CSS
copy), NOT from `dashboard.css`. Updating `dashboard.css` alone changes
nothing in the dashboard.

### 3.5 After deploy, admin page looks wrong

Admin page CSS = `dashboard.css` in the extension tree. Refresh it with the
same rsync-one-liner as 1.1 (target the extension tree instead of
`resources/...`). Admin page is classic blade + admin theme — issues there
are usually the admin theme, not the extension.

### 3.6 Panel entirely down after deploy (white screen / 500 on every page)

1. `tail -50 /var/www/pterodactyl/storage/logs/laravel-$(date +%F).log`
2. Usual suspects: broken blade include, broken routes file, PHP syntax error
   in a synced file (`php -l` the file named in the log).
3. Roll back the offending file in the installed tree from git and re-sync.
4. Do **not** delete panel assets wholesale to "reset" — see 3.7.

### 3.7 What is safe to delete vs not

| Safe | Not safe |
|---|---|
| old `public/assets/*.js` (webpack `clean` handles it) | `public/assets/extensions/` symlinks |
| `.blueprint/dev` (recreated by sync.sh) | `.blueprint/extensions/modpackinstaller` (recreated only by blueprint install) |
| webpack cache `.build-cache.json` | panel `node_modules` (slow to restore; do it knowingly) |
| browser cache | `/var/lib/pterodactyl/modpack-installer/installs.json` (user data!) |

---

## 4. Quick diagnostic script

Paste this when something feels wrong; the output answers 90% of questions:

```bash
echo "=== repo git state ==="; git -C /home/tanmay/Code/modpack-installer status --short
echo "=== CSS import == root.css? ==="
diff <(sha256sum < /home/tanmay/Code/modpack-installer/root.css) \
     <(sha256sum < /var/www/pterodactyl/resources/scripts/blueprint/css/imported/modpackinstaller.css) \
  && echo MATCH || echo STALE
echo "=== newest bundles ==="; ls -lt /var/www/pterodactyl/public/assets/bundle.*.js | head -2
echo "=== TSX == repo? ==="
diff <(sha256sum < /home/tanmay/Code/modpack-installer/components/ModpackInstaller.tsx) \
     <(sha256sum < /var/www/pterodactyl/.blueprint/extensions/modpackinstaller/components/ModpackInstaller.tsx) \
  && echo MATCH || echo STALE
echo "=== components symlink ==="; readlink -f /var/www/pterodactyl/resources/scripts/blueprint/extensions/modpackinstaller
echo "=== assets ownership ==="; stat -c '%U %n' /var/www/pterodactyl/public/assets/bundle.*.js | tail -1
echo "=== loaded PHP copy == repo? ==="
diff -rq /home/tanmay/Code/modpack-installer/app \
  /var/www/pterodactyl/app/BlueprintFramework/Extensions/modpackinstaller >/dev/null 2>&1 \
  && echo MATCH || echo "STALE — see DEV.md 2.0"
echo "=== routes copy == repo? ==="
diff -q /home/tanmay/Code/modpack-installer/client.php \
  /var/www/pterodactyl/routes/blueprint/client/modpackinstaller.php >/dev/null 2>&1 \
  && echo MATCH || echo "STALE — see DEV.md 2.0"
echo "=== routes registered? ==="; cd /var/www/pterodactyl && php artisan route:list 2>/dev/null | grep -c modpackinstaller
echo "=== today's log errors ==="; grep -ci error /var/www/pterodactyl/storage/logs/laravel-$(date +%F).log 2>/dev/null || echo 0
```

Interpretation:
- `CSS STALE` → run `./build.sh`.
- `TSX STALE` → run `./sync.sh` then `./build.sh`.
- PHP copy `STALE` → rsync per DEV.md 2.0 (panel loads a copy, not the repo).
- routes copy `STALE` → rsync per DEV.md 2.0, then `route:clear`.
- bundle older than your last edit → build didn't run / failed; check 1.3/1.4.
- `routes 0` → routes copy stale or controller autoload broken; see 2.1/2.2.

---

## 5. Golden rules

1. `./build.sh` is the only deploy. If you deviate, you will ship stale CSS —
   this has already happened twice.
2. Never trust exit code 0; verify the bundle hash changed and contains your
   newest CSS marker.
3. Verify with `sha256sum`/`diff`, not timestamps.
4. Passwordless sudo = `rsync`, `chown`, `rm` only. Anything else hangs.
5. Never leave `public/assets` or the data dir owned by anything but
   `www-data`.
6. Don't paste `.env`, logs, or install records anywhere public.
7. Frontend changes → run the tsc check (1.6). Backend changes → `php -l` +
   manual test suite (2.8). Then commit (`type: description`).
