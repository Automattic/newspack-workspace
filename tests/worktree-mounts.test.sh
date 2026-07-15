#!/bin/bash
# Unit tests for env.sh worktree-mount parsing (upstream's parse_worktree_mount /
# each_worktree_in_env). Host-runnable; sources env.sh (sourceable-guarded).
set -u
BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }
source "$BIN/env.sh"

ok "monorepo plugin mount"  "$(parse_worktree_mount '      - ./worktrees/feat-x/plugins/np-news:/newspack-plugins/np-news')" "np-news|feat-x|monorepo"
ok "monorepo theme mount"   "$(parse_worktree_mount '      - ./worktrees/feat-y/themes/np-theme:/newspack-themes/np-theme')" "np-theme|feat-y|monorepo"
ok "standalone repos mount" "$(parse_worktree_mount '      - ./worktrees-repos/np-manager/feat-z:/newspack-repos/plugins/np-manager')" "np-manager|feat-z|repos"
if parse_worktree_mount '      - ./html:/var/www/html' >/dev/null 2>&1; then r=matched; else r=nomatch; fi
ok "non-worktree mount -> non-zero" "$r" "nomatch"

# each_worktree_in_env over a compose fixture with one of each shape:
C="$FIX/c.yml"; cat > "$C" <<'EOF'
    volumes:
      - ./worktrees/feat-x/plugins/np-news:/newspack-plugins/np-news
      - ./worktrees-repos/np-manager/feat-z:/newspack-repos/plugins/np-manager
      - ./html:/var/www/html
EOF
ok "each_worktree_in_env yields 2 triples" "$(each_worktree_in_env "$C" | grep -c '|')" "2"

echo ""; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
