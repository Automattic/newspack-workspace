#!/bin/bash
# Unit tests for bin/repos.sh:get_repo_host_path. Host-runnable.
# Covers the monorepo static tiers plus tier-2 standalone autodiscovery under
# repos/{plugins,themes}/<name> (added to complete upstream's registry-free
# auto-discovery direction; consumed by `n env create --worktree`).
set -u
BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }

source "$BIN/repos.sh"

# Fixture workspace root: a standalone plugin (plain clone -> .git dir), a
# standalone theme, and a name that collides with a monorepo plugin.
mkdir -p "$FIX/repos/plugins/acme-standalone/.git"
mkdir -p "$FIX/repos/themes/acme-theme/.git"
mkdir -p "$FIX/repos/plugins/newspack-plugin/.git"   # duplicate of a tracked plugin
# A linked-worktree checkout stores .git as a FILE, not a dir:
mkdir -p "$FIX/repos/plugins/acme-worktree"
echo "gitdir: /somewhere/.git/worktrees/x" > "$FIX/repos/plugins/acme-worktree/.git"
export NABSPATH="$FIX"

ok "monorepo plugin -> plugins/<name>"      "$(get_repo_host_path newspack-plugin)"    "plugins/newspack-plugin"
ok "monorepo theme -> themes/<name>"        "$(get_repo_host_path newspack-theme)"     "themes/newspack-theme"
ok "standalone plugin autodiscovered"       "$(get_repo_host_path acme-standalone)"    "repos/plugins/acme-standalone"
ok "standalone theme autodiscovered"        "$(get_repo_host_path acme-theme)"         "repos/themes/acme-theme"
ok "linked-worktree (.git file) discovered" "$(get_repo_host_path acme-worktree)"      "repos/plugins/acme-worktree"
ok "tracked copy wins over repos/ dup"      "$(get_repo_host_path newspack-plugin)"    "plugins/newspack-plugin"
ok "unknown project -> empty"               "$(get_repo_host_path nope-not-here)"      ""

echo ""; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
