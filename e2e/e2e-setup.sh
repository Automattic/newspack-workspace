#!/bin/bash

# Provision a Newspack site into a known state for the e2e suite.
#
# This wraps newspack-manager's general `scripts/site-setup.sh` (which rebuilds
# the whole site from scratch using the CURRENTLY installed plugin code) and then
# layers the e2e-specific configuration that the general script deliberately does
# not carry: the NEWSPACK_IS_E2E flag, the e2e helper plugin, the extra Newspack
# plugins the suite drives, Stripe test credentials, editor preferences, etc.
#
# It is meant to be copied to the site's WordPress root and run there – locally in
# the Docker container (as root, with --allow-root) or on a remote host over SSH.
#
# Usage: e2e-setup.sh [--woo|--no-woo] [passthrough options...]
#   --woo / --no-woo   Provision with or without the WooCommerce stack (default: --woo)
# All other options (--url, --admin-user, --admin-password, --allow-root, --reset)
# are passed straight through to site-setup.sh.

set -e

# Load Stripe test keys (and any other config) from .env when present.
if [ -f .env ]; then
  set -o allexport
  source .env
  set +o allexport
fi

WOO=true
ALLOW_ROOT=false
SITE_URL="http://localhost"
ADMIN_USER="admin"
ADMIN_PASSWORD="password"

# Options we both consume here and forward to site-setup.sh.
PASSTHROUGH=()
while [[ $# -gt 0 ]]; do
  case $1 in
    --woo)
      WOO=true
      shift
      ;;
    --no-woo)
      WOO=false
      shift
      ;;
    --allow-root)
      ALLOW_ROOT=true
      PASSTHROUGH+=("$1")
      shift
      ;;
    --url)
      SITE_URL="$2"
      PASSTHROUGH+=("$1" "$2")
      shift 2
      ;;
    --admin-user)
      ADMIN_USER="$2"
      PASSTHROUGH+=("$1" "$2")
      shift 2
      ;;
    --admin-password)
      ADMIN_PASSWORD="$2"
      PASSTHROUGH+=("$1" "$2")
      shift 2
      ;;
    *)
      PASSTHROUGH+=("$1")
      shift
      ;;
  esac
done

# WP-CLI wrapper mirroring site-setup.sh: inject --allow-root only when asked.
WP_GLOBAL_OPTS=()
if [ "$ALLOW_ROOT" = true ]; then
  WP_GLOBAL_OPTS+=(--allow-root)
fi
wp() {
  command wp "${WP_GLOBAL_OPTS[@]}" "$@"
}

# Resolve site-setup.sh. It ships next to this script; setupSite (tests/site-setup.ts)
# copies both onto the target and points SITE_SETUP_SCRIPT at the copy, and a manual
# `bash e2e-setup.sh` finds it via this script's own directory.
SITE_SETUP="${SITE_SETUP_SCRIPT:-$(dirname "$0")/site-setup.sh}"
if [ ! -f "$SITE_SETUP" ]; then
  echo "ERROR: site-setup.sh not found at $SITE_SETUP (set SITE_SETUP_SCRIPT to override)" >&2
  exit 1
fi

# Resolve the e2e helper plugin source. setupSite (tests/site-setup.ts) copies it
# onto the target and sets E2E_PLUGIN_SRC to point at that copy; a manual
# `bash e2e-setup.sh` leaves it unset and we fall back to the copy next to this
# script (there $0 is a real path, unlike the piped `bash -s` flow, which always
# sets E2E_PLUGIN_SRC). We track which case we're in so a missing shipped copy
# fails loudly while a missing manual copy only warns (see the sync step below).
if [ -n "${E2E_PLUGIN_SRC:-}" ]; then
  E2E_PLUGIN_SRC_EXPLICIT=true
else
  E2E_PLUGIN_SRC_EXPLICIT=false
  E2E_PLUGIN_SRC="$(dirname "$0")/e2e-plugin.php"
fi

echo "==> Running site-setup.sh (woo=$WOO)"
SITE_SETUP_ARGS=(
  --posts-count 20      # A handful is plenty for e2e; 40 is slow to generate.
  --customers-count 10  # Ditto: the suite doesn't need 100 customers.
  --no-campaigns        # Leave campaigns empty: campaigns.spec.ts builds a prompt
                        # from a clean slate and asserts an empty segment at the end,
                        # so the RAS preset prompts must not be seeded.
  --no-membership-plans # Woo Memberships gets deactivated below (Access Control
                        # owns content gating), so its plans would be inert fixtures.
)
if [ "$WOO" = false ]; then
  SITE_SETUP_ARGS+=(--no-woocommerce)
fi
bash "$SITE_SETUP" "${SITE_SETUP_ARGS[@]}" "${PASSTHROUGH[@]}"

echo "==> Applying e2e-specific configuration"

# Feature flags (written to wp-config.php, so they survive the DB rebuild above).
wp --skip-plugins --skip-themes config set NEWSPACK_IS_E2E true --raw
# Note: this flag is slated for removal upstream.
wp --skip-plugins --skip-themes config set NEWSPACK_EMAIL_CHANGE_ENABLED true --raw
# Enable the Access Control (content gating) system: the flag gates the
# Audience > Access control wizard, its REST routes, and all front-end
# enforcement, so content-gating.spec.ts is inert without it.
wp --skip-plugins --skip-themes config set NEWSPACK_CONTENT_GATES true --raw

# Local Docker only (--allow-root is the marker): WP-CLI runs as root while
# the web server runs as www-data, and under filesystem load WordPress's
# ownership probe can flake, silently switching WP_Filesystem to the
# unconfigured FTP backend - plugin activation then dies with an ftp_nlist()
# fatal mid-provisioning. Pinning the direct method (always correct inside
# the container) removes that fallback path. Managed hosts (CI over SSH)
# configure their own filesystem method, so leave them alone.
if [ "$ALLOW_ROOT" = true ]; then
  wp --skip-plugins --skip-themes config set FS_METHOD direct
fi

wp --skip-themes option update timezone_string 'America/New_York'

# Sync the e2e helper plugin from the repo copy so the running plugin always
# matches the committed source. It's a single-file plugin that provisioning only
# *activates*, so without this an edit to e2e-plugin.php would never reach the
# site (it would keep running whatever was last installed there by hand).
if [ -f "$E2E_PLUGIN_SRC" ]; then
  E2E_PLUGINS_DIR="$(wp --skip-plugins --skip-themes plugin path)"
  cp "$E2E_PLUGIN_SRC" "$E2E_PLUGINS_DIR/e2e-plugin.php"
elif [ "$E2E_PLUGIN_SRC_EXPLICIT" = true ]; then
  # The caller pointed E2E_PLUGIN_SRC at a copy it shipped onto the target, so a
  # missing file means that copy never arrived. Fail loudly rather than silently
  # running whatever stale plugin is already on the site - that drift is exactly
  # what deploying from the repo is meant to eliminate.
  echo "ERROR: E2E_PLUGIN_SRC is set to '$E2E_PLUGIN_SRC' but no file exists there - the shipped e2e-plugin.php did not arrive on the target." >&2
  exit 1
else
  echo "WARNING: e2e-plugin.php source not found at $E2E_PLUGIN_SRC; relying on the copy already installed on the site." >&2
fi

# Activate the remaining Newspack plugins the suite exercises, plus the e2e helper
# plugin (custom logout endpoint, outgoing-email log, admin-email-check bypass).
# These are hard dependencies of the suite (e2e-plugin in particular drives the
# reader-registration email flow), so fail loudly rather than leaving a site that
# breaks confusingly deep in a test. `wp plugin activate` is a no-op success when
# the plugin is already active.
for plugin in newspack-ads newspack-newsletters newspack-manager e2e-plugin; do
  wp --skip-themes plugin activate "$plugin" || {
    echo "ERROR: could not activate required plugin '$plugin' - is it installed on the site?" >&2
    exit 1
  }
done

# newspack-sponsors backs the sponsors spec. It's a separate, tolerant step since
# not every environment bundles it; a failure here must not abort provisioning.
wp --skip-themes plugin activate newspack-sponsors || \
  echo "WARNING: could not activate newspack-sponsors - the sponsors spec will be skipped/fail." >&2

# Run Newspack's own setup routine (creates default pages/config the wizard would).
wp --skip-themes newspack setup || echo "WARNING: 'wp newspack setup' failed"

# Disable the block-editor welcome guide for the admin so it doesn't cover the UI.
wp --skip-plugins --skip-themes user meta delete "$ADMIN_USER" wp_persisted_preferences || true
wp --skip-plugins --skip-themes user meta add "$ADMIN_USER" wp_persisted_preferences \
  '{"core/edit-post":{"welcomeGuide":false}}' --format=json || true

# A recognisable, time-stamped title so it's obvious which run a report came from.
wp --skip-plugins --skip-themes option update blogname "The Daily Test: $(date -u '+%Y-%m-%d %H:%M UTC')"

if [ "$WOO" = true ]; then
  echo "==> Applying e2e WooCommerce configuration"

  # Activate the Stripe gateway. site-setup.sh doesn't (it's not part of the
  # generic Newspack bootstrap), but the @with-woo donation tests need a gateway
  # that supports subscriptions, so treat a failure to activate as fatal.
  wp --skip-themes plugin activate woocommerce-gateway-stripe || {
    echo "ERROR: could not activate woocommerce-gateway-stripe - is it installed on the site?" >&2
    exit 1
  }

  # Access Control defers to WooCommerce Memberships whenever that plugin is
  # active (Content_Gate::restrict_post() bails on Memberships::is_active()), so
  # the first-party gating the suite tests would never take effect. Deactivate it
  # after site-setup.sh (which activates the Woo stack wholesale) to match the
  # target state of migrated Newspack sites: Access Control owns the front-end.
  wp --skip-themes plugin deactivate woocommerce-memberships 2>/dev/null || true

  # Provisioning boots WP many times before WooCommerce activates, and the
  # Newspack native My Account page (reader-activation/class-my-account.php)
  # can get created in one of those boots - winning the "my-account" slug, so
  # WooCommerce's later page install falls back to "my-account-2" and every
  # spec that navigates to /my-account/ lands on the wrong page. Reconcile the
  # way the plugin does when WooCommerce is (re)activated: both features
  # resolve to WooCommerce's page, which owns the canonical slug.
  WOO_MY_ACCOUNT_ID=$(wp --skip-themes option get woocommerce_myaccount_page_id 2>/dev/null || true)
  NATIVE_MY_ACCOUNT_ID=$(wp --skip-themes option get newspack_my_account_page_id 2>/dev/null || true)
  if [ -n "$WOO_MY_ACCOUNT_ID" ] && [ -n "$NATIVE_MY_ACCOUNT_ID" ] && [ "$NATIVE_MY_ACCOUNT_ID" != "$WOO_MY_ACCOUNT_ID" ]; then
    wp --skip-themes post delete "$NATIVE_MY_ACCOUNT_ID" --force
    wp --skip-themes option update newspack_my_account_page_id "$WOO_MY_ACCOUNT_ID"
    wp --skip-themes post update "$WOO_MY_ACCOUNT_ID" --post_name=my-account
  fi

  # Options site-setup.sh doesn't set but the suite relies on.
  wp --skip-plugins --skip-themes option update woocommerce_coming_soon 'no'
  wp --skip-plugins --skip-themes option update woocommerce_task_list_hidden 'yes'
  wp --skip-plugins --skip-themes option update woocommerce_task_list_complete 'yes'
  wp --skip-plugins --skip-themes option update woocommerce_task_list_welcome_modal_dismissed 'yes'
  wp --skip-plugins --skip-themes option update woocommerce_show_marketplace_suggestions 'no'
  wp --skip-plugins --skip-themes option update wc_memberships_admin_restricted_content_notice 'no'

  if [ -n "$STRIPE_PUB_KEY" ] && [ -n "$STRIPE_SECRET_KEY" ]; then
    echo "==> Configuring Stripe test gateway"
    wp --skip-plugins --skip-themes option update woocommerce_stripe_settings '{
      "title": "Credit Card (Stripe test mode)",
      "enabled": "yes",
      "testmode": "yes",
      "test_publishable_key": "'"$STRIPE_PUB_KEY"'",
      "test_secret_key": "'"$STRIPE_SECRET_KEY"'",
      "inline_cc_form": "no",
      "statement_descriptor": "E2E test store",
      "capture": "yes",
      "payment_request": "yes",
      "debug": "yes"
    }' --format=json
  fi
fi

wp cache flush || true
echo "==> e2e site setup complete (woo=$WOO)"
