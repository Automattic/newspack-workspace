#!/bin/bash
# Asserts the isolated-db sidecar naming contract that a combined
# --isolated-db + --worktree env must satisfy (host-runnable, no Docker).
set -u
BIN="$(cd "$(dirname "$0")/../bin" && pwd)"; pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }
source "$BIN/_common.sh"
ok "env_safe_name folds dashes" "$(env_safe_name combo-env)" "combo_env"
ok "env_safe_name folds dots"   "$(env_safe_name foo.bar)"   "foo_bar"
# sidecar_service_for_env recovers the service name from a generated compose file:
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
printf 'services:\n  db_lowercase_combo_env:\n    image: mariadb\n  env-combo-env:\n' > "$FIX/dc.yml"
ok "sidecar detected from compose" "$(sidecar_service_for_env "$FIX/dc.yml")" "db_lowercase_combo_env"
echo ""; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
