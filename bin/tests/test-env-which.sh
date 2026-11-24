#!/usr/bin/env bash
#
# test-env-which.sh
#
# Self-proving spec for `n env which [name] [--porcelain]`: from inside a
# worktree, name the env that serves it, with its status, URL, worktrees and
# login.
#
# Before this, finding the env for a worktree meant reading every compose file,
# or running a command and watching which container `n` said it targeted. `n`
# already resolves the env from the working directory to route commands, so
# `which` must use that same resolution: two copies would drift, and `which`
# would then name an env that commands do not run in. The cases through `n`
# itself cover that, and the hand-off of the caller's directory, since `n`
# changes to the workspace root before it dispatches.
#
# The porcelain row must equal the one `n env list --porcelain` prints, so one
# parser reads both. The password is a local default, but agents copy what
# they see into transcripts, so `which` names the variable and never prints
# its value.
#
# Each case runs the real scripts against a throwaway workspace root.
#
# Run: bash bin/tests/test-env-which.sh

set -uo pipefail # not -e: cases assert on non-zero exits.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd -P)"

# Resolved, because `n` resolves its own root with `pwd -P` and compares $PWD
# against it; macOS temp dirs sit behind a /var -> /private/var symlink.
WORK=$(cd "$(mktemp -d -t env-which-XXXXXX)" && pwd -P)
trap 'rm -rf "$WORK"' EXIT

failures=0

ok() { echo "  ok: $1"; }
fail() {
	echo "  FAIL: $1"
	failures=$((failures + 1))
}

# docker answers `inspect` for the one running env and fails for the rest, so
# a case sees both statuses and nothing reaches a real daemon.
STUB="$WORK/stub"
mkdir -p "$STUB"
cat > "$STUB/docker" <<'EOF'
#!/bin/sh
if [ "$1" = inspect ]; then
	for arg in "$@"; do
		[ "$arg" = newspack_env_blk ] && { echo running; exit 0; }
	done
	exit 1
fi
exit 0
EOF
chmod +x "$STUB/docker"

# A workspace root with a copy of `n` (a copy, because `n` finds its root from
# its own resolved path), the real bin/, and the two env files `up` reads.
SANDBOX="$WORK/root"
git init -q "$SANDBOX"
git -C "$SANDBOX" -c user.name=test -c user.email=test@example.test commit -q --allow-empty -m init
cp "$REPO_ROOT/n" "$SANDBOX/n"
ln -s "$REPO_ROOT/bin" "$SANDBOX/bin"
printf 'WP_ADMIN_USER=wordpress\nWP_ADMIN_PASSWORD=wordpress\n' > "$SANDBOX/default.env"
printf 'WP_ADMIN_USER=spec-admin\nWP_ADMIN_PASSWORD=spec-secret-value\n' > "$SANDBOX/.env"

# One worktree serving two envs, each mounting a different plugin from it, so
# resolution has to pick the deeper mount rather than the first file.
WT="$SANDBOX/worktrees/feat-shared"
git -C "$SANDBOX" worktree add -q -b feat/shared "$WT"
mkdir -p "$WT/plugins/newspack-plugin/includes" "$WT/plugins/newspack-blocks/src"

# make_env <name> <plugin>: a compose file with the mount line `n env create` writes.
make_env() {
	printf 'services:\n  wordpress:\n    environment:\n      - WP_DOMAIN=%s.test\n    volumes:\n      - ./worktrees/feat-shared/plugins/%s:/newspack-plugins/%s\n' \
		"$1" "$2" "$2" > "$SANDBOX/docker-compose.env-$1.yml"
}
make_env blk newspack-blocks
make_env plug newspack-plugin

# run_env <dir> <args>: bin/env.sh called directly from <dir>.
run_env() {
	local dir="$1"
	shift
	(cd "$dir" && PATH="$STUB:$PATH" NABSPATH="$SANDBOX" bash "$SCRIPT_DIR/../env.sh" "$@" </dev/null 2>&1)
}

# run_n <dir> <args>: the sandbox's own `n`, called from <dir>.
run_n() {
	local dir="$1"
	shift
	(cd "$dir" && env -u NABSPATH PATH="$STUB:$PATH" bash "$SANDBOX/n" "$@" </dev/null 2>&1)
}

echo "n routing:"

# Guards the resolver `which` now shares with `n`: commands still go to the
# env that mounts the deepest path, from either plugin.
out=$(run_n "$WT/plugins/newspack-blocks/src" root)
desc="n from the blocks mount targets the env that mounts it"
[[ "$out" == *"Targeting container: newspack_env_blk"* ]] && ok "$desc" || fail "$desc — got: $out"

out=$(run_n "$WT/plugins/newspack-plugin/includes" root)
desc="n from the plugin mount targets the other env"
[[ "$out" == *"Targeting container: newspack_env_plug"* ]] && ok "$desc" || fail "$desc — got: $out"

echo
echo "n env which:"

status=0
out=$(run_n "$WT/plugins/newspack-blocks/src" env which) || status=$?
desc="n env which, run from a worktree, names the env that serves it"
if [[ "$status" -ne 0 ]]; then
	fail "$desc — want exit 0, got $status: $out"
elif [[ "$out" != *"Name:      blk"* ]]; then
	fail "$desc — got: $out"
else
	ok "$desc"
fi

status=0
out=$(run_env "$WT/plugins/newspack-blocks/src" which) || status=$?
desc="which prints status, URL, the real branch and the login"
if [[ "$status" -ne 0 ]]; then
	fail "$desc — want exit 0, got $status: $out"
elif [[ "$out" != *"Status:    running"* || "$out" != *"URL:       https://blk.test/"* ]]; then
	fail "$desc — status or URL missing: $out"
elif [[ "$out" != *"newspack-blocks:feat/shared"* ]]; then
	fail "$desc — worktree branch missing: $out"
elif [[ "$out" != *"spec-admin"* || "$out" != *"WP_ADMIN_PASSWORD"* ]]; then
	fail "$desc — want the user from .env and the password variable named: $out"
else
	ok "$desc"
fi

desc="which never prints the password's value"
[[ "$out" != *"spec-secret-value"* ]] && ok "$desc" || fail "$desc — got: $out"

status=0
out=$(run_env "$WORK" which plug) || status=$?
desc="which plug works from outside any worktree and reports a stopped env"
if [[ "$status" -ne 0 ]]; then
	fail "$desc — want exit 0, got $status: $out"
elif [[ "$out" != *"Name:      plug"* || "$out" != *"Status:    stopped"* ]]; then
	fail "$desc — got: $out"
else
	ok "$desc"
fi

status=0
row=$(run_env "$WT/plugins/newspack-blocks/src" which --porcelain) || status=$?
listed=$(run_env "$SANDBOX" list --porcelain | grep "^blk	")
desc="which --porcelain prints the same row as list --porcelain"
if [[ "$status" -ne 0 ]]; then
	fail "$desc — want exit 0, got $status: $row"
elif [[ -z "$listed" || "$row" != "$listed" ]]; then
	fail "$desc — which: '$row' list: '$listed'"
else
	ok "$desc"
fi

status=0
out=$(run_env "$SANDBOX" which) || status=$?
desc="which outside any env exits 1 with one line"
if [[ "$status" -ne 1 ]]; then
	fail "$desc — want exit 1, got $status: $out"
elif [[ -z "$out" || "$out" == *$'\n'* ]]; then
	fail "$desc — want exactly one line, got: $out"
else
	ok "$desc"
fi

status=0
out=$(run_env "$SANDBOX" which nosuch) || status=$?
desc="which nosuch exits 1"
[[ "$status" -eq 1 ]] && ok "$desc" || fail "$desc — want exit 1, got $status: $out"

status=0
out=$(run_env "$SANDBOX" which blk --porcelin) || status=$?
desc="which blk --porcelin is refused, naming the option"
[[ "$status" -eq 1 && "$out" == *"Unknown option: --porcelin"* ]] && ok "$desc" || fail "$desc — want exit 1 naming it, got $status: $out"

status=0
out=$(run_env "$SANDBOX" which blk --help) || status=$?
desc="which blk --help prints usage"
[[ "$status" -eq 0 && "$out" == *"Usage: n env which"* ]] && ok "$desc" || fail "$desc — want exit 0 and usage, got $status: $out"

echo
if [[ "$failures" -gt 0 ]]; then
	echo "$failures failure(s)"
	exit 1
fi
echo "all passed"
