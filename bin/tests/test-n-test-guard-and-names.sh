#!/usr/bin/env bash
#
# test-n-test-guard-and-names.sh
#
# Self-proving spec for three host-side behaviors of `n`:
#
# 1. `n test-php` and `n test-js` refuse to run from a git worktree when the
#    container would read the project under test from a different checkout (the
#    main checkout, or another worktree an env mounts), since the result would
#    read as the branch's. They run when an env mounts the project from the
#    caller's worktree, when the worktree is itself the project's directory under
#    repos/, and for a repos/ project named from a monorepo worktree.
#    NEWSPACK_TEST_ROOT_OK=1 overrides. The `code:` value `n` passes on names
#    the checkout the container actually reads.
# 2. `n test-php`, `n test-js`, `n composer` and `n npm` take a leading project
#    name, and forward anything else (paths, subcommands, flags) untouched.
# 3. The compact-output mode and the host root reach the container.
#
# The guard has to hold whichever copy of `n` runs (the main checkout's, or a
# worktree's own `./n`) and from whichever directory, because `n` cds to its own
# directory before dispatching. So this spec builds a throwaway repository with a
# real linked worktree, a standalone repos/ clone and an env compose file, and
# runs the real `n` copied into it.
#
# Scope: host-side routing only. The docker stub records what it was handed and
# never runs it.
#
# Run: bash bin/tests/test-n-test-guard-and-names.sh

set -uo pipefail # not -e: several cases assert a non-zero exit.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$(cd "$SCRIPT_DIR/../.." && pwd -P)"

WORK=$(mktemp -d -t n-test-guard-XXXXXX)
WORK="$(cd "$WORK" && pwd -P)" # git reports physical paths; compare like with like
trap 'rm -rf "$WORK"' EXIT

# Signing and identity come from the machine's git config otherwise.
g() { git -c user.name=spec -c user.email=spec@example.invalid -c commit.gpgsign=false "$@"; }

R="$WORK/repo"
mkdir -p "$R/bin" "$R/plugins/newspack-ads" "$R/plugins/newspack-plugin"
cp "$SRC/n" "$R/n"
cp "$SRC/bin/_common.sh" "$SRC/bin/repos.sh" "$R/bin/"
touch "$R/plugins/newspack-ads/.keep" "$R/plugins/newspack-plugin/.keep"
printf 'worktrees/\nworktrees-repos/\n.claude/\nrepos/\ndocker-compose.env-*.yml\n' > "$R/.gitignore"
g -C "$R" init -q && g -C "$R" add -A && g -C "$R" commit -q -m init && g -C "$R" branch -q -M main
g -C "$R" worktree add -q -b wt "$R/worktrees/wt"
g -C "$R" worktree add -q -b wt2 "$R/worktrees/wt2"
WT="$R/worktrees/wt"

STANDALONE="$R/repos/plugins/standalone-thing"
mkdir -p "$STANDALONE" && touch "$STANDALONE/.keep"
g -C "$STANDALONE" init -q && g -C "$STANDALONE" add -A && g -C "$STANDALONE" commit -q -m init
# A worktree of that clone checked out in place under repos/, which the
# container mounts as a project of its own.
g -C "$STANDALONE" worktree add -q -b h1 "$R/repos/plugins/standalone-thing-h1"
# The shape `n env create --worktree <repos-project>:<branch>` makes.
g -C "$STANDALONE" worktree add -q -b rb "$R/worktrees-repos/standalone-thing/rb"
# A worktree outside worktrees/, where Claude Code puts its own.
g -C "$R" worktree add -q -b cw "$R/.claude/worktrees/cw"

write_env() { # an env that mounts newspack-plugin, and only it, from the worktree
	cat > "$R/docker-compose.env-demo.yml" <<'YML'
services:
  env-demo:
    container_name: newspack_env_demo
    volumes:
      - ./bin:/var/scripts
      - ./plugins:/newspack-plugins
      - ./worktrees/wt/plugins/newspack-plugin:/newspack-plugins/newspack-plugin
YML
}

mkdir -p "$WORK/stub"
cat > "$WORK/stub/docker" <<STUB
#!/usr/bin/env bash
printf '%s\n' "\$@" > "$WORK/argv"
exit 0
STUB
chmod +x "$WORK/stub/docker"
export PATH="$WORK/stub:$PATH"

# Agent detection must see a controlled environment, whatever runs this spec.
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

# run_n <n-script> <dir> <args...>: run an n copy from <dir>.
run_n() {
	local script="$1" dir="$2"
	shift 2
	rm -f "$WORK/argv"
	( cd "$dir" && "$script" "$@" ) >"$WORK/out" 2>&1
	echo $? > "$WORK/status"
}
status() { cat "$WORK/status"; }
called_docker() { [[ -e "$WORK/argv" ]] && echo yes || echo no; }
env_value() { sed -n "s/^$1=//p" "$WORK/argv" 2>/dev/null; }
last_arg() { tail -1 "$WORK/argv" 2>/dev/null; }
out_has() { grep -qE -- "$1" "$WORK/out" && echo yes || echo no; }

MAIN_SHA=$(git -C "$R" rev-parse --short HEAD)
WT_SHA=$(git -C "$WT" rev-parse --short HEAD)

# 1. The guard, and what `code:` names.
run_n "$R/n" "$R/plugins/newspack-ads" test-php
check "main checkout: test-php runs" "0" "$(status)"
check "main checkout: code names the main checkout" "main@$MAIN_SHA (plugins/newspack-ads)" "$(env_value NEWSPACK_TEST_CODE)"

for cmd in test-php test-js; do
	run_n "$R/n" "$WT/plugins/newspack-ads" "$cmd"
	check "unmounted worktree: $cmd exits non-zero" "1" "$(status)"
	check "unmounted worktree: $cmd calls no docker" "no" "$(called_docker)"
done
check "unmounted worktree: names the checkout it would read" "yes" "$(out_has 'would read newspack-ads from plugins/newspack-ads, not from this')"
check "unmounted worktree: says an env has to mount it" "yes" "$(out_has 'An isolated env has to mount')"

run_n "$R/n" "$R/.claude/worktrees/cw/plugins/newspack-ads" test-php
check "worktree outside worktrees/: refuses" "1" "$(status)"
check "worktree outside worktrees/: says no env can serve it" "yes" "$(out_has 'no env can serve this one')"
check "worktree outside worktrees/: does not offer n env create" "no" "$(out_has 'n env create')"

run_n "$WT/n" "$WT" test-php newspack-ads
check "worktree's own ./n: still refuses" "1" "$(status)"
check "worktree's own ./n: calls no docker" "no" "$(called_docker)"

NEWSPACK_TEST_ROOT_OK=1 run_n "$R/n" "$WT/plugins/newspack-ads" test-php
check "override: runs" "0" "$(status)"
check "override: code names the main checkout, not the worktree" "main@$MAIN_SHA (plugins/newspack-ads)" "$(env_value NEWSPACK_TEST_CODE)"

NEWSPACK_TEST_ROOT_OK=1 run_n "$WT/n" "$WT" test-php newspack-ads
check "override via ./n: code names the main checkout" "main@$MAIN_SHA (plugins/newspack-ads)" "$(env_value NEWSPACK_TEST_CODE)"

write_env
run_n "$R/n" "$WT/plugins/newspack-plugin" test-php
check "env mounts the project: routes to the env" "yes" "$(grep -qx newspack_env_demo "$WORK/argv" && echo yes || echo no)"
check "env mounts the project: code names the worktree" "wt@$WT_SHA (worktrees/wt/plugins/newspack-plugin)" "$(env_value NEWSPACK_TEST_CODE)"

run_n "$R/n" "$WT" test-php newspack-ads
check "env mounts a sibling only: refuses" "1" "$(status)"
check "env mounts a sibling only: points at the project's directory" "yes" "$(out_has "run from inside")"

# A second env of the same worktree mounts newspack-ads; n picks it from there.
cat > "$R/docker-compose.env-demo2.yml" <<'YML'
services:
  env-demo2:
    container_name: newspack_env_demo2
    volumes:
      - ./worktrees/wt/plugins/newspack-ads:/newspack-plugins/newspack-ads
YML
run_n "$R/n" "$WT/plugins/newspack-ads" test-php
check "second env of the worktree: runs from the project's directory" "0" "$(status)"
check "second env of the worktree: routes to that env" "yes" "$(grep -qx newspack_env_demo2 "$WORK/argv" && echo yes || echo no)"
rm -f "$R/docker-compose.env-demo2.yml"
run_n "$WT/n" "$WT/plugins/newspack-plugin" test-php
check "worktree's own ./n with an env present: refuses" "1" "$(status)"
check "worktree's own ./n: points at the main checkout's n" "yes" "$(out_has "Run the main checkout's n instead")"
check "worktree's own ./n: does not offer n env create" "no" "$(out_has 'n env create')"

# The same env also mounts newspack-ads, but from a different worktree.
echo "      - ./worktrees/wt2/plugins/newspack-ads:/newspack-plugins/newspack-ads" >> "$R/docker-compose.env-demo.yml"
run_n "$R/n" "$WT" test-php newspack-ads
check "env mounts the project from another worktree: refuses" "1" "$(status)"
check "env mounts the project from another worktree: calls no docker" "no" "$(called_docker)"
check "env mounts the project from another worktree: override names that worktree" "yes" \
	"$(out_has 'run anyway, testing worktrees/wt2/plugins/newspack-ads')"
NEWSPACK_TEST_ROOT_OK=1 run_n "$R/n" "$WT" test-php newspack-ads
check "override: code names the worktree the env really mounts" "yes" \
	"$(env_value NEWSPACK_TEST_CODE | grep -q '(worktrees/wt2/plugins/newspack-ads)$' && echo yes || echo no)"
rm -f "$R/docker-compose.env-demo.yml"

run_n "$R/n" "$WT" test-js standalone-thing
check "a repos/ project named from a monorepo worktree runs" "0" "$(status)"

run_n "$R/n" "$R/worktrees-repos/standalone-thing/rb" test-js
check "worktrees-repos/ worktree with no env: refuses" "1" "$(status)"
cat > "$R/docker-compose.env-demo3.yml" <<'YML'
services:
  env-demo3:
    container_name: newspack_env_demo3
    volumes:
      - ./worktrees-repos/standalone-thing/rb:/newspack-repos/plugins/standalone-thing
YML
run_n "$R/n" "$R/worktrees-repos/standalone-thing/rb" test-js
check "worktrees-repos/ worktree an env mounts: runs" "0" "$(status)"
check "and code names that worktree" "yes" \
	"$(env_value NEWSPACK_TEST_CODE | grep -q '^rb@.*(worktrees-repos/standalone-thing/rb)$' && echo yes || echo no)"
rm -f "$R/docker-compose.env-demo3.yml"

run_n "$R/n" "$R/repos/plugins/standalone-thing-h1" test-php
check "a repos/ worktree checked out in place runs" "0" "$(status)"
check "and code names that worktree" "yes" \
	"$(env_value NEWSPACK_TEST_CODE | grep -q '^h1@.*(repos/plugins/standalone-thing-h1)$' && echo yes || echo no)"

run_n "$R/n" "$STANDALONE" test-js
check "standalone repos/ clone is not a worktree: runs" "0" "$(status)"
check "standalone repos/ clone: code names that repo" "yes" \
	"$(env_value NEWSPACK_TEST_CODE | grep -q "(repos/plugins/standalone-thing)$" && echo yes || echo no)"

# 2. Project names. test-php, composer and npm build a `sh -c` string, which
# ends in a space when no arguments follow the project.
run_n "$R/n" "$WORK" test-php newspack-ads --filter foo
check "test-php <name> targets that project" "/var/scripts/test-php.sh newspack-ads --filter foo" "$(last_arg)"

run_n "$R/n" "$WORK" test-php ads
check "test-php accepts a name without the newspack- prefix" "/var/scripts/test-php.sh newspack-ads" "$(last_arg | sed 's/ *$//')"

run_n "$R/n" "$R/plugins/newspack-ads" test-php tests/test-foo.php
check "test-php forwards a path to PHPUnit" "/var/scripts/test-php.sh newspack-ads tests/test-foo.php" "$(last_arg)"

run_n "$R/n" "$WORK" test-js ads
check "test-js normalizes a short name" "newspack-ads" "$(last_arg)"

run_n "$R/n" "$WORK" composer newspack-ads install
check "composer <name> <subcommand> targets that project" "/var/scripts/composer.sh newspack-ads install" "$(last_arg)"

run_n "$R/n" "$R/plugins/newspack-ads" composer update
check "composer forwards a subcommand that names no project" "/var/scripts/composer.sh newspack-ads update" "$(last_arg)"

run_n "$R/n" "$WORK" npm newspack-ads run build
check "npm <name> targets that project" "/var/scripts/npm.sh newspack-ads run build" "$(last_arg)"

# 3. Output mode and host root.
run_n "$R/n" "$R/plugins/newspack-ads" test-php
check "no agent: output mode is empty" "" "$(env_value NEWSPACK_TEST_OUTPUT)"
check "host root is the main checkout" "$R" "$(env_value NEWSPACK_HOST_ROOT)"

CLAUDECODE=1 run_n "$R/n" "$R/plugins/newspack-ads" test-php
check "under an agent: compact" "compact" "$(env_value NEWSPACK_TEST_OUTPUT)"

CLAUDECODE=1 NEWSPACK_TEST_OUTPUT=full run_n "$R/n" "$R/plugins/newspack-ads" test-js
check "NEWSPACK_TEST_OUTPUT=full overrides agent detection" "full" "$(env_value NEWSPACK_TEST_OUTPUT)"

NEWSPACK_TEST_ROOT_OK=1 run_n "$WT/n" "$WT" test-php newspack-ads
check "host root via a worktree's ./n is still the main checkout" "$R" "$(env_value NEWSPACK_HOST_ROOT)"

if [[ $failures -gt 0 ]]; then
	echo "$failures failure(s)"
	exit 1
fi
echo "all passed"
