#!/bin/bash

# Build a ready-to-run local environment for the Newspack Playwright e2e suite
# (in-repo at e2e/). This is the one-shot equivalent of the manual dance of
# creating worktrees, spinning up an isolated env, building any unbuilt assets,
# installing the e2e helper plugin, and pointing the suite's .env at the new site.
#
# The suite provisions the site itself on every run: its setup projects run
# e2e-setup.sh / site-setup.sh (a full from-scratch rebuild against the installed
# plugin code) via `npm run test:setup`. So this script does NOT reset the DB or
# seed content — it only stands the env up with the plugins the suite activates
# present, installs the e2e helper plugin, and configures .env.
#
# Plugins the suite activates, and where they come from on a standard checkout:
#   - newspack-plugin/blocks/popups/newsletters/ads + newspack-theme — worktrees
#     on the target branch, created and built here.
#   - newspack-manager and the WooCommerce stack (woocommerce, -gateway-stripe,
#     -subscriptions, -memberships, -name-your-price) — mounted into the env from
#     the workspace's plugins/ and repos/plugins/ (present on a standard checkout;
#     the env's ./repos and ./plugins mounts symlink them into wp-content/plugins).
#   - e2e-plugin — a single-file helper shipped in e2e/, installed here.
#
# Usage:
#   n env e2e-setup <name> [options]
#
# Options:
#   --branch <branch>     Branch to check out for each plugin (default: release).
#   --domain <domain>     Site domain (default: <name>.test).
#   --e2e-repo <path>     Path to the e2e suite (default: <workspace>/e2e).
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
        # commons is newspack-plugin's shared webpack runtime chunk — always emitted
        # by a successful build, so its asset manifest is a reliable "is built" marker.
        newspack-plugin) echo "dist/commons.asset.php" ;;
        # newspack-theme is a meta-repo; the active classic theme lives in a nested
        # newspack-theme/ subdir, and that's where its JS build output lands.
        newspack-theme)  echo "newspack-theme/js/dist" ;;
        *)               echo "dist" ;;
    esac
}

# The in-container mount root a worktree is served from: themes live under
# /newspack-themes, everything else under /newspack-plugins. (The worktree is
# NOT mounted at /newspack-repos — that root is for repos/plugins checkouts — so
# the build marker must be looked up under the serving root, not there.)
container_root_for() {
    case "$1" in
        newspack-theme) echo "/newspack-themes" ;;
        *)              echo "/newspack-plugins" ;;
    esac
}

usage() {
    # Print the leading comment block (the lines after the shebang), stripped of '#'.
    awk 'NR>2 { if ($0 ~ /^#/) { sub(/^# ?/, ""); print } else { exit } }' "${BASH_SOURCE[0]}"
}

env_name=""
branch="release"
domain=""
e2e_repo="$NABSPATH/e2e"

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

# Resolve the e2e suite and the helper plugin we install from it. e2e-setup.sh
# is checked too, as a sanity check that $e2e_repo really points at the suite.
e2e_plugin_src="$e2e_repo/e2e-plugin.php"
if [[ ! -f "$e2e_plugin_src" || ! -f "$e2e_repo/e2e-setup.sh" ]]; then
    log_error "Could not find e2e-plugin.php / e2e-setup.sh in: $e2e_repo"
    log_error "Pass the e2e suite path with --e2e-repo <path>."
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
#    from a matching repos/<plugin> checkout, so a monorepo worktree with no such
#    source has nothing to copy — compile it directly inside the container, which
#    serves the worktree from /newspack-plugins/<repo> (themes: /newspack-themes).
for repo in "${E2E_PLUGINS[@]}"; do
    marker=$(build_marker_for "$repo")
    root=$(container_root_for "$repo")
    if docker exec "$container_name" test -e "${root}/${repo}/${marker}" 2>/dev/null; then
        log_info "$repo already has built assets — skipping build."
    else
        log_info "Building $repo (missing $marker)..."
        docker exec "$container_name" bash -c "/var/scripts/build-repos.sh ${repo} ci" \
            || { log_error "Failed to build $repo"; exit 1; }
    fi
done

# 3b. Wire Stripe test keys into the suite's .env so the donations test can run
#     locally. dotenv loads .env into the Playwright process, which forwards
#     STRIPE_PUB_KEY / STRIPE_SECRET_KEY into provisioning (e2e-setup.sh applies
#     them to the WooCommerce Stripe gateway). Keys are sourced from the
#     workspace's canonical secrets file when present.
secrets_file="$NABSPATH/bin/secrets.json"
if [[ -f "$secrets_file" ]] && command -v jq >/dev/null 2>&1; then
    stripe_pub=$(jq -r '.stripe.testPublishableKey // empty' "$secrets_file")
    stripe_secret=$(jq -r '.stripe.testSecretKey // empty' "$secrets_file")
    if [[ -n "$stripe_pub" && -n "$stripe_secret" ]]; then
        log_info "Wiring Stripe test keys from secrets.json into $e2e_repo/.env..."
        touch "$e2e_repo/.env"
        upsert_env_var "STRIPE_PUB_KEY" "$stripe_pub" "$e2e_repo/.env"
        upsert_env_var "STRIPE_SECRET_KEY" "$stripe_secret" "$e2e_repo/.env"
    else
        log_warning "No Stripe test keys in secrets.json — the donations test will fail locally."
    fi
fi

# 4. Install the e2e helper plugin (a single-file plugin shipped in the suite, not
#    vendored in the workspace). The suite activates it by slug during provisioning.
log_info "Installing the e2e helper plugin..."
docker cp "$e2e_plugin_src" "${container_name}:/var/www/html/wp-content/plugins/e2e-plugin.php"

# 5. Point the suite's .env at this environment, preserving any other keys
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
echo "Run the suite against it (its setup projects provision the site each run):"
echo "  cd $e2e_repo"
echo "  npm ci && npx playwright install   # first time only"
echo "  npm run test:setup                 # full run (provision + all projects)"
echo "  USE_SETUP=true npx playwright test --project=\"Vanilla in Desktop Chrome\"  # one project"
