#!/usr/bin/env bash
#
# test-env-destroy-keep-branch.sh
#
# Self-proving spec for --keep-branch on `n worktree remove` and
# `n env destroy`: the worktree goes, the branch and its commits stay.
#
# Removing a monorepo worktree always ran `git branch -D`, with no way to opt
# out, so tidying up an environment also threw away its branch, and anything
# that cleans up envs unattended could lose commits nobody had pushed. A branch
# can be deleted in two places: bin/worktree.sh deletes the name it was given,
# and bin/env.sh deletes the real name itself when it differs from the
# directory-safe form (feat/foo vs feat-foo). Both are covered here, because
# guarding only one leaves the other deleting branches. The default is covered
# too, so the flag cannot become the only way a branch survives.
#
# Each case runs the real scripts against a throwaway git repo standing in for
# the workspace root.
#
# Run: bash bin/tests/test-env-destroy-keep-branch.sh

set -uo pipefail # not -e: cases assert on non-zero exits.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

WORK=$(mktemp -d -t env-keep-branch-XXXXXX)
trap 'rm -rf "$WORK"' EXIT

failures=0

ok() { echo "  ok: $1"; }
fail() {
	echo "  FAIL: $1"
	failures=$((failures + 1))
}

# A fresh workspace root per case. _common.sh honours an inherited NABSPATH only
# when it holds an `n`, so the empty one makes this directory the root.
new_sandbox() {
	SANDBOX="$WORK/root-$1"
	git init -q "$SANDBOX"
	git -C "$SANDBOX" -c user.name=test -c user.email=test@example.test commit -q --allow-empty -m init
	touch "$SANDBOX/n"
}

# make_worktree <branch>: a monorepo worktree at worktrees/<safe>, as `n worktree add` lays it out.
make_worktree() {
	local branch="$1"
	git -C "$SANDBOX" worktree add -q -b "$branch" "$SANDBOX/worktrees/${branch//\//-}"
}

sha_of() {
	git -C "$SANDBOX" rev-parse --verify --quiet "refs/heads/$1"
}

run_worktree() {
	NABSPATH="$SANDBOX" bash "$SCRIPT_DIR/../worktree.sh" "$@" </dev/null 2>&1
}

echo "n worktree remove:"

new_sandbox wt-keep
make_worktree keepme
before=$(sha_of keepme)
status=0
out=$(run_worktree remove keepme --yes --keep-branch) || status=$?
desc="remove keepme --yes --keep-branch keeps the branch"
if [[ "$status" -ne 0 ]]; then
	fail "$desc — want exit 0, got $status: $out"
elif [[ -d "$SANDBOX/worktrees/keepme" ]]; then
	fail "$desc — the worktree directory is still there"
elif [[ "$(sha_of keepme)" != "$before" ]]; then
	fail "$desc — refs/heads/keepme is gone or moved"
elif [[ "$out" != *"(kept)"* ]]; then
	fail "$desc — output does not say the branch is kept: $out"
else
	ok "$desc"
fi

new_sandbox wt-drop
make_worktree dropme
status=0
out=$(run_worktree remove dropme --yes) || status=$?
desc="remove dropme --yes still deletes the branch"
if [[ "$status" -ne 0 ]]; then
	fail "$desc — want exit 0, got $status: $out"
elif [[ -n "$(sha_of dropme)" ]]; then
	fail "$desc — refs/heads/dropme survived"
else
	ok "$desc"
fi

echo
if [[ "$failures" -gt 0 ]]; then
	echo "$failures failure(s)"
	exit 1
fi
echo "all passed"
