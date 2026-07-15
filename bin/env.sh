#!/bin/bash

source "$(dirname "${BASH_SOURCE[0]}")/_common.sh"
source "$(dirname "${BASH_SOURCE[0]}")/repos.sh"
source "$(dirname "${BASH_SOURCE[0]}")/ssl-trust.sh"
source "$(dirname "${BASH_SOURCE[0]}")/env-hosts.sh"

# Sanitize env name for use as a database name (replace dashes with underscores).
db_name_for_env() {
    echo "wordpress_$(echo "$1" | tr '-' '_')"
}

# Find the next available loopback IP (127.0.0.2+).
# Checks both compose files and running containers to avoid conflicts.
next_loopback_ip() {
    used_ips=""
    for f in "$NABSPATH"/docker-compose.env-*.yml; do
        [[ -f "$f" ]] || continue
        ip=$(grep -o '127\.0\.0\.[0-9]*' "$f" | head -1)
        [[ -n "$ip" ]] && used_ips="$used_ips $ip"
    done
    # Also check IPs bound by running containers (covers stale/recreated envs).
    for ip in $(docker ps --format '{{.Ports}}' 2>/dev/null | grep -o '127\.0\.0\.[0-9]*' | sort -u); do
        used_ips="$used_ips $ip"
    done
    octet=2
    while echo "$used_ips" | grep -qw "127\\.0\\.0\\.$octet"; do
        octet=$((octet + 1))
    done
    if [[ "$octet" -gt 254 ]]; then
        echo "Error: no available loopback IPs (127.0.0.2-254 exhausted)" >&2
        exit 1
    fi
    echo "127.0.0.$octet"
}

# Read domain from a compose file's WP_DOMAIN env var.
domain_for_env() {
    grep 'WP_DOMAIN=' "$1" | head -1 | sed 's/.*WP_DOMAIN=//'
}

# Read loopback IP from a compose file.
ip_for_env() {
    grep -o '127\.0\.0\.[0-9]*' "$1" | head -1
}

# Parse a single compose volume line into "<repo>|<branch>|<kind>".
# kind is "monorepo" (workspace plugin/theme worktree) or "repos" (standalone
# repos/ checkout worktree). Returns non-zero for lines that aren't worktree
# mounts. The emitted branch is the mount-derived (safe) identifier — the
# directory name as it appears in the host path; use resolve_unsanitized_branch
# to recover the friendly display form for monorepo worktrees.
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
# to the safe (directory-name) form when the worktree is missing or its branch
# ref can't be resolved (e.g., detached HEAD).
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

# Emit "<repo>|<branch>|<kind>" for each worktree mount in a compose file.
# Anchored grep (start-of-line "- ") so commented-out volume lines can't
# false-match.
each_worktree_in_env() {
    local file="$1"
    while IFS= read -r line; do
        parse_worktree_mount "$line"
    done < <(grep -E '^[[:space:]]*-[[:space:]]+\./worktrees(-repos)?/[^[:space:]:]+:/newspack-(repos|plugins|themes)/[^[:space:]:]+' "$file" 2>/dev/null)
}

# Only dispatch subcommands when executed directly. When sourced (e.g. by the
# host-side unit tests) just define the helpers above and return.
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
case $1 in
    create)
        env_name="$2"
        if [[ -z "$env_name" ]]; then
            echo "Usage: n env create <name> --worktree <repo>:<branch> [--worktree ...] [--domain <domain>] [--isolated-db] [--up]"
            exit 1
        fi
        validate_env_name "$env_name"
        # Reject names that would collide after dash/dot/underscore normalization.
        # validate_env_name permits dots; env_safe_name folds them to underscores
        # alongside dashes, so foo-bar, foo.bar, and foo_bar all resolve to the
        # same docker-safe identifier and must not coexist.
        normalized=$(env_safe_name "$env_name")
        for f in "$NABSPATH"/docker-compose.env-*.yml; do
            [[ -f "$f" ]] || continue
            existing=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
            [[ "$existing" == "$env_name" ]] && continue
            if [[ "$(env_safe_name "$existing")" == "$normalized" ]]; then
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
        isolated_db=false
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
                --isolated-db)
                    isolated_db=true
                    shift
                    ;;
                *)
                    echo "Unknown option: $1"
                    exit 1
                    ;;
            esac
        done
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
        ip=$(next_loopback_ip)
        if [[ -z "$domain" ]]; then
            domain="${env_name}.test"
        fi
        compose_file="$NABSPATH/docker-compose.env-${env_name}.yml"
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
        db_name=$(db_name_for_env "$env_name")
        # Create isolated html directory.
        mkdir -p "$NABSPATH/envs/${env_name}/html"
        # Assemble the env-container YAML once. The isolated-db branch differs
        # only in (a) a prepended sidecar service block, (b) which DB service
        # the env depends on, and (c) a MYSQL_HOST override that points at the
        # sidecar. Building once means future edits to volumes / ports /
        # networks land in one place (R2 finding: heredoc duplication risks
        # silent drift -- the prior shape had MYSQL_HOST already asymmetric).
        db_service="db"
        mysql_host_line=""
        sidecar_block=""
        suffix_log=""
        if [[ "$isolated_db" == true ]]; then
            safe_name=$(env_safe_name "$env_name")
            sidecar_service="db_lowercase_${safe_name}"
            sidecar_container="newspack_db_lowercase_${safe_name}"
            db_service="${sidecar_service}"
            mysql_host_line="      - MYSQL_HOST=${sidecar_service}:3306
"
            # mariadb:11.8.6 is duplicated with docker-compose.yml's `db`
            # service tag intentionally (no shared variable in v1). If the
            # shared db's image tag is bumped, bump this one too so isolated
            # envs stay version-aligned with the rest of the workspace.
            #
            # The sidecar deliberately declares NO `networks:` key, so it joins
            # only Compose's implicit per-project `default` network. The env
            # service (below) is on both `default` and `newspack_envs`; the env
            # reaches the sidecar via `MYSQL_HOST=${sidecar_service}:3306` over
            # that shared `default` network. Keeping the sidecar off
            # `newspack_envs` is the isolation boundary -- but it means the env
            # service's `default` membership is load-bearing and must not be
            # removed.
            sidecar_block="  ${sidecar_service}:
    container_name: ${sidecar_container}
    image: mariadb:11.8.6
    volumes:
      - ./data/newspack-dev_mysql_lowercase_${safe_name}:/var/lib/mysql
      - ./config/mysql_lowercase.conf:/etc/mysql/conf.d/docker.cnf
    env_file:
      - default.env
      - .env
    # Use the stock mariadbd entrypoint instead of the shared db's
    # docker-db-start-and-autoupgrade.sh wrapper -- that script has -hdb
    # hardcoded and would never resolve against this service's name. Two
    # consequences accepted as known limitations: (1) no /var/log/mysql
    # ownership fix runs, but the sidecar doesn't bind-mount a host log dir
    # and the LCTN config disables slow-log, so nothing actually writes
    # there; (2) no mariadb-upgrade runs -- fresh data dirs don't need it;
    # if the image tag above is bumped, run mariadb-upgrade manually inside
    # the sidecar.
    command: [\"mariadbd\"]

"
            suffix_log=", isolated-db"
        fi
        cat > "$compose_file" <<YAML
services:
${sidecar_block}  env-${env_name}:
    container_name: ${container_name}
    platform: linux/arm64
    depends_on:
      - ${db_service}
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
    ports:
      - "${ip}:80:80"
      - "${ip}:443:443"
    env_file:
      - default.env
      - .env
    environment:
      - HOST_PORT=80
${mysql_host_line}      - MYSQL_DATABASE=${db_name}
      - WP_CACHE_KEY_SALT=env_${env_name}_
      - WP_DOMAIN=${domain}
      - APACHE_RUN_USER=\${USE_CUSTOM_APACHE_USER:-www-data}
    extra_hosts:
      - "host.docker.internal:host-gateway"
    networks:
      default: {}
      newspack_envs:
        aliases:
          - ${domain}
networks:
  newspack_envs:
    external: true
YAML
        echo "Created $compose_file (db: $db_name, domain: $domain, ip: $ip${suffix_log})"
        # Check networking prerequisites (macOS only — Linux routes all 127.x.x.x by default).
        if [[ "$(uname)" == "Darwin" ]] && ! ifconfig lo0 2>/dev/null | grep -q "$ip"; then
            if command -v newspack-manage-host >/dev/null 2>&1; then
                sudo newspack-manage-host alias-add "$ip"
            else
                echo "Note: loopback alias for $ip is missing. Run 'n start' or: sudo ifconfig lo0 alias $ip"
            fi
        fi
        # Custom domains (not IP-based) need a /etc/hosts entry.
        if [[ "$domain" != "$ip" ]] && ! grep -q "[[:space:]]${domain}" /etc/hosts 2>/dev/null; then
            if command -v newspack-manage-host >/dev/null 2>&1 && [[ "$domain" == *.test || "$domain" == *.local ]]; then
                # Passwordless via the locked-down wrapper — works without a TTY.
                sudo newspack-manage-host host-add "$ip" "$domain" "$env_name"
            elif [ -t 0 ] && [ -t 1 ]; then
                read -p "Add $domain to /etc/hosts? (Y/n): " choice
                choice=$(echo "$choice" | tr '[:upper:]' '[:lower:]')
                if [[ "$choice" != "n" ]]; then
                    echo "$ip $domain # newspack-env:${env_name}" | sudo tee -a /etc/hosts > /dev/null
                fi
            else
                echo "Note: add hosts entry before browser access: sudo sh -c 'echo \"$ip $domain # newspack-env:${env_name}\" >> /etc/hosts'"
            fi
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
        if [[ -z "$env_name" ]]; then
            echo "Usage: n env up <name> [--build]"
            echo "       n env up --all [--build]"
            exit 1
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
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
        db_name=$(db_name_for_env "$env_name")
        domain=$(domain_for_env "$compose_file")
        ip=$(ip_for_env "$compose_file")
        # Detect isolated-db (sidecar) envs by the presence of a db_lowercase_* service.
        sidecar_service=$(sidecar_service_for_env "$compose_file")
        sidecar_container=""
        if [[ -n "$sidecar_service" ]]; then
            sidecar_container="newspack_${sidecar_service}"
        fi
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
        # Re-read domain after potential migration.
        domain=$(domain_for_env "$compose_file")
        # Ensure loopback alias exists (macOS only — Linux routes all 127.x.x.x by default).
        if [[ "$(uname)" == "Darwin" && -n "$ip" && "$ip" != "127.0.0.1" ]] && ! ifconfig lo0 | grep -q "$ip"; then
            if command -v newspack-manage-host >/dev/null 2>&1; then
                sudo newspack-manage-host alias-add "$ip"
            else
                echo "Error: loopback alias for $ip is not set up."
                echo "Run 'n start' to set up networking, or manually: sudo ifconfig lo0 alias $ip"
                exit 1
            fi
        fi
        # Custom domains (not IP-based) need a /etc/hosts entry.
        if [[ -n "$domain" && "$domain" != "$ip" ]] && ! grep -q "[[:space:]]${domain}" /etc/hosts 2>/dev/null; then
            if command -v newspack-manage-host >/dev/null 2>&1 && [[ "$domain" == *.test || "$domain" == *.local ]]; then
                # Passwordless via the locked-down wrapper — works without a TTY.
                sudo newspack-manage-host host-add "$ip" "$domain" "$env_name"
                echo "Added $domain to /etc/hosts"
            elif [ -t 0 ] && [ -t 1 ]; then
                echo "Adding $domain to /etc/hosts (requires sudo)..."
                echo "$ip $domain # newspack-env:${env_name}" | sudo tee -a /etc/hosts > /dev/null
                echo "Added $domain to /etc/hosts"
            else
                echo "Warning: $domain not in /etc/hosts. Browser access won't work until added."
                echo "Run: sudo sh -c 'echo \"$ip $domain # newspack-env:${env_name}\" >> /etc/hosts'"
            fi
        fi
        # Source env files for DB credentials.
        set -a
        source "$NABSPATH/default.env"
        [[ -f "$NABSPATH/.env" ]] && source "$NABSPATH/.env"
        set +a
        # Ensure DB is running and create the environment database.
        if [[ -n "$sidecar_service" ]]; then
            echo "Starting isolated-db sidecar ($sidecar_service)..."
            docker compose -f "$NABSPATH/docker-compose.yml" -f "$compose_file" up -d "$sidecar_service"
            # Wait for sidecar to accept connections.
            ready=false
            for i in $(seq 1 60); do
                if docker compose -f "$NABSPATH/docker-compose.yml" -f "$compose_file" \
                    exec -T "$sidecar_service" \
                    mariadb -h localhost -u root -p"${MYSQL_ROOT_PASSWORD}" -e "SELECT 1" \
                    >/dev/null 2>&1; then
                    ready=true; break
                fi
                sleep 1
            done
            if [[ "$ready" != "true" ]]; then
                echo "Error: $sidecar_service did not become ready within 60s" >&2
                exit 1
            fi
            # Verify LCTN=1 (guards against silent config-mount drift).
            lctn=$(docker compose -f "$NABSPATH/docker-compose.yml" -f "$compose_file" \
                exec -T "$sidecar_service" \
                mariadb -h localhost -u root -p"${MYSQL_ROOT_PASSWORD}" -N -B \
                -e "SELECT @@lower_case_table_names" 2>/dev/null | tr -d '\r')
            if [[ "$lctn" != "1" ]]; then
                echo "Error: $sidecar_service reports lower_case_table_names=$lctn (expected 1)" >&2
                echo "Check that config/mysql_lowercase.conf is mounted correctly." >&2
                echo "If the data dir was previously initialized with LCTN=2, the only fix is 'n env destroy $env_name' then re-create (LCTN is locked at data-dir init)." >&2
                exit 1
            fi
            echo "Creating database $db_name on $sidecar_service..."
            docker compose -f "$NABSPATH/docker-compose.yml" -f "$compose_file" \
                exec -T "$sidecar_service" \
                mariadb -h localhost -u root -p"${MYSQL_ROOT_PASSWORD}" \
                -e "CREATE DATABASE IF NOT EXISTS \`${db_name}\`; GRANT ALL PRIVILEGES ON \`${db_name}\`.* TO '${MYSQL_USER}'@'%'; FLUSH PRIVILEGES;"
        else
            docker compose -f "$NABSPATH/docker-compose.yml" up -d db
            echo "Creating database $db_name..."
            docker compose -f "$NABSPATH/docker-compose.yml" exec -T db \
                mariadb -h localhost -u root -p"${MYSQL_ROOT_PASSWORD}" \
                -e "CREATE DATABASE IF NOT EXISTS \`${db_name}\`; GRANT ALL PRIVILEGES ON \`${db_name}\`.* TO '${MYSQL_USER}'@'%'; FLUSH PRIVILEGES;"
        fi
        # Start the env container.
        if ! docker compose -f "$NABSPATH/docker-compose.yml" -f "$compose_file" up -d "env-${env_name}"; then
            echo "Error: failed to start container"
            exit 1
        fi
        # Generate SSL certificate. Prefer host mkcert (host-trusted CA); fall back to
        # the container's self-signed cert. Three trust states (see SSL-trust spec):
        echo "Setting up SSL for $domain..."
        certs_dir="$NABSPATH/envs/${env_name}/certs"
        mkdir -p "$certs_dir"
        if ssl_host_mkcert_present; then
            if ! ssl_host_ca_trusted; then
                echo "[env] warning: host mkcert is installed but its CA is not trusted —" >&2
                echo "[env]          https://${domain} will be rejected by browsers until you" >&2
                echo "[env]          run ./bin/setup-networking.sh (installs + trusts the CA)," >&2
                echo "[env]          then re-run 'n env up ${env_name}'." >&2
            fi
            # Regenerate if absent OR not chained to the current host CA (stale/container cert).
            if [[ ! -f "$certs_dir/${domain}.pem" ]] || ! ssl_cert_is_host_trusted "$certs_dir/${domain}.pem"; then
                rm -f "$certs_dir/${domain}.pem" "$certs_dir/${domain}-key.pem"
                (cd "$certs_dir" && mkcert "$domain" 2>/dev/null)
            fi
        else
            echo "[env] warning: host mkcert not found — https://${domain} will be untrusted." >&2
            echo "[env]          Run ./bin/setup-networking.sh (or: brew install mkcert && mkcert -install)," >&2
            echo "[env]          then re-run 'n env up ${env_name}' to regenerate a trusted cert." >&2
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
        # Provision Composer vendor/ for the migrated monorepo plugins, so a
        # later `n setup` / plugin activation doesn't fatal on a missing
        # vendor/autoload.php (the foundation-smoke failure mode). Idempotent;
        # skips plugins whose vendor/ is already present. On failure it warns
        # (actionably) rather than tearing down an otherwise-usable env.
        docker exec "$container_name" bash /var/scripts/ensure-vendor.sh || \
            echo "Warning: vendor provisioning reported errors (see above); affected plugins may fatal on activation. Try 'n ci-build all'."
        # Warn (don't fail) if the newspack theme isn't built. Its style.css is a
        # gitignored build artifact; activating an unbuilt theme (e.g. via
        # `n setup`) fatals the whole site with "stylesheet is missing". The JS/SCSS
        # build is slow and is `n ci-build all`'s job, not env-up's — so surface
        # this early and actionably rather than building here.
        if docker exec "$container_name" test -d /newspack-themes/newspack-theme \
           && ! docker exec "$container_name" test -f /newspack-themes/newspack-theme/newspack-theme/style.css; then
            echo "Warning: newspack-theme is not built (style.css missing). Activating it (e.g. 'n setup') will fatal the site — run 'n ci-build all' first."
        fi
        # Reload Apache to pick up SSL config (it's running by now).
        docker exec "$container_name" apachectl graceful 2>/dev/null
        echo "Environment '$env_name' is ready at https://${domain}/"
        # Provision built assets for mounted worktrees.
        if [[ "$auto_build" == true ]]; then
            # Tier-1 (monorepo plugin/theme) worktrees are workspace members (mounted
            # at /newspack-monorepo/<host>), so build them IN PLACE with one workspace
            # install + a single multi-filter build — no copy, no staleness. Tier-2
            # standalone worktrees aren't workspace members; keep the asset copy.
            tier1_filters=""
            while IFS='|' read -r repo safe_branch kind; do
                if [[ "$kind" == "monorepo" ]]; then
                    # Monorepo worktree host path (plugins/X or themes/X) drives the
                    # in-place, workspace-member build.
                    host=$(get_repo_host_path "$repo")
                    # Resolve the real pnpm package name from the worktree's package.json.
                    pkg=$(docker exec "$container_name" node -p "require('/newspack-monorepo/${host}/package.json').name" 2>/dev/null)
                    [[ -n "$pkg" ]] && tier1_filters="$tier1_filters --filter $pkg"
                else
                    # Tier-2 standalone repos/ worktrees aren't workspace members; copy
                    # built assets from the base checkout into the worktree.
                    src="$NABSPATH/$(get_standalone_repo_host_path "$repo")"
                    dst="$NABSPATH/worktrees-repos/$repo/$safe_branch"
                    echo "Copying built assets for $repo..."
                    for dir in node_modules vendor dist build; do
                        if [[ -d "$src/$dir" ]]; then
                            cp -al "$src/$dir" "$dst/$dir" 2>/dev/null || cp -a "$src/$dir" "$dst/$dir"
                        fi
                    done
                fi
            done < <(each_worktree_in_env "$compose_file")
            if [[ -n "$tier1_filters" ]]; then
                echo "Building worktree plugin(s) in place:${tier1_filters}"
                docker exec "$container_name" bash -c "cd /newspack-monorepo && pnpm install && pnpm${tier1_filters} run build"
            fi
        fi
        ;;
    down)
        env_name="$2"
        if [[ -z "$env_name" ]]; then
            echo "Usage: n env down <name>"
            exit 1
        fi
        validate_env_name "$env_name"
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
        compose_file="$NABSPATH/docker-compose.env-${env_name}.yml"
        docker stop "$container_name" 2>/dev/null
        docker rm "$container_name" 2>/dev/null
        if [[ -f "$compose_file" ]]; then
            sidecar_service=$(sidecar_service_for_env "$compose_file")
            if [[ -n "$sidecar_service" ]]; then
                docker stop "newspack_${sidecar_service}" 2>/dev/null
                docker rm "newspack_${sidecar_service}" 2>/dev/null
            fi
        fi
        ;;
    destroy)
        env_name="$2"
        if [[ -z "$env_name" ]]; then
            echo "Usage: n env destroy <name>"
            exit 1
        fi
        validate_env_name "$env_name"
        compose_file="$NABSPATH/docker-compose.env-${env_name}.yml"
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
        db_name=$(db_name_for_env "$env_name")
        # Read domain, IP, worktrees, and sidecar before removing compose file.
        domain=""
        ip=""
        # Read worktree entries ("<repo>|<branch>|<kind>") before removing the
        # compose file. Each entry drives one worktree.sh remove/remove-repos.
        worktree_entries=()
        sidecar_service=""
        sidecar_container=""
        if [[ -f "$compose_file" ]]; then
            domain=$(domain_for_env "$compose_file")
            ip=$(ip_for_env "$compose_file")
            while IFS= read -r entry; do
                worktree_entries+=("$entry")
            done < <(each_worktree_in_env "$compose_file")
            sidecar_service=$(sidecar_service_for_env "$compose_file")
            if [[ -n "$sidecar_service" ]]; then
                sidecar_container="newspack_${sidecar_service}"
            fi
        fi
        docker stop "$container_name" 2>/dev/null
        docker rm "$container_name" 2>/dev/null
        set -a
        source "$NABSPATH/default.env"
        [[ -f "$NABSPATH/.env" ]] && source "$NABSPATH/.env"
        set +a
        if [[ -n "$sidecar_service" && -n "$sidecar_container" ]]; then
            docker stop "$sidecar_container" 2>/dev/null
            docker rm "$sidecar_container" 2>/dev/null
            # Sidecar removed -- nothing more to drop. Its data dir is removed below.
            echo "Stopped isolated-db sidecar $sidecar_container"
        else
            docker compose -f "$NABSPATH/docker-compose.yml" up -d db 2>/dev/null
            docker compose -f "$NABSPATH/docker-compose.yml" exec -T db \
                mariadb -h localhost -u root -p"${MYSQL_ROOT_PASSWORD}" \
                -e "DROP DATABASE IF EXISTS \`${db_name}\`" 2>/dev/null
            echo "Dropped database $db_name"
        fi
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
        # Remove isolated-db sidecar's data dir if this was an --isolated-db env.
        # The sidecar bind-mounts no host log dir (the LCTN config disables the
        # slow-log and nothing writes to /var/log/mysql), so there is no
        # logs/db-lowercase-<env> to clean up.
        if [[ -n "$sidecar_service" ]]; then
            safe="${sidecar_service#db_lowercase_}"
            if [[ -d "$NABSPATH/data/newspack-dev_mysql_lowercase_${safe}" ]]; then
                rm -rf "$NABSPATH/data/newspack-dev_mysql_lowercase_${safe}"
                echo "Removed data/newspack-dev_mysql_lowercase_${safe}/"
            fi
        fi
        # Remove /etc/hosts entries for this env. Prefer marker-based removal
        # (robust if the domain changed mid-life); fall back to the current domain.
        removed_any=false
        # env_name may contain dots (e.g. foo.bar); escape them so the marker
        # grep treats them literally rather than as BRE any-char wildcards.
        escaped_env_name="${env_name//./\\.}"
        if grep -q "${NEWSPACK_HOSTS_MARKER}${escaped_env_name}$" /etc/hosts 2>/dev/null; then
            while IFS= read -r marked_domain; do
                [ -n "$marked_domain" ] || continue
                if env_hosts_remove "$marked_domain"; then removed_any=true; fi
            done < <(grep "${NEWSPACK_HOSTS_MARKER}${escaped_env_name}$" /etc/hosts 2>/dev/null | awk '{print $2}')
        fi
        if [[ "$removed_any" == false && -n "$domain" && "$domain" != "$ip" ]] \
            && grep -q "[[:space:]]${domain}" /etc/hosts 2>/dev/null; then
            if env_hosts_remove "$domain"; then removed_any=true; fi
        fi
        if [[ "$removed_any" == true ]]; then
            echo "Removed /etc/hosts entries for env '$env_name'"
        elif [[ -n "$domain" && "$domain" != "$ip" ]] && grep -q "[[:space:]]${domain}" /etc/hosts 2>/dev/null; then
            echo "Warning: /etc/hosts entry for $domain may remain (no privileged removal ran)."
            echo "Remove it with: sudo newspack-manage-host host-remove $domain"
        fi
        # Remove compose file before worktrees so worktree.sh doesn't see them as env-bound.
        rm -f "$compose_file"
        # Remove worktrees that were mounted by this environment. The branch
        # here is the mount-derived (safe) form from parse_worktree_mount —
        # the stable filesystem identifier, not the live git branch. This is
        # deliberate: if the worktree was retargeted to a different branch
        # via `git checkout` after env creation, we still want destroy to
        # remove the worktree directory the env was bound to, not whatever
        # branch is currently checked out there.
        #
        # Known follow-up: for monorepo worktrees the safe form (e.g. feat-foo)
        # won't match the real branch (feat/foo) in worktree.sh's final
        # `git branch -D`, so the local branch ref is left dangling after the
        # worktree dir is removed. Harmless (re-create reuses it) but accrues
        # across create/destroy cycles. A proper fix removes the dir by safe
        # name and deletes the branch by its resolved real name separately.
        for entry in "${worktree_entries[@]}"; do
            IFS='|' read -r wt_repo wt_branch wt_kind <<< "$entry"
            if [[ "$wt_kind" == "repos" ]]; then
                "$NABSPATH/bin/worktree.sh" remove-repos --yes "$wt_repo" "$wt_branch"
            else
                "$NABSPATH/bin/worktree.sh" remove --yes "$wt_repo" "$wt_branch"
            fi
        done
        echo "Destroyed environment '$env_name'"
        ;;
    list)
        porcelain=false
        if [[ "$2" == "--porcelain" ]]; then
            porcelain=true
        fi
        [[ "$porcelain" == false ]] && echo "Environments:"
        for f in "$NABSPATH"/docker-compose.env-*.yml; do
            [[ -f "$f" ]] || continue
            name=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
            container_name=$(echo "newspack_env_${name}" | tr '-' '_')
            domain=$(domain_for_env "$f")
            isolated_marker=""
            db_kind="shared"
            if [[ -n "$(sidecar_service_for_env "$f")" ]]; then
                isolated_marker=" [isolated-db]"
                db_kind="isolated"
            fi
            if status=$(docker inspect -f '{{.State.Status}}' "$container_name" 2>/dev/null); then
                :
            else
                status="stopped"
            fi
            # Collect worktrees as repo:branch pairs. each_worktree_in_env
            # yields the mount-derived safe branch; resolve_unsanitized_branch
            # recovers the friendly display form (e.g., feat/foo) for monorepo
            # worktrees while leaving filesystem-operation paths to the safe form.
            worktrees=""
            worktree_lines=()
            while IFS='|' read -r repo safe_branch kind; do
                [[ "$kind" == "repos" ]] && repos_repo="$repo" || repos_repo=""
                branch=$(resolve_unsanitized_branch "$safe_branch" "$repos_repo")
                label=$([[ "$kind" == "repos" ]] && echo " [repos]" || echo "")
                worktree_lines+=("${repo}|${branch}|${label}")
                [[ -n "$worktrees" ]] && worktrees="${worktrees},"
                worktrees="${worktrees}${repo}:${branch}"
            done < <(each_worktree_in_env "$f")
            if [[ "$porcelain" == true ]]; then
                printf '%s\t%s\thttps://%s/\t%s\t%s\n' "$name" "$status" "$domain" "$worktrees" "$db_kind"
            else
                echo "  $name ($status) https://${domain}/${isolated_marker}"
                for wl in "${worktree_lines[@]}"; do
                    IFS='|' read -r nm br lbl <<< "$wl"
                    echo "    └ $nm ($br)$lbl"
                done
            fi
        done
        ;;
    cleanup)
        shift
        cleanup_all=false
        cleanup_yes=false
        while [[ $# -gt 0 ]]; do
            case $1 in
                --all) cleanup_all=true; shift ;;
                --yes) cleanup_yes=true; shift ;;
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
        # Sweep stale /etc/hosts entries left by past envs.
        live_names=""; live_domains=""
        for f in "$NABSPATH"/docker-compose.env-*.yml; do
            [[ -f "$f" ]] || continue
            name=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
            live_names="$live_names $name"
            ld=$(domain_for_env "$f")
            [[ -n "$ld" ]] && live_domains="$live_domains $ld"
        done
        marked_orphans=(); legacy_candidates=()
        while read -r kind dom; do
            if [[ "$kind" == "marked-orphan" ]]; then marked_orphans+=("$dom"); fi
            if [[ "$kind" == "legacy-candidate" ]]; then legacy_candidates+=("$dom"); fi
        done < <(env_hosts_classify /etc/hosts "$live_names" "$live_domains")
        # Marked orphans are unambiguously dead Newspack envs — remove automatically.
        if [[ ${#marked_orphans[@]} -gt 0 ]]; then
            for dom in "${marked_orphans[@]}"; do
                if env_hosts_remove "$dom"; then
                    echo "Removed stale /etc/hosts entry $dom (orphaned env)"
                else
                    echo "Warning: could not remove stale entry $dom (no privileged removal ran)."
                fi
            done
        fi
        # Unmarked candidates predate the marker — never auto-remove; confirm first.
        if [[ ${#legacy_candidates[@]} -gt 0 ]]; then
            echo ""
            echo "Unmarked *.test/*.local /etc/hosts entries not matching any live env:"
            printf '  %s\n' "${legacy_candidates[@]}"
            # Never auto-remove unmarked entries: --yes is treated like the
            # non-interactive path (leave them; they may be the user's own).
            if [[ "$cleanup_yes" != true ]] && [ -t 0 ] && [ -t 1 ]; then
                read -p "Remove these? (y/N): " prune_confirm
                if [[ "$prune_confirm" =~ ^[Yy]$ ]]; then
                    for dom in "${legacy_candidates[@]}"; do
                        if env_hosts_remove "$dom"; then echo "Removed $dom"; else echo "Warning: could not remove $dom"; fi
                    done
                fi
            else
                echo "(left in place — re-run 'n env cleanup' interactively to remove, or remove manually)"
            fi
        fi
        ;;
    e2e-setup)
        shift
        exec "$NABSPATH/bin/setup-local-e2e.sh" "$@"
        ;;
    *)
        echo "Usage: n env <create|up|down|destroy|list|cleanup|e2e-setup>"
        echo "  up <name> [--build]      Start an environment"
        echo "  up --all [--build]       Start all environments"
        echo "  cleanup [--all] [--yes]  Remove environments (--all selects everything, --yes skips confirmation)"
        echo "  e2e-setup <name> [opts]  Build a ready-to-run local e2e-tests environment (see --help)"
        ;;
esac
fi
