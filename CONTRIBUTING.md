# Contributing to Addon Manager

![Addon Manager](assets/banner.webp)

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

1. **Blueprint developer tree** (recommended for development) — enable
   Blueprint's developer mode **once** in the panel: **Admin → Extensions →
   Blueprint → set `developer` to `true`** (confirm with `blueprint -info` →
   `Developer: true`). Then clear and clone the repo into
   `/var/www/pterodactyl/.blueprint/dev`. `mi build` detects this layout,
   regenerates `root.css`, syncs the install-time files and runs Blueprint's
   native `blueprint -build`; `mi watch` rebuilds on every change.
2. **Repo checkout anywhere else** (e.g. your home dir) — `mi build` deploys via
   the rsync pipeline (`tools/build.sh`) into `/var/www/pterodactyl`. No
   developer mode needed, but every deploy is a full sync.

Either works; pick one. Only layout 1 requires Blueprint developer mode. See
`INSTALLATION.md` for full panel setup.

`sudo mi remove` uninstalls the extension from the panel and deletes everything
`mi install` / `mi build` published, without touching system packages, PHP
extensions, Blueprint or your checkout — handy for testing a first-time install
(`mi remove --yes && mi build`) or leaving the panel clean. Use `mi remove -n`
to preview it.

## The `mi` workflow

| Command | What it does |
|---|---|
| `mi test [filter]` | Run the manual test suites (`tests/manual/*.php`) |
| `mi lint [--fix]` | PHP syntax + blade compile + style + TS typecheck |
| `mi audit [path...]` | Static check for dead catches (unimported/unknown types) |
| `mi check` | lint + audit + test — **must pass before every push** |
| `mi build` | Deploy to your panel |
| `mi remove` | Uninstall it again, leaving dependencies (and your clone) in place |
| `mi smoke` | Post-deploy health check (page/API/CSS/data dir) |
| `mi release` | Bump version from commits + write CHANGELOG.md |
| `mi hooks` | Pre-push gate: status, `install` / `remove`, `checks on\|off`, `bump on\|off` |
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
| `mi remove -n` | Print what an uninstall would delete, change nothing |
| `--no-color`, `NO_COLOR=1` | Plain output for logs and CI |

## Commit convention

Commits follow [conventional commits](https://www.conventionalcommits.org)
and **directly drive the version number**, whether you bump by hand (`mi update`)
or let the pre-push hook do it:

```
feat: <what>      # adds a feature             → minor +1   0.47.0 → 0.48.0
fix: <what>       # fixes a bug                → patch +1   0.48.0 → 0.48.1
chore: <what>     # tooling, docs, refactors   → major +1   0.48.1 → 1.0.0
```

The type is matched case-insensitively, and a scope is allowed
(`feat(ui): …`, `FIX(api): …`). Anything else — `docs:`, `refactor:`, a merge
commit — moves no version.

A bump always updates `conf.yml`, `components/utils/constants.ts` and
`CHANGELOG.md` together; never bump versions by hand in one place, they live
in two and will drift. `mi release` is the other way to cut a release: it sets
the version from the feat/fix **counts over the whole history**
(e.g. 46 feat + 22 fix commits → `0.46.22`) and writes a grouped changelog
section.

## The pre-push hook

`mi hooks install` symlinks `tools/hooks/pre-push` into `.git/hooks`, and every
push then runs two independent stages:

1. **checks** — `mi check` (lint + audit + all suites), the same gate CI runs
2. **version bump** — one bump per new commit, taken from its message

Both are configurable per clone, and both are stored in `.git/config` so they
never show up in a diff:

| Command | Effect |
|---|---|
| `mi hooks` | Show the hook state and the current toggles |
| `mi hooks checks off` | Stop running the checks before a push |
| `mi hooks bump off` | Stop versioning pushes from commit messages |
| `mi hooks remove` | Unlink the hook entirely |

One-off escapes, without changing any config:

```
git -c mi.hooks.checks=false push     # this push skips the checks
MI_SKIP_CHECKS=1 git push             # same, via the environment
git -c mi.hooks.bump=false push       # this push skips versioning
MI_SKIP_BUMP=1 git push               # same, via the environment
```

When the hook bumps, it writes and **stages** the bump and stops the push, so
the version travels with the commit that caused it:

```
git commit --amend --no-edit && git push   # fold it into that commit
git commit -m 'chore: bump version'        # or keep it as its own commit
```

It will not version the same commit twice: an amended tip that already carries
the version, a re-pushed commit, and a commit that only writes the three
version files are all left alone. A ref with no remote counterpart (a first
push, or a brand new branch) is versioned from its tip commit only, so pushing
a long history does not bump it commit by commit.

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
