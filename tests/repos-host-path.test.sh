#!/bin/bash
# Unit tests for bin/repos.sh host-path resolution. Host-runnable.
# Covers upstream's two-function split: get_repo_host_path (monorepo only) +
# get_standalone_repo_host_path (repos/{plugins,themes}) + is_standalone_git_repo,
# and the caller-order precedence (monorepo copy wins over a repos/ duplicate).
set -u
BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }

source "$BIN/repos.sh"

# A real caller resolves a name by trying monorepo first, then standalone:
resolve(){ local p; p=$(get_repo_host_path "$1"); [[ -n "$p" ]] && { echo "$p"; return; }; get_standalone_repo_host_path "$1"; }

# Fixtures: a standalone plugin + theme (real git repos so is_standalone_git_repo
# works), a name colliding with a monorepo plugin, and an unzipped (non-git) dir.
export NABSPATH="$FIX"
git init -q "$FIX/repos/plugins/acme-standalone"
git init -q "$FIX/repos/themes/acme-theme"
mkdir -p "$FIX/repos/plugins/newspack-network"; git init -q "$FIX/repos/plugins/newspack-network"  # dual-existing name
mkdir -p "$FIX/repos/plugins/unzipped-plugin"   # NOT its own git repo

# get_repo_host_path: monorepo only
ok "monorepo plugin -> plugins/<name>"  "$(get_repo_host_path newspack-plugin)"  "plugins/newspack-plugin"
ok "monorepo theme -> themes/<name>"    "$(get_repo_host_path newspack-theme)"   "themes/newspack-theme"
ok "get_repo_host_path ignores standalone" "$(get_repo_host_path acme-standalone)" ""

# get_standalone_repo_host_path
ok "standalone plugin"  "$(get_standalone_repo_host_path acme-standalone)"  "repos/plugins/acme-standalone"
ok "standalone theme"   "$(get_standalone_repo_host_path acme-theme)"       "repos/themes/acme-theme"
ok "standalone unknown -> empty" "$(get_standalone_repo_host_path nope)"    ""

# Precedence (finding #5): newspack-network exists in the monorepo AND as a
# standalone checkout -> the monorepo copy wins via caller order.
ok "dual-existing name: monorepo wins" "$(resolve newspack-network)" "plugins/newspack-network"

# is_standalone_git_repo: true for a real repo, false for an unzipped dir
if is_standalone_git_repo "repos/plugins/acme-standalone"; then r=yes; else r=no; fi
ok "real git repo IS standalone" "$r" "yes"
if is_standalone_git_repo "repos/plugins/unzipped-plugin"; then r=yes; else r=no; fi
ok "unzipped dir is NOT standalone" "$r" "no"

echo ""; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
