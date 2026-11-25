#!/bin/bash

source "$(dirname "${BASH_SOURCE[0]}")/_common.sh"
source "$(dirname "${BASH_SOURCE[0]}")/repos.sh"

# Sanitize env name for use as a database name (replace dashes with underscores).
db_name_for_env() {
    echo "wordpress_$(echo "$1" | tr '-' '_')"
}

# The PHPUnit database for an env. Created lazily by the first `n test-php` run
# in the env, so it may not exist. Keep in step with bin/test-php.sh, which
# derives the same name inside the container from $MYSQL_DATABASE.
test_db_name_for_env() {
    echo "wp_tests_$(echo "$1" | tr '-' '_')"
}

# The address an env's /etc/hosts entry belongs on: the shared proxy's, or the
# env's own loopback address for a compose file not yet migrated onto the proxy
# (see drop_legacy_env_ports).
ip_for_env() {
    local legacy
    legacy=$(legacy_env_ip "$1")
    echo "${legacy:-$ENV_PROXY_IP}"
}

# Whether a TLS connection for the domain ends at the env itself: the
# certificate that comes back names the domain. Each env serves a certificate
# for its own domain and the main site's does not name it, so the main site
# answering as the proxy's catch-all is not mistaken for the env.
#
# Args: domain, address to connect to.
env_cert_served() {
    local escaped="${1//./\\.}" limit=()
    # s_client waits forever on a peer that accepts the connection and never
    # speaks TLS, so it runs under a time limit where the system has a way to
    # set one.
    if command -v timeout >/dev/null 2>&1; then
        limit=(timeout 5)
    elif command -v perl >/dev/null 2>&1; then
        limit=(perl -e 'alarm shift; exec @ARGV' 5)
    fi
    "${limit[@]}" openssl s_client -connect "${2}:443" -servername "$1" </dev/null 2>/dev/null \
        | openssl x509 -noout -text 2>/dev/null \
        | grep -Eq "DNS:${escaped}(,|[[:space:]]|$)"
}

# Finish `up` by checking the env answers, instead of announcing a URL that may
# not work. The causes have different fixes, so they are told apart: the proxy
# is not handing the domain to the env; the domain does not resolve to the
# proxy on this machine; or everything is in place.
#
# Returns 1 only when the proxy is not routing the domain. A hosts problem is
# reported and returns 0: the env is up and reachable by address.
#
# Args: env name, domain.
report_env_reachability() {
    local env_name="$1" domain="$2" routed=false hosts_ip
    local url="https://${domain}/"
    # A non-default host port moves the site off 443, and the check with it.
    if [[ -n "${PORT_WORDPRESS:-}" ]] || ! command -v openssl >/dev/null 2>&1; then
        echo "Environment '$env_name' is ready at $url"
        return 0
    fi
    # The proxy picks a new route up within about a second.
    for _ in 1 2 3 4 5; do
        if env_cert_served "$domain" "$ENV_PROXY_IP"; then
            routed=true
            break
        fi
        sleep 1
    done
    if [[ "$routed" != true ]]; then
        echo "Error: environment '$env_name' is running, but the proxy is not routing $domain to it. See 'docker logs newspack_proxy' and $(proxy_route_file "$env_name")." >&2
        return 1
    fi
    hosts_ip=$(hosts_ip_for "$domain")
    # No hosts line is fine when the name reaches the proxy another way, such as
    # a local DNS server for .test. It has to be the proxy that answers: a
    # custom domain that also exists publicly resolves to the real site.
    if [[ "$hosts_ip" == "$ENV_PROXY_IP" ]] \
        || { [[ -z "$hosts_ip" ]] && [[ "$(curl -sk -o /dev/null --max-time 5 -w '%{remote_ip}' "$url" 2>/dev/null)" == "$ENV_PROXY_IP" ]]; }; then
        echo "Environment '$env_name' is ready at $url"
    else
        echo "Environment '$env_name' is running, but $domain does not resolve to $ENV_PROXY_IP on this machine (see the warning above)."
        echo "Until that is fixed: curl -k --resolve ${domain}:443:${ENV_PROXY_IP} $url"
    fi
    return 0
}

# Parse a docker-compose volume line for a worktree mount and emit
# "repo|branch|kind" (kind ∈ monorepo|repos). Handles three shapes:
#   legacy (pre-monorepo): "- ./worktrees/<repo>/<branch>:/newspack-repos/<repo>"
#   monorepo:              "- ./worktrees/<safe_branch>/plugins/<repo>:/newspack-plugins/<repo>"
#                          "- ./worktrees/<safe_branch>/themes/<repo>:/newspack-themes/<repo>"
#   repos (standalone):    "- ./worktrees-repos/<repo>/<safe_branch>:/newspack-repos/{plugins,themes}/<repo>"
# Returns non-zero for lines that don't match any shape.
parse_worktree_mount() {
    local line="$1"
    # Standalone repos/ worktree. Both fields come from the host path, which is
    # always "worktrees-repos/<repo>/<safe_branch>" (neither segment has a slash);
    # the container side is always under /newspack-repos/.
    if [[ "$line" =~ ^[[:space:]]*-[[:space:]]+\./worktrees-repos/([^[:space:]/:]+)/([^[:space:]/:]+):/newspack-repos/ ]]; then
        local repos_repo="${BASH_REMATCH[1]}"
        local repos_branch="${BASH_REMATCH[2]}"
        [[ -n "$repos_repo" && -n "$repos_branch" ]] || return 1
        echo "$repos_repo|$repos_branch|repos"
        return 0
    fi
    # Use regex extraction so the parser tolerates exactly what the grep
    # admits (tabs / multi-space after the dash) and cuts cleanly at the
    # next `:` — so mount-mode suffixes (`:ro`, `:cached`) and trailing
    # comments don't fold into the captured fields.
    #
    # The emitted `branch` is the *mount-derived* identifier — the directory
    # name as it appears in the compose file's host path. Legacy mounts have
    # the unsanitized branch in the directory name; monorepo mounts have the
    # sanitized (safe) form. Use resolve_unsanitized_branch() to recover the
    # display form for the monorepo case. Keeping the parser mount-path-only
    # ensures filesystem operations (e.g., worktree.sh remove) get a stable
    # identifier that doesn't drift when the worktree's git state changes.
    [[ "$line" =~ ^[[:space:]]*-[[:space:]]+\./worktrees/([^[:space:]:]+):/newspack-(repos|plugins|themes)/([^[:space:]:]+) ]] || return 1
    local host_rel="worktrees/${BASH_REMATCH[1]}"
    local container_type="${BASH_REMATCH[2]}"
    local repo="${BASH_REMATCH[3]}"
    local branch=""
    case "$container_type" in
        repos)
            # Legacy: host = ./worktrees/<repo>/<branch> (slashes preserved in directory name).
            branch="${host_rel#worktrees/$repo/}"
            ;;
        plugins|themes)
            # Monorepo: host = ./worktrees/<safe_branch>/{plugins,themes}/<repo>.
            branch="${host_rel#worktrees/}"
            branch="${branch%/*/$repo}"
            ;;
    esac
    [[ -n "$repo" && -n "$branch" ]] || return 1
    echo "$repo|$branch|monorepo"
}

# Resolve the unsanitized git branch name for a worktree directory.
# Display-only — never use the result as a filesystem identifier. Falls back
# to the safe (directory-name) form when the worktree is missing or its
# branch ref can't be resolved (e.g., detached HEAD).
resolve_unsanitized_branch() {
    local safe_branch="$1"
    local repos_repo="$2"  # set for standalone repos/ worktrees.
    local wt_dir
    if [[ -n "$repos_repo" ]]; then
        wt_dir="$NABSPATH/worktrees-repos/$repos_repo/$safe_branch"
    else
        wt_dir="$NABSPATH/worktrees/$safe_branch"
    fi
    local resolved
    resolved=$(git -C "$wt_dir" branch --show-current 2>/dev/null)
    [[ -n "$resolved" ]] && echo "$resolved" || echo "$safe_branch"
}

# Iterate worktree mount lines in a compose file and yield "repo|branch|kind"
# triples (kind ∈ monorepo|repos), as emitted by parse_worktree_mount.
each_worktree_in_env() {
    local file="$1"
    while IFS= read -r line; do
        parse_worktree_mount "$line"
    done < <(grep -E '^[[:space:]]*-[[:space:]]+\./worktrees(-repos)?/[^[:space:]:]+:/newspack-(repos|plugins|themes)/[^[:space:]:]+' "$file" 2>/dev/null)
}

# The container's state as docker reports it, or "stopped" when it has none.
env_status() {
    local container_name status
    container_name=$(env_container_name "$1")
    if status=$(docker inspect -f '{{.State.Status}}' "$container_name" 2>/dev/null); then
        echo "$status"
    else
        echo "stopped"
    fi
}

# Comma-separated repo:branch pairs for the worktrees an env mounts.
# each_worktree_in_env yields the mount-derived safe branch;
# resolve_unsanitized_branch recovers the display form (e.g., feat/foo) for
# monorepo worktrees, leaving filesystem operations to the safe form.
env_worktrees() {
    local worktrees="" repo safe_branch kind repos_repo branch
    while IFS='|' read -r repo safe_branch kind; do
        [[ "$kind" == "repos" ]] && repos_repo="$repo" || repos_repo=""
        branch=$(resolve_unsanitized_branch "$safe_branch" "$repos_repo")
        [[ -n "$worktrees" ]] && worktrees="$worktrees,"
        worktrees="${worktrees}${repo}:${branch}"
    done < <(each_worktree_in_env "$1")
    echo "$worktrees"
}

# One env's row in `list --porcelain` and `which --porcelain`: name, status,
# URL and worktrees, tab-separated. Both print it from here so one parser
# reads either.
env_porcelain_row() {
    local name="$1" file="$NABSPATH/docker-compose.env-$1.yml"
    printf '%s\t%s\thttps://%s/\t%s\n' "$name" "$(env_status "$name")" "$(domain_for_env "$file")" "$(env_worktrees "$file")"
}

# True for an explicit help request in the name position. It matches -h and
# --help and nothing else, so `up --all` — the one other dash-leading token read
# from that position — still reaches its own branch below.
is_help_arg() {
    [[ "$1" == "-h" || "$1" == "--help" ]]
}

# Print a subcommand's usage and exit. Status 0 when the user asked for help,
# 1 when the name was simply missing — one usage string serves both, and the
# caller passes whatever it read positionally so this can tell them apart.
env_usage() {
    local name="$1"; shift
    printf '%s\n' "$@"
    is_help_arg "$name" && exit 0
    exit 1
}

# For subcommands that take nothing after the name. Without it, a mistyped or
# imagined flag is ignored and the command runs anyway, which on destroy drops
# the env's database and deletes its worktree.
reject_extra_args() {
    if [[ $# -gt 2 ]]; then
        echo "Unknown option: $3"
        exit 1
    fi
}

# The arms below look for a help flag only in the name position. Without this, a
# help flag after the name does not print usage. A help flag anywhere is a help
# request, so move it into the name position before dispatch. cleanup and
# e2e-setup parse their own options.
case $1 in
    create|up|down|destroy|list|which)
        for arg in "${@:2}"; do
            if is_help_arg "$arg"; then
                set -- "$1" "$arg"
                break
            fi
        done
        ;;
esac

case $1 in
    create)
        env_name="$2"
        if [[ -z "$env_name" ]] || is_help_arg "$env_name"; then
            env_usage "$env_name" "Usage: n env create <name> --worktree <repo>:<branch> [--worktree ...] [--domain <domain>] [--up]"
        fi
        validate_new_env_name "$env_name"
        # Reject names that would collide after dash/underscore normalization.
        normalized=$(echo "$env_name" | tr '-' '_')
        for f in "$NABSPATH"/docker-compose.env-*.yml; do
            [[ -f "$f" ]] || continue
            existing=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
            [[ "$existing" == "$env_name" ]] && continue
            if [[ "$(echo "$existing" | tr '-' '_')" == "$normalized" ]]; then
                echo "Error: '$env_name' conflicts with existing environment '$existing' (same container/database name after normalization)"
                exit 1
            fi
        done
        shift 2
        worktree_volumes=""
        # Worktrees to create are recorded here during arg parsing and only
        # created after the whole arg list validates (below), so a bad later
        # --worktree/--domain can't leave an orphaned worktree behind. Each entry
        # is "mono|<branch>|<abs_dir>" or "repos|<repo>|<branch>|<abs_dir>".
        wt_specs=()
        domain=""
        auto_up=false
        while [[ $# -gt 0 ]]; do
            case $1 in
                --worktree)
                    if [[ -z "$2" || "$2" == --* ]]; then
                        echo "Error: --worktree requires a value (plugin:branch)"
                        exit 1
                    fi
                    IFS=':' read -r wt_repo wt_branch <<< "$2"
                    validate_name "$wt_repo" "repo"
                    validate_name "$wt_branch" "branch"
                    # Sanitize branch for directory name (feat/foo -> feat-foo).
                    safe_branch=$(echo "$wt_branch" | tr '/' '-')
                    wt_host_path=$(get_repo_host_path "$wt_repo")
                    if [[ -n "$wt_host_path" ]]; then
                        # ---- Monorepo plugin/theme: worktree of the workspace repo. ----
                        # Record the worktree for creation after arg parsing.
                        wt_specs+=("mono|$wt_branch|$NABSPATH/worktrees/$safe_branch")
                        # Mount the specific plugin/theme subdirectory from the worktree.
                        if [[ "$wt_host_path" == themes/* ]]; then
                            wt_container_path="/newspack-themes/$wt_repo"
                        else
                            wt_container_path="/newspack-plugins/$wt_repo"
                        fi
                        worktree_dir="./worktrees/$safe_branch/$wt_host_path"
                        # Mount the worktree subdir at BOTH container roots:
                        #   - the site-serving path (/newspack-plugins|themes/<repo>), and
                        #   - the pnpm-workspace path (/newspack-monorepo/<host_path>).
                        # The site reads the first; the JS toolchain (n build / n test-js /
                        # jest, all resolved under /newspack-monorepo) reads the second. Without
                        # the workspace mount the toolchain builds/tests the *main* checkout's
                        # source, never the worktree's, and the worktree's own node_modules has
                        # relative pnpm symlinks (../../../packages/*) that only resolve when the
                        # plugin sits at its real workspace location. Mounting here makes both
                        # work, so builds land in the worktree's dist and are served immediately.
                        worktree_volumes+="      - $worktree_dir:$wt_container_path"$'\n'
                        worktree_volumes+="      - $worktree_dir:/newspack-monorepo/$wt_host_path"$'\n'
                    else
                        # ---- Standalone repos/ checkout: worktree of its own git repo. ----
                        repos_host_path=$(get_standalone_repo_host_path "$wt_repo")
                        if [[ -z "$repos_host_path" ]]; then
                            echo "Error: unknown project '$wt_repo' (not a monorepo plugin/theme, and no repos/plugins|themes/$wt_repo checkout)"
                            exit 1
                        fi
                        # Record the worktree for creation after arg parsing. add-repos
                        # (run then) owns the standalone-repo validation -- it errors if
                        # the checkout isn't its own git repo; here we only need
                        # repos_host_path to derive the container subdir (plugins vs themes).
                        wt_specs+=("repos|$wt_repo|$wt_branch|$NABSPATH/worktrees-repos/$wt_repo/$safe_branch")
                        # kind is "plugins" or "themes" (from repos/<kind>/<name>).
                        repos_kind="${repos_host_path#repos/}"
                        repos_kind="${repos_kind%%/*}"
                        worktree_dir="./worktrees-repos/$wt_repo/$safe_branch"
                        # Single override mount: shadow just this checkout's subpath under
                        # the whole-dir "./repos:/newspack-repos" mount, so this env serves
                        # the worktree at the canonical name while other envs keep the base
                        # checkout. No /newspack-monorepo mount -- repos/ plugins aren't pnpm
                        # workspace members; they build standalone via build-repos.sh.
                        worktree_volumes+="      - $worktree_dir:/newspack-repos/$repos_kind/$wt_repo"$'\n'
                    fi
                    shift 2
                    ;;
                --domain)
                    if [[ -z "$2" || "$2" == --* ]]; then
                        echo "Error: --domain requires a value"
                        exit 1
                    fi
                    domain="$2"
                    validate_domain "$domain"
                    shift 2
                    ;;
                --up)
                    auto_up=true
                    shift
                    ;;
                *)
                    echo "Unknown option: $1"
                    exit 1
                    ;;
            esac
        done
        # A domain can have one owner. An env's route outranks the main site's
        # catch-all, so a name the main container already serves would be taken
        # from it, and two envs given one domain would both claim it.
        new_domain=$(printf '%s' "${domain:-${env_name}.test}" | tr '[:upper:]' '[:lower:]')
        domain_owner=""
        # The main site answers at WP_DOMAIN, which .env can change from localhost.
        main_domain=$( (
            set -a
            source "$NABSPATH/default.env"
            [[ -f "$NABSPATH/.env" ]] && source "$NABSPATH/.env"
            printf '%s' "${WP_DOMAIN:-localhost}"
        ) 2>/dev/null | tr '[:upper:]' '[:lower:]')
        case "$new_domain" in
            localhost|manager.com|"$main_domain") domain_owner="the main container" ;;
        esac
        for site_dir in "$NABSPATH"/additional-sites-html/*/; do
            [[ -d "$site_dir" ]] || continue
            site_label=$(basename "$site_dir")
            if [[ "$(printf '%s' "$site_label" | tr '[:upper:]' '[:lower:]').test" == "$new_domain" ]]; then
                domain_owner="the additional site '$site_label'"
            fi
        done
        for f in "$NABSPATH"/docker-compose.env-*.yml; do
            [[ -f "$f" ]] || continue
            existing=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
            [[ "$existing" == "$env_name" ]] && continue
            if [[ "$(domain_for_env "$f" | tr '[:upper:]' '[:lower:]')" == "$new_domain" ]]; then
                domain_owner="environment '$existing'"
            fi
        done
        if [[ -n "$domain_owner" ]]; then
            echo "Error: the domain '$new_domain' is already served by $domain_owner. Choose another with --domain."
            exit 1
        fi
        # All args validated -- now create the recorded worktrees. If a creation
        # fails mid-batch, roll back the ones made in this run (worktree dir +
        # registration only; branches are left alone) so we don't leave orphans.
        created_gitdirs=()
        created_wtdirs=()
        wt_rollback() {
            local i
            for i in "${!created_wtdirs[@]}"; do
                git -C "${created_gitdirs[$i]}" worktree remove --force "${created_wtdirs[$i]}" 2>/dev/null || rm -rf "${created_wtdirs[$i]}"
                git -C "${created_gitdirs[$i]}" worktree prune 2>/dev/null || true
            done
        }
        for spec in "${wt_specs[@]}"; do
            IFS='|' read -r wt_kind wt_a wt_b wt_c <<< "$spec"
            if [[ "$wt_kind" == "mono" ]]; then
                # spec: mono|<branch>|<abs_dir>
                [[ -d "$wt_b" ]] && continue  # pre-existing worktree; leave it.
                if ! "$NABSPATH/bin/worktree.sh" add "$wt_a"; then
                    wt_rollback
                    exit 1
                fi
                created_gitdirs+=("$NABSPATH")
                created_wtdirs+=("$wt_b")
            else
                # spec: repos|<repo>|<branch>|<abs_dir>
                [[ -d "$wt_c" ]] && continue  # pre-existing worktree; leave it.
                if ! "$NABSPATH/bin/worktree.sh" add-repos "$wt_a" "$wt_b"; then
                    wt_rollback
                    exit 1
                fi
                created_gitdirs+=("$NABSPATH/$(get_standalone_repo_host_path "$wt_a")")
                created_wtdirs+=("$wt_c")
            fi
        done
        if [[ -z "$domain" ]]; then
            domain="${env_name}.test"
        fi
        compose_file="$NABSPATH/docker-compose.env-${env_name}.yml"
        container_name=$(env_container_name "$env_name")
        db_name=$(db_name_for_env "$env_name")
        # Create isolated html directory.
        mkdir -p "$NABSPATH/envs/${env_name}/html"
        cat > "$compose_file" <<YAML
services:
  env-${env_name}:
    container_name: ${container_name}
    platform: \${NEWSPACK_DOCKER_PLATFORM:-linux/arm64}
    depends_on:
      - db
    image: newspack-dev:latest
    volumes:
      - ./logs/env-${env_name}/apache2:/var/log/apache2
      - ./logs/env-${env_name}/php:/var/log/php
      - ./bin:/var/scripts
      - .:/newspack-monorepo
      - ./plugins:/newspack-plugins
      - ./themes:/newspack-themes
      - ./repos:/newspack-repos
${worktree_volumes}      - ./envs/${env_name}/html:/var/www/html
      - ./manager-html:/var/www/manager-html
      - ./additional-sites-html:/var/www/additional-sites-html
      - ./snapshots:/snapshots
    env_file:
      - default.env
      - .env
    environment:
      - HOST_PORT=80
      - MYSQL_DATABASE=${db_name}
      - WP_CACHE_KEY_SALT=env_${env_name}_
      - WP_DOMAIN=${domain}
      - APACHE_RUN_USER=\${USE_CUSTOM_APACHE_USER:-www-data}
      - WP_ENVIRONMENT_TYPE=local
    extra_hosts:
      - "host.docker.internal:host-gateway"
    ## Probes memcached -- see docker-compose.yml for the rationale. Kept in step
    ## with the healthcheck migration in env_up().
    healthcheck:
      test: ["CMD", "bash", "-c", "echo > /dev/tcp/127.0.0.1/11211"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 180s
    networks:
      default: {}
      newspack_envs:
        aliases:
          - ${domain}
networks:
  newspack_envs:
    external: true
YAML
        echo "Created $compose_file (db: $db_name, domain: $domain)"
        # Tell the proxy where the domain goes. The env publishes no ports itself.
        if ! env_proxy_route "$env_name" "$domain" | write_proxy_route "$(proxy_route_file "$env_name")"; then
            echo "Error: could not write the proxy route for $env_name under $PROXY_ROUTES_DIR" >&2
            exit 1
        fi
        # Start the environment immediately or prompt.
        if [[ "$auto_up" == true ]]; then
            exec "$NABSPATH/bin/env.sh" up "$env_name"
        elif [ -t 0 ] && [ -t 1 ]; then
            read -p "Start environment now? (Y/n): " choice
            choice=$(echo "$choice" | tr '[:upper:]' '[:lower:]')
            if [[ "$choice" != "n" ]]; then
                exec "$NABSPATH/bin/env.sh" up "$env_name"
            else
                echo "Run: n env up $env_name"
            fi
        else
            echo "Run: n env up $env_name"
        fi
        ;;
    up)
        env_name="$2"
        if [[ -z "$env_name" ]] || is_help_arg "$env_name"; then
            env_usage "$env_name" "Usage: n env up <name> [--build]" "       n env up --all [--build]"
        fi
        # --all: start all existing environments.
        if [[ "$env_name" == "--all" ]]; then
            shift 2
            pass_args=()
            while [[ $# -gt 0 ]]; do
                pass_args+=("$1"); shift
            done
            started=0
            failed=0
            for f in "$NABSPATH"/docker-compose.env-*.yml; do
                [[ -f "$f" ]] || continue
                name=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
                echo ""
                echo "=== Starting $name ==="
                if "$NABSPATH/bin/env.sh" up "$name" "${pass_args[@]}"; then
                    started=$((started + 1))
                else
                    failed=$((failed + 1))
                fi
            done
            echo ""
            echo "Done: $started started, $failed failed."
            exit 0
        fi
        validate_env_name "$env_name"
        shift 2
        auto_build=false
        while [[ $# -gt 0 ]]; do
            case $1 in
                --build) auto_build=true; shift ;;
                *) echo "Unknown option: $1"; exit 1 ;;
            esac
        done
        compose_file="$NABSPATH/docker-compose.env-${env_name}.yml"
        if [[ ! -f "$compose_file" ]]; then
            echo "Error: environment '$env_name' not found. Run: n env create $env_name ..."
            exit 1
        fi
        container_name=$(env_container_name "$env_name")
        db_name=$(db_name_for_env "$env_name")
        domain=$(domain_for_env "$compose_file")
        ip=$(ip_for_env "$compose_file")
        # --- Migration: add shared network + domain if missing ---
        if ! grep -q 'newspack_envs' "$compose_file"; then
            # Assign a .test domain if the env is IP-based.
            if [[ "$domain" == "$ip" || -z "$domain" ]]; then
                domain="${env_name}.test"
                # Update WP_DOMAIN in the compose file.
                sed -i '' "s|WP_DOMAIN=${ip}|WP_DOMAIN=${domain}|" "$compose_file" 2>/dev/null || \
                    sed -i "s|WP_DOMAIN=${ip}|WP_DOMAIN=${domain}|" "$compose_file"
            fi
            # Replace the old networks block. All existing env compose files end with:
            #     networks:
            #       - default
            # Remove those two trailing lines, then append the new config.
            # BSD head doesn't support -n -2, so use wc + awk.
            total=$(wc -l < "$compose_file")
            awk -v n="$((total - 2))" 'NR <= n' "$compose_file" > "${compose_file}.tmp" && mv "${compose_file}.tmp" "$compose_file"
            cat >> "$compose_file" <<MIGRATE
    networks:
      default: {}
      newspack_envs:
        aliases:
          - ${domain}
networks:
  newspack_envs:
    external: true
MIGRATE
            echo "Migrated $env_name: added shared network (domain: $domain)"
        fi
        # --- Migration: add the memcached healthcheck if missing ---
        # Compose files are generated once at create time, so envs predating the
        # healthcheck would never get it. Keep this block in step with the
        # healthcheck in the generated YAML above.
        if ! grep -q 'healthcheck:' "$compose_file"; then
            awk '
                { print }
                /^      - "host\.docker\.internal:host-gateway"$/ && !inserted {
                    print "    ## Probes memcached -- see docker-compose.yml for the rationale."
                    print "    healthcheck:"
                    print "      test: [\"CMD\", \"bash\", \"-c\", \"echo > /dev/tcp/127.0.0.1/11211\"]"
                    print "      interval: 30s"
                    print "      timeout: 5s"
                    print "      retries: 3"
                    print "      start_period: 180s"
                    inserted = 1
                }
            ' "$compose_file" > "${compose_file}.tmp" && mv "${compose_file}.tmp" "$compose_file"
            # awk's insertion flag is invisible to the shell, so re-read the file:
            # a compose file without the anchor line passes through unchanged, and
            # reporting a migration that did not happen would send whoever debugs
            # a stale env looking in the wrong place.
            if grep -q 'healthcheck:' "$compose_file"; then
                echo "Migrated $env_name: added memcached healthcheck"
            else
                echo "Warning: could not add the memcached healthcheck to $compose_file (no extra_hosts anchor). Recreate the env to pick it up." >&2
            fi
        fi
        # --- Migration: add WP_ENVIRONMENT_TYPE if missing (same reason as above) ---
        if ! grep -q 'WP_ENVIRONMENT_TYPE=' "$compose_file"; then
            awk '{ print } /^      - APACHE_RUN_USER=/ { print "      - WP_ENVIRONMENT_TYPE=local" }' "$compose_file" > "${compose_file}.tmp" && mv "${compose_file}.tmp" "$compose_file"
            grep -q 'WP_ENVIRONMENT_TYPE=' "$compose_file" && echo "Migrated $env_name: added WP_ENVIRONMENT_TYPE=local" || echo "Warning: could not add WP_ENVIRONMENT_TYPE to $compose_file. Recreate the env to pick it up." >&2
        fi
        # --- Migration: read the platform from NEWSPACK_DOCKER_PLATFORM (same reason as above) ---
        # Older envs pin linux/arm64, which no longer matches an image rebuilt for
        # another platform. Only the exact generated line is rewritten, so a
        # hand-edited platform is left alone.
        if grep -qx '    platform: linux/arm64' "$compose_file"; then
            awk '{ if ($0 == "    platform: linux/arm64") print "    platform: ${NEWSPACK_DOCKER_PLATFORM:-linux/arm64}"; else print }' "$compose_file" > "${compose_file}.tmp" && mv "${compose_file}.tmp" "$compose_file"
            echo "Migrated $env_name: platform now follows NEWSPACK_DOCKER_PLATFORM"
        fi
        # Re-read domain after potential migration.
        domain=$(domain_for_env "$compose_file")
        # --- Migration: reach the host through the shared proxy (same reason as above) ---
        # An env from before the proxy publishes 80/443 on a loopback address of
        # its own. Docker Desktop for Mac 4.97+ binds those ports on every
        # interface, so that env can no longer start next to anything else that
        # publishes them.
        legacy_ip=$(legacy_env_ip "$compose_file")
        if [[ -n "$legacy_ip" ]]; then
            if drop_legacy_env_ports "$compose_file"; then
                echo "Migrated $env_name: served through the shared proxy instead of $legacy_ip"
            else
                echo "Error: could not move $env_name onto the shared proxy. $compose_file still publishes ports on $legacy_ip and was left unchanged. Remove its 80/443 port lines by hand, or recreate the env." >&2
                exit 1
            fi
        fi
        # The domain has to reach the proxy from the host. The reachability report
        # at the end of `up` repeats the outcome, so a failure here is not fatal.
        [[ -n "$domain" ]] && point_host_at_proxy "$domain"
        # Source env files for DB credentials.
        set -a
        source "$NABSPATH/default.env"
        [[ -f "$NABSPATH/.env" ]] && source "$NABSPATH/.env"
        set +a
        ensure_envs_network || exit 1
        # Ensure db is running and create the environment database.
        docker compose -f "$NABSPATH/docker-compose.yml" up -d db
        echo "Creating database $db_name..."
        docker compose -f "$NABSPATH/docker-compose.yml" exec -T db \
            mariadb -h localhost -u root -p"${MYSQL_ROOT_PASSWORD}" \
            -e "CREATE DATABASE IF NOT EXISTS \`${db_name}\`; GRANT ALL PRIVILEGES ON \`${db_name}\`.* TO '${MYSQL_USER}'@'%'; FLUSH PRIVILEGES;" 2>/dev/null
        # Start the env container.
        if ! docker compose -f "$NABSPATH/docker-compose.yml" -f "$compose_file" up -d "env-${env_name}"; then
            echo "Error: failed to start container"
            exit 1
        fi
        # The proxy is what makes the env reachable from the host. Its routes
        # come first: the directory it mounts has to exist and be the user's.
        if ! sync_proxy_routes; then
            echo "Error: could not write the proxy routes under $PROXY_ROUTES_DIR" >&2
            exit 1
        fi
        # The same compose files as `n start`, so a developer's override of the
        # proxy is not undone here. --no-deps because the proxy names the main
        # site as a dependency, and starting an env is no reason to start that.
        proxy_compose=(-f "$NABSPATH/docker-compose.yml")
        [[ -f "$NABSPATH/docker-compose.override.yml" ]] && proxy_compose+=(-f "$NABSPATH/docker-compose.override.yml")
        # Two envs starting at once both try to create the proxy; the one that
        # loses finds it running, which is all it needed.
        if ! docker compose "${proxy_compose[@]}" up -d --no-deps proxy \
            && [[ -z "$(docker ps -q --filter name='^newspack_proxy$' --filter status=running 2>/dev/null)" ]]; then
            echo "Error: could not start the shared proxy on ports 80/443." >&2
            port_holders=$(docker ps --filter publish=443 --filter publish=80 --format '{{.Names}}' 2>/dev/null | grep -v '^newspack_proxy$' | tr '\n' ' ')
            if [[ -n "$port_holders" ]]; then
                echo "Still publishing those ports from before the proxy: $port_holders" >&2
                echo "Run 'n start' to replace the main site's container, and 'n env up <name>' for an env." >&2
            fi
            exit 1
        fi
        # Generate SSL certificate using host mkcert (trusted CA) and copy into container.
        echo "Setting up SSL for $domain..."
        certs_dir="$NABSPATH/envs/${env_name}/certs"
        mkdir -p "$certs_dir"
        if command -v mkcert >/dev/null 2>&1 && [[ ! -f "$certs_dir/${domain}.pem" ]]; then
            (cd "$certs_dir" && mkcert "$domain" 2>/dev/null)
        fi
        if [[ -f "$certs_dir/${domain}.pem" ]]; then
            docker cp "$certs_dir/${domain}.pem" "$container_name":/etc/ssl/certs/${domain}.pem
            docker cp "$certs_dir/${domain}-key.pem" "$container_name":/etc/ssl/certs/${domain}-key.pem
        else
            # Fallback to container-side mkcert (untrusted CA, but functional).
            docker exec "$container_name" /usr/local/bin/ssl "$domain" 2>/dev/null
        fi
        # Update Apache config: replace any ServerName, ServerAdmin, and cert paths with the env domain.
        docker exec "$container_name" bash -c "sed -i \
            -e 's|ServerName .*|ServerName $domain|' \
            -e 's|ServerAdmin .*|ServerAdmin webmaster@$domain|' \
            -e 's|SSLCertificateFile .*|SSLCertificateFile /etc/ssl/certs/${domain}.pem|' \
            -e 's|SSLCertificateKeyFile .*|SSLCertificateKeyFile /etc/ssl/certs/${domain}-key.pem|' \
            /etc/apache2/sites-available/000-default.conf"
        # Provision Composer vendor/ for the migrated monorepo plugins and themes,
        # so plugin activation doesn't fatal on a missing vendor/autoload.php (the
        # foundation-smoke failure mode). Idempotent; skips projects whose vendor/
        # is already present. On failure it warns (actionably) rather than tearing
        # down an otherwise-usable env.
        #
        # Ahead of the WP-CLI calls below deliberately: every one of them boots
        # WordPress and loads the active plugins, so on an env whose database
        # already has a Newspack plugin active while its mounted vendor/ is missing,
        # they fatal before provisioning would have run. That repairs itself on a
        # second `n env up`, but only because the failures here are non-fatal --
        # running first repairs such an env in one pass. This step needs no
        # database, so nothing is lost by doing it earlier.
        docker exec "$container_name" bash /var/scripts/ensure-vendor.sh || \
            echo "Warning: vendor provisioning reported errors (see above); affected plugins may fatal on activation. Try 'n ci-build all'."

        # Auto-install WordPress if not already installed.
        echo "Waiting for WordPress setup..."
        for i in $(seq 1 20); do
            if docker exec "$container_name" test -f /var/www/html/wp-config.php 2>/dev/null; then
                # Check if core tables exist (wp core is-installed returns true even without them).
                if docker exec "$container_name" wp --allow-root db query "SELECT 1 FROM wp_options LIMIT 1" 2>/dev/null | grep -q 1; then
                    echo "WordPress already installed."
                    # Update site URL if domain changed (e.g., migration from IP to .test).
                    current_url=$(docker exec "$container_name" wp --allow-root option get siteurl 2>/dev/null)
                    if [[ -n "$current_url" && "$current_url" != "https://${domain}" ]]; then
                        docker exec "$container_name" wp --allow-root search-replace "$current_url" "https://${domain}" --skip-columns=guid --quiet 2>/dev/null
                        docker exec "$container_name" wp --allow-root cache flush 2>/dev/null
                        echo "Updated site URL: $current_url -> https://${domain}"
                    fi
                    break
                fi
                echo "Installing WordPress..."
                docker exec "$container_name" wp --allow-root cache flush 2>/dev/null
                docker exec "$container_name" wp --allow-root core install \
                    --url="https://${domain}" \
                    --title="${WP_TITLE:-Newspack}" \
                    --admin_user="${WP_ADMIN_USER:-admin}" \
                    --admin_password="${WP_ADMIN_PASSWORD:-password}" \
                    --admin_email="${WP_ADMIN_EMAIL:-wordpress@example.com}" \
                    --skip-email
                # Activate newspack-theme so a fresh env starts on the Newspack
                # theme rather than WordPress's default. link-repos.sh symlinks
                # the theme into wp-content/themes at container startup, so it's
                # available by now. `n setup` does this too, but envs brought up
                # with just `n env up` skip that, so it has to happen here.
                docker exec "$container_name" wp --allow-root theme activate newspack-theme 2>/dev/null \
                    || echo "Warning: could not activate newspack-theme (is it built/symlinked?)."
                break
            fi
            sleep 3
        done
        if ! docker exec "$container_name" wp --allow-root core is-installed 2>/dev/null; then
            echo "Warning: WordPress may not be fully installed. Run 'n env up $env_name' to retry."
        elif docker exec "$container_name" test -f /var/www/html/wp-config.php 2>/dev/null; then
            # Ensure pretty permalinks work. A fresh isolated env installs with WP's
            # default (plain) permalink structure, so the .htaccess rewrite block stays
            # empty — and a later `wp rewrite flush --hard` with an empty structure
            # rewrites .htaccess down to a bare "# BEGIN/END WordPress" marker, 404-ing
            # every pretty permalink. Set a structure and flush --hard so the rewrite
            # rules are written out, then hand the file back to the Apache user so WP can
            # keep it updated. The main-site path does this via site-setup.sh; envs skip
            # that and install WordPress directly, so it has to happen here too.
            run_user="${USE_CUSTOM_APACHE_USER:-www-data}"
            docker exec "$container_name" wp --allow-root rewrite structure '/%year%/%monthnum%/%day%/%postname%/' --hard >/dev/null 2>&1
            docker exec "$container_name" wp --allow-root rewrite flush --hard >/dev/null 2>&1
            docker exec "$container_name" chown "$run_user":"$run_user" /var/www/html/.htaccess 2>/dev/null || true
        fi
        # Reload Apache to pick up SSL config (it's running by now).
        docker exec "$container_name" apachectl graceful 2>/dev/null
        env_reachable=0
        report_env_reachability "$env_name" "$domain" || env_reachable=1
        # Copy built assets from main repos into worktrees.
        if [[ "$auto_build" == true ]]; then
            # Anchored regex (matching each_worktree_in_env / get_target_container
            # in n) so commented-out volume lines don't false-match and trigger
            # spurious copies.
            grep -E '^[[:space:]]*-[[:space:]]+\./worktrees(-repos)?/[^[:space:]:]+:/newspack-(repos|plugins|themes)/[^[:space:]:]+' "$compose_file" 2>/dev/null | while read -r line; do
                # Parse volume line: "- ./worktrees[-repos]/…:/newspack-{plugins,themes,repos}/repo"
                # Strip leading whitespace + dash with the same [[:space:]] class
                # the grep above uses. `env create` only emits 6-space-indented
                # mounts, so a tab here arises only from a hand-edited compose
                # file — this keeps the strip in step with the grep for that case.
                wt_path=$(echo "$line" | sed -E 's/^[[:space:]]*-[[:space:]]*//' | cut -d: -f1)
                container_path=$(echo "$line" | cut -d: -f2)
                repo=$(basename "$container_path")
                # Resolve the source: monorepo layout first, else the standalone repos/ dir.
                repo_host_path=$(get_repo_host_path "$repo")
                [[ -z "$repo_host_path" ]] && repo_host_path=$(get_standalone_repo_host_path "$repo")
                src="$NABSPATH/$repo_host_path"
                dst="$NABSPATH/${wt_path#./}"
                echo "Copying built assets for $repo..."
                for dir in node_modules vendor dist build; do
                    if [[ -d "$src/$dir" ]]; then
                        cp -al "$src/$dir" "$dst/$dir" 2>/dev/null || cp -a "$src/$dir" "$dst/$dir"
                    fi
                done
            done
        fi
        # Non-zero when the proxy is not routing the env, so a caller does not
        # continue against a site that cannot be reached.
        exit "$env_reachable"
        ;;
    down)
        env_name="$2"
        if [[ -z "$env_name" ]] || is_help_arg "$env_name"; then
            env_usage "$env_name" "Usage: n env down <name>"
        fi
        reject_extra_args "$@"
        validate_env_name "$env_name"
        container_name=$(env_container_name "$env_name")
        docker stop "$container_name" 2>/dev/null
        docker rm "$container_name" 2>/dev/null
        ;;
    destroy)
        env_name="$2"
        if [[ -z "$env_name" ]] || is_help_arg "$env_name"; then
            env_usage "$env_name" "Usage: n env destroy <name> [--keep-branch]"
        fi
        # --keep-branch is the one thing allowed after the name; anything else is
        # refused as reject_extra_args would, before anything is removed.
        keep_branch=false
        for arg in "${@:3}"; do
            if [[ "$arg" == "--keep-branch" ]]; then
                keep_branch=true
            else
                echo "Unknown option: $arg"
                exit 1
            fi
        done
        validate_env_name "$env_name"
        compose_file="$NABSPATH/docker-compose.env-${env_name}.yml"
        container_name=$(env_container_name "$env_name")
        db_name=$(db_name_for_env "$env_name")
        test_db_name=$(test_db_name_for_env "$env_name")
        # Read domain, IP, and worktrees before removing compose file.
        domain=""
        ip=""
        worktree_entries=()
        if [[ -f "$compose_file" ]]; then
            domain=$(domain_for_env "$compose_file")
            ip=$(ip_for_env "$compose_file")
            while IFS= read -r entry; do
                worktree_entries+=("$entry")
            done < <(each_worktree_in_env "$compose_file")
        fi
        docker stop "$container_name" 2>/dev/null
        docker rm "$container_name" 2>/dev/null
        # Drop the environment databases via docker compose (avoids hardcoding container name).
        # The test database only exists if `n test-php` ever ran in this env, hence IF EXISTS.
        set -a
        source "$NABSPATH/default.env"
        [[ -f "$NABSPATH/.env" ]] && source "$NABSPATH/.env"
        set +a
        # The compose file names this network as external, so it has to exist for
        # any compose command, this one included.
        ensure_envs_network >/dev/null
        docker compose -f "$NABSPATH/docker-compose.yml" up -d db 2>/dev/null
        docker compose -f "$NABSPATH/docker-compose.yml" exec -T db \
            mariadb -h localhost -u root -p"${MYSQL_ROOT_PASSWORD}" \
            -e "DROP DATABASE IF EXISTS \`${db_name}\`; DROP DATABASE IF EXISTS \`${test_db_name}\`" 2>/dev/null
        echo "Dropped databases $db_name, $test_db_name"
        # Remove env html and certs directories.
        if [[ -d "$NABSPATH/envs/${env_name}" ]]; then
            rm -rf "$NABSPATH/envs/${env_name}"
            echo "Removed envs/${env_name}/"
        fi
        # Remove log directories.
        if [[ -d "$NABSPATH/logs/env-${env_name}" ]]; then
            rm -rf "$NABSPATH/logs/env-${env_name}"
            echo "Removed logs/env-${env_name}/"
        fi
        # Remove /etc/hosts entry (only for custom domains, not IP-based).
        if [[ -n "$domain" && "$domain" != "$ip" ]] && grep -q "$domain" /etc/hosts 2>/dev/null; then
            if command -v newspack-manage-host >/dev/null 2>&1 && [[ "$domain" == *.test || "$domain" == *.local ]]; then
                sudo newspack-manage-host host-remove "$domain"
            else
                escaped_domain="${domain//./\\.}"
                { sudo sed -i '' "/[[:space:]]${escaped_domain}$/d" /etc/hosts 2>/dev/null || \
                  sudo sed -i "/[[:space:]]${escaped_domain}$/d" /etc/hosts; }
            fi
            echo "Removed $domain from /etc/hosts"
        fi
        # Remove compose file before worktrees so worktree.sh doesn't see them as env-bound.
        rm -f "$compose_file"
        # Then the proxy's route, so the domain stops being claimed for an env
        # that no longer exists. After the compose file: a route sync running in
        # between would otherwise see the env and write the route back.
        rm -f "$(proxy_route_file "$env_name")"
        # Remove worktrees that were mounted by this environment. The branch
        # here is the mount-derived (safe) form from parse_worktree_mount —
        # the stable filesystem identifier, not the live git branch. This is
        # deliberate: if the worktree was retargeted to a different branch
        # via `git checkout` after env creation, we still want destroy to
        # remove the worktree directory the env was bound to, not whatever
        # branch is currently checked out there.
        #
        for entry in "${worktree_entries[@]}"; do
            IFS='|' read -r wt_repo wt_branch wt_kind <<< "$entry"
            if [[ "$wt_kind" == "repos" ]]; then
                # Standalone repos/ worktrees keep their (long-lived) branch.
                "$NABSPATH/bin/worktree.sh" remove-repos --yes "$wt_repo" "$wt_branch"
            else
                # Remove the worktree DIR by its safe form (the identifier the env
                # was bound to -- stable even if someone `git checkout`ed a
                # different branch inside it), then, unless --keep-branch, delete
                # the REAL branch separately: worktree.sh's own
                # `git branch -D <safe>` no-ops against a slash-named branch
                # (feat/foo vs feat-foo), which previously left the local ref
                # dangling across create/destroy.
                real_branch=$(resolve_unsanitized_branch "$wt_branch" "")
                if [[ "$keep_branch" == true ]]; then
                    "$NABSPATH/bin/worktree.sh" remove --yes --keep-branch "$wt_repo" "$wt_branch"
                    echo "Kept branch ${real_branch:-$wt_branch}"
                else
                    "$NABSPATH/bin/worktree.sh" remove --yes "$wt_repo" "$wt_branch"
                    if [[ -n "$real_branch" && "$real_branch" != "$wt_branch" ]]; then
                        git -C "$NABSPATH" branch -D "$real_branch" 2>/dev/null && echo "Deleted branch $real_branch"
                    fi
                fi
            fi
        done
        echo "Destroyed environment '$env_name'"
        ;;
    list)
        if is_help_arg "$2"; then
            echo "Usage: n env list [--porcelain]"
            exit 0
        fi
        porcelain=false
        if [[ "$2" == "--porcelain" ]]; then
            porcelain=true
        fi
        [[ "$porcelain" == false ]] && echo "Environments:"
        for f in "$NABSPATH"/docker-compose.env-*.yml; do
            [[ -f "$f" ]] || continue
            name=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
            if [[ "$porcelain" == true ]]; then
                env_porcelain_row "$name"
            else
                echo "  $name ($(env_status "$name")) https://$(domain_for_env "$f")/"
                # Show worktrees mounted by this environment.
                while IFS='|' read -r repo safe_branch kind; do
                    [[ "$kind" == "repos" ]] && repos_repo="$repo" || repos_repo=""
                    branch=$(resolve_unsanitized_branch "$safe_branch" "$repos_repo")
                    label=$([[ "$kind" == "repos" ]] && echo " [repos]" || echo "")
                    echo "    └ $repo ($branch)$label"
                done < <(each_worktree_in_env "$f")
            fi
        done
        ;;
    which)
        which_usage=(
            "Usage: n env which [name] [--porcelain]"
            "  Show the env that serves the current worktree, or the named one: its"
            "  status, URL, worktrees and admin login. --porcelain prints the same"
            "  tab-separated row as 'n env list --porcelain'."
        )
        is_help_arg "$2" && env_usage "$2" "${which_usage[@]}"
        shift
        porcelain=false
        env_name=""
        for arg in "$@"; do
            case "$arg" in
                --porcelain) porcelain=true ;;
                -*) echo "Unknown option: $arg"; exit 1 ;;
                *)
                    if [[ -n "$env_name" ]]; then
                        echo "Unknown option: $arg"
                        exit 1
                    fi
                    env_name="$arg"
                    ;;
            esac
        done
        if [[ -z "$env_name" ]]; then
            # `n` runs from the workspace root, so it passes the caller's directory.
            caller_dir="${N_CALLER_PWD:-$PWD}"
            if ! env_name=$(env_for_path "$caller_dir"); then
                echo "No environment mounts $caller_dir. Run 'n env list' to see them all."
                exit 1
            fi
        fi
        validate_env_name "$env_name"
        compose_file="$NABSPATH/docker-compose.env-$env_name.yml"
        if [[ ! -f "$compose_file" ]]; then
            echo "Environment '$env_name' not found."
            exit 1
        fi
        if [[ "$porcelain" == true ]]; then
            env_porcelain_row "$env_name"
            exit 0
        fi
        # The same files `up` reads; .env wins. That is the configured user, which can
        # differ from the site admin if .env changed after install. Only the user is
        # shown: agents copy what they see into transcripts, so the password is named,
        # not printed.
        admin_user=$(
            # shellcheck source=/dev/null
            [[ -f "$NABSPATH/default.env" ]] && source "$NABSPATH/default.env"
            # shellcheck source=/dev/null
            [[ -f "$NABSPATH/.env" ]] && source "$NABSPATH/.env"
            echo "${WP_ADMIN_USER:-admin}"
        )
        echo "Name:      $env_name"
        echo "Status:    $(env_status "$env_name")"
        echo "URL:       https://$(domain_for_env "$compose_file")/"
        worktrees=$(env_worktrees "$compose_file")
        echo "Worktrees: ${worktrees:-(none)}"
        echo "Login:     $admin_user (configured; password: WP_ADMIN_PASSWORD in .env or default.env)"
        ;;
    cleanup)
        shift
        cleanup_all=false
        cleanup_yes=false
        while [[ $# -gt 0 ]]; do
            case $1 in
                --all) cleanup_all=true; shift ;;
                --yes) cleanup_yes=true; shift ;;
                -h|--help) echo "Usage: n env cleanup [--all] [--yes]"; exit 0 ;;
                *) echo "Usage: n env cleanup [--all] [--yes]"; exit 1 ;;
            esac
        done
        envs=()
        for f in "$NABSPATH"/docker-compose.env-*.yml; do
            [[ -f "$f" ]] || continue
            name=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
            envs+=("$name")
        done
        if [[ ${#envs[@]} -eq 0 ]]; then
            echo "No environments to clean up."
            exit 0
        fi
        # --all: skip interactive selection (select all for removal).
        # --yes: skip final confirmation prompt.
        if [[ "$cleanup_all" != true ]]; then
            if ! [ -t 0 ] || ! [ -t 1 ]; then
                echo "Interactive mode requires a terminal. Use --all --yes for non-interactive cleanup."
                exit 1
            fi
            # Interactive toggle loop.
            keep_flags=()
            for i in "${!envs[@]}"; do keep_flags[$i]=false; done
            while true; do
                echo ""
                echo "Environments (marked for REMOVAL unless toggled):"
                for i in "${!envs[@]}"; do
                    name="${envs[$i]}"
                    container_name=$(echo "newspack_env_${name}" | tr '-' '_')
                    domain=$(domain_for_env "$NABSPATH/docker-compose.env-${name}.yml")
                    status="stopped"
                    docker inspect -f '{{.State.Status}}' "$container_name" >/dev/null 2>&1 && \
                        status=$(docker inspect -f '{{.State.Status}}' "$container_name" 2>/dev/null)
                    if [[ "${keep_flags[$i]}" == true ]]; then
                        echo "  $((i+1)). [KEEP]    $name ($status) https://${domain}/"
                    else
                        echo "  $((i+1)). [REMOVE]  $name ($status) https://${domain}/"
                    fi
                done
                echo ""
                echo "Enter a number to toggle, 'a' to select all for removal, or 'delete' to proceed:"
                read -p "> " choice
                if [[ "$choice" == "delete" ]]; then
                    break
                elif [[ "$choice" == "a" ]]; then
                    for i in "${!envs[@]}"; do keep_flags[$i]=false; done
                elif [[ "$choice" =~ ^[0-9]+$ ]] && [[ "$choice" -ge 1 && "$choice" -le ${#envs[@]} ]]; then
                    idx=$((choice-1))
                    if [[ "${keep_flags[$idx]}" == true ]]; then
                        keep_flags[$idx]=false
                    else
                        keep_flags[$idx]=true
                    fi
                fi
            done
            to_remove=()
            for i in "${!envs[@]}"; do
                [[ "${keep_flags[$i]}" != true ]] && to_remove+=("${envs[$i]}")
            done
        else
            to_remove=("${envs[@]}")
        fi
        if [[ ${#to_remove[@]} -eq 0 ]]; then
            echo "Nothing to remove."
            exit 0
        fi
        echo "Will destroy: ${to_remove[*]}"
        if [[ "$cleanup_yes" != true ]]; then
            read -p "Confirm? (y/N): " confirm
            if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
                echo "Aborted."
                exit 0
            fi
        fi
        for name in "${to_remove[@]}"; do
            echo ""
            echo "--- Destroying $name ---"
            "$NABSPATH/bin/env.sh" destroy "$name"
        done
        ;;
    e2e-setup)
        shift
        exec "$NABSPATH/bin/setup-local-e2e.sh" "$@"
        ;;
    *)
        echo "Usage: n env <create|up|down|destroy|list|which|cleanup|e2e-setup>"
        echo "  up <name> [--build]      Start an environment"
        echo "  up --all [--build]       Start all environments"
        echo "  which [name]             Show the env serving this worktree, or the named one (--porcelain for one row)"
        echo "  cleanup [--all] [--yes]  Remove environments (--all selects everything, --yes skips confirmation)"
        echo "  e2e-setup <name> [opts]  Build a ready-to-run local e2e-tests environment (see --help)"
        ;;
esac
