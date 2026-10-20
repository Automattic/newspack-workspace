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

# Loopback IPs currently bound by running containers (covers an env whose
# compose file is gone or stale but the container is still up). Collected
# with no lock held -- `docker ps` has no bound timeout and can legitimately
# stall while Docker Desktop is still starting, and env_create_lock_acquire's
# staleness check can't tell a holder that's merely slow in here from one
# that's actually dead. A container from a *concurrent* `n env create` always
# goes through this same lock first to write its compose file, so the
# in-lock compose-file rescan in next_loopback_ip() already covers anything a
# parallel create adds; this snapshot only needs to catch containers with no
# (or a stale) compose file, which isn't time-sensitive the way the pick
# itself is.
docker_bound_loopback_ips() {
    docker ps --format '{{.Ports}}' 2>/dev/null | grep -o '127\.0\.0\.[0-9]*' | sort -u | tr '\n' ' '
}

# Find the next available loopback IP (127.0.0.2+), given $1 = IPs already
# bound by running containers (from docker_bound_loopback_ips, collected
# once before the lock -- see its own comment) plus the existing compose
# files, rescanned fresh every call since that part does need to be current.
next_loopback_ip() {
    used_ips="$1"
    for f in "$NABSPATH"/docker-compose.env-*.yml; do
        [[ -f "$f" ]] || continue
        ip=$(grep -o '127\.0\.0\.[0-9]*' "$f" | head -1)
        [[ -n "$ip" ]] && used_ips="$used_ips $ip"
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

# Read a lock directory's mtime as epoch seconds, or print nothing if that
# fails (directory gone, permissions). Branch on `stat --version` rather than
# trying BSD's `-f %m` and falling back to GNU's `-c %Y` on failure: GNU stat
# accepts `-f` (it means "describe the filesystem", not "describe the file")
# and doesn't reliably fail on an unknown format directive, so that fallback
# chain isn't a safe way to tell the two apart.
lockdir_mtime() {
    if stat --version >/dev/null 2>&1; then
        stat -c %Y "$1" 2>/dev/null
    else
        stat -f %m "$1" 2>/dev/null
    fi
}

# Serialize the loopback-IP pick and the compose-file write across concurrent
# `n env create` runs (NPPM-3647). Without this, two creates started together
# can both call next_loopback_ip() and read the same free IP, because neither
# has written a compose file claiming it yet. mkdir is atomic on both
# filesystems this runs on (APFS, ext4) and needs no extra binary.
env_create_lock_acquire() {
    local lockdir="$NABSPATH/.env-create.lock"
    local attempts=0
    local last_seen_mtime=""
    while true; do
        local mkdir_err
        # LC_ALL=C pins mkdir's own error text to English for the match
        # below. Newspack has contributors worldwide, and a developer
        # running this under a non-English locale would otherwise get
        # "File exists" translated -- on GNU coreutils in particular,
        # strerror() is gettext-translated when a matching message catalog
        # is installed -- which would make ordinary lock contention look
        # like a real error and fail every concurrent create immediately
        # instead of waiting.
        mkdir_err=$(LC_ALL=C mkdir "$lockdir" 2>&1) && break
        # mkdir fails this way whenever $lockdir already exists -- the normal,
        # expected shape of contention with another waiter. Any other reason
        # (a read-only or missing $NABSPATH, a permissions error) means this
        # process can never win the lock no matter how long it waits; fail
        # fast with the real cause instead of spinning the full ~90s below
        # and then blaming a "stale lock" that was never the problem.
        if [[ "$mkdir_err" != *"File exists"* ]]; then
            echo "Error: couldn't create the env-create lock at $lockdir: ${mkdir_err#mkdir: }" >&2
            return 1
        fi
        # A lock held longer than this outlived any plausible IP-pick-and-write;
        # the process that made it is gone (killed, crashed) rather than slow.
        # Clear it so a crash doesn't wedge every later `create` behind a lock
        # nothing will ever release. A failed `lockdir_mtime` read means the
        # directory is gone *right now*, not that it's stale -- skip reclaim
        # and loop back to `mkdir` instead.
        #
        # A *successful* read is not safe to act on directly either: two
        # waiters can both read the same old mtime and both decide "stale" at
        # nearly the same moment. Relocating the directory to inspect it
        # first (renaming it aside, restoring it if a fresh `mkdir` turns
        # out to have won the race) isn't safe either: moving it at all
        # leaves the path briefly absent, and a third waiter's `mkdir` can
        # fill that gap and believe it holds the lock while the original
        # holder still does too. So never relocate a directory that might
        # still be live: the destructive step runs behind its own
        # one-holder-at-a-time mkdir-mutex (`$lockdir.recovering`), with
        # staleness re-checked in place immediately before acting. At most
        # one process ever wins that mutex at a time, so at most one
        # `rmdir` ever runs against a given stale generation; everyone else
        # only ever competes for `$lockdir` itself once it's already gone,
        # which plain `mkdir` resolves to exactly one winner.
        local mtime
        mtime=$(lockdir_mtime "$lockdir") || mtime=""
        # A different mtime (including empty, meaning the dir briefly isn't
        # there at all) than last time through this loop means the lock
        # changed hands since our last poll. Reset the timeout clock so a
        # late-arriving waiter can't accumulate attempts across several
        # different holders' short, legitimate tenures and hit the timeout
        # even though no single lock was ever actually stale.
        if [[ "$mtime" != "$last_seen_mtime" ]]; then
            attempts=0
            last_seen_mtime="$mtime"
        fi
        if [[ -n "$mtime" ]] && (( $(date +%s) - mtime > 60 )); then
            local recovering="$lockdir.recovering"
            if mkdir "$recovering" 2>/dev/null; then
                local mtime2
                mtime2=$(lockdir_mtime "$lockdir") || mtime2=""
                if [[ -n "$mtime2" ]] && (( $(date +%s) - mtime2 > 60 )); then
                    # `rmdir` only removes an empty directory; a stray file
                    # inside $lockdir (nothing here writes one, but
                    # something else could) makes this fail silently. A
                    # failure here still falls through to the ordinary
                    # attempts/sleep accounting below, bounding it the same
                    # as every other path through this function.
                    rmdir "$lockdir" 2>/dev/null || true
                fi
                rmdir "$recovering" 2>/dev/null || true
            fi
            # Lost the race for $recovering: either another waiter is already
            # running the reclaim above (fine -- it'll release in a couple of
            # syscalls and our next mkdir attempt picks it up), or $recovering
            # was itself abandoned by a crash or Ctrl-C mid-reclaim (nothing
            # protects this function with a trap of its own, so an interrupt
            # here is possible, if rare). Don't try to tell those two apart
            # with a second, unprotected stale-mtime-then-rmdir on
            # $recovering itself: that reopens the identical two-holder bug
            # one level down (two waiters reading the same old mtime, the
            # slower one's rmdir deleting the faster one's fresh reclaim of
            # it). Falling through to the ordinary wait/timeout below is
            # always safe instead -- an abandoned $recovering costs a
            # bounded wait and a message naming both directories to clear
            # by hand, never a second holder.
        fi
        attempts=$((attempts + 1))
        # Comfortably longer than the 60s staleness window above: a holder
        # that is merely slow must never time out here before staleness
        # would even have considered it abandoned. (docker_bound_loopback_ips
        # -- the one call in this path with no bound timeout -- now runs
        # before the lock is taken, so the holder itself never stalls on it;
        # this margin is for other slowness, e.g. a loaded filesystem.)
        if [[ "$attempts" -gt 900 ]]; then
            echo "Error: timed out waiting for another 'n env create' to finish picking an IP (stale lock? rm -r $lockdir; if $lockdir.recovering also exists, rm -r that too)" >&2
            return 1
        fi
        sleep 0.1
    done
}

env_create_lock_release() {
    rmdir "$NABSPATH/.env-create.lock" 2>/dev/null || true
}

# Read domain from a compose file's WP_DOMAIN env var.
domain_for_env() {
    grep 'WP_DOMAIN=' "$1" | head -1 | sed 's/.*WP_DOMAIN=//'
}

# Read loopback IP from a compose file.
ip_for_env() {
    grep -o '127\.0\.0\.[0-9]*' "$1" | head -1
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
        # All args validated -- now create the recorded worktrees. If a creation
        # fails mid-batch, roll back the ones made in this run (worktree dir +
        # registration only; branches are left alone) so we don't leave orphans.
        created_gitdirs=()
        created_wtdirs=()
        wt_rollback() {
            local i
            for i in "${!created_wtdirs[@]}"; do
                # Worktrees are shared by design -- a `create` for the same
                # branch that started around the same time can see this one
                # already exists and skip making its own (the "pre-existing
                # worktree; leave it" check below), relying on it staying
                # put. If anything's since written a compose file naming
                # this path, a sibling create has already gotten far enough
                # to be using it; leave it alone instead of deleting out
                # from under that env. The path is matched relative to
                # $NABSPATH, which is how compose files mount it, anchored
                # on what always follows the worktree segment in a mount
                # line -- a `/` for a monorepo worktree (more path segments
                # follow, e.g. `/plugins/<repo>`) or a `:` for a repos/
                # worktree (the host path ends right there) -- so a bare
                # substring match can't mistake "foo" for a sibling
                # "foo-bar" worktree.
                local wt_rel="${created_wtdirs[$i]#"$NABSPATH"/}"
                if grep -lF "$wt_rel/" "$NABSPATH"/docker-compose.env-*.yml >/dev/null 2>&1 ||
                   grep -lF "$wt_rel:" "$NABSPATH"/docker-compose.env-*.yml >/dev/null 2>&1; then
                    echo "Note: leaving worktree ${created_wtdirs[$i]} in place -- another env's compose file already references it" >&2
                    continue
                fi
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
        docker_ips=$(docker_bound_loopback_ips)
        if ! env_create_lock_acquire; then
            # No wt_rollback here, unlike the two worktree-creation failures
            # above: by this point the worktrees this run made are valid
            # and already shared (a second concurrent create for the same
            # branch may have seen one as pre-existing and skipped making
            # its own, relying on it staying put -- see wt_rollback's own
            # compose-file check, which can't protect a worktree before any
            # compose file exists yet, exactly the state two creates stuck
            # on this same lock would both be in). The lock timing out is
            # unrelated to whether the worktrees are good; leave them for a
            # later `create` to reuse via the "pre-existing worktree" check.
            if [[ "${#created_wtdirs[@]}" -gt 0 ]]; then
                echo "Note: this run's worktrees are left in place for reuse: ${created_wtdirs[*]}" >&2
            fi
            exit 1
        fi
        trap 'env_create_lock_release' EXIT
        ip=$(next_loopback_ip "$docker_ips")
        if [[ -z "$domain" ]]; then
            domain="${env_name}.test"
        fi
        compose_file="$NABSPATH/docker-compose.env-${env_name}.yml"
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
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
    ports:
      - "${ip}:80:80"
      - "${ip}:443:443"
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
        echo "Created $compose_file (db: $db_name, domain: $domain, ip: $ip)"
        # The IP is claimed by the compose file above now existing, so a
        # concurrent create's next_loopback_ip() call will see it. Release
        # before the alias/hosts setup below, which is per-IP and needs no
        # serialization now that IPs can't collide.
        trap - EXIT
        env_create_lock_release
        # Check networking prerequisites (macOS only — Linux routes all 127.x.x.x by default).
        if [[ "$(uname)" == "Darwin" ]] && ! lo0_alias_exists "$ip"; then
            warn_if_manage_host_stale
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
                sudo newspack-manage-host host-add "$ip" "$domain"
            elif [ -t 0 ] && [ -t 1 ]; then
                read -p "Add $domain to /etc/hosts? (Y/n): " choice
                choice=$(echo "$choice" | tr '[:upper:]' '[:lower:]')
                if [[ "$choice" != "n" ]]; then
                    echo "$ip $domain" | sudo tee -a /etc/hosts > /dev/null
                fi
            else
                echo "Note: add hosts entry before browser access: sudo sh -c 'echo \"$ip $domain\" >> /etc/hosts'"
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
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
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
        # Ensure loopback alias exists (macOS only — Linux routes all 127.x.x.x by default).
        if [[ "$(uname)" == "Darwin" && -n "$ip" && "$ip" != "127.0.0.1" ]] && ! lo0_alias_exists "$ip"; then
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
                sudo newspack-manage-host host-add "$ip" "$domain"
                echo "Added $domain to /etc/hosts"
            elif [ -t 0 ] && [ -t 1 ]; then
                echo "Adding $domain to /etc/hosts (requires sudo)..."
                echo "$ip $domain" | sudo tee -a /etc/hosts > /dev/null
                echo "Added $domain to /etc/hosts"
            else
                echo "Warning: $domain not in /etc/hosts. Browser access won't work until added."
                echo "Run: sudo sh -c 'echo \"$ip $domain\" >> /etc/hosts'"
            fi
        fi
        # Source env files for DB credentials.
        set -a
        source "$NABSPATH/default.env"
        [[ -f "$NABSPATH/.env" ]] && source "$NABSPATH/.env"
        set +a
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
        echo "Environment '$env_name' is ready at https://${domain}/"
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
        ;;
    down)
        env_name="$2"
        if [[ -z "$env_name" ]] || is_help_arg "$env_name"; then
            env_usage "$env_name" "Usage: n env down <name>"
        fi
        validate_env_name "$env_name"
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
        docker stop "$container_name" 2>/dev/null
        docker rm "$container_name" 2>/dev/null
        ;;
    destroy)
        env_name="$2"
        if [[ -z "$env_name" ]] || is_help_arg "$env_name"; then
            env_usage "$env_name" "Usage: n env destroy <name>"
        fi
        validate_env_name "$env_name"
        compose_file="$NABSPATH/docker-compose.env-${env_name}.yml"
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
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
                # different branch inside it), then delete the REAL branch
                # separately: worktree.sh's own `git branch -D <safe>` no-ops
                # against a slash-named branch (feat/foo vs feat-foo), which
                # previously left the local ref dangling across create/destroy.
                real_branch=$(resolve_unsanitized_branch "$wt_branch" "")
                "$NABSPATH/bin/worktree.sh" remove --yes "$wt_repo" "$wt_branch"
                if [[ -n "$real_branch" && "$real_branch" != "$wt_branch" ]]; then
                    git -C "$NABSPATH" branch -D "$real_branch" 2>/dev/null && echo "Deleted branch $real_branch"
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
            container_name=$(echo "newspack_env_${name}" | tr '-' '_')
            domain=$(domain_for_env "$f")
            if status=$(docker inspect -f '{{.State.Status}}' "$container_name" 2>/dev/null); then
                :
            else
                status="stopped"
            fi
            # Collect worktrees as repo:branch pairs. each_worktree_in_env
            # yields the mount-derived safe branch; resolve_unsanitized_branch
            # recovers the friendly display form (e.g., feat/foo) for monorepo
            # worktrees while leaving filesystem-operation paths to the safe
            # form.
            worktrees=""
            while IFS='|' read -r repo safe_branch kind; do
                [[ "$kind" == "repos" ]] && repos_repo="$repo" || repos_repo=""
                branch=$(resolve_unsanitized_branch "$safe_branch" "$repos_repo")
                [[ -n "$worktrees" ]] && worktrees="$worktrees,"
                worktrees="${worktrees}${repo}:${branch}"
            done < <(each_worktree_in_env "$f")
            if [[ "$porcelain" == true ]]; then
                printf '%s\t%s\thttps://%s/\t%s\n' "$name" "$status" "$domain" "$worktrees"
            else
                echo "  $name ($status) https://${domain}/"
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
        echo "Usage: n env <create|up|down|destroy|list|cleanup|e2e-setup>"
        echo "  up <name> [--build]      Start an environment"
        echo "  up --all [--build]       Start all environments"
        echo "  cleanup [--all] [--yes]  Remove environments (--all selects everything, --yes skips confirmation)"
        echo "  e2e-setup <name> [opts]  Build a ready-to-run local e2e-tests environment (see --help)"
        ;;
esac
