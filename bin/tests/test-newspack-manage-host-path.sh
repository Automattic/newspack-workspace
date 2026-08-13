#!/usr/bin/env bash
#
# test-newspack-manage-host-path.sh
#
# Spec for the pinned PATH in bin/newspack-manage-host.
#
# That wrapper is granted passwordless root by a sudoers drop-in, and macOS
# sudoers sets no secure_path, so sudo passes the caller's PATH straight
# through. A helper binary planted earlier in that PATH would therefore run as
# root. The wrapper pins PATH to root-owned system directories to prevent it;
# this spec proves the pin is not overridable by the caller.
#
# No sudo is involved. PATH resolution works the same way whoever runs the
# script, so the mechanism can be proved without privileges — which also means
# this is safe to run in CI.
#
# Run: bash bin/tests/test-newspack-manage-host-path.sh

set -u
BIN="$(cd "$(dirname "$0")/.." && pwd)"
WRAP="$BIN/newspack-manage-host"
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }

[ -x "$WRAP" ] || { echo "  FAIL  wrapper not found or not executable at $WRAP"; exit 1; }

# Plant an `ifconfig` that records having run. alias-add is the path that calls
# it, and it is reached before any privileged operation, so an unpinned wrapper
# executes the plant even when the caller is unprivileged.
mkdir -p "$FIX/evil"
printf '#!/bin/bash\ntouch "%s/HIJACKED"\nexit 0\n' "$FIX" > "$FIX/evil/ifconfig"
chmod +x "$FIX/evil/ifconfig"

rm -f "$FIX/HIJACKED"
PATH="$FIX/evil:$PATH" bash "$WRAP" alias-add 127.0.0.99 >/dev/null 2>&1 || true

# Assert on the marker rather than on exit status: a planted binary that runs
# and then exits 0 leaves the status clean, so a status check would pass while
# the hijack succeeded.
ok "planted ifconfig is not reached" \
   "$([ -e "$FIX/HIJACKED" ] && echo hijacked || echo clean)" "clean"

# The pin must not simply break command resolution. If the wrapper could no
# longer find its helpers it would also report "clean" above, so confirm the
# real binaries are still reachable under the pinned PATH.
missing=""
for c in ifconfig grep sed; do
    PATH=/usr/sbin:/usr/bin:/sbin:/bin command -v "$c" >/dev/null 2>&1 || missing="$missing $c"
done
ok "pinned PATH still resolves every helper the wrapper uses" "$missing" ""

# Guard against a future edit adding a command the pin does not cover, which
# would fail at runtime under `set -e` rather than here.
uncovered=""
while read -r c; do
    [ -n "$c" ] || continue
    PATH=/usr/sbin:/usr/bin:/sbin:/bin command -v "$c" >/dev/null 2>&1 || uncovered="$uncovered $c"
done <<EOF
$(grep -oE '\b(ifconfig|grep|sed|awk|perl|python3|curl|networksetup|dscacheutil|killall)\b' "$WRAP" | sort -u)
EOF
ok "no external command used by the wrapper falls outside the pin" "$uncovered" ""

echo ""; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
