#!/usr/bin/env bash
#
# test-newspack-manage-host-path.sh
#
# Spec for the pinned PATH in bin/newspack-manage-host.
#
# That wrapper is granted passwordless root by a sudoers drop-in, and macOS
# sudoers sets no secure_path, so sudo passes the caller's PATH straight
# through. A helper binary placed earlier in that PATH would therefore run as
# root. The wrapper pins PATH to root-owned system directories to prevent it;
# this spec proves the pin is not overridable by the caller.
#
# Nothing here needs privileges and nothing mutates host state. The probe calls
# alias-add with 127.0.0.999, which the wrapper's own validation accepts
# (^127\.0\.0\.[0-9]+$) and ifconfig rejects as a bad value, so the helper call
# is reached but never applied. That holds under root too, which matters
# because CI containers commonly run as one.
#
# Two properties are read from the wrapper rather than restated here, because a
# copy in this file would go stale in the direction that matters: widening the
# pin, or adding a helper it does not cover, would still be checked against
# yesterday's values and pass.
#
# Run: bash bin/tests/test-newspack-manage-host-path.sh

set -u
BIN="$(cd "$(dirname "$0")/.." && pwd)"
WRAP="$BIN/newspack-manage-host"
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }

[ -x "$WRAP" ] || { echo "  FAIL  wrapper not found or not executable at $WRAP"; exit 1; }

# --- the pinned value, read from the wrapper --------------------------------
PIN="$(grep -m1 '^export PATH=' "$WRAP" | sed 's/^export PATH=//; s/[[:space:]]*$//')"
ok "wrapper declares a pinned PATH" "$([ -n "$PIN" ] && echo yes || echo no)" "yes"

# --- every pinned directory must be writable only by root -------------------
#
# This is the property the pin actually rests on, and the one a later edit is
# most likely to break: adding a directory to make a new helper resolve is an
# ordinary-looking change, and if that directory is user-writable the pin stops
# meaning anything while every other check here still passes. Asserting the
# property rather than the string is what makes widening visible.
#
# Symlinks are followed deliberately. Under usrmerge, /bin and /sbin are
# symlinks into /usr and carry mode 777 like every symlink, so reading the link
# rather than its target reports them world-writable and fails on Linux while
# passing on macOS, where both are real directories. What governs lookup is the
# target, and a link sitting in root-owned / cannot be replaced by a user.
dir_root_only() {
    local d="$1" owner mode g o
    [ -d "$d" ] || return 0   # absent dir contributes nothing to lookup
    if stat --version >/dev/null 2>&1; then
        owner=$(stat -L -c '%U' "$d"); mode=$(stat -L -c '%a' "$d")   # GNU
    else
        owner=$(stat -L -f '%Su' "$d"); mode=$(stat -L -f '%Lp' "$d") # BSD
    fi
    [ "$owner" = "root" ] || return 1
    mode=$(printf '%03d' "$mode" 2>/dev/null || printf '%s' "$mode")
    g=$(printf '%s' "$mode" | tail -c 2 | head -c 1)
    o=$(printf '%s' "$mode" | tail -c 1)
    case "$g" in [2367]) return 1 ;; esac
    case "$o" in [2367]) return 1 ;; esac
    return 0
}

writable=""
IFS=: read -r -a PIN_DIRS <<< "$PIN"
for d in "${PIN_DIRS[@]}"; do
    dir_root_only "$d" || writable="$writable $d"
done
ok "every pinned directory is owned by root and not group- or world-writable" "$writable" ""

# --- the commands it invokes, derived from the wrapper ----------------------
#
# Deriving beats enumerating: a hardcoded list of command names can only catch
# names someone already thought of, so the check that exists to catch a future
# edit is exactly the check that cannot. Tokens are taken from command position,
# minus shell keywords and the wrapper's own functions, then filtered to those
# that resolve as commands somewhere on this system — which drops case labels
# and variable names without needing to know what they are.
derive_commands() {
    local f="$1" funcs builtins tok
    funcs=$(grep -oE '^[a-zA-Z_][a-zA-Z0-9_]*\(\)' "$f" | tr -d '()' | sort -u)
    builtins='^(if|then|else|elif|fi|for|while|until|do|done|case|esac|function|return|local|echo|exit|set|export|shift|read|printf|test|eval|source|cd|true|false|break|continue|unset|trap)$'
    sed 's/#.*//' "$f" \
      | grep -oE '(^|[;&|(]|&&|\|\||!|\b(if|then|else|do|while|until)\b)[[:space:]]*!?[[:space:]]*[a-zA-Z_][a-zA-Z0-9_.-]*' \
      | grep -oE '[a-zA-Z_][a-zA-Z0-9_.-]*$' \
      | grep -vE "$builtins" \
      | { if [ -n "$funcs" ]; then grep -vxF "$funcs"; else cat; fi; } \
      | sort -u \
      | while read -r tok; do command -v "$tok" >/dev/null 2>&1 && echo "$tok"; done
}

# Control for the derivation itself. If it silently stopped matching, it would
# return nothing and every check below would pass while testing nothing.
#
# The probe command is created here rather than borrowed from the system: the
# derivation drops tokens that resolve nowhere, so naming a real command would
# make this control depend on that command being installed. An earlier version
# used openssl and reported "not detected" on a bare Ubuntu image, which is the
# same silent-pass failure this control exists to prevent.
mkdir -p "$FIX/ctl"
printf '#!/bin/sh\nexit 0\n' > "$FIX/ctl/derivectlprobe"; chmod +x "$FIX/ctl/derivectlprobe"
probe_src="$FIX/derive-probe"; cp "$WRAP" "$probe_src"
printf '\nderivectlprobe --version\n' >> "$probe_src"
ok "derivation detects a command added to the wrapper" \
   "$(PATH="$FIX/ctl:$PATH" derive_commands "$probe_src" | grep -cx derivectlprobe)" "1"

CMDS="$(derive_commands "$WRAP")"
ok "derivation finds the wrapper's helpers" \
   "$([ "$(printf '%s\n' "$CMDS" | grep -c .)" -ge 2 ] && echo yes || echo no)" "yes"

uncovered=""
for c in $CMDS; do
    PATH="$PIN" command -v "$c" >/dev/null 2>&1 || uncovered="$uncovered $c"
done
ok "every command the wrapper invokes resolves under its own pinned PATH" "$uncovered" ""

# --- the hijack probe, with a control that must hijack ----------------------
mkdir -p "$FIX/evil"
printf '#!/bin/bash\ntouch "%s/HIJACKED"\nexit 0\n' "$FIX" > "$FIX/evil/ifconfig"
chmod +x "$FIX/evil/ifconfig"

# $1 = wrapper to run. Echoes hijacked|clean.
hijack_probe() {
    rm -f "$FIX/HIJACKED"
    PATH="$FIX/evil:$PATH" "$1" alias-add 127.0.0.999 >/dev/null 2>&1 || true
    [ -e "$FIX/HIJACKED" ] && echo hijacked || echo clean
}

ok "planted ifconfig is not reached" "$(hijack_probe "$WRAP")" "clean"

# Without this control, "clean" cannot tell a working pin from a code path that
# was never reached: any early return added ahead of the ifconfig call would
# also leave the marker absent, and the probe above would still report a pass.
# Running the identical probe against a copy with the pin stripped proves the
# probe can still detect a hijack when one is present.
unpinned="$FIX/unpinned"
grep -v '^export PATH=' "$WRAP" > "$unpinned"; chmod +x "$unpinned"
ok "control: the same probe hijacks once the pin is removed" \
   "$(hijack_probe "$unpinned")" "hijacked"

echo ""; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
