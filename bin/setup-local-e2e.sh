#!/bin/bash

# Build a ready-to-run local environment for the newspack-e2e-tests Playwright
# suite, end to end. This is the one-shot equivalent of the manual dance of
# creating worktrees, spinning up an isolated env, building any unbuilt assets,
# installing the e2e helper plugin, running the e2e reset script, and pointing
# the e2e repo's .env at the new site.
#
# Usage:
#   n env e2e-setup <name> [options]
#
# Options:
#   --branch <branch>     Branch to check out for each plugin (default: release).
#   --domain <domain>     Site domain (default: <name>.test).
#   --e2e-repo <path>     Path to the newspack-e2e-tests checkout
#                         (default: a sibling of this workspace).
#   -h, --help            Show this help.
#
# Safe to re-run: an existing environment is reused, and only worktrees missing
# built assets are (re)built.

source "$(dirname "${BASH_SOURCE[0]}")/_common.sh"

# Plugins the e2e suite needs, checked out on the target branch as worktrees.
E2E_PLUGINS=(
    newspack-plugin
    newspack-blocks
    newspack-popups
    newspack-newsletters
    newspack-ads
    newspack-theme
)

# A path (relative to the repo root) whose presence means the worktree is built.
# Missing marker => the worktree's assets need compiling before WordPress can
# load it (e.g. newspack-plugin's editor bundle, newspack-theme's compiled JS).
build_marker_for() {
    case "$1" in
        newspack-plugin) echo "dist/editor.asset.php" ;;
        # newspack-theme is a meta-repo; the active classic theme lives in a nested
        # newspack-theme/ subdir, and that's where its JS build output lands.
        newspack-theme)  echo "newspack-theme/js/dist" ;;
        *)               echo "dist" ;;
    esac
}

usage() {
    # Print the leading comment block (the lines after the shebang), stripped of '#'.
    awk 'NR>2 { if ($0 ~ /^#/) { sub(/^# ?/, ""); print } else { exit } }' "${BASH_SOURCE[0]}"
}

env_name=""
branch="release"
domain=""
e2e_repo="$(cd "$NABSPATH/.." 2>/dev/null && pwd)/newspack-e2e-tests"

while [[ $# -gt 0 ]]; do
    case "$1" in
        -h|--help) usage; exit 0 ;;
        --branch)
            [[ -z "$2" || "$2" == --* ]] && { log_error "--branch requires a value"; exit 1; }
            branch="$2"; shift 2 ;;
        --domain)
            [[ -z "$2" || "$2" == --* ]] && { log_error "--domain requires a value"; exit 1; }
            domain="$2"; shift 2 ;;
        --e2e-repo)
            [[ -z "$2" || "$2" == --* ]] && { log_error "--e2e-repo requires a value"; exit 1; }
            e2e_repo="$2"; shift 2 ;;
        --*) log_error "Unknown option: $1"; usage; exit 1 ;;
        *)
            if [[ -z "$env_name" ]]; then
                env_name="$1"; shift
            else
                log_error "Unexpected argument: $1"; exit 1
            fi
            ;;
    esac
done

if [[ -z "$env_name" ]]; then
    usage
    exit 1
fi
validate_env_name "$env_name"
validate_name "$branch" "branch"
[[ -n "$domain" ]] && validate_domain "$domain"
[[ -z "$domain" ]] && domain="${env_name}.test"

# Resolve the e2e-tests repo and the two files we pull from it.
e2e_plugin_src="$e2e_repo/e2e-plugin.php"
e2e_reset_src="$e2e_repo/e2e-reset.sh"
if [[ ! -f "$e2e_plugin_src" || ! -f "$e2e_reset_src" ]]; then
    log_error "Could not find e2e-plugin.php / e2e-reset.sh in: $e2e_repo"
    log_error "Pass the e2e-tests checkout with --e2e-repo <path>."
    exit 1
fi

container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
compose_file="$NABSPATH/docker-compose.env-${env_name}.yml"

# Upsert a KEY="value" line into an env file (in-place replace, or append).
upsert_env_var() {
    local key="$1" value="$2" file="$3"
    if [[ -f "$file" ]] && grep -q "^${key}=" "$file"; then
        # In-place replace; handle both BSD and GNU sed.
        sed -i '' "s|^${key}=.*|${key}=\"${value}\"|" "$file" 2>/dev/null \
            || sed -i "s|^${key}=.*|${key}=\"${value}\"|" "$file"
    else
        echo "${key}=\"${value}\"" >> "$file"
    fi
}

log_info "Setting up local e2e environment '$env_name' (branch: $branch, domain: $domain)"

# The isolated-env compose files reference an external Docker network; create it
# if 'n start' hasn't already (env up assumes it exists).
if ! docker network inspect newspack_envs >/dev/null 2>&1; then
    log_info "Creating shared Docker network 'newspack_envs'..."
    docker network create newspack_envs >/dev/null || { log_error "Failed to create network"; exit 1; }
fi

# 1. Create the environment (worktrees on the target branch), reusing it if present.
if [[ -f "$compose_file" ]]; then
    log_info "Environment '$env_name' already exists — reusing it."
else
    worktree_args=()
    for repo in "${E2E_PLUGINS[@]}"; do
        worktree_args+=(--worktree "${repo}:${branch}")
    done
    log_info "Creating environment with worktrees: ${E2E_PLUGINS[*]} @ $branch"
    # Drive create non-interactively (no "start now?" prompt); we start it below.
    "$NABSPATH/bin/env.sh" create "$env_name" "${worktree_args[@]}" --domain "$domain" < /dev/null \
        || { log_error "Failed to create environment"; exit 1; }
fi

# 2. Start it. --build seeds each worktree with built assets from the matching
#    repos/<plugin> checkout, and the up flow installs WP + writes permalinks.
log_info "Starting environment (this installs WordPress and seeds assets)..."
"$NABSPATH/bin/env.sh" up "$env_name" --build || { log_error "Failed to start environment"; exit 1; }

# Ensure the domain resolves so Playwright (running on the host) can reach it.
# `n env up` only adds /etc/hosts entries interactively; the e2e suite is usually
# driven non-interactively, so add it here via the passwordless helper if present.
if [[ "$domain" == *.test || "$domain" == *.local ]] && ! grep -q "[[:space:]]${domain}$" /etc/hosts 2>/dev/null; then
    ip=$(grep -o '127\.0\.0\.[0-9]*' "$compose_file" | head -1)
    if command -v newspack-manage-host >/dev/null 2>&1; then
        sudo newspack-manage-host host-add "$ip" "$domain" \
            && log_info "Added $domain -> $ip to /etc/hosts"
    else
        log_warning "$domain is not in /etc/hosts. Add it before running tests:"
        log_warning "  sudo sh -c 'echo \"$ip $domain\" >> /etc/hosts'"
    fi
fi

# 3. Build any worktree that still lacks compiled assets. --build only copies
#    from repos/<plugin>, so a worktree whose source repo was never built has
#    nothing to copy — compile it directly inside the container (it mounts the
#    worktree at /newspack-repos/<repo>).
for repo in "${E2E_PLUGINS[@]}"; do
    marker=$(build_marker_for "$repo")
    if docker exec "$container_name" test -e "/newspack-repos/${repo}/${marker}" 2>/dev/null; then
        log_info "$repo already has built assets — skipping build."
    else
        log_info "Building $repo (missing $marker)..."
        docker exec "$container_name" bash -c "/var/scripts/build-repos.sh ${repo} ci" \
            || { log_error "Failed to build $repo"; exit 1; }
    fi
done

# 3b. Wire Stripe test keys into the e2e repo's .env so the donations test can run
#     locally. e2e-reset.sh reads STRIPE_PUB_KEY / STRIPE_SECRECT_KEY from the site's
#     .env (which we copy into the container in step 4). The keys are sourced from
#     newspack-workspace/bin/secrets.json — the workspace's canonical secrets file.
#     (Today this lives in newspack-docker; once the e2e suite is merged into the
#     workspace, that same secrets.json will be the single home for these keys.)
secrets_file="$NABSPATH/bin/secrets.json"
if [[ -f "$secrets_file" ]] && command -v jq >/dev/null 2>&1; then
    stripe_pub=$(jq -r '.stripe.testPublishableKey // empty' "$secrets_file")
    stripe_secret=$(jq -r '.stripe.testSecretKey // empty' "$secrets_file")
    if [[ -n "$stripe_pub" && -n "$stripe_secret" ]]; then
        log_info "Wiring Stripe test keys from secrets.json into $e2e_repo/.env..."
        touch "$e2e_repo/.env"
        upsert_env_var "STRIPE_PUB_KEY" "$stripe_pub" "$e2e_repo/.env"
        # Note: e2e-reset.sh expects the (misspelled) STRIPE_SECRECT_KEY var name.
        upsert_env_var "STRIPE_SECRECT_KEY" "$stripe_secret" "$e2e_repo/.env"
    else
        log_warning "No Stripe test keys in secrets.json — the donations test will fail locally."
    fi
fi

# 4. Install the e2e helper plugin and run the e2e reset script, both pulled from
#    the e2e-tests repo (not vendored here). e2e-reset.sh resolves the WordPress
#    install from its working directory, so it runs from the web root.
log_info "Installing the e2e helper plugin..."
docker cp "$e2e_plugin_src" "${container_name}:/var/www/html/wp-content/plugins/e2e-plugin.php"

log_info "Running e2e-reset.sh (Newspack setup, sample content, snapshots, Woo)..."
docker cp "$e2e_reset_src" "${container_name}:/var/www/html/e2e-reset.sh"
# Copy the e2e repo's .env too, so optional Stripe keys are picked up; removed after.
copied_env=false
if [[ -f "$e2e_repo/.env" ]]; then
    docker cp "$e2e_repo/.env" "${container_name}:/var/www/html/.env"
    copied_env=true
fi
# e2e-reset.sh has no `set -e`; snapshot/manager/premium-Woo steps can fail
# harmlessly on a local env, so don't let a non-zero exit abort this script.
docker exec "$container_name" bash -c "cd /var/www/html && bash e2e-reset.sh" \
    || log_warning "e2e-reset.sh reported errors (often snapshot/manager/premium-Woo steps); continuing."
# Clean up the transient files from the web root (keep the activated plugin).
docker exec "$container_name" rm -f /var/www/html/e2e-reset.sh
[[ "$copied_env" == true ]] && docker exec "$container_name" rm -f /var/www/html/.env

# 5. Re-assert pretty permalinks. `wp newspack setup` (run by e2e-reset) can flush
#    rewrite rules, so write them out once more and hand .htaccess back to Apache.
log_info "Ensuring permalink rewrite rules are in place..."
run_user="${USE_CUSTOM_APACHE_USER:-www-data}"
docker exec "$container_name" wp --allow-root rewrite structure '/%year%/%monthnum%/%day%/%postname%/' --hard >/dev/null 2>&1
docker exec "$container_name" wp --allow-root rewrite flush --hard >/dev/null 2>&1
docker exec "$container_name" chown "$run_user":"$run_user" /var/www/html/.htaccess 2>/dev/null || true
docker exec "$container_name" wp --allow-root cache flush >/dev/null 2>&1 || true

# 6. Point the e2e repo's .env at this environment, preserving any other keys
#    (e.g. Stripe credentials) already present.
log_info "Configuring $e2e_repo/.env..."
touch "$e2e_repo/.env"
upsert_env_var "SITE_URL" "https://${domain}" "$e2e_repo/.env"
upsert_env_var "ADMIN_USER" "admin" "$e2e_repo/.env"
upsert_env_var "ADMIN_PASSWORD" "password" "$e2e_repo/.env"

echo ""
log_success "Local e2e environment '$env_name' is ready at https://${domain}/"
log_success "$e2e_repo/.env is configured (SITE_URL=https://${domain})."
echo ""
echo "Run the suite against it:"
echo "  cd $e2e_repo"
echo "  npx playwright test --project=\"Vanilla in Desktop Chrome\""
