# Contributing to Addon Manager

Thanks for your interest in contributing! This guide gets you from clone to
merged PR with minimal friction.

## Setup

You need a Pterodactyl panel with [Blueprint](https://blueprint.build) to run
the extension locally.

```bash
git clone https://github.com/IndoGeek/addon-manager.git
cd addon-manager
sudo ln -sf "$PWD/mi" /usr/local/bin/mi   # one-time: global `mi` command
```

Two supported layouts:

- **Repo checkout** (anywhere, e.g. your home dir) — `mi build` rsyncs into
  `/var/www/pterodactyl`.
- **Panel dev tree** (`/var/www/pterodactyl/.blueprint/dev/`) — `mi build`
  detects this and uses Blueprint's native developer-build instead.

Either works; pick one. See `INSTALLATION.md` for full panel setup.

## The `mi` workflow

| Command | What it does |
|---|---|
| `mi test [filter]` | Run the manual test suites (`tests/manual/*.php`) |
| `mi lint [--fix]` | PHP syntax + blade compile + style + TS typecheck |
| `mi audit [path...]` | Static check for dead catches (unimported/unknown types) |
| `mi check` | lint + audit + test — **must pass before every push** |
| `mi build` | Deploy to your panel |
| `mi smoke` | Post-deploy health check (page/API/CSS/data dir) |
| `mi release` | Bump version from commits + write CHANGELOG.md |
| `mi install-hooks` | Install the git pre-push gate (runs `mi check` before a push) |
| `mi coverage` | Show app classes with no test coverage (`--min <pct>` gates it) |
| `mi css` | Regenerate `root.css` (`--check` fails when it is stale) |
| `mi db:reset` | Wipe install records/history/cache locally (`--dry-run` first) |
| `mi version` | Show the extension version + identifier from `conf.yml` |
| `mi watch` | Rebuild automatically on file changes |

Useful flags (run `mi <command> --help` for the rest):

| Flag | Effect |
|---|---|
| `mi test -x` | Stop at the first failing suite |
| `mi test -v` | Full output for failing suites |
| `mi test -l` | List the suites that would run |
| `mi test -q` / `--json` | Failures + summary only / machine-readable summary |
| `mi lint --only syntax` | Run one lint stage (`syntax`, `blade`, `style`, `ts`) |
| `mi check --fast` | lint + audit without the suites |
| `mi build -n` | Print the detected layout, source, panel and steps |
| `--no-color`, `NO_COLOR=1` | Plain output for logs and CI |

## Commit convention

Commits follow [conventional commits](https://www.conventionalcommits.org)
and **directly drive the version number**:

```
feat: <what>      # adds a feature
fix: <what>       # fixes a bug
chore: <what>     # tooling, docs, refactors — does not affect version
```

The version is `0.<feat-count>.<fix-count>` over the whole history
(e.g. 46 feat + 22 fix commits → `0.46.22`). `mi release` computes this for
you and updates `conf.yml`, `components/utils/constants.ts`, and
`CHANGELOG.md` together — never bump versions by hand, they live in two
places and will drift.

## Before opening a PR

1. `mi check` passes (lint + audit + all suites)
2. New features ship with a test suite in `tests/manual/`
3. UI changes tested against a real panel, not just typechecked
4. Commits follow the convention above

## Testing notes

Tests are plain PHP scripts in `tests/manual/` — no framework. Each file is
self-contained and exits non-zero on failure. Add one per new service or
behavior; look at `archive-validator-test.php` for the pattern.

`mi audit` is the static check `mi lint` cannot do: `php -l` will not tell you
that `catch (Throwable)` in a namespaced file without `use Throwable;`
resolves to a class that does not exist, so the clause never matches and the
error it was meant to handle escapes. It reports unimported globals, catches
of classes that live in another namespace, and imports with no file behind
them — fully qualified `catch (\Throwable)` and file-level imports are fine.

`tests/manual/audit-tool-test.php` covers the detector itself, so it cannot
quietly stop detecting.

The blade compile check (`mi lint`) compiles `view.blade.php` with the real
panel Blade compiler — if you touch the admin view, this is what protects
you from the directives-in-attributes class of 500s.
