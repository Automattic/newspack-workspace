#!/usr/bin/env bash
#
# test-restore-release-artifacts.sh
#
# Self-proving spec for restore_release_artifacts (lib.sh). Simulates the state
# left by a clean "legacy wins" merge where a late legacy hotfix release stamped
# a regressed version into the version-bearing files while also carrying a real
# source fix and a real new dependency. Asserts the helper pins the release
# stamps back to the monorepo (HEAD) side without discarding the real changes,
# across all three release-stamped shapes in the manifest: a classic theme, a
# plugin (main PHP file + version constant), and the block theme.
#
# Run: bash bin/migration/test-restore-release-artifacts.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
. "$SCRIPT_DIR/lib.sh"

WORK=$(mktemp -d -t restore-release-artifacts-XXXXXX)
trap 'rm -rf "$WORK"' EXIT
cd "$WORK"

git init -q
git config user.email t@t.t
git config user.name t

THEME=themes/newspack-theme
PLUGIN=plugins/newspack-blocks
BLOCK=themes/newspack-block-theme
mkdir -p "$THEME/newspack-theme/sass/blocks" "$THEME/newspack-joseph/sass" \
         "$PLUGIN" "$BLOCK/src/scss"

# ---------------------------------------------------------------------------
# Monorepo (HEAD) state: ahead on version, owns the changelogs and stamps.
# ---------------------------------------------------------------------------
cat > "$THEME/package.json" <<'JSON'
{
	"name": "newspack-theme",
	"version": "2.23.1",
	"dependencies": {
		"keep-me": "^1.0.0"
	}
}
JSON
printf 'Theme Name: Newspack\nRequires at least: 6.9\nTested up to: 7.0\nVersion: 2.23.1\n' \
  > "$THEME/newspack-theme/sass/theme-description.scss"
printf 'Theme Name: Joseph\nVersion: 2.23.1\n' \
  > "$THEME/newspack-joseph/sass/theme-description.scss"
printf '# newspack-theme [2.23.1] (monorepo)\n' > "$THEME/CHANGELOG.md"
printf '.block { color: red; }\n' > "$THEME/newspack-theme/sass/blocks/_blocks.scss"

cat > "$PLUGIN/package.json" <<'JSON'
{
	"name": "@automattic/newspack-blocks",
	"version": "4.26.3"
}
JSON
cat > "$PLUGIN/newspack-blocks.php" <<'PHP'
<?php
/**
 * Plugin Name: Newspack Blocks
 * Version:     4.26.3
 */
define( 'NEWSPACK_BLOCKS__VERSION', '4.26.3' );
function newspack_blocks_init() { return true; }
PHP
printf '# newspack-blocks [4.26.3] (monorepo)\n' > "$PLUGIN/CHANGELOG.md"

cat > "$BLOCK/package.json" <<'JSON'
{
	"name": "newspack-block-theme",
	"version": "1.28.1"
}
JSON
printf '/*!\nTheme Name: Newspack Block Theme\nVersion: 1.28.1\n*/\n' \
  > "$BLOCK/src/scss/_theme-description.scss"
cat > "$BLOCK/functions.php" <<'PHP'
<?php
/**
 * Newspack Block Theme functions.
 *
 * Version: 1.28.1
 */
function newspack_block_theme_setup() { return true; }
PHP

git add -A
git commit -qm "monorepo state"

# ---------------------------------------------------------------------------
# Legacy state landed by a clean merge: regressed stamps + real fixes + a real
# new dependency. Staged as if -Xtheirs brought it in with no conflict.
# ---------------------------------------------------------------------------
cat > "$THEME/package.json" <<'JSON'
{
	"name": "newspack",
	"version": "2.22.3",
	"dependencies": {
		"keep-me": "^1.0.0",
		"legit-new-dep": "^2.0.0"
	}
}
JSON
printf 'Theme Name: Newspack\nRequires at least: 6.7\nTested up to: 6.8\nVersion: 2.22.3\n' \
  > "$THEME/newspack-theme/sass/theme-description.scss"
printf 'Theme Name: Joseph\nVersion: 2.22.3\n' \
  > "$THEME/newspack-joseph/sass/theme-description.scss"
printf '## [2.22.3] (legacy)\n# newspack-theme [2.23.1] (monorepo)\n' > "$THEME/CHANGELOG.md"
printf '.block { color: red; }\n.everlit-audio > * { width: 100%%; }\n' \
  > "$THEME/newspack-theme/sass/blocks/_blocks.scss"

cat > "$PLUGIN/package.json" <<'JSON'
{
	"name": "@automattic/newspack-blocks",
	"version": "4.25.0"
}
JSON
cat > "$PLUGIN/newspack-blocks.php" <<'PHP'
<?php
/**
 * Plugin Name: Newspack Blocks
 * Version:     4.25.0
 */
define( 'NEWSPACK_BLOCKS__VERSION', '4.25.0' );
function newspack_blocks_init() { return true; }
function newspack_blocks_legacy_fix() { return 'fixed'; }
PHP

printf '/*!\nTheme Name: Newspack Block Theme\nVersion: 1.27.0\n*/\n' \
  > "$BLOCK/src/scss/_theme-description.scss"
cat > "$BLOCK/functions.php" <<'PHP'
<?php
/**
 * Newspack Block Theme functions.
 *
 * Version: 1.27.0
 */
function newspack_block_theme_setup() { return true; }
function newspack_block_theme_legacy_fix() { return 'fixed'; }
PHP

git add -A

restore_release_artifacts "$THEME"
restore_release_artifacts "$PLUGIN"
restore_release_artifacts "$BLOCK"

fail=0
assert() { # <description> <expected> <actual>
  if [ "$2" = "$3" ]; then
    echo "  ok: $1"
  else
    echo "  FAIL: $1 — expected [$2], got [$3]"; fail=1
  fi
}
pjv() { node -e "process.stdout.write(String(JSON.parse(require('fs').readFileSync('$1','utf8'))['$2']))"; }
has() { grep -q "$2" "$1" && echo yes || echo no; }

echo "Classic theme — stamps restored, real changes kept:"
assert "package.json name"           "newspack-theme" "$(pjv "$THEME/package.json" name)"
assert "package.json version"        "2.23.1"         "$(pjv "$THEME/package.json" version)"
assert "theme header version"        "yes"            "$(has "$THEME/newspack-theme/sass/theme-description.scss" 'Version: 2.23.1')"
assert "theme header requires"       "yes"            "$(has "$THEME/newspack-theme/sass/theme-description.scss" 'Requires at least: 6.9')"
assert "child theme header version"  "yes"            "$(has "$THEME/newspack-joseph/sass/theme-description.scss" 'Version: 2.23.1')"
assert "CHANGELOG is monorepo's"     "no"             "$(has "$THEME/CHANGELOG.md" 'legacy')"
assert "source fix kept"             "yes"            "$(has "$THEME/newspack-theme/sass/blocks/_blocks.scss" 'everlit-audio')"
assert "new dependency kept"         "^2.0.0"         "$(node -e "process.stdout.write(JSON.parse(require('fs').readFileSync('$THEME/package.json','utf8')).dependencies['legit-new-dep'])")"

echo "Plugin — PHP header + version constant restored, real fix kept:"
assert "php Version header"          "yes"            "$(has "$PLUGIN/newspack-blocks.php" 'Version:     4.26.3')"
assert "php _VERSION constant"       "yes"            "$(has "$PLUGIN/newspack-blocks.php" "NEWSPACK_BLOCKS__VERSION', '4.26.3'")"
assert "old version fully gone"      "no"             "$(has "$PLUGIN/newspack-blocks.php" '4.25.0')"
assert "plugin source fix kept"      "yes"            "$(has "$PLUGIN/newspack-blocks.php" 'newspack_blocks_legacy_fix')"
assert "plugin package.json version" "4.26.3"         "$(pjv "$PLUGIN/package.json" version)"

echo "Block theme — underscore SCSS + functions.php restored, real fix kept:"
assert "_theme-description version"  "yes"            "$(has "$BLOCK/src/scss/_theme-description.scss" 'Version: 1.28.1')"
assert "functions.php Version"       "yes"            "$(has "$BLOCK/functions.php" 'Version: 1.28.1')"
assert "block source fix kept"       "yes"            "$(has "$BLOCK/functions.php" 'newspack_block_theme_legacy_fix')"
assert "block package.json version"  "1.28.1"         "$(pjv "$BLOCK/package.json" version)"

# ---------------------------------------------------------------------------
# Conflict path: drive an actual `git merge --no-commit -Xtheirs` where both
# sides changed the same release artifact, then prove the restore resolves it
# to the monorepo side (the scenarios above stage a post-merge state directly;
# this one exercises the real merge the integrate phase runs).
# ---------------------------------------------------------------------------
WORK2=$(mktemp -d -t restore-release-artifacts-merge-XXXXXX)
trap 'rm -rf "$WORK" "$WORK2"' EXIT
(
  cd "$WORK2"
  git init -q && git config user.email t@t.t && git config user.name t
  printf '# base\n' > CHANGELOG.md
  git add -A && git commit -qm base
  git branch legacy
  printf '# newspack-theme [2.23.1] (monorepo)\n' > CHANGELOG.md
  git commit -qam monorepo
  git checkout -q legacy
  printf '## [2.22.3] (legacy)\n# base\n' > CHANGELOG.md
  git commit -qam "legacy hotfix"
  git checkout -q main 2> /dev/null || git checkout -q master
  git merge --no-commit -Xtheirs legacy > /dev/null 2>&1 || true
  restore_release_artifacts .
)
echo "Conflict path — release artifact resolves to monorepo after a real merge:"
assert "CHANGELOG is monorepo's" "no" "$(has "$WORK2/CHANGELOG.md" 'legacy')"

[ "$fail" = 0 ] && echo "PASS" || { echo "FAIL"; exit 1; }
