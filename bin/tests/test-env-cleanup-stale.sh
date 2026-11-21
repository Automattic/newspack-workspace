#!/usr/bin/env bash
#
# test-env-cleanup-stale.sh
#
# Self-proving spec for `n env cleanup --stale[=DAYS] [--yes]`: offer for
# removal only the envs that are safe to retire, say why beside each, and never
# delete a branch whose work has not merged through its PR.
#
# Plain `cleanup` marks every env for removal, and `destroy` deletes monorepo
# branches, so a careless confirm could lose unmerged work. `--stale` picks
# stopped envs whose worktrees are gone, whose branches' PRs merged, or that
# have not been used for DAYS. An env with uncommitted changes in a worktree is
# never picked: destroy removes worktrees with --force, so those edits would be
# lost even with --keep-branch.
#
# Each case runs the real scripts against a throwaway workspace root, with
# docker and gh stubbed so nothing reaches a daemon or GitHub.
#
# Run: bash bin/tests/test-env-cleanup-stale.sh

set -uo pipefail # not -e: cases assert on non-zero exits.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd -P)"

WORK=$(cd "$(mktemp -d -t env-cleanup-stale-XXXXXX)" && pwd -P)
trap 'rm -rf "$WORK"' EXIT

failures=0

ok() { echo "  ok: $1"; }
fail() {
	echo "  FAIL: $1"
	failures=$((failures + 1))
}

# docker: `inspect` reports running only for newspack_env_running, with no
# start or finish times for any env, so last use comes from file times alone.
# Everything else fails, which stops `up` at its first compose call.
STUB="$WORK/stub"
mkdir -p "$STUB"
cat > "$STUB/docker" <<'EOF'
#!/bin/sh
# DOCKER_DOWN=1 fails every call, as a quit Docker Desktop does.
[ "${DOCKER_DOWN:-0}" = 1 ] && { echo "Cannot connect to the Docker daemon" >&2; exit 1; }
[ "$1" = info ] && exit 0
if [ "$1" = inspect ]; then
	for arg in "$@"; do
		[ "$arg" = newspack_env_running ] || continue
		case "$*" in
			*State.Status*) echo running ;;
			*) echo "0001-01-01T00:00:00Z 0001-01-01T00:00:00Z" ;;
		esac
		exit 0
	done
	exit 1
fi
exit 1
EOF
chmod +x "$STUB/docker"

# gh answers `pr list --head <branch>` from $GH_MAP ("<branch> <head oid>"
# lines, one per merged PR) and logs its arguments, so a case can check which
# repository was asked. GH_FAIL=1 makes it fail the way an offline or
# unauthenticated gh does.
cat > "$STUB/gh" <<'EOF'
#!/bin/sh
echo "$*" >> "$GH_LOG"
[ "${GH_FAIL:-0}" = 1 ] && { echo "gh: not logged in" >&2; exit 4; }
head=""
while [ $# -gt 0 ]; do
	[ "$1" = --head ] && head="$2"
	shift
done
[ -n "$head" ] && awk -v b="$head" '$1 == b { print $2 }' "$GH_MAP"
exit 0
EOF
chmod +x "$STUB/gh"
export GH_MAP="$WORK/gh-map" GH_LOG="$WORK/gh-log"

# new_sandbox: a fresh workspace root holding a git repo, a copy of `n`, the
# real bin/ and default.env, as SANDBOX.
new_sandbox() {
	SANDBOX="$WORK/root-$1"
	git init -q "$SANDBOX"
	git -C "$SANDBOX" -c user.name=test -c user.email=test@example.test commit -q --allow-empty -m init
	cp "$REPO_ROOT/n" "$SANDBOX/n"
	ln -s "$REPO_ROOT/bin" "$SANDBOX/bin"
	printf 'WP_ADMIN_USER=wordpress\nWP_ADMIN_PASSWORD=wordpress\n' > "$SANDBOX/default.env"
	: > "$GH_MAP"
	: > "$GH_LOG"
}
new_sandbox args

# A timestamp 40 days back, for touch -t.
OLD=$(date -v-40d +%Y%m%d%H%M 2>/dev/null || date -d '40 days ago' +%Y%m%d%H%M)

# make_env <name>: a compose file `up` can read without migrating or touching
# networking (loopback 127.0.0.1, markers for every migration present), and
# its envs/<name>/ directory, both back-dated 40 days.
make_env() {
	local f="$SANDBOX/docker-compose.env-$1.yml"
	cat > "$f" <<EOF
services:
  env-$1:
    environment:
      - WP_DOMAIN=127.0.0.1
      - WP_ENVIRONMENT_TYPE=local
    healthcheck:
      test: ["CMD", "true"]
    volumes:
${2:-}
    networks:
      newspack_envs: {}
EOF
	mkdir -p "$SANDBOX/envs/$1/html"
	touch -t "$OLD" "$f" "$SANDBOX/envs/$1"
}

# snapshot: compose files and envs/ dirs with their mtimes, to prove a refused
# command touched nothing.
snapshot() {
	(cd "$SANDBOX" && for p in docker-compose.env-*.yml envs/*; do
		[[ -e "$p" ]] && echo "$p $(stat -f %m "$p" 2>/dev/null || stat -c %Y "$p")"
	done)
}

run_env() {
	(cd "$SANDBOX" && PATH="$STUB:$PATH" NABSPATH="$SANDBOX" bash "$SCRIPT_DIR/../env.sh" "$@" </dev/null 2>&1)
}

# commit_on <branch> <message>: a commit on <branch> through its worktree.
commit_on() {
	git -C "$SANDBOX/worktrees/${1//\//-}" -c user.name=test -c user.email=test@example.test commit -q --allow-empty -m "$2"
}

sha_of() {
	git -C "$SANDBOX" rev-parse --verify --quiet "refs/heads/$1"
}

# stale_env <name> <age: old|fresh>: an env bound to its own worktree on
# branch spec/<name>, with one commit of its own.
stale_env() {
	local branch="spec/$1"
	git -C "$SANDBOX" worktree add -q -b "$branch" "$SANDBOX/worktrees/spec-$1"
	commit_on "$branch" "work on $1"
	make_env "$1" "      - ./worktrees/spec-$1/plugins/newspack-plugin:/newspack-plugins/newspack-plugin"
	if [[ "$2" == fresh ]]; then
		touch "$SANDBOX/docker-compose.env-$1.yml" "$SANDBOX/envs/$1"
	fi
}

# stale_fixture: one env per case the selection has to tell apart.
stale_fixture() {
	new_sandbox "$1"
	stale_env gone fresh
	rm -rf "$SANDBOX/worktrees/spec-gone"
	stale_env merged fresh
	echo "spec/merged $(sha_of spec/merged)" >> "$GH_MAP"
	stale_env after old
	echo "spec/after $(sha_of spec/after)" >> "$GH_MAP"
	commit_on spec/after "pushed after the PR merged"
	stale_env old old
	stale_env fresh fresh
	stale_env running old
	stale_env dirty old
	echo "unsaved" > "$SANDBOX/worktrees/spec-dirty/notes.txt"
	# A pre-monorepo mount keeps the repo and the branch in the host path.
	git -C "$SANDBOX" worktree add -q -b spec/legacy "$SANDBOX/worktrees/newspack-plugin/spec/legacy"
	make_env legacy "      - ./worktrees/newspack-plugin/spec/legacy:/newspack-repos/newspack-plugin"
	touch "$SANDBOX/docker-compose.env-legacy.yml" "$SANDBOX/envs/legacy"
	# Created on a slash-free branch, then switched to a branch whose PR merged
	# (as `gh pr checkout` would). The merged PR says nothing about spec-moved,
	# which destroy would delete by its mount name.
	git -C "$SANDBOX" worktree add -q -b spec-moved "$SANDBOX/worktrees/spec-moved"
	commit_on spec-moved "unmerged work"
	make_env moved "      - ./worktrees/spec-moved/plugins/newspack-plugin:/newspack-plugins/newspack-plugin"
	touch "$SANDBOX/docker-compose.env-moved.yml" "$SANDBOX/envs/moved"
	git -C "$SANDBOX/worktrees/spec-moved" switch -q -c spec/colleague
	echo "spec/colleague $(sha_of spec/colleague)" >> "$GH_MAP"
}

# line_for <name> <output>: the selection line --stale printed for <name>.
line_for() {
	grep -E "^  (remove|keep) +$1 " <<< "$2"
}

make_env idle

echo "cleanup --stale arguments:"

# refused <args...> <message>: exit 1, the message, and nothing touched.
refused() {
	local msg="${*: -1}" args=("${@:1:$#-1}") before out status=0
	before=$(snapshot)
	out=$(run_env cleanup "${args[@]}") || status=$?
	local desc="cleanup ${args[*]} is refused"
	if [[ "$status" -ne 1 ]]; then
		fail "$desc — want exit 1, got $status: $out"
	elif [[ "$out" != *"$msg"* ]]; then
		fail "$desc — want '$msg', got: $out"
	elif [[ "$(snapshot)" != "$before" ]]; then
		fail "$desc — files changed"
	else
		ok "$desc"
	fi
}
refused --stale=abc "Invalid --stale value: abc"
refused --stale=0 "Invalid --stale value: 0"
refused --stale --all "--stale and --all cannot be combined"
refused --all --stale "--stale and --all cannot be combined"

status=0
out=$(run_env cleanup --help) || status=$?
desc="cleanup --help names --stale[=DAYS]"
[[ "$status" -eq 0 && "$out" == *"--stale[=DAYS]"* ]] && ok "$desc" || fail "$desc — got $status: $out"

echo
echo "up records last use:"

# `up` touches envs/<name>/ before it starts anything; the stubbed docker then
# fails its first compose call, so the run ends there.
before=$(stat -f %m "$SANDBOX/envs/idle" 2>/dev/null || stat -c %Y "$SANDBOX/envs/idle")
run_env up idle >/dev/null
after=$(stat -f %m "$SANDBOX/envs/idle" 2>/dev/null || stat -c %Y "$SANDBOX/envs/idle")
desc="up moves the mtime of envs/<name>/ forward"
[[ "$after" -gt "$before" ]] && ok "$desc" || fail "$desc — before $before, after $after"

echo
echo "cleanup --stale --yes:"

stale_fixture yes
gone_sha=$(sha_of spec/gone)
after_sha=$(sha_of spec/after)
old_sha=$(sha_of spec/old)
moved_sha=$(sha_of spec-moved)
out=$(run_env cleanup --stale --yes)

# expect_line <name> <want: remove|keep> <want reason, a substring> [<must not contain>]
expect_line() {
	local line desc="$1: $2 ($3)"
	line=$(line_for "$1" "$out")
	if [[ "$line" != "  $2 "* ]]; then
		fail "$desc — got line '$line' in: $out"
	elif [[ "$line" != *"$3"* ]]; then
		fail "$desc — reason missing: '$line'"
	elif [[ -n "${4:-}" && "$line" == *"$4"* ]]; then
		fail "$desc — must not say '$4': '$line'"
	else
		ok "$desc"
	fi
}
expect_line gone remove "worktrees gone"
expect_line merged remove "merged"
expect_line after remove "unused 40 days" "merged"
expect_line old remove "unused 40 days"
expect_line fresh keep "(in use)"
expect_line running keep "(running)"
expect_line dirty keep "(uncommitted changes)"
expect_line legacy keep "(in use)"
expect_line moved keep "(in use)"

desc="a gone worktree's branch is kept: nothing proves it merged"
[[ "$(sha_of spec/gone)" == "$gone_sha" && ! -f "$SANDBOX/docker-compose.env-gone.yml" ]] && ok "$desc" || fail "$desc"

desc="a merged branch is deleted with its env"
[[ -z "$(sha_of spec/merged)" && ! -f "$SANDBOX/docker-compose.env-merged.yml" ]] && ok "$desc" || fail "$desc"

desc="a branch with commits after its PR merged is kept"
[[ "$(sha_of spec/after)" == "$after_sha" && ! -f "$SANDBOX/docker-compose.env-after.yml" ]] && ok "$desc" || fail "$desc"

desc="an old env is destroyed and its branch kept"
[[ "$(sha_of spec/old)" == "$old_sha" && ! -f "$SANDBOX/docker-compose.env-old.yml" ]] && ok "$desc" || fail "$desc"

desc="a fresh env and a running one are left alone"
[[ -f "$SANDBOX/docker-compose.env-fresh.yml" && -f "$SANDBOX/docker-compose.env-running.yml" ]] && ok "$desc" || fail "$desc"

desc="a dirty env keeps its worktree and the uncommitted file"
[[ -f "$SANDBOX/docker-compose.env-dirty.yml" && -f "$SANDBOX/worktrees/spec-dirty/notes.txt" ]] && ok "$desc" || fail "$desc"

desc="a worktree switched to a merged branch keeps the branch it was created on"
[[ "$(sha_of spec-moved)" == "$moved_sha" ]] && ok "$desc" || fail "$desc"

desc="monorepo branches ask Automattic/newspack-workspace"
grep -q -- "--repo Automattic/newspack-workspace" "$GH_LOG" && ok "$desc" || fail "$desc — gh was called with: $(cat "$GH_LOG")"

echo
echo "cleanup --stale --yes when gh fails:"

stale_fixture nogh
merged_sha=$(sha_of spec/merged)
out=$(GH_FAIL=1 run_env cleanup --stale --yes)
status=$?
desc="cleanup still runs on the other reasons"
[[ "$status" -eq 0 && ! -f "$SANDBOX/docker-compose.env-old.yml" ]] && ok "$desc" || fail "$desc — exit $status: $out"
expect_line merged keep "(in use)"
desc="the merged branch survives when gh cannot say it merged"
[[ "$(sha_of spec/merged)" == "$merged_sha" ]] && ok "$desc" || fail "$desc"

echo
echo "cleanup --stale without a terminal:"

# The toggle list needs a person; without one, say how to run unattended
# before spending a GitHub call per worktree on a list nobody will read.
stale_fixture tty
: > "$GH_LOG"
status=0
out=$(run_env cleanup --stale) || status=$?
desc="cleanup --stale with no terminal exits 1 naming --stale --yes"
[[ "$status" -eq 1 && "$out" == *"--stale --yes"* ]] && ok "$desc" || fail "$desc — got $status: $out"
desc="and asks GitHub nothing"
[[ ! -s "$GH_LOG" ]] && ok "$desc" || fail "$desc — gh was called: $(cat "$GH_LOG")"
desc="and destroys nothing"
[[ -f "$SANDBOX/docker-compose.env-old.yml" ]] && ok "$desc" || fail "$desc"

echo
echo "cleanup --stale with Docker down:"

# With no daemon every env looks stopped and has no container times, so a
# daily-used env would read as unused from file times alone.
stale_fixture down
status=0
out=$(DOCKER_DOWN=1 run_env cleanup --stale --yes) || status=$?
desc="cleanup --stale --yes refuses to run when Docker is not running"
[[ "$status" -eq 1 && "$out" == *"Docker is not running"* ]] && ok "$desc" || fail "$desc — got $status: $out"
desc="and destroys nothing"
[[ -f "$SANDBOX/docker-compose.env-old.yml" && -d "$SANDBOX/envs/old" ]] && ok "$desc" || fail "$desc"

echo
echo "cleanup --stale=50 --yes:"

stale_fixture days
out=$(run_env cleanup --stale=50 --yes)
expect_line old keep "(in use)"

echo
if [[ "$failures" -gt 0 ]]; then
	echo "$failures failure(s)"
	exit 1
fi
echo "all passed"
