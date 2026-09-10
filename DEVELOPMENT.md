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
