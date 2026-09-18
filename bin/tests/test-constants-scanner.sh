#!/usr/bin/env bash
#
# test-constants-scanner.sh
#
# Self-proving spec for bin/constants-scanner.php and the scanner class it
# wraps, bin/class-newspack-constants-scanner.php.
#
# The scanner and its fixtures moved here from newspack-manager-admin, where
# they were covered by a PHPUnit suite (WordPress test scaffolding this
# monorepo's root-level scripts have no equivalent of). This spec ports every
# assertion from that suite to the CLI, running it exactly as CI and the
# reusable workflow do: `php bin/constants-scanner.php --source=... --format=json`
# over the fixtures at bin/tests/fixtures/constants/, read back with jq.
#
# Run: bash bin/tests/test-constants-scanner.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCANNER="$SCRIPT_DIR/../constants-scanner.php"
FIXTURES="$SCRIPT_DIR/fixtures/constants"

failures=0

assert_eq() {
	local desc="$1" want="$2" got="$3"
	if [[ "$got" == "$want" ]]; then
		echo "  ok: $desc"
	else
		echo "  FAIL: $desc — want [$want], got [$got]"
		failures=$((failures + 1))
	fi
}

assert_match() {
	local desc="$1" pattern="$2" got="$3"
	if [[ "$got" =~ $pattern ]]; then
		echo "  ok: $desc"
	else
		echo "  FAIL: $desc — [$got] did not match /$pattern/"
		failures=$((failures + 1))
	fi
}

# alpha + beta: the pair every location- and docblock-shaped assertion below
# scans. alpha/includes/documented.php carries the one full docblock;
# beta/includes/shared.php guards the same constant with no docblock at all;
# alpha also has a mismatched-docblock guard, an undocumented one, and a
# vendor/ guard that must never be reached.
json_ab=$(php "$SCANNER" --source=alpha="$FIXTURES/alpha" --branch=alpha=release --sha=alpha=aaa111 \
	--source=beta="$FIXTURES/beta" --branch=beta=trunk --sha=beta=bbb222 --format=json)

echo "scan(): only the documented constant is catalogued:"
names=$(jq -r '.constants[].name' <<<"$json_ab")
assert_eq "exactly one documented constant" "NEWSPACK_FIXTURE_ALLOW_SYNC" "$names"
assert_eq "the vendor/ guard is never scanned" "" "$(jq -r '.constants[] | select(.name == "NEWSPACK_FIXTURE_VENDORED")' <<<"$json_ab")"

echo
echo "docblock fields are parsed, including a multi-line description:"
constant=$(jq '.constants[0]' <<<"$json_ab")
assert_eq "type" "bool" "$(jq -r '.type' <<<"$constant")"
assert_eq "default" "false" "$(jq -r '.default' <<<"$constant")"
assert_eq "status" "draft" "$(jq -r '.status' <<<"$constant")"
assert_eq "description spans both paragraphs" $'Allow reader sync.\n\nSecond paragraph of the description.' "$(jq -r '.description' <<<"$constant")"
assert_eq "example" "define( 'NEWSPACK_FIXTURE_ALLOW_SYNC', true );" "$(jq -r '.example' <<<"$constant")"

echo
echo "locations merge across sources, has_docblock is per location:"
assert_eq "two locations" "2" "$(jq '.locations | length' <<<"$constant")"
alpha_loc=$(jq '.locations[] | select(.source == "alpha")' <<<"$constant")
beta_loc=$(jq '.locations[] | select(.source == "beta")' <<<"$constant")
assert_eq "alpha's location" "includes/documented.php" "$(jq -r '.file' <<<"$alpha_loc")"
assert_eq "alpha's location has_docblock" "true" "$(jq -r '.has_docblock' <<<"$alpha_loc")"
assert_eq "beta's location" "includes/shared.php" "$(jq -r '.file' <<<"$beta_loc")"
assert_eq "beta's location has_docblock is false (no docblock there)" "false" "$(jq -r '.has_docblock' <<<"$beta_loc")"

echo
echo "undocumented constants are reported, not catalogued:"
undocumented_md=$(php "$SCANNER" --source=alpha="$FIXTURES/alpha" --source=beta="$FIXTURES/beta" --undocumented)
if [[ "$undocumented_md" == *"NEWSPACK_FIXTURE_MISMATCH"* && "$undocumented_md" == *"NEWSPACK_FIXTURE_TOKEN"* ]]; then
	echo "  ok: a mismatched @constant and a guard with no docblock both list as undocumented"
else
	echo "  FAIL: undocumented markdown missing an expected entry: $undocumented_md"
	failures=$((failures + 1))
fi
if [[ "$undocumented_md" == *"NEWSPACK_FIXTURE_ALLOW_SYNC"* ]]; then
	echo "  FAIL: the documented constant leaked into the undocumented list"
	failures=$((failures + 1))
else
	echo "  ok: the documented constant is not listed as undocumented"
fi

echo
echo "a comment line between the docblock and the guard still counts as documented:"
json_gamma=$(php "$SCANNER" --source=gamma="$FIXTURES/gamma" --format=json)
gap=$(jq '.constants[] | select(.name == "NEWSPACK_FIXTURE_COMMENT_GAP")' <<<"$json_gamma")
assert_eq "NEWSPACK_FIXTURE_COMMENT_GAP is documented despite the // comment before its guard" "true" "$(jq -r '.locations[0].has_docblock' <<<"$gap")"

echo
echo "a guard quoted inside a docblock's prose does not create a location:"
json_abg=$(php "$SCANNER" --source=alpha="$FIXTURES/alpha" --source=beta="$FIXTURES/beta" --source=gamma="$FIXTURES/gamma" --format=json)
sync_locations=$(jq '.constants[] | select(.name == "NEWSPACK_FIXTURE_ALLOW_SYNC") | .locations | length' <<<"$json_abg")
# gamma/includes/comment-mentions-guard.php's docblock quotes
# `defined( 'NEWSPACK_FIXTURE_ALLOW_SYNC' )` in its description. Comments are
# stripped with the tokenizer before the guard regex runs, so that text must
# not add a third location beyond alpha's and beta's real guards.
assert_eq "NEWSPACK_FIXTURE_ALLOW_SYNC still has only its two real locations" "2" "$sync_locations"

echo
echo "some_predefined( 'NEWSPACK_X' ) is not matched as a defined() guard:"
assert_eq "absent from the catalog" "" "$(jq -r '.constants[] | select(.name == "NEWSPACK_FIXTURE_NOT_A_GUARD")' <<<"$json_gamma")"
undocumented_gamma_md=$(php "$SCANNER" --source=gamma="$FIXTURES/gamma" --undocumented)
if [[ "$undocumented_gamma_md" == *"NEWSPACK_FIXTURE_NOT_A_GUARD"* ]]; then
	echo "  FAIL: NEWSPACK_FIXTURE_NOT_A_GUARD was reported as undocumented; a word-boundary regression turned some_predefined() into a guard"
	failures=$((failures + 1))
else
	echo "  ok: absent from the undocumented list too — no word boundary before 'defined', no match at all"
fi

echo
echo "a documented @default 0 is kept, not treated as empty:"
json_delta=$(php "$SCANNER" --source=delta="$FIXTURES/delta" --format=json)
default_zero=$(jq '.constants[] | select(.name == "NEWSPACK_FIXTURE_DEFAULT_ZERO")' <<<"$json_delta")
assert_eq "default is the string \"0\", not dropped" "0" "$(jq -r '.default' <<<"$default_zero")"
assert_eq "type still parses alongside a zero default" "int" "$(jq -r '.type' <<<"$default_zero")"

echo
echo "a defined() guard quoted inside a string literal is not a real guard:"
json_gamma_strings=$(php "$SCANNER" --source=gamma="$FIXTURES/gamma" --format=json)
assert_eq "absent from the catalog" "" "$(jq -r '.constants[] | select(.name == "NEWSPACK_FIXTURE_INSIDE_STRING")' <<<"$json_gamma_strings")"
undocumented_gamma_strings_md=$(php "$SCANNER" --source=gamma="$FIXTURES/gamma" --undocumented)
if [[ "$undocumented_gamma_strings_md" == *"NEWSPACK_FIXTURE_INSIDE_STRING"* ]]; then
	echo "  FAIL: NEWSPACK_FIXTURE_INSIDE_STRING was reported as undocumented; a string literal was mistaken for a real guard"
	failures=$((failures + 1))
else
	echo "  ok: absent from the undocumented list too — no constant invented from a quoted string"
fi

echo
echo "a defined() guard quoted inside a heredoc body is not a real guard:"
assert_eq "absent from the catalog" "" "$(jq -r '.constants[] | select(.name == "NEWSPACK_FIXTURE_INSIDE_HEREDOC")' <<<"$json_gamma_strings")"
if [[ "$undocumented_gamma_strings_md" == *"NEWSPACK_FIXTURE_INSIDE_HEREDOC"* ]]; then
	echo "  FAIL: NEWSPACK_FIXTURE_INSIDE_HEREDOC was reported as undocumented; a heredoc body was mistaken for a real guard"
	failures=$((failures + 1))
else
	echo "  ok: absent from the undocumented list too — no constant invented from a heredoc body"
fi

echo
echo "real guards are still matched after the string/heredoc fix:"
assert_eq "NEWSPACK_FIXTURE_COMMENT_GAP still found" "true" "$(jq -r 'any(.constants[]; .name == "NEWSPACK_FIXTURE_COMMENT_GAP")' <<<"$json_gamma_strings")"
assert_eq "NEWSPACK_FIXTURE_QUOTES_GUARD still found" "true" "$(jq -r 'any(.constants[]; .name == "NEWSPACK_FIXTURE_QUOTES_GUARD")' <<<"$json_gamma_strings")"

echo
echo "the envelope carries schema_version, generated_at, sources and constants:"
assert_eq "schema_version" "1" "$(jq -r '.schema_version' <<<"$json_ab")"
assert_match "generated_at is an ISO-8601 UTC timestamp" '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$' "$(jq -r '.generated_at' <<<"$json_ab")"
assert_eq "sources records name, branch and sha for each source" \
	'[{"name":"alpha","branch":"release","sha":"aaa111"},{"name":"beta","branch":"trunk","sha":"bbb222"}]' \
	"$(jq -c '.sources' <<<"$json_ab")"
assert_eq "constants is present and is the documented catalog" "true" "$(jq 'has("constants")' <<<"$json_ab")"

if [[ "$failures" -gt 0 ]]; then
	echo
	echo "$failures assertion(s) failed"
	exit 1
fi
echo
echo "All assertions passed."
