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

# A mistyped flag before the branch would land in the ignored legacy repo slot,
# so the branch it was meant to keep would be deleted.
new_sandbox wt-typo
make_worktree feat/typo
before=$(sha_of feat/typo)
status=0
out=$(run_worktree remove --keep-brnach feat/typo --yes) || status=$?
desc="remove --keep-brnach feat/typo --yes is refused and keeps the branch"
if [[ "$status" -ne 1 || "$out" != *"Unknown option: --keep-brnach"* ]]; then
	fail "$desc — want exit 1 naming the option, got $status: $out"
elif [[ ! -d "$SANDBOX/worktrees/feat-typo" ]]; then
	fail "$desc — the worktree was removed"
elif [[ "$(sha_of feat/typo)" != "$before" ]]; then
	fail "$desc — refs/heads/feat/typo is gone or moved"
else
	ok "$desc"
fi

# destroy also reaches Docker, /etc/hosts and the shared database. These stubs
# answer success and run nothing, so a case can only change the sandbox.
STUB="$WORK/stub"
mkdir -p "$STUB"
for cmd in sudo docker newspack-manage-host; do
	printf '#!/bin/sh\nexit 0\n' > "$STUB/$cmd"
	chmod +x "$STUB/$cmd"
done

# destroy runs "$NABSPATH/bin/worktree.sh" and sources "$NABSPATH/default.env".
new_env_sandbox() {
	new_sandbox "$1"
	ln -s "$SCRIPT_DIR/.." "$SANDBOX/bin"
	touch "$SANDBOX/default.env"
}

# make_env <name> <branch>...: an env bound to a worktree per branch, with the
# mount line `n env create` writes. The domain is one /etc/hosts will not hold.
make_env() {
	local name="$1" branch compose
	shift
	compose="$SANDBOX/docker-compose.env-$name.yml"
	printf 'services:\n  wordpress:\n    environment:\n      - WP_DOMAIN=keep-branch-spec-%s.test\n    volumes:\n' "$$" > "$compose"
	for branch in "$@"; do
		make_worktree "$branch"
		printf '      - ./worktrees/%s/plugins/newspack-plugin:/newspack-plugins/newspack-plugin\n' "${branch//\//-}" >> "$compose"
	done
}

run_destroy() {
	PATH="$STUB:$PATH" NABSPATH="$SANDBOX" bash "$SCRIPT_DIR/../env.sh" destroy "$@" </dev/null 2>&1
}

# expect_kept <desc> <status> <out> <branch=sha>...: destroyed, branches intact.
expect_kept() {
	local desc="$1" status="$2" out="$3" pair branch
	shift 3
	if [[ "$status" -ne 0 ]]; then
		fail "$desc — want exit 0, got $status: $out"
		return
	fi
	if [[ -f "$SANDBOX/docker-compose.env-demo.yml" ]]; then
		fail "$desc — the compose file is still there"
		return
	fi
	for pair in "$@"; do
		branch="${pair%%=*}"
		if [[ -d "$SANDBOX/worktrees/${branch//\//-}" ]]; then
			fail "$desc — worktrees/${branch//\//-} is still there"
			return
		fi
		if [[ "$(sha_of "$branch")" != "${pair#*=}" ]]; then
			fail "$desc — refs/heads/$branch is gone or moved"
			return
		fi
	done
	ok "$desc"
}

# expect_untouched <desc> <branch>: the env, its worktree and branch all remain.
expect_untouched() {
	local desc="$1" branch="$2"
	if [[ ! -f "$SANDBOX/docker-compose.env-demo.yml" ]]; then
		fail "$desc — the compose file was removed"
	elif [[ ! -d "$SANDBOX/worktrees/${branch//\//-}" ]]; then
		fail "$desc — the worktree was removed"
	elif [[ -z "$(sha_of "$branch")" ]]; then
		fail "$desc — the branch was deleted"
	else
		ok "$desc"
	fi
}

echo
echo "n env destroy:"

# env.sh deletes a slashed branch itself, by its real name.
new_env_sandbox env-slash
make_env demo feat/keep-slash
before=$(sha_of feat/keep-slash)
status=0
out=$(run_destroy demo --keep-branch) || status=$?
expect_kept "destroy demo --keep-branch keeps a slashed branch" "$status" "$out" "feat/keep-slash=$before"

# worktree.sh deletes a branch whose name is already directory-safe.
new_env_sandbox env-plain
make_env demo keepme
before=$(sha_of keepme)
status=0
out=$(run_destroy demo --keep-branch) || status=$?
expect_kept "destroy demo --keep-branch keeps a branch with no slash" "$status" "$out" "keepme=$before"

new_env_sandbox env-two
make_env demo feat/one two
one=$(sha_of feat/one)
two=$(sha_of two)
status=0
out=$(run_destroy demo --keep-branch) || status=$?
expect_kept "destroy demo --keep-branch keeps both branches of a two-worktree env" "$status" "$out" "feat/one=$one" "two=$two"

new_env_sandbox env-default
make_env demo feat/drop-me
status=0
out=$(run_destroy demo) || status=$?
desc="destroy demo with no flag still deletes the branch"
if [[ "$status" -ne 0 ]]; then
	fail "$desc — want exit 0, got $status: $out"
elif [[ -n "$(sha_of feat/drop-me)" ]]; then
	fail "$desc — refs/heads/feat/drop-me survived"
else
	ok "$desc"
fi

new_env_sandbox env-flag-first
make_env demo feat/flag-first
status=0
out=$(run_destroy --keep-branch demo) || status=$?
if [[ "$status" -ne 1 ]]; then
	fail "destroy --keep-branch demo is refused — want exit 1, got $status: $out"
else
	expect_untouched "destroy --keep-branch demo is refused and destroys nothing" feat/flag-first
fi

new_env_sandbox env-unknown
make_env demo feat/unknown
status=0
out=$(run_destroy demo --keep-branch --dry-run) || status=$?
if [[ "$status" -ne 1 || "$out" != *"Unknown option: --dry-run"* ]]; then
	fail "destroy demo --keep-branch --dry-run is refused — want exit 1 naming the option, got $status: $out"
else
	expect_untouched "destroy demo --keep-branch --dry-run is refused and destroys nothing" feat/unknown
fi

new_env_sandbox env-help
make_env demo feat/help
status=0
out=$(run_destroy demo --keep-branch --help) || status=$?
if [[ "$status" -ne 0 || "$out" != *"Usage: n env destroy"* ]]; then
	fail "destroy demo --keep-branch --help prints usage — want exit 0 and usage, got $status: $out"
else
	expect_untouched "destroy demo --keep-branch --help prints usage and destroys nothing" feat/help
fi

echo
if [[ "$failures" -gt 0 ]]; then
	echo "$failures failure(s)"
	exit 1
fi
echo "all passed"
