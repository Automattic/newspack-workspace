#!/usr/bin/env bash
#
# test-env-up-keeps-installed-theme.sh
#
# Self-proving spec for `n env up` after a reboot: an installed site keeps its
# theme, and a fresh one still starts on newspack-theme.
#
# After a reboot, `up` can reach the env before the shared database accepts
# connections. The installed check then fails as though the tables were
# missing, `wp core install` runs on a site that already exists, and that path
# used to switch the site to newspack-theme. Three guards stop it, one case
# each:
#   - `up` waits for MariaDB before creating the env database;
#   - the installed check waits until the database answers;
#   - newspack-theme is activated only after an install that happened.
# The fresh-install case keeps the third guard from being satisfied by never
# activating the theme at all.
#
# Each case runs the real env.sh against a stub `docker` that logs every call
# and answers by pattern, so nothing reaches Docker, the database or
# /etc/hosts.
#
# Run: bash bin/tests/test-env-up-keeps-installed-theme.sh

set -uo pipefail # not -e: cases assert on non-zero exits.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

WORK=$(mktemp -d -t env-up-theme-XXXXXX)
trap 'rm -rf "$WORK"' EXIT

failures=0

ok() { echo "  ok: $1"; }
fail() {
	echo "  FAIL: $1"
	failures=$((failures + 1))
}

STUB="$WORK/stub"
mkdir -p "$STUB"
for cmd in sudo mkcert newspack-manage-host; do
	printf '#!/bin/sh\nexit 0\n' > "$STUB/$cmd"
	chmod +x "$STUB/$cmd"
done

# The stub reads its answers from the environment:
#   STUB_PING_FAILS  how many MariaDB pings fail before one succeeds
#   STUB_DB_FAILS    how many `wp db query` calls fail first, of either kind
#   STUB_TABLES      yes when the wp_options check should find the tables
#   STUB_INSTALL_OUT what `wp core install` prints
cat > "$STUB/docker" <<'STUB_DOCKER'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$STUB_LOG"
# fail_first <key> <count>: fail the first <count> calls for <key>.
fail_first() {
	local file="$STUB_STATE/$1" left
	left=$(cat "$file" 2>/dev/null || echo "$2")
	if [[ "$left" -gt 0 ]]; then
		echo $((left - 1)) > "$file"
		return 0
	fi
	echo 0 > "$file"
	return 1
}
case "$*" in
	*"mariadb-admin"*"ping"*)
		fail_first ping "${STUB_PING_FAILS:-0}" && exit 1
		exit 0 ;;
	*"db query SELECT 1 FROM wp_options LIMIT 1")
		fail_first db "${STUB_DB_FAILS:-0}" && exit 1
		[[ "${STUB_TABLES:-no}" == yes ]] && { echo 1; echo 1; exit 0; }
		exit 1 ;;
	*"db query SELECT 1")
		fail_first db "${STUB_DB_FAILS:-0}" && exit 1
		echo 1; echo 1; exit 0 ;;
	*"core install"*)
		printf '%s\n' "$STUB_INSTALL_OUT"
		exit 0 ;;
	*)
		exit 0 ;;
esac
STUB_DOCKER
chmod +x "$STUB/docker"

# new_sandbox <case>: a workspace root holding one env, `demo`, whose compose
# file already has every migration `up` applies, on 127.0.0.1 so no loopback
# alias or /etc/hosts entry is needed. _common.sh honours an inherited
# NABSPATH only when it holds an `n`.
new_sandbox() {
	SANDBOX="$WORK/root-$1"
	mkdir -p "$SANDBOX"
	touch "$SANDBOX/n" "$SANDBOX/default.env"
	ln -s "$SCRIPT_DIR/.." "$SANDBOX/bin"
	cat > "$SANDBOX/docker-compose.env-demo.yml" <<'COMPOSE'
services:
  env-demo:
    environment:
      - WP_DOMAIN=127.0.0.1
      - WP_ENVIRONMENT_TYPE=local
    healthcheck:
      test: ["CMD", "true"]
    networks:
      default: {}
      newspack_envs:
        aliases:
          - 127.0.0.1
networks:
  newspack_envs:
    external: true
COMPOSE
	export STUB_LOG="$SANDBOX/docker.log" STUB_STATE="$SANDBOX/state"
	mkdir -p "$STUB_STATE"
	: > "$STUB_LOG"
}

run_up() {
	PATH="$STUB:$PATH" NABSPATH="$SANDBOX" bash "$SCRIPT_DIR/../env.sh" up demo </dev/null 2>&1
}

# calls <pattern>: how many logged docker calls contain <pattern>.
calls() {
	grep -cF -- "$1" "$STUB_LOG"
}

# first_call <pattern>: the log line number of the first call containing it.
first_call() {
	grep -nF -- "$1" "$STUB_LOG" | head -1 | cut -d: -f1
}

echo "n env up:"

# The tables check misfires on an installed site, so `core install` runs and
# reports there is nothing to do. The theme must be left alone.
new_sandbox installed
status=0
out=$(STUB_TABLES=no STUB_INSTALL_OUT="WordPress is already installed." run_up) || status=$?
desc="an installed site that reaches core install keeps its theme"
if [[ "$status" -ne 0 ]]; then
	fail "$desc — want exit 0, got $status: $out"
elif [[ "$(calls "core install")" -ne 1 ]]; then
	fail "$desc — the case never reached core install, so it proves nothing"
elif [[ "$(calls "theme activate")" -ne 0 ]]; then
	fail "$desc — theme activate ran on an installed site"
else
	ok "$desc"
fi

new_sandbox fresh
status=0
out=$(STUB_TABLES=no STUB_INSTALL_OUT="Success: WordPress installed successfully." run_up) || status=$?
desc="a fresh install still activates newspack-theme once"
if [[ "$status" -ne 0 ]]; then
	fail "$desc — want exit 0, got $status: $out"
elif [[ "$(calls "theme activate newspack-theme")" -ne 1 ]]; then
	fail "$desc — want one theme activate newspack-theme, got $(calls "theme activate newspack-theme")"
else
	ok "$desc"
fi

# The database refuses the first query, as it does while still starting. `up`
# must wait for it rather than treat the site as uninstalled. Costs one sleep.
new_sandbox db-starting
status=0
out=$(STUB_DB_FAILS=1 STUB_TABLES=yes STUB_INSTALL_OUT="WordPress is already installed." run_up) || status=$?
desc="an installed site whose database is still starting is not reinstalled"
if [[ "$status" -ne 0 ]]; then
	fail "$desc — want exit 0, got $status: $out"
elif [[ "$(cat "$STUB_STATE/db" 2>/dev/null)" != 0 ]]; then
	fail "$desc — the case never saw the database refuse a query"
elif [[ "$(calls "core install")" -ne 0 ]]; then
	fail "$desc — core install ran before the database answered"
elif [[ "$out" != *"WordPress already installed."* ]]; then
	fail "$desc — want the already-installed branch, got: $out"
else
	ok "$desc"
fi

# MariaDB is not accepting connections yet. The env database must not be
# created until it is, or a new env is left with no database at all.
new_sandbox db-ping
status=0
out=$(STUB_PING_FAILS=1 STUB_TABLES=yes STUB_INSTALL_OUT="unused" run_up) || status=$?
desc="the env database is created only after MariaDB answers a ping"
ping_lines=$(grep -nF -- "mariadb-admin" "$STUB_LOG" | cut -d: -f1 | tr '\n' ' ')
create_line=$(first_call "CREATE DATABASE")
last_ping=$(grep -nF -- "mariadb-admin" "$STUB_LOG" | tail -1 | cut -d: -f1)
if [[ "$status" -ne 0 ]]; then
	fail "$desc — want exit 0, got $status: $out"
elif [[ "$(calls "mariadb-admin")" -lt 2 ]]; then
	fail "$desc — want a failed ping then a good one, got pings at lines: ${ping_lines:-none}"
elif [[ -z "$create_line" || "$create_line" -lt "$last_ping" ]]; then
	fail "$desc — CREATE DATABASE (line ${create_line:-none}) ran before the last ping (line $last_ping)"
else
	ok "$desc"
fi

echo
if [[ "$failures" -gt 0 ]]; then
	echo "$failures failure(s)"
	exit 1
fi
echo "all passed"
