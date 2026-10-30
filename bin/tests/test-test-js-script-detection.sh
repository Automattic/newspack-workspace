#!/usr/bin/env bash
#
# test-test-js-script-detection.sh
#
# Self-proving spec for bin/test-js.sh's standalone branch: how it decides that a
# checkout has a JS test script to run.
#
# The decision used to be `grep '"test"[[:space:]]*:' package.json`, which matches
# the same shape anywhere in the file — including a *dependency* named "test". Such
# a checkout has no test script, so the run reached `<pm> run test` and died with
# "Missing script", turning an intended no-op into a non-zero exit.
#
# Reading scripts.test through node fixes that, but introduces a second question
# this spec also pins: a package.json that cannot be read at all must not be
# reported as "nothing to test". Empty output means no script; a non-zero node
# means unknown, and unknown fails closed rather than skipping a repo's whole
# suite while exiting 0.
#
# Run: bash bin/tests/test-test-js-script-detection.sh

set -uo pipefail # not -e: two of the cases assert a non-zero exit.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

WORK=$(mktemp -d -t test-js-detection-XXXXXX)
trap 'rm -rf "$WORK"' EXIT

mkdir -p "$WORK/plugins" \
	"$WORK/repos/plugins/dep-named-test" \
	"$WORK/repos/plugins/unreadable-manifest" \
	"$WORK/repos/plugins/real-test-script"

# A dependency named "test", and no test script.
cat > "$WORK/repos/plugins/dep-named-test/package.json" <<'JSON'
{ "name": "dep-named-test", "devDependencies": { "test": "^1.0.0" } }
JSON
: > "$WORK/repos/plugins/dep-named-test/package-lock.json"

# Truncated mid-value: node cannot parse it, and it still contains `"test":`.
printf '{ "name": "unreadable-manifest", "scripts": { "test": ' \
	> "$WORK/repos/plugins/unreadable-manifest/package.json"
: > "$WORK/repos/plugins/unreadable-manifest/package-lock.json"

cat > "$WORK/repos/plugins/real-test-script/package.json" <<'JSON'
{ "name": "real-test-script", "scripts": { "test": "newspack-scripts test" } }
JSON
: > "$WORK/repos/plugins/real-test-script/package-lock.json"

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

# 1. The regression this spec exists for: a dependency named "test" is not a test
#    script, so nothing runs and the no-op stays a no-op.
run_case "dep-named-test"
check "dependency named test exits zero" "0" "$(cat "$WORK/status")"
check "dependency named test runs no package manager" "" "$(cat "$WORK/calls.log")"
check "dependency named test says there is no script" "yes" \
	"$(grep -q 'No "test" script' "$WORK/out" && echo yes || echo no)"

# 2. An unreadable manifest is unknown, not empty. Reporting "nothing to test"
#    here would skip the repo's suite and still exit 0.
run_case "unreadable-manifest"
check "unreadable manifest exits non-zero" "1" "$(cat "$WORK/status")"
check "unreadable manifest runs no package manager" "" "$(cat "$WORK/calls.log")"
check "unreadable manifest names the file" "yes" \
	"$(grep -q 'Could not read unreadable-manifest/package.json' "$WORK/out" && echo yes || echo no)"
check "unreadable manifest is not called empty" "no" \
	"$(grep -q 'nothing to test' "$WORK/out" && echo yes || echo no)"

# 3. Control: a real test script still installs and runs.
run_case "real-test-script"
check "real test script exits zero" "0" "$(cat "$WORK/status")"
check "real test script runs its own test script" "yes" \
	"$(grep -q 'npm run test' "$WORK/calls.log" && echo yes || echo no)"

if [[ $failures -gt 0 ]]; then
	echo "$failures failure(s)"
	exit 1
fi
echo "All cases passed."
