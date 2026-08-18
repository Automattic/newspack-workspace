#!/usr/bin/env bash
#
# test-n-test-js-name-argument.sh
#
# Self-proving spec for `n test-js <name>`: the name reaches the container
# script, as one argument.
#
# `n` derives the target project from PWD. Until the test-js case read $2 the way
# build/ci-build/watch do, the name was silently discarded and the command failed
# with "You must be inside one of the repos" from anywhere but the project's own
# directory — so a standalone repos/ checkout could only be tested by cd-ing into
# it, and the documented `n test-js <name>` form did not work at all.
#
# The name now comes from the command line, so it also has to reach the container
# as a single argv entry: interpolated unquoted into `sh -c`, a value carrying a
# space or a `;` would word-split or be read as shell syntax there.
#
# Scope: this covers argument routing on the host only. The docker stub records
# what it was handed and never runs it, so nothing here observes container-side
# execution; the argv-entry assertion is what pins the quoting.
#
# Run: bash bin/tests/test-n-test-js-name-argument.sh

set -uo pipefail # not -e: one case asserts a non-zero exit.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

WORK=$(mktemp -d -t n-test-js-arg-XXXXXX)
trap 'rm -rf "$WORK"' EXIT

# Stub docker, recording argv one entry per line so a word-split shows up as two.
mkdir -p "$WORK/stub"
cat > "$WORK/stub/docker" <<STUB
#!/usr/bin/env bash
printf '%s\n' "\$@" > "$WORK/argv"
exit 0
STUB
chmod +x "$WORK/stub/docker"
export PATH="$WORK/stub:$PATH"

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

# Run from a directory that is not inside any project, so nothing can be inferred
# from PWD and only the argument can supply the target.
run_n() {
	rm -f "$WORK/argv"
	( cd "$WORK" && "$REPO_ROOT/n" "$@" ) >"$WORK/out" 2>&1
	echo $? > "$WORK/status"
}

# 1. The name is routed through to the container script.
run_n test-js newspack-blocks
check "n test-js <name> reaches docker" "0" "$(cat "$WORK/status")"
check "the container script is invoked" "/var/scripts/test-js.sh" \
	"$(tail -2 "$WORK/argv" 2>/dev/null | head -1)"
check "the name is passed to it" "newspack-blocks" "$(tail -1 "$WORK/argv" 2>/dev/null)"

# 2. With no name and no project in PWD, the guard still refuses.
run_n test-js
check "bare n test-js outside a project exits non-zero" "1" "$(cat "$WORK/status")"
check "bare n test-js explains why" "yes" \
	"$(grep -q 'must be inside one of the repos' "$WORK/out" && echo yes || echo no)"
check "bare n test-js calls no docker" "no" \
	"$([[ -e "$WORK/argv" ]] && echo yes || echo no)"

# 3. A name carrying shell metacharacters stays one argument.
run_n test-js 'a b; echo INJECTED'
check "a name with a space stays one argv entry" "a b; echo INJECTED" \
	"$(tail -1 "$WORK/argv" 2>/dev/null)"
# This sees only host-side evaluation: the docker stub never runs what it is
# handed, so container-side execution is out of reach from here. The argv-entry
# check above is what actually pins the quoting.
check "no part of the name was executed on the host" "no" \
	"$(grep -q INJECTED "$WORK/out" && echo yes || echo no)"

if [[ $failures -gt 0 ]]; then
	echo "$failures failure(s)"
	exit 1
fi
echo "All cases passed."
