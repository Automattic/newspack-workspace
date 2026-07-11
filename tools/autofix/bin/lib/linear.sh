#!/bin/bash
# linear.sh — Linear GraphQL client. Source after common.sh.
LINEAR_ENDPOINT="${LINEAR_ENDPOINT:-https://api.linear.app/graphql}"

linear_gql() { # opname query [variables-json]
  local op="$1" q="$2" vars="${3:-"{}"}"
  if [ -n "${AUTOFIX_LINEAR_MOCK_DIR:-}" ]; then
    printf '%s\t%s\n' "$op" "$vars" >> "$AUTOFIX_LINEAR_MOCK_DIR/requests.log"
    cat "$AUTOFIX_LINEAR_MOCK_DIR/$op.json" 2>/dev/null || { log "no mock fixture: $op"; return 1; }
    return 0
  fi
  [ -n "${LINEAR_API_KEY:-}" ] || die "LINEAR_API_KEY not set (and not in mock mode)"
  require curl; require jq
  local attempt=1 max="${AUTOFIX_MAX_ATTEMPTS:-3}" resp code body payload
  payload="$(jq -n --arg q "$q" --argjson v "$vars" '{query:$q, variables:$v}')"
  while :; do
    resp="$(curl -sS -w '\n%{http_code}' -H "Authorization: $LINEAR_API_KEY" \
      -H 'Content-Type: application/json' -d "$payload" "$LINEAR_ENDPOINT" || true)"
    code="${resp##*$'\n'}"; body="${resp%$'\n'*}"
    if [ "$code" = "200" ] && ! printf '%s' "$body" | jq -e '.errors' >/dev/null 2>&1; then
      printf '%s\n' "$body"; return 0
    fi
    if [ "$attempt" -ge "$max" ]; then
      log "linear_gql $op failed after $max attempts (http $code): $(printf '%s' "$body" | head -c 300)"
      return 1
    fi
    sleep $((attempt * 2)); attempt=$((attempt + 1))
  done
}

linear_viewer_id() {
  linear_gql viewer 'query { viewer { id } }' | jq -r '.data.viewer.id'
}
