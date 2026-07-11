#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")"; . ./helpers.sh
R=../bin/redact.sh
D="$(mktemp -d)"

cat > "$D/dirty.txt" <<'EOF'
See https://mc.a8c.com/secret-store/?secret_id=7798 for the password.
Reporter: nykera@richlandsource.com
api_key = "abcdef1234567890"
EOF
cat > "$D/clean.txt" <<'EOF'
Admin user is admin@example.com on https://myenv.test/
EOF

out="$(bash "$R" scan "$D/dirty.txt" 2>&1)" && rc=0 || rc=$?
assert_eq 1 "$rc" "dirty file flagged"
assert_contains "$out" secret-store "secret-store URL caught"
assert_contains "$out" email "customer email caught"
assert_contains "$out" credential-assign "credential assignment caught"

bash "$R" scan "$D/clean.txt"
assert_eq 0 $? "example.com + .test are exempt"

printf 'nykera@richlandsource.com\n' > "$D/allow.txt"
export AUTOFIX_REDACT_ALLOWLIST="$D/allow.txt"
out="$(bash "$R" scan "$D/dirty.txt" 2>&1)" && rc=0 || rc=$?
unset AUTOFIX_REDACT_ALLOWLIST
assert_eq 1 "$rc" "other findings still block"
case "$out" in *nykera*) printf 'FAIL  allowlisted email still reported\n'; FAILURES=$((FAILURES+1));;
*) printf 'ok    allowlisted email suppressed\n';; esac
finish
