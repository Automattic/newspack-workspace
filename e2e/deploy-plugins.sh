#!/bin/bash

# Deploy the monorepo's built Newspack plugins/theme onto an Atomic target site,
# so the e2e suite runs against the candidate code rather than whatever the site
# had installed. This is the release-gate step: TeamCity builds the plugins from a
# PR into alpha/release, runs this to push them, then provisions and tests.
#
# It is NOT part of the nightly or local flows. The nightly tests the staging
# site's installed release build; a local env mounts the worktree directly. Only
# the per-branch gate deploys candidate code, so only the gate calls this.
#
# Transport mirrors tests/site-setup.ts's remote path: SSH with sshpass using the
# E2E_SSH_* credentials, streaming each payload over stdin (no sftp needed). Set
# E2E_SSH_CMD to override the transport (e.g. to test the swap against an Atomic
# site over the local autoproxxy SSH); when set it is used verbatim as the ssh
# program and must accept a remote command as its final argument and pipe stdin.
#
# Usage: deploy-plugins.sh
# Env:
#   E2E_SSH_HOST        Required unless E2E_SSH_CMD is set. SSH host.
#   E2E_SSH_USER        Required unless E2E_SSH_CMD is set. SSH user.
#   E2E_SSH_USER_PASS   Password for sshpass auth (as the nightly uses).
#   E2E_SSH_KEY_FILE    Optional. Path to a private key; when set, SSH uses it
#                       (-i) and skips sshpass. The release gate uses this.
#   E2E_SSH_CMD         Optional. Full ssh invocation to use instead of sshpass;
#                       receives the remote command as its last argument.
#   E2E_REMOTE_WP_PATH  WordPress root on the target, relative to the SSH home.
#                       Default: htdocs.
#   E2E_BUILD_ROOT      Monorepo root holding plugins/ and themes/. Default: the
#                       directory above this script.
#
# The caller must build each plugin deploy-ready first: the asset build (dist/)
# AND production Composer deps (vendor/, via `composer install --no-dev`) for
# plugins that ship them - a plugin that loads vendor/autoload.php fatals on the
# target without it. This script refuses to ship a plugin missing either.
#
# DEPLOY_SET / E2E_DEPLOY_SET values are trusted (CI-controlled): they interpolate
# into the remote shell command, so this is not a place for untrusted input.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_ROOT="${E2E_BUILD_ROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
REMOTE_WP_PATH="${E2E_REMOTE_WP_PATH:-htdocs}"

# The deploy set: the plugins/theme the suite drives whose source lives in this
# monorepo. Each row is "name|src|dest|marker":
#   name   - directory name on the target (under wp-content/plugins or /themes).
#   src    - source directory, relative to BUILD_ROOT.
#   dest   - "plugins" or "themes" (which wp-content subdir it swaps into).
#   marker - a path under src that only exists once the asset build has run, so a
#            missing marker fails loudly rather than shipping an unbuilt plugin.
# newspack-manager is deliberately absent: it is a separate repo, not in this
# checkout, and is already installed on the gate sites.
DEPLOY_SET=(
  "newspack-plugin|plugins/newspack-plugin|plugins|dist/commons.asset.php"
  "newspack-blocks|plugins/newspack-blocks|plugins|dist"
  "newspack-popups|plugins/newspack-popups|plugins|dist"
  "newspack-newsletters|plugins/newspack-newsletters|plugins|dist"
  "newspack-ads|plugins/newspack-ads|plugins|dist"
  # newspack-theme is a meta-repo; the active classic theme is the nested subdir,
  # and its build output lands in newspack-theme/js/dist.
  "newspack-theme|themes/newspack-theme/newspack-theme|themes|js/dist"
)

# Override the deploy set with E2E_DEPLOY_SET (newline-separated rows, same
# "name|src|dest|marker" format) to deploy a subset. Empty rows are ignored.
if [ -n "${E2E_DEPLOY_SET:-}" ]; then
  DEPLOY_SET=()
  while IFS= read -r _row; do
    [ -n "$_row" ] && DEPLOY_SET+=("$_row")
  done <<< "$E2E_DEPLOY_SET"
fi

# Run a command on the target, piping this function's stdin to it. Mirrors
# runSsh in tests/site-setup.ts: sshpass for password auth, overridable via
# E2E_SSH_CMD for a different transport (e.g. autoproxxy during local testing).
remote_exec() {
  local remote_cmd="$1"
  if [ -n "${E2E_SSH_CMD:-}" ]; then
    # shellcheck disable=SC2086 # E2E_SSH_CMD is a deliberate word-split command.
    ${E2E_SSH_CMD} "$remote_cmd"
    return
  fi
  : "${E2E_SSH_HOST:?set E2E_SSH_HOST or E2E_SSH_CMD}"
  : "${E2E_SSH_USER:?set E2E_SSH_USER or E2E_SSH_CMD}"
  local ssh_opts=(-o StrictHostKeyChecking=no)
  # Prefer the gate's scoped key (E2E_SSH_KEY_FILE) over password auth.
  [ -n "${E2E_SSH_KEY_FILE:-}" ] && ssh_opts=(-i "$E2E_SSH_KEY_FILE" "${ssh_opts[@]}")
  ssh_opts+=("${E2E_SSH_USER}@${E2E_SSH_HOST}" "$remote_cmd")
  if [ -n "${E2E_SSH_USER_PASS:-}" ] && [ -z "${E2E_SSH_KEY_FILE:-}" ]; then
    sshpass -p "$E2E_SSH_USER_PASS" ssh "${ssh_opts[@]}"
  else
    ssh "${ssh_opts[@]}"
  fi
}

deploy_one() {
  local name="$1" src="$2" dest="$3" marker="$4"
  local src_dir="$BUILD_ROOT/$src"

  if [ ! -d "$src_dir" ]; then
    echo "ERROR: $name source not found at $src_dir" >&2
    exit 1
  fi
  # Reject an unbuilt plugin: the asset-build marker must exist and, when it's a
  # directory, be non-empty (an empty dist/ is unbuilt, not built).
  if [ ! -e "$src_dir/$marker" ] || { [ -d "$src_dir/$marker" ] && [ -z "$(ls -A "$src_dir/$marker" 2>/dev/null)" ]; }; then
    echo "ERROR: $name looks unbuilt - missing or empty $marker under $src_dir. Build the plugins before deploying." >&2
    exit 1
  fi
  # A plugin with a composer.json loads vendor/autoload.php at runtime; shipping it
  # without vendor/ would fatal on the target. Require production deps to be present.
  if [ -f "$src_dir/composer.json" ] && [ ! -f "$src_dir/vendor/autoload.php" ]; then
    echo "ERROR: $name has composer.json but no vendor/autoload.php - run 'composer install --no-dev' before deploying." >&2
    exit 1
  fi

  echo "==> Deploying $name -> wp-content/$dest/$name"

  local src_base; src_base="$(basename "$src_dir")"

  # Tar the built dir locally (recursive sftp is far slower), excluding dev-only
  # dirs that have no business on the target, and stream it over. The swap then
  # stages and verifies the new copy BEFORE displacing the live one, backs the
  # live one up under /tmp (outside wp-content, so WordPress doesn't list the
  # backup as an extra plugin), and preserves the original managed symlink across
  # re-runs. A trap clears the staging artifacts on any exit; the stdin redirect
  # keeps this command-only ssh from inheriting the script's stdin.
  tar -C "$src_dir/.." --exclude=node_modules --exclude=.git -cf - "$src_base" | gzip \
    | remote_exec "cat > /tmp/e2e-deploy-$name.tgz"

  remote_exec "
    set -e
    trap 'rm -rf \"/tmp/e2e-deploy-$name.d\" \"/tmp/e2e-deploy-$name.tgz\"' EXIT
    D=\"\$HOME/$REMOTE_WP_PATH/wp-content/$dest\"
    N='$name'
    STAGE='/tmp/e2e-deploy-$name.d'
    rm -rf \"\$STAGE\"; mkdir -p \"\$STAGE\"
    tar -C \"\$STAGE\" -xzf '/tmp/e2e-deploy-$name.tgz'
    NEW=\"\$STAGE/$src_base\"
    [ -d \"\$NEW\" ] || { echo \"ERROR: $name: extracted dir \$NEW missing\" >&2; exit 1; }
    cd \"\$D\"
    if [ -L \"\$N\" ]; then
      [ -e '/tmp/e2e-deploy-$name.orig' ] || cp -P \"\$N\" '/tmp/e2e-deploy-$name.orig'
      rm -f \"\$N\"
    elif [ -d \"\$N\" ]; then
      rm -rf '/tmp/e2e-deploy-$name.prev'; mv \"\$N\" '/tmp/e2e-deploy-$name.prev'
    fi
    mv \"\$NEW\" \"\$D/\$N\"
  " </dev/null
}

echo "==> Deploying candidate build from $BUILD_ROOT to the target site"
for row in "${DEPLOY_SET[@]}"; do
  IFS='|' read -r name src dest marker <<< "$row"
  deploy_one "$name" "$src" "$dest" "$marker"
done
echo "==> Deploy complete"
