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
#   E2E_SSH_CMD         Optional. Full ssh invocation to use instead of sshpass;
#                       receives the remote command as its last argument.
#   E2E_REMOTE_WP_PATH  WordPress root on the target, relative to the SSH home.
#                       Default: htdocs.
#   E2E_BUILD_ROOT      Monorepo root holding plugins/ and themes/. Default: the
#                       directory above this script.

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
  local ssh_opts=(-o StrictHostKeyChecking=no "${E2E_SSH_USER}@${E2E_SSH_HOST}" "$remote_cmd")
  if [ -n "${E2E_SSH_USER_PASS:-}" ]; then
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
  if [ ! -e "$src_dir/$marker" ]; then
    echo "ERROR: $name looks unbuilt - missing $marker under $src_dir. Build the plugins before deploying." >&2
    exit 1
  fi

  echo "==> Deploying $name -> wp-content/$dest/$name"

  # Tar the built directory locally (recursive sftp is far slower), stream it to
  # the target, then swap it in. The swap detects whether the current entry is a
  # managed symlink or a real directory and backs it up accordingly, so a later
  # run (or a manual revert) can restore the original.
  tar -C "$src_dir/.." -cf - "$(basename "$src_dir")" | gzip \
    | remote_exec "cat > /tmp/e2e-deploy-$name.tgz"

  remote_exec "
    set -e
    WP=\"\$HOME/$REMOTE_WP_PATH\"
    D=\"\$WP/wp-content/$dest\"
    N='$name'
    rm -rf \"/tmp/e2e-deploy-\$N.d\"
    mkdir -p \"/tmp/e2e-deploy-\$N.d\"
    tar -C \"/tmp/e2e-deploy-\$N.d\" -xzf \"/tmp/e2e-deploy-\$N.tgz\"
    cd \"\$D\"
    if [ -L \"\$N\" ]; then
      rm -f \"\$N--symlink\"; mv \"\$N\" \"\$N--symlink\"
    elif [ -d \"\$N\" ]; then
      rm -rf \"\$N--previous\"; mv \"\$N\" \"\$N--previous\"
    fi
    mv \"/tmp/e2e-deploy-\$N.d/\$N\" \"\$D/\$N\"
    rm -rf \"/tmp/e2e-deploy-\$N.d\" \"/tmp/e2e-deploy-\$N.tgz\"
  "
}

echo "==> Deploying candidate build from $BUILD_ROOT to the target site"
for row in "${DEPLOY_SET[@]}"; do
  IFS='|' read -r name src dest marker <<< "$row"
  deploy_one "$name" "$src" "$dest" "$marker"
done
echo "==> Deploy complete"
