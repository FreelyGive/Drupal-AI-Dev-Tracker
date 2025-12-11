# CLAUDE-BRANCH.md

## PURPOSE OF THIS FILE

**IMPORTANT FOR FUTURE AGENTS**: This file documents the current branch's purpose, progress, and implementation plans.

- **DO NOT REMOVE THIS SECTION** - it helps orient future agents to the branch context
- **Current Branch**: `planning/improvements-to-projects`
- **Branch Goal**: Fix settings.php and config_sync_directory conflict between DDEV (local) and DevPanel (live)
- **Update the branch name above** when working on a different branch
- **See Also**: `Codex_Branch.md` - Contains feedback from ChatGPT Codex on the work done in this branch

---

## THE PROBLEM

### Summary
When trying to set up the site locally with `ddev drush site:install --existing-config`, it fails because:
1. `settings.php` is gitignored (required because DevPanel makes it read-only on live)
2. DDEV creates a default `settings.php` that sets `config_sync_directory` to `sites/default/files/sync`
3. The actual config files are in `/config/sync/` (outside web root)
4. Drush can't find `core.extension.yml` in the wrong location

### Current File Structure
```
/config/sync/               <- Where config actually lives (741 files)
/web/sites/default/
  settings.php              <- DDEV-created, gitignored, points to wrong config dir
  settings.ddev.php         <- DDEV-generated, sets config_sync_directory to 'sites/default/files/sync'
/.devpanel/
  settings.devpanel.php     <- DevPanel's settings (sets config_sync_directory to '../config/sync')
```

### Key Settings Differences

| Setting | DDEV (settings.ddev.php) | DevPanel (settings.devpanel.php) |
|---------|-------------------------|----------------------------------|
| `config_sync_directory` | `sites/default/files/sync` | `../config/sync` |
| `hash_salt` | Hardcoded value | `file_get_contents(__DIR__ . '/salt.txt')` |
| `file_private_path` | Not set | `../private` |
| Database | Hardcoded db/db/db | Environment variables |

### Constraints
1. **Cannot commit settings.php** - DevPanel overwrites it and makes it read-only
2. **Cannot commit from live server** - DevPanel's settings.php would break local dev
3. **Must work on fresh clone** - New developers need to be able to set up without manual intervention
4. **Config must deploy cleanly** - `drush cim` must work on both local and live

---

## USER PLAN

(Claude can put its planning in Claude Plan)

Goals:
- Fix the local site setup so `ddev drush site:install --existing-config` works
- Maintain compatibility with DevPanel deployment
- Avoid manual steps when setting up from a fresh clone
- Document the solution clearly

---

## CLAUDE PLAN

### Analysis

The root cause is that DDEV's auto-generated `settings.ddev.php` hardcodes the wrong `config_sync_directory`:
```php
// In settings.ddev.php (line 36-38)
if (empty($settings['config_sync_directory'])) {
  $settings['config_sync_directory'] = 'sites/default/files/sync';  // WRONG!
}
```

This only applies "if empty" - so if we set it BEFORE settings.ddev.php is included, it would work. But `settings.ddev.php` is included at the END of `settings.php`.

### Solution Options

#### Option A: Custom DDEV Post-Start Hook
Create a DDEV hook that patches settings.php after DDEV creates it.
- **Pros**: Automated, works on fresh clone
- **Cons**: Fragile if DDEV changes its template, hook runs every `ddev start`

#### Option B: Custom settings.ddev.php Override
DDEV allows customizing settings.ddev.php by removing the `#ddev-generated` comment.
- **Pros**: Clean, documented DDEV approach
- **Cons**: Need to maintain custom file, conflicts possible

#### Option C: DDEV config.yaml `disable_settings_management: true`
Tell DDEV not to create settings files, then provide our own.
- **Pros**: Full control over settings
- **Cons**: Lose DDEV's auto-configuration benefits

#### Option D: settings.local.php (RECOMMENDED)
Create a `settings.local.php` that DDEV can include, and modify the settings.php pattern.
- **Pros**: Standard Drupal pattern, clean separation
- **Cons**: Requires initial settings.php modification

#### Option E: Fix settings.ddev.php directly
Modify the DDEV-generated settings.ddev.php to use the correct path.
- **Pros**: Simple, direct fix
- **Cons**: Will be overwritten if DDEV regenerates it (need to remove #ddev-generated marker)

### Recommended Solution: Simple DDEV Post-Start Hook (Option A - Simplified)

Append the correct `config_sync_directory` to the end of `settings.php` via a DDEV hook.

This is the standard approach recommended by Drupal/DDEV developers:
- Simple one-liner that appends to settings.php
- Idempotent (checks if already set before adding)
- Works on fresh clone with no manual steps
- Runs after DDEV creates settings.php, so our line comes last and overrides
- Separate config file keeps it organized and clear

---

## Implementation Details

### File 1: `.ddev/config.settings.yaml`

```yaml
# DDEV hook to configure config_sync_directory for this project.
#
# Problem: DDEV defaults config_sync_directory to 'sites/default/files/sync'
# but this project stores config in '/config/sync/' (outside web root).
#
# Solution: Append the correct setting to the end of settings.php.
# This runs after DDEV creates settings.php, so our line takes precedence.
#
# See: https://github.com/ddev/ddev/issues/3549

hooks:
  post-start:
    - exec: |
        if ! grep -q "config_sync_directory" web/sites/default/settings.php; then
          echo "\$settings['config_sync_directory'] = '../config/sync';" >> web/sites/default/settings.php
        fi
```

That's it. One file, ~15 lines including comments.

---

## How It Works

1. **Fresh clone**: No `settings.php` exists
2. **Run `ddev start`**:
   - DDEV creates `settings.php` from its template
   - DDEV creates `settings.ddev.php` (sets config_sync_directory to wrong path, but only "if empty")
   - Our hook checks if `config_sync_directory` is already in settings.php
   - If not, appends our correct path to the end of settings.php
3. **Run `ddev drush site:install --existing-config`**:
   - Drupal loads settings.php
   - Our appended line sets `config_sync_directory` to `../config/sync`
   - settings.ddev.php include happens, but its "if empty" check sees it's already set
   - Drush finds config in `../config/sync/` ✓

**Key insight**: By appending to the END of settings.php, our setting is evaluated BEFORE settings.ddev.php's "if empty" check, so DDEV's default never applies.

---

## Implementation Tasks

1. [ ] Create `.ddev/config.settings.yaml`
2. [ ] Test: `ddev stop && ddev start`
3. [ ] Verify settings.php has the appended line
4. [ ] Test: `ddev drush site:install --existing-config --account-pass=admin`
5. [ ] Update README.md with setup instructions
6. [ ] Update CLAUDE.md if needed

---

## Execution Status

- [ ] Phase 1: Create the config file
- [ ] Phase 2: Test site installation
- [ ] Phase 3: Update documentation
- [ ] Phase 4: Commit and verify

---

## Notes

- The `settings.devpanel.php` file in `/.devpanel/` is already correct and doesn't need modification
- DevPanel has its own build process that handles settings.php on the live server
- The `salt.txt` file in `.devpanel/` is gitignored (correctly) for security
