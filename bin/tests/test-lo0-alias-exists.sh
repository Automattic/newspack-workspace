#!/usr/bin/env bash
#
# test-lo0-alias-exists.sh
#
# Self-proving spec for lo0_alias_exists (_common.sh): the address is matched
# whole, never as a substring.
#
# Loopback addresses are prefixes of each other — 127.0.0.2 sits inside
# 127.0.0.24 — and the aliases are recycled, so the low ones come free again
# while high ones stay up. A substring test therefore reports 127.0.0.2 present
# the moment any 127.0.0.2X exists, `n env create` / `n env up` skip creating
# the alias, and Docker fails the env with "can't assign requested address"
# binding a port on an address nothing listens on.
#
# Run: bash bin/tests/test-lo0-alias-exists.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../_common.sh
. "$SCRIPT_DIR/../_common.sh"

WORK=$(mktemp -d -t lo0-alias-XXXXXX)
trap 'rm -rf "$WORK"' EXIT

# Stub ifconfig with a host whose low aliases have been recycled away: .11 and
# .24-.26 are up, .2-.10 are not. macOS always follows the address with a
# netmask; the trailing .30 is synthetic, pinning the case where the address
# ends its line.
cat > "$WORK/ifconfig" <<'STUB'
#!/usr/bin/env bash
cat <<'OUT'
lo0: flags=8049<UP,LOOPBACK,RUNNING,MULTICAST> mtu 16384
	options=1203<RXCSUM,TXCSUM,TXSTATUS,SW_TIMESTAMP>
	inet 127.0.0.1 netmask 0xff000000
	inet6 ::1 prefixlen 128
	inet6 fe80::1%lo0 prefixlen 64 scopeid 0x1
	inet 127.0.0.11 netmask 0xff000000
	inet 127.0.0.24 netmask 0xff000000
	inet 127.0.0.25 netmask 0xff000000
	inet 127.0.0.26 netmask 0xff000000
	inet 127.0.0.30
OUT
STUB
chmod +x "$WORK/ifconfig"
PATH="$WORK:$PATH"

failures=0
assert_alias() {
	local ip="$1" want="$2" desc="$3" got
	if lo0_alias_exists "$ip"; then got=present; else got=absent; fi
	if [[ "$got" == "$want" ]]; then
		echo "  ok: $desc"
	else
		echo "  FAIL: $desc — want $want, got $got"
		failures=$((failures + 1))
	fi
}

echo "lo0_alias_exists:"
assert_alias 127.0.0.24 present "an alias that is up reads present"
assert_alias 127.0.0.5 absent "an alias that was never created reads absent"
assert_alias 127.0.0.2 absent "a recycled alias reads absent though 127.0.0.24 is up"
assert_alias 127.0.0.3 absent "a recycled alias reads absent though 127.0.0.30 is up"
assert_alias 127.0.0.1 present "a prefix that is itself up still reads present"
assert_alias 127.0.0.30 present "an address ending its line reads present"

# The fail-safe direction. An unreadable lo0 must read absent, so the caller
# creates the alias; a wrong "present" is the shape of the bug above — skip the
# creation, then fail to bind a port on an address the host does not have.
mkdir -p "$WORK/broken"
cat > "$WORK/broken/ifconfig" <<'STUB'
#!/usr/bin/env bash
echo "ifconfig: interface lo0 does not exist" >&2
exit 1
STUB
chmod +x "$WORK/broken/ifconfig"
if ( PATH="$WORK/broken:$PATH"; lo0_alias_exists 127.0.0.24 ); then
	echo "  FAIL: an unreadable lo0 must read absent — got present"
	failures=$((failures + 1))
else
	echo "  ok: an unreadable lo0 reads absent, so the caller creates the alias"
fi

if [[ "$failures" -gt 0 ]]; then
	echo "$failures assertion(s) failed"
	exit 1
fi
echo "All assertions passed."
