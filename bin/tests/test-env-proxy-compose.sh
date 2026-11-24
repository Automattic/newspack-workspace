#!/usr/bin/env bash
#
# test-env-proxy-compose.sh
#
# Self-proving spec for how an isolated env reaches the shared proxy (the
# `proxy` service in docker-compose.yml).
#
# Envs used to publish 80/443 on a loopback address of their own. Docker
# Desktop for Mac 4.97+ binds ports below 1024 on all interfaces, so the second
# container to publish 443 fails to start whatever address it asked for. Envs
# now publish nothing, and the proxy routes to them from one small file per env.
#
# The proxy has no Docker socket, so these files are the only way it learns
# which envs exist. That puts three jobs on the helpers under test: write a
# route that sends a domain to its own env and nowhere else, keep the set of
# routes equal to the set of envs, and move a compose file from before the
# proxy onto it without touching anything else in it.
#
# Run: bash bin/tests/test-env-proxy-compose.sh

set -uo pipefail # not -e: cases assert on non-zero returns.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../_common.sh
. "$SCRIPT_DIR/../_common.sh"

WORK=$(mktemp -d -t env-proxy-XXXXXX)
trap 'rm -rf "$WORK"' EXIT

# The helpers read both at call time, so pointing them at a sandbox here keeps
# every case away from the real workspace.
NABSPATH="$WORK/root"
PROXY_ROUTES_DIR="$WORK/root/.proxy-routes"
mkdir -p "$NABSPATH"

failures=0
ok() { echo "  ok: $1"; }
fail() {
	echo "  FAIL: $1"
	failures=$((failures + 1))
}
assert_eq() {
	if [[ "$1" == "$2" ]]; then ok "$3"; else fail "$3: want '$2', got '$1'"; fi
}
assert_contains() {
	if grep -qF -- "$2" <<< "$1"; then ok "$3"; else fail "$3: missing '$2'"; fi
}

# An env compose file as `n env create` wrote it before the proxy. $1 is an
# extra line for the ports block, for the hand-edited case.
legacy_file() {
	cat <<YAML
services:
  env-demo:
    container_name: newspack_env_demo
    image: newspack-dev:latest
    volumes:
      - ./envs/demo/html:/var/www/html
    ports:
      - "127.0.0.7:80:80"
      - "127.0.0.7:443:443"
${1:+$1
}    env_file:
      - default.env
    environment:
      - WP_DOMAIN=demo.test
    healthcheck:
      test: ["CMD", "bash", "-c", "echo > /dev/tcp/127.0.0.1/11211"]
    networks:
      default: {}
      newspack_envs:
        aliases:
          - demo.test
networks:
  newspack_envs:
    external: true
YAML
}

echo "env_proxy_route:"
route=$(env_proxy_route demo demo.test)
# shellcheck disable=SC2016 # the backticks are Traefik rule syntax, not shell.
assert_contains "$route" 'rule: "HostSNI(`demo.test`)"' "TLS for the domain is matched by SNI"
assert_contains "$route" 'passthrough: true' "TLS is passed through, so the env keeps its own certificate"
assert_contains "$route" 'address: "newspack_env_demo:443"' "and goes to the env's own 443"
# shellcheck disable=SC2016
assert_contains "$route" 'rule: "Host(`demo.test`)"' "plain HTTP for the domain is matched by Host"
assert_contains "$route" 'url: "http://newspack_env_demo:80"' "and goes to the env's own 80"
# The backend must be a name only Docker's DNS knows. Docker passes any other
# name to the host's resolver, where a stopped env's domain still resolves: to
# the proxy itself through /etc/hosts, or to the real site for a public domain.
route_custom=$(env_proxy_route my-site news.example.com)
if grep -E '(address|url):' <<< "$route_custom" | grep -q 'news\.example\.com'; then
	fail "a route never dials the env's domain, which the host can resolve when the env is down"
else
	ok "a route never dials the env's domain, which the host can resolve when the env is down"
fi
assert_contains "$route_custom" 'address: "newspack_env_my_site:443"' "it dials the container by name instead"

# Env names allow dots, dashes and underscores. Folding them into one safe
# character would give these two envs the same route, and one would take the
# other's traffic.
id_dot=$(env_proxy_route foo.bar foo.bar.test | sed -n '3p')
id_dash=$(env_proxy_route foo-bar foo-bar.test | sed -n '3p')
if [[ "$id_dot" != "$id_dash" && "$(proxy_route_file foo.bar)" != "$(proxy_route_file foo-bar)" ]]; then
	ok "envs named foo.bar and foo-bar get separate routes and files"
else
	fail "envs named foo.bar and foo-bar get separate routes and files"
fi

echo "main_proxy_route:"
# Without the low priority the catch-all could win over an env's own route.
assert_eq "$(main_proxy_route | grep -c '^      priority: 1$')" "2" "the catch-all for 443 and for 80 both sit behind every env route"

echo "write_proxy_route:"
echo "first" | write_proxy_route "$PROXY_ROUTES_DIR/env-a.yml"
assert_eq "$(cat "$PROXY_ROUTES_DIR/env-a.yml")" "first" "a route is written"
inode_before=$(ls -i "$PROXY_ROUTES_DIR/env-a.yml" | awk '{ print $1 }')
echo "first" | write_proxy_route "$PROXY_ROUTES_DIR/env-a.yml"
assert_eq "$(ls -i "$PROXY_ROUTES_DIR/env-a.yml" | awk '{ print $1 }')" "$inode_before" "an unchanged route is not replaced, so the proxy is not made to reload"
# Checked right after the unchanged write, the one path that has to delete its
# temp file itself. Temp files pile up in a directory the proxy watches.
assert_eq "$(find "$PROXY_ROUTES_DIR" -type f ! -name 'env-a.yml' | wc -l | tr -d ' ')" "0" "an unchanged write leaves no temp file behind"
echo "second" | write_proxy_route "$PROXY_ROUTES_DIR/env-a.yml"
assert_eq "$(cat "$PROXY_ROUTES_DIR/env-a.yml")" "second" "a changed route is replaced"
assert_eq "$(find "$PROXY_ROUTES_DIR" -type f ! -name 'env-a.yml' | wc -l | tr -d ' ')" "0" "and so does a changed one"
# The proxy runs as a user of its own, so a private umask must not hide routes from it.
(umask 077; echo "third" | write_proxy_route "$PROXY_ROUTES_DIR/env-a.yml")
assert_eq "$(ls -l "$PROXY_ROUTES_DIR/env-a.yml" | cut -c1-10)" "-rw-r--r--" "a route stays readable by the proxy under a private umask"
rm -f "$PROXY_ROUTES_DIR/env-a.yml"

echo "sync_proxy_routes:"
legacy_file > "$NABSPATH/docker-compose.env-demo.yml"
legacy_file | sed 's/demo\.test/custom.example.test/' > "$NABSPATH/docker-compose.env-other.yml"
echo "stale" > "$PROXY_ROUTES_DIR/env-gone.yml"
sync_proxy_routes
assert_eq "$(cat "$PROXY_ROUTES_DIR/main.yml")" "$(main_proxy_route)" "the main route is written"
assert_eq "$(cat "$PROXY_ROUTES_DIR/env-demo.yml")" "$(env_proxy_route demo demo.test)" "each env gets a route for the domain in its compose file"
# shellcheck disable=SC2016
assert_contains "$(cat "$PROXY_ROUTES_DIR/env-other.yml")" 'rule: "HostSNI(`custom.example.test`)"' "a custom domain is matched as given"
if [[ -e "$PROXY_ROUTES_DIR/env-gone.yml" ]]; then
	fail "the route of an env that no longer exists is removed"
else
	ok "the route of an env that no longer exists is removed"
fi
rm -f "$NABSPATH"/docker-compose.env-*.yml

echo "legacy_env_ip:"
legacy_file > "$WORK/legacy.yml"
assert_eq "$(legacy_env_ip "$WORK/legacy.yml")" "127.0.0.7" "a file from before the proxy reports its loopback address"
assert_eq "$(legacy_env_ip "$WORK/missing.yml")" "" "a missing file reports nothing"

echo "drop_legacy_env_ports:"
cp "$WORK/legacy.yml" "$WORK/before.yml"
drop_legacy_env_ports "$WORK/legacy.yml"
assert_eq "$?" "0" "a legacy file migrates"
migrated=$(cat "$WORK/legacy.yml")
assert_eq "$(legacy_env_ip "$WORK/legacy.yml")" "" "the migrated file publishes on no loopback address"
assert_eq "$migrated" "$(grep -v -e '^    ports:' -e '^      - "127\.0\.0\.7:' "$WORK/before.yml")" "only the ports block goes, and nothing else in the file changes"

drop_legacy_env_ports "$WORK/legacy.yml"
assert_eq "$?" "1" "a migrated file reports nothing to migrate"
assert_eq "$(cat "$WORK/legacy.yml")" "$migrated" "and is left byte for byte as it was"

# A port added by hand is not one of ours to remove.
legacy_file '      - "127.0.0.1:9003:9003"' > "$WORK/custom.yml"
drop_legacy_env_ports "$WORK/custom.yml"
assert_eq "$(grep -A1 '^    ports:' "$WORK/custom.yml" | tail -1)" '      - "127.0.0.1:9003:9003"' "a hand-added port stays under its ports key"
assert_eq "$(legacy_env_ip "$WORK/custom.yml")" "" "while the generated 80/443 lines go"

# An editor that leaves trailing whitespace must not strand the env on its old address.
legacy_file | sed 's/:443:443"$/:443:443"  /' > "$WORK/trailing.yml"
drop_legacy_env_ports "$WORK/trailing.yml"
assert_eq "$(legacy_env_ip "$WORK/trailing.yml")" "" "port lines with trailing whitespace are still removed"

# A file the rewrite cannot handle (here, re-indented) must survive untouched:
# a half-migrated compose file would take the env down with no way back.
legacy_file | sed 's/^      - "127/        - "127/' > "$WORK/reindented.yml"
cp "$WORK/reindented.yml" "$WORK/reindented.before.yml"
drop_legacy_env_ports "$WORK/reindented.yml"
assert_eq "$?" "2" "a file the rewrite cannot handle is reported as failed"
assert_eq "$(cat "$WORK/reindented.yml")" "$(cat "$WORK/reindented.before.yml")" "and is left exactly as it was"
assert_eq "$(find "$WORK" -maxdepth 1 -name '*.tmp' | wc -l | tr -d ' ')" "0" "with no temp file left behind"

# A new env's file never had the ports, and reads as already on the proxy.
legacy_file | grep -v -e '^    ports:' -e '^      - "127\.0\.0\.7:' > "$WORK/new.yml"
assert_eq "$(legacy_env_ip "$WORK/new.yml")" "" "the healthcheck's 127.0.0.1 is not mistaken for a published address"

echo
if [[ "$failures" -gt 0 ]]; then
	echo "$failures failure(s)."
	exit 1
fi
echo "All cases passed."
