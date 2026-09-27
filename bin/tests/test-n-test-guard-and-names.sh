#!/usr/bin/env bash
#
# test-n-test-guard-and-names.sh
#
# Self-proving spec for three host-side behaviors of `n`:
#
# 1. `n test-php` and `n test-js` refuse to run from a worktree that no isolated
#    env mounts, because the main container would test the root checkout's code
#    and report it as the branch's. NEWSPACK_TEST_ROOT_OK=1 overrides.
# 2. `n test-php`, `n composer` and `n npm` take a leading project name, and
#    forward anything else (paths, subcommands, flags) to the tool untouched.
# 3. The compact-output mode reaches the container: set for coding agents,
#    unset otherwise, and overridable with NEWSPACK_TEST_OUTPUT.
#
# The guard reads the directory `n` was called from. `n` cds to its own directory
# before dispatching, so a check against $PWD inside a command always saw the
# root and never fired; running the real `n` from a real subdirectory is what
# pins that.
#
# Scope: host-side routing only. The docker stub records what it was handed and
# never runs it.
#
# Run: bash bin/tests/test-n-test-guard-and-names.sh

set -uo pipefail # not -e: several cases assert a non-zero exit.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd -P)"

WORK=$(mktemp -d -t n-test-guard-XXXXXX)
# The guard only recognizes worktrees under the checkout's own worktrees/ (which
# is gitignored), so the fake one has to live there.
FAKE_WT="$REPO_ROOT/worktrees/spec-n-test-guard-$$"
trap 'rm -rf "$WORK" "$FAKE_WT"' EXIT
mkdir -p "$FAKE_WT/plugins/newspack-ads"

mkdir -p "$WORK/stub"
cat > "$WORK/stub/docker" <<STUB
#!/usr/bin/env bash
printf '%s\n' "\$@" > "$WORK/argv"
exit 0
STUB
chmod +x "$WORK/stub/docker"
export PATH="$WORK/stub:$PATH"

# The agent detection must see a controlled environment, whatever runs this spec.
unset CLAUDECODE AI_AGENT CODEX_SANDBOX NEWSPACK_TEST_OUTPUT NEWSPACK_TEST_ROOT_OK

failures=0
check() {
	local desc="$1" want="$2" got="$3"
	if [[ "$want" == "$got" ]]; then
		echo "ok   - $desc"
	else
		echo "FAIL - $desc: wanted [$want], got [$got]"
		failures=$((failures + 1))
	fi
}

# run_n <dir> <args...>: run the real n from <dir>; env assignments may precede it.
run_n() {
	local dir="$1"
	shift
	rm -f "$WORK/argv"
	( cd "$dir" && "$REPO_ROOT/n" "$@" ) >"$WORK/out" 2>&1
	echo $? > "$WORK/status"
}
argv_has() { grep -qxF -- "$1" "$WORK/argv" 2>/dev/null && echo yes || echo no; }
last_arg() { tail -1 "$WORK/argv" 2>/dev/null; }

# 1. The bare-worktree guard.
for cmd in test-php test-js; do
	run_n "$FAKE_WT/plugins/newspack-ads" "$cmd"
	check "$cmd from an unmounted worktree exits non-zero" "1" "$(cat "$WORK/status")"
	check "$cmd from an unmounted worktree explains why" "yes" \
		"$(grep -q 'no isolated env mounts this worktree' "$WORK/out" && echo yes || echo no)"
	check "$cmd from an unmounted worktree calls no docker" "no" \
		"$([[ -e "$WORK/argv" ]] && echo yes || echo no)"
done

export NEWSPACK_TEST_ROOT_OK=1
run_n "$FAKE_WT/plugins/newspack-ads" test-php
check "NEWSPACK_TEST_ROOT_OK=1 lets test-php run" "0" "$(cat "$WORK/status")"
unset NEWSPACK_TEST_ROOT_OK

run_n "$REPO_ROOT/plugins/newspack-ads" test-php
check "test-php from the root checkout's project runs" "0" "$(cat "$WORK/status")"
check "and names the root checkout's code" "yes" \
	"$(grep -q '^NEWSPACK_TEST_CODE=.*(plugins/newspack-ads)$' "$WORK/argv" && echo yes || echo no)"

# 2. Project names. test-php and composer build a `sh -c` string; npm too.
run_n "$WORK" test-php newspack-ads --filter foo
check "test-php <name> targets that project" "yes" \
	"$(last_arg | grep -q '^/var/scripts/test-php.sh newspack-ads --filter foo$' && echo yes || echo no)"

run_n "$WORK" test-php ads
check "test-php accepts a name without the newspack- prefix" "yes" \
	"$(last_arg | grep -q '^/var/scripts/test-php.sh newspack-ads *$' && echo yes || echo no)"

run_n "$REPO_ROOT/plugins/newspack-ads" test-php tests/test-foo.php
check "test-php forwards a path to PHPUnit" "yes" \
	"$(last_arg | grep -q '^/var/scripts/test-php.sh newspack-ads tests/test-foo.php$' && echo yes || echo no)"

run_n "$WORK" composer newspack-ads install
check "composer <name> <subcommand> targets that project" "yes" \
	"$(last_arg | grep -q '^/var/scripts/composer.sh newspack-ads install$' && echo yes || echo no)"

run_n "$REPO_ROOT/plugins/newspack-ads" composer update
check "composer forwards a subcommand that names no project" "yes" \
	"$(last_arg | grep -q '^/var/scripts/composer.sh newspack-ads update$' && echo yes || echo no)"

run_n "$WORK" npm newspack-ads run build
check "npm <name> targets that project" "yes" \
	"$(last_arg | grep -q '^/var/scripts/npm.sh newspack-ads run build$' && echo yes || echo no)"

run_n "$WORK" test-php --filter foo
check "test-php with no name outside a project still refuses" "1" "$(cat "$WORK/status")"

# 3. Output mode.
run_n "$REPO_ROOT/plugins/newspack-ads" test-php
check "no agent: output mode is empty" "yes" "$(argv_has 'NEWSPACK_TEST_OUTPUT=')"

CLAUDECODE=1 run_n "$REPO_ROOT/plugins/newspack-ads" test-php
check "under an agent: compact" "yes" "$(argv_has 'NEWSPACK_TEST_OUTPUT=compact')"

CLAUDECODE=1 NEWSPACK_TEST_OUTPUT=full run_n "$REPO_ROOT/plugins/newspack-ads" test-js
check "NEWSPACK_TEST_OUTPUT=full overrides agent detection" "yes" "$(argv_has 'NEWSPACK_TEST_OUTPUT=full')"

if [[ $failures -gt 0 ]]; then
	echo "$failures failure(s)"
	exit 1
fi
echo "all passed"
