#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")"; . ./helpers.sh
MOCK="$(mktemp -d)"; cp fixtures/viewer.json "$MOCK/"
export AUTOFIX_LINEAR_MOCK_DIR="$MOCK"
. ../bin/lib/common.sh
. ../bin/lib/linear.sh

out="$(linear_gql viewer 'query{viewer{id}}')"
assert_contains "$out" 56b3262a "mock mode returns fixture"
assert_contains "$(cat "$MOCK/requests.log")" viewer "request logged"
assert_eq 56b3262a-bf16-4c9f-8c0f-8580fc5f6fea "$(linear_viewer_id)" "viewer id helper"

# missing fixture → non-zero, so callers see failures
linear_gql nope 'query{}' >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "missing mock fixture fails"
finish
