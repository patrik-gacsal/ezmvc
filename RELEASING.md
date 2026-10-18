# Releasing ezmvc

Versioning is driven by **[Conventional Commits](https://www.conventionalcommits.org)** +
**[cocogitto](https://docs.cocogitto.io/)** (`cog`), with a manual release trigger.

**The git tag is the single source of truth for the framework version.** Nothing in the
source holds a copy of it, and `composer.json` has no `version` field (Packagist derives it
from the tag). `Config::VERSION` is the *downstream app's* product version and is never
touched by any of this.

---

## One-time setup (per machine)

`cog` is a single self-contained binary — no runtime needed. It is **not** on winget, so grab
the release binary:

1. Download the Windows build from
   <https://github.com/cocogitto/cocogitto/releases> (`cocogitto-<ver>-x86_64-pc-windows-msvc.tar.gz`),
   extract `cog.exe`, and put it in a folder that's on your `PATH`
   (e.g. `%LOCALAPPDATA%\Microsoft\WindowsApps`, or a `~/bin` you've added to PATH).
   *(Alternatively, if you ever install Rust: `cargo install cocogitto`.)*
2. Confirm it's found: `cog --version`
3. From the repo root, install the git hooks:
   ```
   cog install-hook --all
   ```
   This installs a **commit-msg** hook that rejects any message which isn't a valid
   Conventional Commit. (The hook calls `cog`, which is why step 1 must come first.)

---

## Day-to-day: branching & commits (GitHub Flow)

- Every change goes on its own branch off `main`: `git switch -c feat/short-name`.
- Merge back into `main` **preserving individual commits** (merge commit or rebase-merge —
  not squash), because the changelog is built from the individual commit messages.
- Merge commits themselves never become changelog entries (`ignore_merge_commits`).

### Commit message format

```
<type>[optional scope]: <description>

[optional body]

[optional footer(s)]
```

| Type | In changelog? | Version effect | Use for |
|------|:---:|---|---|
| `feat`     | ✅ Features   | minor bump | a new capability |
| `fix`      | ✅ Bug Fixes  | patch bump | a fix to released behaviour |
| `perf`     | ✅            | patch bump | performance improvement |
| `revert`   | ✅            | patch bump | reverting a previous change |
| `chore`    | ❌ hidden     | none | tidy-up, deps, "fixing a feat that isn't released yet" |
| `refactor` | ❌ hidden     | none | internal restructure, no behaviour change |
| `docs`     | ❌ hidden     | none | docs / comments |
| `test`     | ❌ hidden     | none | tests |
| `ci`/`build`/`style` | ❌ hidden | none | pipeline / formatting |

**Breaking changes** — append `!` after the type or add a `BREAKING CHANGE:` footer:
```
feat!: drop ApiController::ok() in favour of Response factories
```
Pre-1.0 this bumps the **minor** (0.x); post-1.0 it bumps the **major**.

> Tip for in-branch corrections: `git commit --fixup=<sha>` then
> `git rebase -i --autosquash` before merging — the noise never reaches `main`.

---

## Cutting a release (manual)

A release is a tag on `main` and nothing else: `cog.toml` sets `disable_bump_commit` and
`disable_changelog`, so `cog bump` creates no commit and `main` only ever changes through
pull requests.

From an up-to-date `main`:

```bash
git switch main
git pull

cog bump --auto          # computes the next version from the commits since the last tag
                         # and tags the current commit vX.Y.Z

git push origin vX.Y.Z   # publish the tag
```

- Preview first without changing anything: `cog bump --auto --dry-run` (prints the version it
  would pick), or `cog changelog` (shows the unreleased entries).
- Force a specific version instead of `--auto`: `cog bump --version 1.0.0`
  (or `--major` / `--minor` / `--patch`).

### After the tag is pushed

- Create the **GitHub Release** for the tag, with the output of `cog changelog --at vX.Y.Z`
  as the body. The GitHub Releases page is the project's changelog.
- **Packagist** picks up the new version automatically from the tag — no `composer.json`
  edit needed.

---

## Version numbering

- Starts at **v0.1.0** (pre-1.0 signals the API may still move; breaking changes stay minor).
- Cut **v1.0.0** deliberately, when you want to signal a stable public API — from then on,
  `feat` → minor and breaking → major, per semver.
