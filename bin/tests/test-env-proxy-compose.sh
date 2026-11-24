#!/usr/bin/env bash
#
# test-env-proxy-compose.sh
#
# Self-proving spec for how an isolated env's compose file reaches the shared
# proxy (the `proxy` service in docker-compose.yml).
#
# Envs used to publish 80/443 on a loopback address of their own. Docker
# Desktop for Mac 4.97+ binds ports below 1024 on all interfaces, so the second
# container to publish 443 fails to start whatever address it asked for. Envs
# now publish nothing and have labels the proxy routes by.
#
# Compose files are written once, at `n env create`, so the helpers under test
# have two jobs: write the labels for a new env, and move a file from before
# the proxy onto it without touching anything else in it.
#
# Run: bash bin/tests/test-env-proxy-compose.sh

set -uo pipefail # not -e: cases assert on non-zero returns.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../_common.sh
. "$SCRIPT_DIR/../_common.sh"

WORK=$(mktemp -d -t env-proxy-XXXXXX)
trap 'rm -rf "$WORK"' EXIT

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

echo "env_proxy_labels:"
labels=$(env_proxy_labels demo demo.test)
assert_contains "$labels" 'traefik.tcp.routers.env-demo.rule=HostSNI(`demo.test`)' "TLS for the domain is routed by SNI"
assert_contains "$labels" 'traefik.tcp.routers.env-demo.tls.passthrough=true' "TLS is passed through, so the env keeps its own certificate"
assert_contains "$labels" 'traefik.http.routers.env-demo.rule=Host(`demo.test`)' "plain HTTP for the domain is routed by Host"
assert_contains "$labels" 'traefik.docker.network=newspack_envs' "the proxy is told which of the env's two networks to dial"

# A label key is a dotted path to Traefik. Env names allow dots.
labels=$(env_proxy_labels pr-1.2_x pr-1.2_x.test)
assert_contains "$labels" 'traefik.tcp.routers.env-pr-1-2-x.rule=HostSNI(`pr-1.2_x.test`)' "a dotted env name yields one router name and keeps its real domain"

echo "legacy_env_ip:"
legacy_file > "$WORK/legacy.yml"
assert_eq "$(legacy_env_ip "$WORK/legacy.yml")" "127.0.0.7" "a file from before the proxy reports its loopback address"
assert_eq "$(legacy_env_ip "$WORK/missing.yml")" "" "a missing file reports nothing"

echo "migrate_env_compose_to_proxy:"
cp "$WORK/legacy.yml" "$WORK/before.yml"
if migrate_env_compose_to_proxy "$WORK/legacy.yml" demo demo.test; then
	ok "a legacy file migrates"
else
	fail "a legacy file migrates"
fi
migrated=$(cat "$WORK/legacy.yml")
assert_eq "$(legacy_env_ip "$WORK/legacy.yml")" "" "the migrated file publishes on no loopback address"
if grep -q '^    ports:' "$WORK/legacy.yml"; then
	fail "the emptied ports key is removed"
else
	ok "the emptied ports key is removed"
fi
assert_eq "$(grep -c 'traefik.enable=true' "$WORK/legacy.yml")" "1" "the labels are added once"
# The labels take the ports block's place, so they sit inside the service.
assert_eq "$(awk '/^    labels:/ { l = NR } /^    env_file:/ { e = NR } END { print (l && e && l < e) ? "inside" : "outside" }' "$WORK/legacy.yml")" "inside" "the labels land inside the service, before env_file"
# Everything that was not a generated port line survives, in order.
want=$(grep -v -e '^    ports:' -e '^      - "127\.0\.0\.7:' "$WORK/before.yml")
got=$(awk '/^    labels:/ { skip = 1; next } skip && /^      - "traefik\./ { next } { skip = 0; print }' "$WORK/legacy.yml")
assert_eq "$got" "$want" "nothing else in the file changes"

if migrate_env_compose_to_proxy "$WORK/legacy.yml" demo demo.test; then
	fail "a migrated file reports nothing to migrate"
else
	ok "a migrated file reports nothing to migrate"
fi
assert_eq "$(cat "$WORK/legacy.yml")" "$migrated" "and is left byte for byte as it was"

# A port added by hand is not one of ours to remove.
legacy_file '      - "127.0.0.1:9003:9003"' > "$WORK/custom.yml"
migrate_env_compose_to_proxy "$WORK/custom.yml" demo demo.test
assert_eq "$(grep -A1 '^    ports:' "$WORK/custom.yml" | tail -1)" '      - "127.0.0.1:9003:9003"' "a hand-added port stays under its ports key"
assert_eq "$(legacy_env_ip "$WORK/custom.yml")" "" "while the generated 80/443 lines go"

# A new env's file never had the ports, and reads as already on the proxy.
legacy_file | grep -v -e '^    ports:' -e '^      - "127\.0\.0\.7:' > "$WORK/new.yml"
assert_eq "$(legacy_env_ip "$WORK/new.yml")" "" "the healthcheck's 127.0.0.1 is not mistaken for a published address"

echo
if [[ "$failures" -gt 0 ]]; then
	echo "$failures failure(s)."
	exit 1
fi
echo "All cases passed."
