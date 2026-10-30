#!/usr/bin/env bash
#
# test-test-js-project-resolution.sh
#
# Self-proving spec for bin/test-js.sh: an unrecognised project name aborts, and
# a recognised one selects the branch matching where it lives.
#
# find_project runs inside a command substitution, so its `exit 1` ends only that
# subshell. Until the status was propagated at the call site, an unknown name left
# PROJECT_DIR empty, fell through to the workspace branch, and ran `pnpm install`
# plus `--filter ""` across every package in the monorepo — a typo silently became
# a full-workspace test run instead of an error.
#
# Run: bash bin/tests/test-test-js-project-resolution.sh

set -uo pipefail # not -e: the point of the first case is a non-zero exit.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

WORK=$(mktemp -d -t test-js-resolution-XXXXXX)
trap 'rm -rf "$WORK"' EXIT

# Fixture tree standing in for the container's mounts.
mkdir -p "$WORK/plugins/newspack-blocks" "$WORK/repos/plugins/standalone-thing"
cat > "$WORK/plugins/newspack-blocks/package.json" <<'JSON'
{ "name": "@newspack/blocks-pkg", "scripts": { "test": "true" } }
JSON
cat > "$WORK/repos/plugins/standalone-thing/package.json" <<'JSON'
{ "name": "standalone-thing", "scripts": { "test": "true" } }
JSON
: > "$WORK/repos/plugins/standalone-thing/package-lock.json"

# Stub the package managers: record every call, install nothing.
mkdir -p "$WORK/stub"
for pm in pnpm npm yarn corepack; do
	cat > "$WORK/stub/$pm" <<STUB
#!/usr/bin/env bash
echo "$pm \$*" >> "$WORK/calls.log"
exit 0
STUB
	chmod +x "$WORK/stub/$pm"
done
export PATH="$WORK/stub:$PATH"
export PLUGINS_PATH="$WORK/plugins" THEMES_PATH="$WORK/themes" REPOS_PATH="$WORK/repos"
export MONOREPO_ROOT="$WORK"

failures=0
run_case() {
	: > "$WORK/calls.log"
	bash "$BIN_DIR/test-js.sh" "$1" >"$WORK/out" 2>&1
	echo $? > "$WORK/status"
}
check() {
	local desc="$1" want="$2" got="$3"
	if [[ "$want" == "$got" ]]; then
		echo "ok   - $desc"
	else
		echo "FAIL - $desc: wanted [$want], got [$got]"
		failures=$((failures + 1))
	fi
}

# 1. The regression this spec exists for.
run_case "definitely-not-a-project"
check "unknown name exits non-zero" "1" "$(cat "$WORK/status")"
check "unknown name runs no package manager" "" "$(cat "$WORK/calls.log")"
check "unknown name says which name" "yes" \
	"$(grep -q 'definitely-not-a-project not found' "$WORK/out" && echo yes || echo no)"

# 2. A monorepo plugin takes the workspace branch, filtered by its package name
#    rather than its directory name.
run_case "newspack-blocks"
check "workspace project exits zero" "0" "$(cat "$WORK/status")"
check "workspace project filters by package name" "yes" \
	"$(grep -q 'pnpm --filter @newspack/blocks-pkg run test' "$WORK/calls.log" && echo yes || echo no)"

# 3. A standalone repos/ checkout runs its own test script instead, with the
#    package manager its lockfile implies.
run_case "standalone-thing"
check "standalone repo exits zero" "0" "$(cat "$WORK/status")"
check "standalone repo runs its own test script" "yes" \
	"$(grep -q 'npm run test' "$WORK/calls.log" && echo yes || echo no)"
check "standalone repo does not reach pnpm --filter" "" \
	"$(grep 'pnpm --filter' "$WORK/calls.log" || true)"

# 4. An unreachable MONOREPO_ROOT aborts rather than running the workspace install
#    from wherever the shell happened to start. Reachable only because the root is
#    overridable; the hardcoded value could only fail on a broken container mount.
saved_root="$MONOREPO_ROOT"
export MONOREPO_ROOT="$WORK/no-such-root"
run_case "newspack-blocks"
check "unreachable monorepo root exits non-zero" "1" "$(cat "$WORK/status")"
check "unreachable monorepo root runs no package manager" "" "$(cat "$WORK/calls.log")"
export MONOREPO_ROOT="$saved_root"

if [[ $failures -gt 0 ]]; then
	echo "$failures failure(s)"
	exit 1
fi
echo "All cases passed."
