# ML Link Auditor — Permanent Agent Rules

## Environment

This project does NOT use Champ.

Never search for Champ.
Never inspect Champ configuration.
Never ask about Champ.
Never attempt to run Champ.
Never use Champ as part of build, validation, packaging, tests, or deployment.

This project uses Docker for safe build/validation/packaging when execution is required.

## Execution Rules

* Use Docker-based execution only when commands are needed.
* Do not use local PowerShell, CMD, bash/sh, wp-cli, or host-level package tools.
* Do not assume Champ exists.
* Do not waste time or tokens looking for Champ.
* If a build/test/package step is needed, use Docker or the existing repository workflow only.

## WordPress Plugin Rules

* Work only on the existing plugin root.
* Keep slug unchanged: `ml-link-auditor`
* Keep root folder unchanged: `ml-link-auditor/`
* Keep main file unchanged: `ml-link-auditor/ml-link-auditor.php`
* Final ZIP must install as UPDATE over the existing plugin.
* Never create a parallel plugin.
* Never rename the root folder.
* Never package from a temporary renamed folder.

## Versioning Rules

Synchronize version in:

* plugin header (`Version:`)
* `MLLA_VERSION` constant
* `readme.txt` Stable tag
* changelog (top entry)

## Scope Control

Only modify files required by the task.
Do not inspect unrelated systems unless needed.
Do not refactor unrelated code.
Do not touch the scan engine, AJAX handlers, quarantine, license manager, or updater unless the task explicitly requires it.

---

## Release Workflow

Every change that ships a new version follows the same flow. **No exceptions.**

### 1. Backup first (local)

```powershell
cd scripts
.\backup.ps1 -Reason "pre-release-vX.Y.Z"
```

This snapshots `ml-link-auditor/` into `backups/<timestamp>_<reason>/`.
`backups/` is git-ignored but recoverable from the OS Recycle Bin via `mavis-trash`.

### 2. Update version in **all** sync points

The version MUST be identical in:

| Location                                  | Field                              |
|-------------------------------------------|------------------------------------|
| `ml-link-auditor/ml-link-auditor.php`    | Plugin header `Version:`           |
| `ml-link-auditor/ml-link-auditor.php`    | `define('MLLA_VERSION', ...)`      |
| `ml-link-auditor/readme.txt`             | `Stable tag:`                      |
| `CHANGELOG.md`                            | New top entry                      |
| `ml-link-auditor/readme.txt`             | `= X.Y.Z =` block                  |
| Git tag                                   | `vX.Y.Z`                           |
| Release asset (ZIP)                       | `ml-link-auditor-vX.Y.Z.zip`       |

Validate before doing anything else:

```powershell
.\sync-version.ps1
```

### 3. Build the ZIP

```powershell
.\package.ps1 -Version X.Y.Z
# -> dist/ml-link-auditor-vX.Y.Z.zip + SHA-256
```

ZIP MUST:

- Have `ml-link-auditor/` as the **root** folder.
- Exclude `.git/`, `node_modules/`, `vendor/`, `*.log`, `.DS_Store`, `Thumbs.db`.
- Be installable via `WP Admin > Plugins > Upload` as an **update** over the existing plugin.

### 4. Commit, tag, push

```powershell
git add -A
git -c user.name=mlopesdesign -c user.email=mlopesdesign@gmail.com commit -m "release: vX.Y.Z"
git -c user.name=mlopesdesign -c user.email=mlopesdesign@gmail.com tag -a vX.Y.Z -m "ML Link Auditor vX.Y.Z"
git push origin main --follow-tags
```

Or use the full orchestrator:

```powershell
.\release.ps1 -Version X.Y.Z -Title "ML Link Auditor vX.Y.Z - <resumo>" -Notes "<markdown changelog>"
```

### 5. CI auto-publishes the Release

`.github/workflows/release.yml` listens for tag pushes matching `v*` and:

1. Runs `php -l` and `node --check` on every file.
2. Re-runs `sync-version.sh` and `package.sh`.
3. Computes SHA-256 + size.
4. Creates a GitHub Release with the ZIP as the only asset and `generate_release_notes: true`.

If the CI build fails, **fix the cause** before re-pushing the tag. Don't `git tag -f`.

### Auto-update on customer sites

The updater logic inside `ml-link-auditor.php` (do not touch without explicit request) calls
`https://api.github.com/repos/mlopesdesign/ml-link-auditor/releases/latest`
via the License Hub (`license.mlopesdesign.com.br`) and exposes the asset as a native WordPress update.

The asset name matched in this order:

1. `ml-link-auditor-vX.Y.Z.zip` (lowercase, dots — current convention)
2. `ml-link-auditor-vX_Y_Z.zip` (legacy underscore variant)

Always ship the first one.

---

## What this agent should NOT do

- Don't rename `ml-link-auditor/`, the main file, or the slug.
- Don't touch the scan engine, AJAX handlers, quarantine, license manager, or updater unless the task explicitly says so.
- Don't introduce jQuery, React, or any framework dependency (jQuery is already used — keep it that way).
- Don't commit `*.zip`, `backups/`, `dist/`, or scratch folders.
- Don't rebase or force-push `main` (unless rebuilding history explicitly approved).
- Don't create parallel plugins.