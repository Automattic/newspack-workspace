#!/bin/bash
# Unit tests for bin/worktree-tooling.sh:activate_worktree_tooling. Host-runnable.
# Uses a stub `pnpm` on PATH so no real install runs; asserts the helper's
# frozen-first/plain-fallback, the .husky/_ outcome check, and its graceful
# degradation (best-effort, always returns 0).
set -u
BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }
has(){ if printf '%s' "$2" | grep -qF -- "$3"; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (missing [$3] in: $2)"; fail=$((fail+1)); fi; }
hasnt(){ if printf '%s' "$2" | grep -qF -- "$3"; then echo "  FAIL  $1 (unexpected [$3])"; fail=$((fail+1)); else echo "  PASS  $1"; pass=$((pass+1)); fi; }

source "$BIN/worktree-tooling.sh"

# Build a stub `pnpm` in $1/bin that logs "<cwd> | <args>" to $PNPM_LOG and exits
# with PNPM_FROZEN_EXIT for a --frozen-lockfile call, PNPM_PLAIN_EXIT otherwise.
make_stub_dir(){
    mkdir -p "$1/bin"
    cat > "$1/bin/pnpm" <<'STUB'
#!/bin/bash
echo "$PWD | $*" >> "$PNPM_LOG"
case "$*" in
    *--frozen-lockfile*) exit "${PNPM_FROZEN_EXIT:-0}" ;;
    *) exit "${PNPM_PLAIN_EXIT:-0}" ;;
esac
STUB
    chmod +x "$1/bin/pnpm"
}

# Run the helper with PATH pointed only at $stub_bin (so system pnpm can't leak);
# builtins cover everything else the helper needs. Captures combined output in $OUT
# and return code in $RC without polluting the test shell's PATH.
run_helper(){ # $1=stub_bin (or "" for none), $2=dir, $3=no_install
    local sp="$1" dir="$2" ni="$3" oldpath="$PATH"
    PATH="${sp:-$FIX/emptybin}"
    OUT="$(activate_worktree_tooling "$dir" "$ni" 2>&1)"; RC=$?
    PATH="$oldpath"
}

mkdir -p "$FIX/emptybin"   # a PATH dir guaranteed to lack pnpm

# --- Case 1: no_install=true -> skip, do not invoke pnpm ---
S1="$FIX/c1"; make_stub_dir "$S1"; export PNPM_LOG="$S1/log"; : > "$PNPM_LOG"
D1="$FIX/wt1"; mkdir -p "$D1/.husky/_"; : > "$D1/.husky/_/pre-commit"
run_helper "$S1/bin" "$D1" true
has  "case1 prints --no-install skip"        "$OUT" "--no-install"
ok   "case1 pnpm NOT invoked (empty log)"    "$(cat "$PNPM_LOG")" ""
ok   "case1 returns 0"                        "$RC" "0"

# --- Case 2: pnpm absent on PATH -> note, return 0 ---
D2="$FIX/wt2"; mkdir -p "$D2"
run_helper "$FIX/emptybin" "$D2" false
has  "case2 prints pnpm-not-available note"  "$OUT" "pnpm not available"
ok   "case2 returns 0"                        "$RC" "0"

# --- Case 3: pnpm present but non-executable -> degrades to the same note ---
S3="$FIX/c3"; make_stub_dir "$S3"; chmod -x "$S3/bin/pnpm"
export PNPM_LOG="$S3/log"; : > "$PNPM_LOG"
D3="$FIX/wt3"; mkdir -p "$D3"
run_helper "$S3/bin" "$D3" false
has  "case3 non-exec pnpm -> not-available"  "$OUT" "pnpm not available"
ok   "case3 pnpm NOT invoked"                 "$(cat "$PNPM_LOG")" ""
ok   "case3 returns 0"                         "$RC" "0"

# --- Case 4: frozen install succeeds, hook present -> no fallback, no warning ---
S4="$FIX/c4"; make_stub_dir "$S4"; export PNPM_LOG="$S4/log"; : > "$PNPM_LOG"
export PNPM_FROZEN_EXIT=0 PNPM_PLAIN_EXIT=0
D4="$FIX/wt4"; mkdir -p "$D4/.husky/_"; : > "$D4/.husky/_/pre-commit"
run_helper "$S4/bin" "$D4" false
has  "case4 invoked with --frozen-lockfile in dir" "$(cat "$PNPM_LOG")" "$D4 | install --frozen-lockfile"
ok   "case4 exactly one pnpm invocation"      "$(wc -l < "$PNPM_LOG" | tr -d ' ')" "1"
hasnt "case4 no INACTIVE warning"             "$OUT" "INACTIVE"
ok   "case4 returns 0"                         "$RC" "0"

# --- Case 5: frozen fails -> plain fallback runs; hook present -> no warning ---
S5="$FIX/c5"; make_stub_dir "$S5"; export PNPM_LOG="$S5/log"; : > "$PNPM_LOG"
export PNPM_FROZEN_EXIT=1 PNPM_PLAIN_EXIT=0
D5="$FIX/wt5"; mkdir -p "$D5/.husky/_"; : > "$D5/.husky/_/pre-commit"
run_helper "$S5/bin" "$D5" false
has  "case5 frozen attempted"                 "$(cat "$PNPM_LOG")" "install --frozen-lockfile"
ok   "case5 two invocations (frozen+plain)"   "$(wc -l < "$PNPM_LOG" | tr -d ' ')" "2"
has  "case5 prints fallback notice"           "$OUT" "retrying with a full"
ok   "case5 returns 0"                         "$RC" "0"
unset PNPM_FROZEN_EXIT PNPM_PLAIN_EXIT

# --- Case 6: install ok but hook absent -> INACTIVE warning ---
S6="$FIX/c6"; make_stub_dir "$S6"; export PNPM_LOG="$S6/log"; : > "$PNPM_LOG"
D6="$FIX/wt6"; mkdir -p "$D6"   # no .husky/_/pre-commit
run_helper "$S6/bin" "$D6" false
has  "case6 warns hooks INACTIVE"             "$OUT" "INACTIVE"
ok   "case6 returns 0"                         "$RC" "0"

# --- Case 7: missing worktree dir -> dir-not-found warning, no pnpm ---
S7="$FIX/c7"; make_stub_dir "$S7"; export PNPM_LOG="$S7/log"; : > "$PNPM_LOG"
run_helper "$S7/bin" "$FIX/does-not-exist" false
has  "case7 warns dir not found"              "$OUT" "not found"
ok   "case7 pnpm NOT invoked"                 "$(cat "$PNPM_LOG")" ""
ok   "case7 returns 0"                         "$RC" "0"

echo ""
echo "worktree-tooling: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
