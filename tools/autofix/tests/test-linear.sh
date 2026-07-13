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


# .env fallback: key read from workspace .env when env var unset (live path, stubbed curl)
FAKE_WS="$(mktemp -d)"
printf 'SOME_DOCKER_VAR=has spaces unquoted\nLINEAR_API_KEY=lin_api_from_dotenv\n' > "$FAKE_WS/.env"
STUB="$(mktemp -d)"
cat > "$STUB/curl" <<'CURL'
#!/bin/bash
for a in "$@"; do case "$prev" in -H) echo "$a" >> "${CURL_HDRS:?}";; esac; prev="$a"; done
printf '{"data":{"ok":true}}\n200'
CURL
chmod +x "$STUB/curl"
export CURL_HDRS="$STUB/hdrs"; : > "$CURL_HDRS"
out="$(unset LINEAR_API_KEY AUTOFIX_LINEAR_MOCK_DIR; export AUTOFIX_WORKSPACE_ROOT="$FAKE_WS"; export PATH="$STUB:$PATH"; export CURL_HDRS
  bash -c '. ../bin/lib/common.sh; . ../bin/lib/linear.sh; linear_gql ping "query{}" 2>&1')" && rc=0 || rc=$?
assert_eq 0 "$rc" ".env fallback: live call succeeds"
assert_contains "$(cat "$CURL_HDRS")" "Authorization: lin_api_from_dotenv" ".env fallback: key from workspace .env used"
out2="$(unset LINEAR_API_KEY AUTOFIX_LINEAR_MOCK_DIR; export AUTOFIX_WORKSPACE_ROOT="$(mktemp -d)"; export PATH="$STUB:$PATH"
  bash -c '. ../bin/lib/common.sh; . ../bin/lib/linear.sh; linear_gql ping "query{}" 2>&1')" && rc2=0 || rc2=$?
assert_eq 1 "$rc2" "no env var and no .env still dies"
finish
