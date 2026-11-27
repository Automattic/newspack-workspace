# shellcheck shell=bash
# Release-gate build helper — SOURCE this (don't execute) before the deploy +
# provision + test step, passing the PR's target branch:
#
#   source e2e/gate-env.sh "$TARGET_BRANCH"   # alpha | release
#
# It maps the branch to its dedicated staging site and materialises the shared
# SSH key, so the rest of the step (deploy-plugins.sh, then the suite) runs
# against the right site with the right scoped credential. Everything else — the
# SSH host/known-host, WP admin creds, Stripe keys — is inherited from the
# Newspack TeamCity project, so nothing secret lives here.
#
# Inputs (TeamCity params / secret store):
#   $1            Target branch: alpha or release.
#   E2E_SSH_KEY   The shared private key (key-only users on both gate sites).
# Exports: E2E_SITE_URL, E2E_SSH_USER, and (when E2E_SSH_KEY is set) E2E_SSH_KEY_FILE.

_gate_branch="${1:-}"
_gate_branch="${_gate_branch#refs/heads/}"
case "$_gate_branch" in
  alpha)
    export E2E_SITE_URL="https://e2e-alpha.newspackstaging.com"
    export E2E_SSH_USER="newspack-e2e-gate-alpha"
    ;;
  release)
    export E2E_SITE_URL="https://e2e-release.newspackstaging.com"
    export E2E_SSH_USER="newspack-e2e-gate-release"
    ;;
  *)
    echo "gate-env.sh: unexpected target branch '$_gate_branch' (expected alpha|release)" >&2
    return 1 2>/dev/null || exit 1
    ;;
esac

# Write the shared private key to a 0600 temp file the suite's SSH points at via
# -i (E2E_SSH_KEY_FILE). The gate's users are key-only, so there is no password.
if [ -n "${E2E_SSH_KEY:-}" ]; then
  E2E_SSH_KEY_FILE="$(mktemp "${TMPDIR:-/tmp}/e2e-gate-key.XXXXXX")"
  printf '%s\n' "$E2E_SSH_KEY" > "$E2E_SSH_KEY_FILE"
  chmod 600 "$E2E_SSH_KEY_FILE"
  export E2E_SSH_KEY_FILE
fi

echo "gate-env: branch=$_gate_branch site=$E2E_SITE_URL user=$E2E_SSH_USER key=${E2E_SSH_KEY_FILE:+set}"
unset _gate_branch
