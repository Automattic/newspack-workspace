#!/bin/bash

# Derived unless the caller already set it to a real workspace root: `n` resolves
# symlinks with `pwd -P` and that form has to survive being sourced here. An
# inherited value is checked rather than trusted — bin/worktree.sh removes trees
# under $NABSPATH, so a root arriving from the environment would delete elsewhere.
[[ -n "${NABSPATH:-}" && -f "$NABSPATH/n" ]] ||
    NABSPATH="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Names that become git branches and path components. Slashes are allowed here
# and not in validate_env_name, since `fix/some-thing` is the normal branch
# shape. The first character may not be a dash, so an option is never taken as
# a name: bin/worktree.sh and the --worktree parsing in bin/env.sh both read
# these positionally. A leading dot or underscore stays legal because branches
# like _pr738 are in use, and git rejects the refnames that are truly invalid.
validate_name() {
    if [[ ! "$1" =~ ^[a-zA-Z0-9._][a-zA-Z0-9._/-]*$ ]] || [[ "$1" == *..* ]] || [[ "$1" == /* ]]; then
        echo "Error: invalid $2 '$1' (must not start with '-'; only alphanumeric, dots, hyphens, underscores, slashes allowed; no '..' or leading '/')"
        exit 1
    fi
}

# Stricter validation for env names — no slashes (Docker rejects them in
# container/service names), and no leading dash. The dash rule is what stops an
# option being read as a name: bin/env.sh takes the name positionally, so
# without it `n env create --help` validates cleanly and creates an environment
# called "--help" instead of printing usage. It excludes a leading dash and
# nothing else, deliberately. This validator also gates `up`, `down` and
# `destroy`, so a rule that rejected leading dots or underscores would strand an
# environment created under an older, laxer one — unmanageable and removable
# only by hand.
# Stricter still for a name being created. The leniency in validate_env_name
# exists so environments made under an older, laxer rule stay manageable by
# `up`/`down`/`destroy`, and that reason cannot apply to a name that does not
# exist yet. A leading dot is the case it costs: `.demo` yields
# https://.demo.test, whose first DNS label is empty, and the container name and
# certificate are derived from the same string.
validate_new_env_name() {
    validate_env_name "$1"
    if [[ ! "$1" =~ ^[a-zA-Z0-9] ]]; then
        echo "Error: invalid environment name '$1' (must start with a letter or digit)"
        exit 1
    fi
}

validate_env_name() {
    # The `..` clause matches validate_name's. `n env destroy ..` would otherwise
    # validate and reach `rm -rf "$NABSPATH/envs/.."`, i.e. the workspace root.
    # POSIX rm refuses a trailing `.` or `..` component, so that is not a live
    # escape today -- but the guard then lives in rm rather than here, and moves
    # out from under us the moment a call site builds the path differently.
    if [[ ! "$1" =~ ^[a-zA-Z0-9._][a-zA-Z0-9._-]*$ ]] || [[ "$1" == *..* ]]; then
        echo "Error: invalid environment name '$1' (must not start with '-' or contain '..'; only alphanumeric, dots, hyphens, underscores allowed)"
        exit 1
    fi
}

validate_domain() {
    if [[ ! "$1" =~ ^[a-zA-Z0-9.-]+$ ]] || [[ ${#1} -gt 253 ]]; then
        echo "Error: invalid domain '$1'"
        exit 1
    fi
}

validate_port() {
    if [[ ! "$1" =~ ^[0-9]+$ ]] || [[ "$1" -lt 1 || "$1" -gt 65535 ]]; then
        echo "Error: invalid port '$1' (must be a number between 1 and 65535)"
        exit 1
    fi
}

# Is this loopback alias up on lo0? (macOS only — Linux routes all 127.x.x.x by
# default.) The address is compared whole, as a field of its own: loopback
# addresses are prefixes of each other (127.0.0.2 sits inside 127.0.0.24) and the
# low ones are recycled while higher envs stay up, so a substring test reports a
# free address as already aliased — and the env then dies binding a port on an
# address the host does not have. Returns 0 (shell true) when the address is up;
# `found` is unset, and so awk-numeric 0, when it is not. An unreadable lo0 is
# therefore reported absent, which makes the caller create the alias.
lo0_alias_exists() {
    ifconfig lo0 2>/dev/null | awk -v ip="$1" '$1 == "inet" && $2 == ip { found = 1 } END { exit !found }'
}

# The address every site answers on. The shared proxy (the `proxy` service in
# docker-compose.yml) listens on ports 80 and 443 there and routes by hostname,
# so an isolated env needs no loopback address of its own. /etc/hosts entries for env
# domains point here.
# shellcheck disable=SC2034 # read by the scripts that source this file.
ENV_PROXY_IP="127.0.0.1"

# The network the proxy and the isolated envs meet on. The proxy's compose
# service declares it external, so it has to exist before any `docker compose
# up` that starts the proxy. Safe to call repeatedly.
ensure_envs_network() {
    docker network inspect newspack_envs >/dev/null 2>&1 && return 0
    if ! docker network create newspack_envs >/dev/null; then
        echo "Error: failed to create Docker network 'newspack_envs'. Is Docker running?" >&2
        return 1
    fi
    echo "Created shared network: newspack_envs"
}

# The `labels:` block that registers an env's compose service with the proxy:
# TLS for its domain is passed through to the container's 443, plain HTTP for
# its domain goes to 80. Anything without such a block falls through to the
# main site (see the newspack-main routers in docker-compose.yml).
#
# Args: env name, domain.
env_proxy_labels() {
    local router domain="$2"
    # Traefik reads a label key as a dotted path, so a dot in the env name would
    # cut the router name in two. Env names allow dots and underscores.
    router="env-$(printf '%s' "$1" | tr -c 'a-zA-Z0-9' '-')"
    printf '    labels:\n'
    printf '      - "traefik.enable=true"\n'
    printf '      - "traefik.docker.network=newspack_envs"\n'
    # shellcheck disable=SC2016 # the backticks are Traefik rule syntax, not shell.
    printf '      - "traefik.tcp.routers.%s.rule=HostSNI(`%s`)"\n' "$router" "$domain"
    printf '      - "traefik.tcp.routers.%s.entrypoints=websecure"\n' "$router"
    printf '      - "traefik.tcp.routers.%s.tls.passthrough=true"\n' "$router"
    printf '      - "traefik.tcp.services.%s.loadbalancer.server.port=443"\n' "$router"
    # shellcheck disable=SC2016
    printf '      - "traefik.http.routers.%s.rule=Host(`%s`)"\n' "$router" "$domain"
    printf '      - "traefik.http.routers.%s.entrypoints=web"\n' "$router"
    printf '      - "traefik.http.services.%s.loadbalancer.server.port=80"\n' "$router"
}

# The loopback address an env compose file publishes 443 on, for a file written
# before the proxy existed. Prints nothing for an env that already goes through
# the proxy. Matched on the published-port line alone: every env file also
# mentions 127.0.0.1 in its healthcheck.
legacy_env_ip() {
    sed -nE 's/^[[:space:]]*-[[:space:]]*"(127\.0\.0\.[0-9]+):443:443"[[:space:]]*$/\1/p' "$1" 2>/dev/null | head -1
}

# Move a legacy env compose file onto the proxy: drop the 80/443 ports it
# published on its own loopback address and add the proxy labels where they
# were. Compose files are written once at create time, so without this an env
# made before the proxy keeps asking for a port the proxy is already bound to.
#
# Only the two generated port lines are removed. A port someone added by hand
# stays, under its `ports:` key.
#
# Args: compose file, env name, domain. Returns 0 when the file was rewritten,
# 1 when it had nothing to migrate or the rewrite did not take.
migrate_env_compose_to_proxy() {
    local file="$1" labels
    [[ -n "$(legacy_env_ip "$file")" ]] || return 1
    labels=$(env_proxy_labels "$2" "$3")
    # The labels travel through the environment: awk -v rejects a value with
    # newlines on macOS.
    ENV_PROXY_LABELS="$labels" awk '
        function flush_ports(   i) {
            if (!in_ports) return
            if (kept > 0) {
                print "    ports:"
                for (i = 1; i <= kept; i++) print keep[i]
            }
            if (!placed) { print ENVIRON["ENV_PROXY_LABELS"]; placed = 1 }
            in_ports = 0
            kept = 0
        }
        $0 == "    ports:" && !placed { in_ports = 1; next }
        in_ports && /^      - / {
            if ($0 ~ /^      - "127\.0\.0\.[0-9]+:(80:80|443:443)"$/) next
            keep[++kept] = $0
            next
        }
        { flush_ports(); print }
        END { flush_ports() }
    ' "$file" > "${file}.tmp" && mv "${file}.tmp" "$file"
    # awk cannot report whether it found the block, so read the result back.
    [[ -z "$(legacy_env_ip "$file")" ]] && grep -q 'traefik.enable=true' "$file"
}

# Whether a project's vendor/ was installed with its dev dependencies.
#
# `n env up` provisions vendor/ with --no-dev (see ensure-vendor.sh): enough to
# activate a plugin, but without PHPUnit or the Yoast polyfills the WordPress
# test bootstrap requires. Both kinds of install write vendor/autoload.php, so
# its presence says nothing about whether the tests can run — composer's own
# record of which install produced the directory, installed.json's top-level
# "dev" flag, is what separates them.
#
# Read without a JSON parser: this is sourced on the host, in the containers and
# in CI, and no single parser is present in all three. Two things make the
# plain-text read safe. The value must be a bare boolean alone on its line,
# which skips the `"dev": "Development related task"` entries real packages
# carry in their metadata; and of the lines surviving that, the last is the
# top-level flag, because composer writes it after the packages array closes.
# Leading whitespace is not matched, so re-indenting the file changes nothing.
#
# The two unhappy paths answer differently, on purpose:
#
# - No readable installed.json means nothing was installed here, so the tests
#   cannot run whatever else is true. Report absent and let the caller explain
#   how to fix it.
# - A file that exists but yields no recognisable flag means composer wrote a
#   shape this function does not know — a format change, say. Report present.
#   Guessing "absent" there would block every project at once, with no way past
#   it; reporting present costs only a return to the bootstrap error this guard
#   was added to replace, for whoever hits it first.
project_has_dev_deps() {
    local installed="$1/vendor/composer/installed.json"
    [ -r "$installed" ] || return 1

    local flag
    flag=$(grep -E '^[[:space:]]*"dev": *(true|false),?$' "$installed" | tail -1)
    [ -n "$flag" ] || return 0

    [[ "$flag" == *true* ]]
}

# Logging helpers — mirror the colored output used by bin/site-setup.sh.
NP_RED='\033[0;31m'
NP_GREEN='\033[0;32m'
NP_YELLOW='\033[1;33m'
NP_BLUE='\033[0;34m'
NP_NC='\033[0m'

log_info() { echo -e "${NP_BLUE}[INFO]${NP_NC} ${1}"; }
log_success() { echo -e "${NP_GREEN}[SUCCESS]${NP_NC} ${1}"; }
log_warning() { echo -e "${NP_YELLOW}[WARNING]${NP_NC} ${1}"; }
# Warn when the installed passwordless wrapper is older than the repo copy.
#
# bin/setup-networking.sh snapshots bin/newspack-manage-host to /usr/local/bin
# once, and nothing re-syncs it: every caller resolves the installed copy and
# gates only on `command -v`. A machine set up before a change to the wrapper
# therefore keeps running the old one indefinitely, which matters because the
# wrapper runs as root -- the PATH pin it carries reaches nobody who does not
# re-run the installer. Warn rather than act: this is a sudo-owned file, and a
# script that silently re-copied it would need root at an arbitrary moment.
warn_if_manage_host_stale() {
    local installed="/usr/local/bin/newspack-manage-host"
    local repo="${NABSPATH:-.}/bin/newspack-manage-host"
    [ -f "$installed" ] && [ -f "$repo" ] || return 0
    cmp -s "$installed" "$repo" && return 0
    log_warning "The installed newspack-manage-host differs from this checkout's copy."
    log_warning "Re-run ./bin/setup-networking.sh to update it (it runs as root via sudo)."
}

log_error() { echo -e "${NP_RED}[ERROR]${NP_NC} ${1}"; }

# Print the name of the isolated env that serves <path>, or return 1 when none
# does. `n` routes commands with this and `n env which` reports with it, so the
# two can never disagree about which env a worktree belongs to.
#
# It reads each compose file's mount lines and picks the LONGEST absolute path
# that <path> sits under. An earlier 2-segment prefix match collapsed branches
# with slashes (`jl/news-2239-pr1b-frontend` -> `jl`) and matched the wrong env
# when several mounted `jl/*` worktrees.
env_for_path() {
    local path="$1"
    [[ "$path" == "$NABSPATH/worktrees/"* || "$path" == "$NABSPATH/worktrees-repos/"* ]] || return 1
    local best_match="" best_env="" f mount
    for f in "$NABSPATH"/docker-compose.env-*.yml; do
        [[ -f "$f" ]] || continue
        while IFS= read -r mount; do
            # Mounts can take three shapes — pre-monorepo per-plugin worktrees
            # (./worktrees/<repo>/<branch>:/newspack-repos/<repo>), monorepo
            # workspace worktrees (./worktrees/<safe_branch>/plugins/<name>:/newspack-plugins/<name>,
            # or .../themes/<name>:/newspack-themes/<name>), and standalone
            # repos/ worktrees (./worktrees-repos/<name>/<safe_branch>:/newspack-repos/<kind>/<name>).
            # For the monorepo shape, the env mounts a per-plugin subdir but a
            # path elsewhere in the worktree (its root, bin/, packages/, sibling
            # plugins) still belongs to it, so match the enclosing worktree root
            # as well. Regex extraction tolerates tabs/multi-space and cuts
            # cleanly at the next `:` so mount-mode suffixes (`:ro`) and
            # trailing comments don't corrupt the captured paths.
            [[ "$mount" =~ ^[[:space:]]*-[[:space:]]+\./worktrees(-repos)?/([^[:space:]:]+):/newspack-(repos|plugins|themes)/[^[:space:]:]+ ]] || continue
            local rel="worktrees${BASH_REMATCH[1]}/${BASH_REMATCH[2]}"
            local container_type="${BASH_REMATCH[3]}"
            local abs="$NABSPATH/$rel"
            local worktree_root="$abs"
            case "$container_type" in
                plugins|themes)
                    worktree_root="${abs%/plugins/*}"
                    worktree_root="${worktree_root%/themes/*}"
                    ;;
            esac
            # Prefer the deeper mounted-subpath match (more specific) so a path
            # inside a mounted plugin/theme resolves to that env even when a
            # sibling env mounts a different plugin from the same worktree.
            # Fall back to the worktree root for shared dirs (bin/, packages/,
            # the worktree root itself).
            if [[ "$path" == "$abs" || "$path" == "$abs"/* ]] && [ ${#abs} -gt ${#best_match} ]; then
                best_match="$abs"
                best_env=$(basename "$f" | sed 's/docker-compose\.env-//;s/\.yml//')
            elif [[ "$path" == "$worktree_root" || "$path" == "$worktree_root"/* ]] && [ ${#worktree_root} -gt ${#best_match} ]; then
                best_match="$worktree_root"
                best_env=$(basename "$f" | sed 's/docker-compose\.env-//;s/\.yml//')
            fi
        done < <(grep -E '^[[:space:]]*-[[:space:]]+\./worktrees(-repos)?/[^[:space:]:]+:/newspack-(repos|plugins|themes)/[^[:space:]:]+' "$f" 2>/dev/null)
    done
    [[ -n "$best_env" ]] || return 1
    echo "$best_env"
}
