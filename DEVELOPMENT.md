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
php tests/manual/mock-catalog-provider-test.php
php tests/manual/modrinth-catalog-provider-test.php
php tests/manual/catalog-service-test.php
```

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
4. `./sync.sh` and `blueprint -build` (deployment compatibility), then lint
   the deployed controller under `/var/www/pterodactyl/.blueprint/dev`.
