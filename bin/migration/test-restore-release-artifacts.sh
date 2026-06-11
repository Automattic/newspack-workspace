#!/usr/bin/env bash
#
# test-restore-release-artifacts.sh
#
# Self-proving spec for restore_release_artifacts (lib.sh). Simulates the state
# left by a clean "legacy wins" merge where a late legacy hotfix release stamped
# a regressed version into the version-bearing files while also carrying a real
# source fix and a real new dependency. Asserts the helper pins the release
# stamps back to the monorepo (HEAD) side without discarding the real changes.
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

T=themes/newspack-theme
mkdir -p "$T/newspack-theme/sass/blocks" "$T/newspack-joseph/sass"

# --- Monorepo (HEAD) state: ahead on version, owns CHANGELOG + theme headers ---
cat > "$T/package.json" <<'JSON'
{
	"name": "newspack-theme",
	"version": "2.23.1",
	"dependencies": {
		"keep-me": "^1.0.0"
	}
}
JSON
printf 'Theme Name: Newspack\nRequires at least: 6.9\nTested up to: 7.0\nVersion: 2.23.1\n' \
  > "$T/newspack-theme/sass/theme-description.scss"
printf 'Theme Name: Joseph\nVersion: 2.23.1\n' \
  > "$T/newspack-joseph/sass/theme-description.scss"
printf '# newspack-theme [2.23.1] (monorepo)\n' > "$T/CHANGELOG.md"
printf '.block { color: red; }\n' > "$T/newspack-theme/sass/blocks/_blocks.scss"
git add -A
git commit -qm "monorepo state"

# --- Legacy state landed by a clean merge: regressed stamps + a real fix + a
#     real new dependency. Staged as if -Xtheirs brought it in with no conflict.
cat > "$T/package.json" <<'JSON'
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
  > "$T/newspack-theme/sass/theme-description.scss"
printf 'Theme Name: Joseph\nVersion: 2.22.3\n' \
  > "$T/newspack-joseph/sass/theme-description.scss"
printf '## [2.22.3] (legacy)\n# newspack-theme [2.23.1] (monorepo)\n' > "$T/CHANGELOG.md"
printf '.block { color: red; }\n.everlit-audio > * { width: 100%%; }\n' \
  > "$T/newspack-theme/sass/blocks/_blocks.scss"
git add -A

restore_release_artifacts "$T"

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

echo "Release stamps restored to monorepo:"
assert "package.json name"           "newspack-theme" "$(pjv "$T/package.json" name)"
assert "package.json version"        "2.23.1"         "$(pjv "$T/package.json" version)"
assert "theme header version"        "yes"            "$(has "$T/newspack-theme/sass/theme-description.scss" 'Version: 2.23.1')"
assert "theme header requires"       "yes"            "$(has "$T/newspack-theme/sass/theme-description.scss" 'Requires at least: 6.9')"
assert "child theme header version"  "yes"            "$(has "$T/newspack-joseph/sass/theme-description.scss" 'Version: 2.23.1')"
assert "CHANGELOG is monorepo's"     "no"             "$(has "$T/CHANGELOG.md" 'legacy')"

echo "Real legacy changes kept:"
assert "source fix kept"             "yes"            "$(has "$T/newspack-theme/sass/blocks/_blocks.scss" 'everlit-audio')"
assert "new dependency kept"         "^2.0.0"         "$(node -e "process.stdout.write(JSON.parse(require('fs').readFileSync('$T/package.json','utf8')).dependencies['legit-new-dep'])")"

[ "$fail" = 0 ] && echo "PASS" || { echo "FAIL"; exit 1; }
