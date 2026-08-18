#!/usr/bin/env bash
#
# test-name-guards.sh
#
# Self-proving spec for the guards that stop an option being taken as a name.
#
# `n env create --help` used to create an environment called "--help". The
# subcommands read the name positionally, validate_env_name allowed a leading
# dash, and no arm handled -h or --help, so the option validated as an ordinary
# name: a compose file, an envs/--help/ directory and an /etc/hosts line, with
# no usage text and exit 0. Both halves are covered here because either one
# alone lets the other regress. Loosen the validator and `destroy` accepts an
# option again; drop the help arm and `--help` errors instead of helping.
#
# validate_name is covered alongside it because it has the same exposure by a
# different route: bin/worktree.sh and the --worktree parsing in bin/env.sh read
# branch and repo names positionally through it, so `n worktree add --help` used
# to reach git with "--help" as the branch. Git refuses that refname, so nothing
# was created, but the user got a git error rather than usage.
#
# Scope: every env subcommand that reads $2 is covered — create, up, down and
# destroy take a name, list and cleanup take only options. e2e-setup is the one
# exception: it delegates to bin/setup-local-e2e.sh, which carries its own
# --help arm and calls validate_env_name itself.
#
# Run: bash bin/tests/test-name-guards.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../_common.sh
. "$SCRIPT_DIR/../_common.sh"

WORK=$(mktemp -d -t env-name-guards-XXXXXX)
trap 'rm -rf "$WORK"' EXIT

failures=0

# Classify a validator call. Both validators exit rather than return, so the
# call runs in a subshell. A rejection is exit 1 carrying the error message:
# counting any non-zero as a rejection would let a syntax error or a set -u
# abort satisfy every rejection assertion below, and the suite would stay green
# against a _common.sh that no longer parses.
classify() {
	local out status=0
	out=$( "$@" 2>&1 ) || status=$?
	if [[ "$status" -eq 0 ]]; then
		echo accepted
	elif [[ "$status" -eq 1 && "$out" == *"Error: invalid"* ]]; then
		echo rejected
	else
		echo "broken(exit $status)"
	fi
}

assert_name() {
	local name="$1" want="$2" desc="$3" got
	got=$(classify validate_env_name "$name")
	if [[ "$got" == "$want" ]]; then
		echo "  ok: $desc"
	else
		echo "  FAIL: $desc — want $want, got $got"
		failures=$((failures + 1))
	fi
}

echo "validate_env_name:"
assert_name "demo" accepted "a plain name is accepted"
assert_name "demo-1" accepted "an embedded dash is accepted"
assert_name "demo_1" accepted "an embedded underscore is accepted"
assert_name "demo.1" accepted "a dotted name is accepted, as isolated-db envs use"
assert_name "1demo" accepted "a leading digit is accepted"
assert_name "--help" rejected "the option that named the bug is rejected"
assert_name "-h" rejected "a short option is rejected"
assert_name "-demo" rejected "a leading dash is rejected"
assert_name ".demo" accepted "a leading dot is accepted; rejecting it would strand an existing env"
assert_name "_demo" accepted "a leading underscore is accepted, for the same reason"
assert_name "-" rejected "a bare dash is rejected"
assert_name "de mo" rejected "an embedded space is rejected"
assert_name $'demo\tx' rejected "an embedded tab is rejected"
assert_name $'demo\nx' rejected "an embedded newline is rejected, which the hosts marker relies on"
assert_name "demo/1" rejected "a slash is rejected, since Docker rejects it in container names"
assert_name ".." rejected "a bare traversal is rejected, as validate_name already rejects it"
assert_name "a..b" rejected "an embedded traversal is rejected on both validators"
assert_name "" rejected "an empty name is rejected"

assert_new_name() {
	local name="$1" want="$2" desc="$3" got
	got=$(classify validate_new_env_name "$name")
	if [[ "$got" == "$want" ]]; then
		echo "  ok: $desc"
	else
		echo "  FAIL: $desc — want $want, got $got"
		failures=$((failures + 1))
	fi
}

echo ""
echo "validate_new_env_name (create path only):"
assert_new_name "demo" accepted "a plain name is accepted"
assert_new_name "1demo" accepted "a leading digit is accepted"
assert_new_name ".demo" rejected "a leading dot is rejected at creation; it would yield an empty first DNS label"
assert_new_name "_demo" rejected "a leading underscore is rejected at creation, for the same reason"
assert_new_name "--help" rejected "the option shape is still rejected"
assert_new_name ".." rejected "a traversal is still rejected"
assert_new_name "" rejected "an empty name is rejected"

assert_path_name() {
	local name="$1" want="$2" desc="$3" got
	got=$(classify validate_name "$name" "branch")
	if [[ "$got" == "$want" ]]; then
		echo "  ok: $desc"
	else
		echo "  FAIL: $desc — want $want, got $got"
		failures=$((failures + 1))
	fi
}

echo
echo "validate_name:"
assert_path_name "fix/some-thing" accepted "a slashed branch name is accepted, unlike an env name"
assert_path_name "newspack-plugin" accepted "a repo name with an internal dash is accepted"
assert_path_name "_pr738" accepted "a leading underscore is accepted, since such branches are in use"
assert_path_name "demo.1" accepted "a dotted name is accepted"
assert_path_name "--help" rejected "the option shape that reached git as a branch name is rejected"
assert_path_name "-h" rejected "a short option is rejected"
assert_path_name "--force" rejected "any long option is rejected, not just --help"
assert_path_name "a..b" rejected "a parent-directory traversal is still rejected"
assert_path_name "/abs" rejected "a leading slash is still rejected"
assert_path_name "de mo" rejected "an embedded space is rejected"
assert_path_name $'demo\nx' rejected "an embedded newline is rejected"
assert_path_name "" rejected "an empty name is rejected"

# The sandbox root. _common.sh honours an inherited NABSPATH only when it names
# a real workspace root, so the stub `n` is what makes this one: anything a
# subcommand writes lands here rather than in the checkout.
SANDBOX="$WORK/root"
mkdir -p "$SANDBOX"
touch "$SANDBOX/n"

# NABSPATH does not bound everything `create` can reach. If a guard regresses it
# runs for real, and its /etc/hosts step calls `sudo newspack-manage-host`, which
# is deliberately passwordless and needs no TTY — so a broken tree would write to
# the host's real hosts file from inside this test, which is how the debris this
# spec exists to prevent got created in the first place. Stubbing those out of
# PATH and closing stdin keeps the failure contained.
STUB="$WORK/stub"
mkdir -p "$STUB"
for cmd in sudo docker newspack-manage-host; do
	printf '#!/bin/sh\necho "%s: blocked by the test sandbox" >&2\nexit 127\n' "$cmd" > "$STUB/$cmd"
	chmod +x "$STUB/$cmd"
done

assert_usage() {
	local want_status="$1" desc="$2"; shift 2
	local sub="$1" out status=0
	out=$(PATH="$STUB:$PATH" NABSPATH="$SANDBOX" bash "$SCRIPT_DIR/../env.sh" "$@" </dev/null 2>&1) || status=$?
	if [[ "$status" != "$want_status" ]]; then
		echo "  FAIL: $desc — want exit $want_status, got $status"
		failures=$((failures + 1))
	elif [[ "$out" != *"Usage: n env $sub"* ]]; then
		# Matching the arm's own usage line, not a bare "Usage:", so a truncated
		# string or another arm's text cannot satisfy the assertion.
		echo "  FAIL: $desc — usage text did not name 'n env $sub'"
		failures=$((failures + 1))
	else
		echo "  ok: $desc"
	fi
}

echo
echo "n env <subcommand> --help:"
for sub in create up down destroy list cleanup; do
	assert_usage 0 "$sub --help prints its own usage and succeeds" "$sub" --help
	assert_usage 0 "$sub -h prints its own usage and succeeds" "$sub" -h
done

echo
echo "n env <subcommand> with no name:"
for sub in create up down destroy; do
	# Distinct from the help path on purpose: a missing name is a usage error,
	# so it must keep exiting non-zero for callers that check.
	assert_usage 1 "$sub with no name prints usage and fails" "$sub"
done

echo
echo "no environment is created by any of the above:"
# `--all` is the one other dash-leading token read from the name position, and
# is_help_arg's comment states that it must still reach the up arm's own branch.
# Exit status cannot tell the two apart -- a usage dump also exits 0 -- so this
# asserts on the output: widening is_help_arg to `-*` would look like a
# strengthening of the guard and silently turn `n env up --all` into usage.
assert_up_all_is_not_help() {
	local out status=0
	out=$(PATH="$STUB:$PATH" NABSPATH="$SANDBOX" bash "$SCRIPT_DIR/../env.sh" up --all </dev/null 2>&1) || status=$?
	if [[ "$status" -ne 0 ]]; then
		echo "  FAIL: up --all reaches its own branch — want exit 0, got $status"
		failures=$((failures + 1))
	elif [[ "$out" == *"Usage: n env up"* ]]; then
		echo "  FAIL: up --all reaches its own branch — it printed usage, so it was read as a help request"
		failures=$((failures + 1))
	elif [[ "$out" != *"started,"* ]]; then
		echo "  FAIL: up --all reaches its own branch — no start summary in output: $out"
		failures=$((failures + 1))
	else
		echo "  ok: up --all reaches its own branch rather than the help guard"
	fi
}

echo ""
echo "n env up --all is not a help request:"
assert_up_all_is_not_help

# The create arm must actually reach the stricter validator. Asserting
# validate_new_env_name directly cannot see the call site, so reverting create to
# the lax validator would leave every assertion above green.
assert_create_rejects() {
	local name="$1" desc="$2" out status=0
	out=$(PATH="$STUB:$PATH" NABSPATH="$SANDBOX" bash "$SCRIPT_DIR/../env.sh" create "$name" </dev/null 2>&1) || status=$?
	if [[ "$status" -eq 1 && "$out" == *"must start with a letter or digit"* ]]; then
		echo "  ok: $desc"
	else
		echo "  FAIL: $desc — want exit 1 with the create-path message, got exit $status: $out"
		failures=$((failures + 1))
	fi
}

echo ""
echo "n env create reaches the stricter validator:"
assert_create_rejects ".demo" "a leading dot is refused by the create arm itself"
assert_create_rejects "_demo" "a leading underscore is refused by the create arm itself"

leaked=$(find "$SANDBOX" -mindepth 1 ! -name n)
if [[ -n "$leaked" ]]; then
	echo "  FAIL: the sandbox gained files — $(echo "$leaked" | tr '\n' ' ')"
	failures=$((failures + 1))
else
	echo "  ok: the sandbox is untouched, so no compose file or env dir was written"
fi

if [[ "$failures" -gt 0 ]]; then
	echo "$failures assertion(s) failed"
	exit 1
fi
echo "All assertions passed."
