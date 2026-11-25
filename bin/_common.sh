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

# Where the proxy reads its routes: one small file per env plus one for the main
# site, written here by `n` and mounted read-only into the proxy, which watches
# the directory. Files rather than the proxy asking Docker which containers
# exist, because that needs the Docker socket, and the socket would hand the
# proxy control of every container and every path Docker can mount.
#
# A directory of its own rather than one under envs/: `n env destroy <name>`
# removes envs/<name>, and a route directory in there could be named and
# deleted as if it were an env.
PROXY_ROUTES_DIR="$NABSPATH/.proxy-routes"

# The route file for an env. The name is used as is, so two envs can never
# share a file: env names are unique, and all of their characters are legal in
# a file name and in a route name.
proxy_route_file() {
    echo "$PROXY_ROUTES_DIR/env-$1.yml"
}

# Read domain from a compose file's WP_DOMAIN env var.
domain_for_env() {
    grep 'WP_DOMAIN=' "$1" | head -1 | sed 's/.*WP_DOMAIN=//'
}

# An env's container name. Docker rejects dashes nowhere, but the name doubles
# as a database-safe identifier elsewhere, so dashes become underscores.
env_container_name() {
    echo "newspack_env_$1" | tr '-' '_'
}

# The route for one env: TLS for its domain is passed through to the env's own
# Apache, which keeps serving the env's certificate, and plain HTTP for its
# domain goes to port 80.
#
# The backend is the container's name, never the domain. Only Docker's own DNS
# knows that name, so when the env is down the lookup fails and the request
# fails with it. Docker hands a name it does not know to the host's resolver,
# and for the domain that resolver has an answer: the /etc/hosts line pointing
# at the proxy, which would then connect to itself, or the real site, for a
# custom domain that also exists publicly.
#
# Args: env name, domain.
env_proxy_route() {
    local id="env-$1" domain="$2" backend
    backend=$(env_container_name "$1")
    # shellcheck disable=SC2016 # the backticks are Traefik rule syntax, not shell.
    printf '%s\n' \
        'tcp:' \
        '  routers:' \
        "    $id:" \
        '      rule: "HostSNI(`'"$domain"'`)"' \
        '      entryPoints: [websecure]' \
        "      service: $id" \
        '      tls:' \
        '        passthrough: true' \
        '  services:' \
        "    $id:" \
        '      loadBalancer:' \
        '        servers:' \
        "          - address: \"$backend:443\"" \
        'http:' \
        '  routers:' \
        "    $id:" \
        '      rule: "Host(`'"$domain"'`)"' \
        '      entryPoints: [web]' \
        "      service: $id" \
        '  services:' \
        "    $id:" \
        '      loadBalancer:' \
        '        servers:' \
        "          - url: \"http://$backend:80\""
}

# The route for everything no env claims: localhost, the additional sites, the
# manager hub. Priority 1 puts it behind every env's route.
main_proxy_route() {
    # shellcheck disable=SC2016 # the backticks are Traefik rule syntax, not shell.
    printf '%s\n' \
        'tcp:' \
        '  routers:' \
        '    newspack-main:' \
        '      rule: "HostSNI(`*`)"' \
        '      priority: 1' \
        '      entryPoints: [websecure]' \
        '      service: newspack-main' \
        '      tls:' \
        '        passthrough: true' \
        '  services:' \
        '    newspack-main:' \
        '      loadBalancer:' \
        '        servers:' \
        '          - address: "wordpress:443"' \
        'http:' \
        '  routers:' \
        '    newspack-main:' \
        '      rule: "PathPrefix(`/`)"' \
        '      priority: 1' \
        '      entryPoints: [web]' \
        '      service: newspack-main' \
        '  services:' \
        '    newspack-main:' \
        '      loadBalancer:' \
        '        servers:' \
        '          - url: "http://wordpress:80"'
}

# Write a route file from stdin. The content lands in a temp file and is then
# renamed into place, so the proxy never reads half a file. The temp name has
# no .yml extension, which is the one thing the proxy goes by when it decides
# what to load: a half-written .yml would fail the whole directory, main route
# included.
# The temp name is unique to this call, because several `n` commands sync the
# routes at once and would otherwise write through the same file. An unchanged
# route is left alone, which spares the proxy a reload.
#
# Modes are set here, not left to the caller's umask: the proxy runs as a user
# of its own and reads the directory as "other".
#
# Args: target path.
write_proxy_route() {
    local target="$1" tmp
    mkdir -p "$PROXY_ROUTES_DIR" && chmod 755 "$PROXY_ROUTES_DIR" || return 1
    tmp=$(mktemp "$PROXY_ROUTES_DIR/.route.XXXXXX") || return 1
    if ! cat > "$tmp"; then
        rm -f "$tmp"
        return 1
    fi
    if [[ -f "$target" ]] && cmp -s "$tmp" "$target"; then
        rm -f "$tmp"
        return 0
    fi
    chmod 644 "$tmp" && mv "$tmp" "$target" || { rm -f "$tmp"; return 1; }
}

# Make the route files match the envs that exist: the main route, one route per
# env compose file, and nothing for an env that is gone. Run before the proxy
# starts, so the directory it mounts is there and is the user's. Docker would
# otherwise create it as root on Linux.
sync_proxy_routes() {
    local f name domain route
    main_proxy_route | write_proxy_route "$PROXY_ROUTES_DIR/main.yml" || return 1
    for f in "$NABSPATH"/docker-compose.env-*.yml; do
        [[ -f "$f" ]] || continue
        name=$(basename "$f")
        name="${name#docker-compose.env-}"
        name="${name%.yml}"
        domain=$(domain_for_env "$f")
        [[ -n "$domain" ]] || continue
        env_proxy_route "$name" "$domain" | write_proxy_route "$(proxy_route_file "$name")" || return 1
    done
    for route in "$PROXY_ROUTES_DIR"/env-*.yml; do
        [[ -f "$route" ]] || continue
        name=$(basename "$route")
        name="${name#env-}"
        name="${name%.yml}"
        [[ -f "$NABSPATH/docker-compose.env-$name.yml" ]] || rm -f "$route"
    done
}

# The address /etc/hosts gives a name: the first line that lists it, which is
# the one the system resolver uses. Prints nothing when no line does.
hosts_ip_for() {
    awk -v d="$1" '
        /^[[:space:]]*#/ { next }
        {
            for (i = 2; i <= NF; i++) {
                if ($i ~ /^#/) break
                if ($i == d) { print $1; found = 1; exit }
            }
        }
    ' /etc/hosts 2>/dev/null
}

# Point an env's domain at the proxy in /etc/hosts, replacing a line left from
# when the env had a loopback address of its own. Such a line keeps working
# only while that address is still aliased, which ends at the next reboot.
#
# Uses the passwordless wrapper when it is installed, sudo when there is a
# terminal to ask on, and otherwise prints what to change. Returns 0 when the
# file ends up right, 1 when it still needs a change by hand.
point_host_at_proxy() {
    local domain="$1" current stale_line
    current=$(hosts_ip_for "$domain")
    [[ "$current" == "$ENV_PROXY_IP" ]] && return 0
    if command -v newspack-manage-host >/dev/null 2>&1 && [[ "$domain" == *.test || "$domain" == *.local ]]; then
        [[ -n "$current" ]] && sudo newspack-manage-host host-remove "$domain"
        sudo newspack-manage-host host-add "$ENV_PROXY_IP" "$domain"
    elif [ -t 0 ] && [ -t 1 ]; then
        echo "Pointing $domain at $ENV_PROXY_IP in /etc/hosts (requires sudo)..."
        if [[ -n "$current" ]]; then
            # Only a line that maps this domain alone is ours to remove.
            stale_line="^[[:space:]]*${current//./\\.}[[:space:]]+${domain//./\\.}[[:space:]]*$"
            { sudo sed -i '' -E "/${stale_line}/d" /etc/hosts 2>/dev/null || \
                sudo sed -i -E "/${stale_line}/d" /etc/hosts; }
        fi
        [[ -z "$(hosts_ip_for "$domain")" ]] && echo "$ENV_PROXY_IP $domain" | sudo tee -a /etc/hosts > /dev/null
    fi
    current=$(hosts_ip_for "$domain")
    [[ "$current" == "$ENV_PROXY_IP" ]] && return 0
    if [[ -n "$current" ]]; then
        echo "Warning: /etc/hosts maps $domain to $current, an address from before the shared proxy. It stops working after a reboot. Change that line to: $ENV_PROXY_IP $domain"
    else
        echo "Warning: $domain is not in /etc/hosts, so a browser cannot reach it. Add it with: sudo sh -c 'echo \"$ENV_PROXY_IP $domain\" >> /etc/hosts'"
    fi
    return 1
}

# Stop running env containers that still publish 80/443 themselves, as envs did
# before the proxy. Where Docker binds those ports on every interface, one such
# container keeps the proxy from starting. The env's data is untouched, and its
# next `n env up` moves it onto the proxy.
#
# Returns 0 when it stopped at least one container, 1 when there was none.
stop_legacy_env_publishers() {
    local none_stopped=1 name ports f env
    while IFS=$'\t' read -r name ports; do
        [[ "$name" == newspack_env_* ]] || continue
        [[ "$ports" == *":443->"* || "$ports" == *":80->"* ]] || continue
        docker stop "$name" >/dev/null 2>&1
        docker rm "$name" >/dev/null 2>&1
        env=""
        for f in "$NABSPATH"/docker-compose.env-*.yml; do
            [[ -f "$f" ]] || continue
            if grep -q "container_name: ${name}\$" "$f"; then
                env=$(basename "$f")
                env="${env#docker-compose.env-}"
                env="${env%.yml}"
                break
            fi
        done
        echo "Stopped $name: it published ports 80/443 itself, which the shared proxy does now. Bring it back with: n env up ${env:-<name>}"
        none_stopped=0
    done < <(docker ps --format '{{.Names}}\t{{.Ports}}' 2>/dev/null)
    return "$none_stopped"
}

# The loopback address an env compose file publishes 443 on, for a file written
# before the proxy existed. Prints nothing for an env that already goes through
# the proxy. Matched on the published-port line alone: every env file also
# mentions 127.0.0.1 in its healthcheck.
legacy_env_ip() {
    sed -nE 's/^[[:space:]]*-[[:space:]]*"(127\.0\.0\.[0-9]+):443:443"[[:space:]]*$/\1/p' "$1" 2>/dev/null | head -1
}

# Move a legacy env compose file onto the proxy by dropping the 80/443 ports it
# published on its own loopback address. Compose files are written once, at
# create time, so without this an env made before the proxy keeps asking for a
# port the proxy is already bound to.
#
# Only the two generated port lines go. A port someone added by hand stays,
# under its `ports:` key. The file is replaced only once the result is known to
# be good, so a file this cannot handle is left exactly as it was.
#
# Args: compose file. Returns 0 when the file was rewritten, 1 when there was
# nothing to migrate, 2 when the rewrite did not produce a usable file.
drop_legacy_env_ports() {
    local file="$1"
    [[ -n "$(legacy_env_ip "$file")" ]] || return 1
    local generated='^      - "127\.0\.0\.[0-9]+:(80:80|443:443)"[[:space:]]*$'
    local header='^    ports:[[:space:]]*$'
    # The block runs until the next line that is not indented deeper than its
    # key. Comments and blank lines inside it stay with it: ending the block at
    # one would drop the key and leave a hand-added port below it stranded
    # under whatever came before.
    # The pattern travels through the environment: awk -v would eat its backslashes.
    GENERATED_PORT_LINE="$generated" awk '
        function flush_ports(   i) {
            if (!in_ports) return
            if (entries > 0) print ports_line
            for (i = 1; i <= kept; i++) print keep[i]
            in_ports = 0
            kept = 0
            entries = 0
        }
        /^    ports:[[:space:]]*$/ { flush_ports(); in_ports = 1; ports_line = $0; next }
        in_ports && ($0 ~ /^[[:space:]]*$/ || $0 ~ /^     /) {
            if ($0 ~ ENVIRON["GENERATED_PORT_LINE"]) next
            keep[++kept] = $0
            if ($0 ~ /^[[:space:]]*-[[:space:]]/) entries++
            next
        }
        { flush_ports(); print }
        END { flush_ports() }
    ' "$file" > "${file}.tmp" || { rm -f "${file}.tmp"; return 2; }
    # awk cannot report what it did, so read the result back: no generated line
    # left, and every other line still there, in order. Only the `ports:` key
    # itself may have gone with them.
    if [[ -s "${file}.tmp" && -z "$(legacy_env_ip "${file}.tmp")" ]] \
        && diff -q <(grep -vE "$generated" "$file" | grep -vE "$header") <(grep -vE "$header" "${file}.tmp") >/dev/null 2>&1; then
        mv "${file}.tmp" "$file"
        return 0
    fi
    rm -f "${file}.tmp"
    return 2
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
