# Development Guide

## Project Rules

1. The Pterodactyl installation is not the project repository.
2. The Git repository is the source of truth.
3. Blueprint is used for extension development and deployment.
4. Do not modify Pterodactyl core unless absolutely necessary.
5. Prefer Blueprint APIs and extension mechanisms.
6. Do not depend on another Blueprint extension.
7. Avoid assumptions about panel themes.
8. Namespace extension assets, routes, styles and JavaScript.
9. Keep the user interface separate from installation logic.
10. Keep modpack providers separate from the installation engine.
11. Security takes priority over convenience.
12. Test every meaningful change before committing.
13. Every successful development milestone receives a Git commit.
14. Never commit secrets, credentials or production data.
15. Do not use production server data as test fixtures.

## Git Checkpoints

Every successful phase or meaningful working milestone should result in
a commit that leaves the working tree clean.

Commit messages should use:

    type: description

Examples:

    feat: add modpack provider abstraction
    fix: prevent archive path traversal
    test: add provider resolution tests
    chore: initialize project foundation

## Testing

Manual integration tests live in `tests/manual/` and run against the real
classes (no Pterodactyl boot required). Each file prints `PASS` per
assertion and exits non-zero on the first failure:

```bash
for f in tests/manual/*-test.php; do php "$f"; done
```

Catalog manual tests (hermetic - they never contact Modrinth or CurseForge):

```bash
php tests/manual/catalog-query-test.php
php tests/manual/catalog-version-query-test.php
php tests/manual/mock-catalog-provider-test.php
php tests/manual/modrinth-catalog-provider-test.php
php tests/manual/modrinth-catalog-versions-test.php
php tests/manual/mock-catalog-versions-test.php
php tests/manual/catalog-service-test.php
php tests/manual/catalog-versions-service-test.php
php tests/manual/curseforge-catalog-provider-test.php
```

Exact-version (pinned source) resolution is covered hermetically:

```bash
php tests/manual/modrinth-version-resolution-test.php
```

Installed-modpack records, the ownership store, and the file-only uninstall
remover are covered hermetically:

```bash
php tests/manual/install-record-test.php
php tests/manual/install-record-store-test.php
php tests/manual/ownership-remover-test.php
```

The record store writes to `MODPACK_INSTALLER_DATA_DIR` (default
`/var/lib/pterodactyl/modpack-installer`); tests point it at a fresh temp
directory. The store's lock + atomic-rename guarantees are exercised by
reopening the store and by concurrency-free corruption checks.

Standalone (non-extension) tests may `require` the classes directly;
tests that exercise the extension namespace register an `spl_autoload_register`
callback that maps `Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\...`
onto `app/`. Network-dependent assertions (the downloader's live success and
redirect path, see ARCHITECTURE.md) are deferred to deployment-time
validation rather than the hermetic suite.

## Verification checklist

Run every meaningful change against:

1. All manual tests (`tests/manual/*-test.php`).
2. `php -l` on every tracked `.php` file.
3. `git diff --check` (whitespace hygiene).
4. If the frontend changed: a strict TypeScript check of
   `components/ModpackInstaller.tsx` (e.g. `tsc --noEmit` with
   `jsx: react-jsx` and `@types/react`), since the panel build is deferred to
   deployment.
5. Deployment compatibility, then build and deploy the frontend:
   ```bash
   ./build.sh
   ```
   `build.sh` performs the full pipeline:
   1. `./sync.sh` — rsyncs the repository into
      `/var/www/pterodactyl/.blueprint/dev` (owned by `www-data`).
   2. Copies `root.css` into
      `/var/www/pterodactyl/resources/scripts/blueprint/css/imported/modpackinstaller.css`,
      which the panel's webpack build imports through
      `resources/scripts/index.tsx` -> `blueprint/css/extensions.css`.
      This step is essential: the imported CSS file is a plain copy, not a
      symlink, so a stale copy ships old styles to the browser even though
      the build succeeds.
   3. Temporarily takes ownership of `public/assets` and
      `.build-cache.json` (they are `www-data`-owned; only `rsync`,
      `chown`, and `rm` are passwordless in sudoers), then runs
      `NODE_OPTIONS=--openssl-legacy-provider yarn run build:production`
      inside the panel directory, and restores `www-data` ownership.

   `blueprint -build` is not used because `sudo` is unavailable on the
   panel; assets are built directly and the extension files, controller,
   and routes are synced into the running tree manually.

### Verifying a frontend deploy actually went live

The bundle filename embeds a content hash, so a real rebuild changes it
(e.g. `bundle.3fafd6f6.js`). After building:

```bash
# The loaded bundle must contain your newest CSS marker:
grep -o 'your-new-rule{[^}]*}' /var/www/pterodactyl/public/assets/bundle.*.js

# The imported CSS copy must match the repository source:
diff <(sha256sum < root.css) \
  <(sha256sum < /var/www/pterodactyl/resources/scripts/blueprint/css/imported/modpackinstaller.css)
```

The bundle hash change is the cache-busting mechanism: browsers fetch the
new URL automatically. If the hash did not change, nothing was rebuilt.
