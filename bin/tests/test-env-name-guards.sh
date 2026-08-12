#!/usr/bin/env bash
#
# test-env-name-guards.sh
#
# Self-proving spec for the two guards that stop an option being taken as an
# environment name.
#
# `n env create --help` used to create an environment called "--help". The
# subcommands read the name positionally, validate_env_name allowed a leading
# dash, and no arm handled -h or --help, so the option validated as an ordinary
# name: a compose file, an envs/--help/ directory and an /etc/hosts line, with
# no usage text and exit 0. Both halves are covered here because either one
# alone lets the other regress. Loosen the validator and `destroy` accepts an
# option again; drop the help arm and `--help` errors instead of helping.
#
# Run: bash bin/tests/test-env-name-guards.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../_common.sh
. "$SCRIPT_DIR/../_common.sh"

WORK=$(mktemp -d -t env-name-guards-XXXXXX)
trap 'rm -rf "$WORK"' EXIT

failures=0

assert_name() {
	local name="$1" want="$2" desc="$3" got
	# validate_env_name exits rather than returning, so it runs in a subshell.
	if ( validate_env_name "$name" ) >/dev/null 2>&1; then got=accepted; else got=rejected; fi
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
assert_name ".demo" rejected "a leading dot is rejected, which would misname the data dir"
assert_name "_demo" rejected "a leading underscore is rejected"
assert_name "demo/1" rejected "a slash is rejected, since Docker rejects it in container names"
assert_name "" rejected "an empty name is rejected"

# _common.sh honours an inherited NABSPATH only when it names a real workspace
# root, so the stub `n` is what makes this a sandbox: anything a subcommand
# writes lands here rather than in the checkout.
touch "$WORK/n"

assert_usage() {
	local want_status="$1" desc="$2"; shift 2
	local out status=0
	out=$(NABSPATH="$WORK" bash "$SCRIPT_DIR/../env.sh" "$@" 2>&1) || status=$?
	if [[ "$status" != "$want_status" ]]; then
		echo "  FAIL: $desc — want exit $want_status, got $status"
		failures=$((failures + 1))
	elif [[ "$out" != *"Usage:"* ]]; then
		echo "  FAIL: $desc — printed no usage text"
		failures=$((failures + 1))
	else
		echo "  ok: $desc"
	fi
}

echo
echo "n env <subcommand> --help:"
for sub in create up down destroy; do
	assert_usage 0 "$sub --help prints usage and succeeds" "$sub" --help
	assert_usage 0 "$sub -h prints usage and succeeds" "$sub" -h
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
leaked=$(find "$WORK" -mindepth 1 ! -name n)
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
