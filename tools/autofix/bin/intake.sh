#!/bin/bash
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
. "$(dirname "${BASH_SOURCE[0]}")/lib/linear.sh"
require jq

ISSUE_QUERY='query Issue($id: String!) { issue(id: $id) {
  id identifier title branchName
  team { id } state { id name type } assignee { id }
  labels { nodes { id name } }
  attachments { nodes { url title } }
} }'

# _check_core <id> <allow_pr: yes|no> <security_mode: bar|allow>
# Shared body of `check` and `check-secure` (spec magi #14 — one implementation,
# the Security-label decision is the only parameterized branch). Prints the
# eligibility summary JSON on stdout, or exits 2 (security-barred) / 3
# (existing PR) / dies.
_check_core() {
  local id="$1" allow_pr="$2" secmode="$3" body issue pr_url
  body="$(linear_gql issue "$ISSUE_QUERY" "$(jq -n --arg id "$id" '{id:$id}')")" \
    || die "could not fetch $id from Linear"
  issue="$(printf '%s' "$body" | jq '.data.issue')"
  [ "$issue" != "null" ] || die "issue not found: $id"
  if printf '%s' "$issue" | jq -e '.labels.nodes[] | select(.name=="Security")' >/dev/null; then
    if [ "$secmode" = bar ]; then
      log "SECURITY-LABELED: $id is ineligible in every base mode (use autofix-secure)"; exit 2
    fi
    log "SECURITY-LABELED: $id — eligible under the secure path"
  fi
  pr_url="$(printf '%s' "$issue" | jq -r '[.attachments.nodes[].url | select(test("/pull/"))][0] // empty')"
  if [ -n "$pr_url" ] && [ "$allow_pr" != yes ]; then
    log "EXISTING-PR: $id already has an open PR attachment: $pr_url"; exit 3
  fi
  printf '%s' "$issue" | jq '{identifier, title, branchName, teamId:.team.id,
    stateId:.state.id, stateName:.state.name, assigneeId:(.assignee.id // null),
    labels:[.labels.nodes[].name]}'
}

cmd="${1:?usage: intake.sh check|check-secure <ISSUE-ID> [--allow-existing-pr] | queue --dry-run}"; shift

case "$cmd" in
  check)
    id="${1:?issue id}"; allow="${2:-}"
    a="$([ "$allow" = --allow-existing-pr ] && echo yes || echo no)"
    _check_core "$id" "$a" bar ;;
  check-secure)
    # Security-eligible path. Stricter existing-PR isolation (spec magi #7):
    # honor --allow-existing-pr ONLY with the env double-signal, else hard exit 3.
    id="${1:?issue id}"; allow="${2:-}"
    if [ "$allow" = --allow-existing-pr ] && [ "${AUTOFIX_SECURE_ALLOW_EXISTING_PR:-}" = 1 ]; then
      a=yes
    else
      a=no
    fi
    _check_core "$id" "$a" allow ;;
  queue)
    [ "${1:-}" = "--dry-run" ] || die "v1 supports queue --dry-run only (label-queue claiming is v1.1)"
    statuses="$(printf '%s' "$AUTOFIX_ELIGIBLE_STATUSES" | jq -R 'split(",")')"
    q='query Queue($team: String!, $label: String!, $states: [String!]) {
      issues(first: 50, filter: {
        team: { name: { eq: $team } },
        labels: { name: { eq: $label } },
        state: { name: { in: $states } },
        assignee: { null: true }
      }) { nodes { identifier title priority createdAt } } }'
    vars="$(jq -n --arg team "$AUTOFIX_TEAM" --arg label "$AUTOFIX_READY_LABEL" \
      --argjson states "$statuses" '{team:$team, label:$label, states:$states}')"
    body="$(linear_gql queue "$q" "$vars")" || die "queue query failed"
    printf '%s' "$body" | jq -r '.data.issues.nodes
      | map(.p = (if .priority == 0 then 5 else .priority end))
      | sort_by(.p, .createdAt)[] | [.identifier, (.priority|tostring), .title] | @tsv'
    n="$(printf '%s' "$body" | jq '.data.issues.nodes | length')"
    printf '# %s candidate(s)\n' "$n" ;;
  *) die "unknown subcommand: $cmd" ;;
esac
