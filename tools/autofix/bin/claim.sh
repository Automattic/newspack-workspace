#!/bin/bash
set -euo pipefail
BIN="$(dirname "${BASH_SOURCE[0]}")"
. "$BIN/lib/common.sh"
. "$BIN/lib/linear.sh"
require jq
LEDGER="$BIN/ledger.sh"

ISSUE_Q='query Issue($id: String!) { issue(id: $id) {
  id identifier assignee { id } state { id name } team { id }
  labels { nodes { id name } } } }'
POSTCLAIM_Q='query IssuePostClaim($id: String!) { issue(id: $id) {
  id identifier assignee { id } state { id name } labels { nodes { id name } }
  comments(first: 50) { nodes { body } } } }'
STATES_Q='query States($teamId: String!) { team(id: $teamId) { states { nodes { id name } } } }'
UPDATE_M='mutation Update($id: String!, $input: IssueUpdateInput!) {
  issueUpdate(id: $id, input: $input) { success } }'
COMMENT_M='mutation Comment($input: CommentCreateInput!) {
  commentCreate(input: $input) { success } }'

fetch_issue() { # opname issue-id
  linear_gql "$1" "$([ "$1" = issue ] && echo "$ISSUE_Q" || echo "$POSTCLAIM_Q")" \
    "$(jq -nc --arg id "$2" '{id:$id}')" | jq '.data.issue'
}
update_issue() { # uuid input-json
  # -c keeps requests.log one line per request (linear_gql just appends
  # "$vars\n" — a pretty-printed multi-line vars blob would break any
  # line-oriented grep/tail over the log).
  linear_gql issueUpdate "$UPDATE_M" \
    "$(jq -nc --arg id "$1" --argjson input "$2" '{id:$id, input:$input}')" >/dev/null
}
comment() { # uuid body
  linear_gql commentCreate "$COMMENT_M" \
    "$(jq -nc --arg id "$1" --arg body "$2" '{input:{issueId:$id, body:$body}}')" >/dev/null
}

cmd="${1:?usage: claim.sh claim|release <ISSUE-ID> <RUN_ID> [flags]}"; issue_id="${2:?}"; run_id="${3:?}"; shift 3

case "$cmd" in
  claim)
    # same-issue guard (spec magi #4): another non-terminal run on this issue?
    for lf in "$RUNS_DIR"/*/ledger.json; do
      [ -f "$lf" ] || continue
      # skip unparsable ledgers: under set -e a jq parse failure would abort the
      # claim outright; a corrupt ledger can't testify to an active run anyway
      jq -e 'type == "object"' "$lf" >/dev/null 2>&1 \
        || { log "same-issue guard: skipping unparsable ledger $lf"; continue; }
      other="$(jq -r --arg i "$issue_id" 'select(.issue==$i and .terminal==null) | .run_id' "$lf")"
      if [ -n "$other" ] && [ "$other" != "$run_id" ]; then
        log "SAME-ISSUE: non-terminal run $other already targets $issue_id"
        # mark THIS run's own ledger terminal now — otherwise it stays
        # terminal:null forever (never swept, and itself trips this same
        # guard for every future run against this issue).
        "$LEDGER" set "$run_id" '.terminal = "bailed-superseded"'
        exit 4
      fi
    done
    me="$(linear_viewer_id)"
    issue="$(fetch_issue issue "$issue_id")"
    uuid="$(printf '%s' "$issue" | jq -r .id)"
    team="$(printf '%s' "$issue" | jq -r .team.id)"
    "$LEDGER" set "$run_id" '.linear_prior = $p' --argjson p "$(printf '%s' "$issue" \
      | jq '{assigneeId:(.assignee.id // null), stateId:.state.id, stateName:.state.name,
             labels:[.labels.nodes[].id]}')"
    in_progress="$(linear_gql states "$STATES_Q" "$(jq -nc --arg teamId "$team" '{teamId:$teamId}')" \
      | jq -r '.data.team.states.nodes[] | select(.name=="In Progress") | .id')"
    [ -n "$in_progress" ] || die "no 'In Progress' state on team $team"
    # remember the state id THIS RUN set, so release can tell "still where we
    # put it" apart from "a human moved it mid-run" (conditional restore).
    "$LEDGER" set "$run_id" '.linear_prior.claimed_state_id = $s' --arg s "$in_progress"
    update_issue "$uuid" "$(jq -nc --arg a "$me" --arg s "$in_progress" '{assigneeId:$a, stateId:$s}')"
    comment "$uuid" "🤖 autofix run $run_id started"
    # verify the claim held (spec codex #1): assignee is us AND our comment exists
    post="$(fetch_issue issue_postclaim "$issue_id")"
    got_assignee="$(printf '%s' "$post" | jq -r '.assignee.id // ""')"
    # Ownership = assignee-match AND *this run's* claim comment. Assignee
    # alone is not run-specific — two concurrent runs under the same operator
    # identity would both see a match — so the comment must carry our RUN_ID.
    has_comment="$(printf '%s' "$post" | jq -r --arg r "$run_id" \
      '[.comments.nodes[].body | select(contains($r))] | length')"
    if [ "$got_assignee" != "$me" ] || [ "$has_comment" = "0" ]; then
      log "LOST-RACE: claim did not hold (assignee=$got_assignee); backing off"
      prior_state="$("$LEDGER" get "$run_id" '.linear_prior.stateId')"
      prior_assignee="$("$LEDGER" get "$run_id" '.linear_prior.assigneeId')"
      got_state="$(printf '%s' "$post" | jq -r '.state.id // ""')"
      # conditional back-off (spec magi #2): only unwind fields still holding
      # the value this run set — assignee stays put if someone else already
      # claimed it, and state stays put if it moved out from under us too.
      input='{}'
      if [ "$got_state" = "$in_progress" ]; then
        input="$(printf '%s' "$input" | jq --arg s "$prior_state" '.stateId = $s')"
      fi
      if [ "$got_assignee" = "$me" ]; then
        input="$(printf '%s' "$input" | jq --arg a "$prior_assignee" \
          '.assigneeId = (if $a == "null" or $a == "" then null else $a end)')"
      fi
      [ "$input" = "{}" ] || update_issue "$uuid" "$input"
      comment "$uuid" "🤖 autofix run $run_id detected a competing claim and backed off."
      "$LEDGER" set "$run_id" '.terminal = "bailed-lost-claim-race"'
      exit 5
    fi
    "$LEDGER" history "$run_id" intake claimed "assignee=$me state=In Progress"
    log "claimed $issue_id as run $run_id" ;;
  release)
    fail_label=""; note=""
    while [ $# -gt 0 ]; do case "$1" in
      --fail-label) fail_label=1; shift ;;
      --comment) note="$2"; shift 2 ;;
      *) die "unknown flag: $1" ;;
    esac; done
    # redaction gate FIRST — before any Linear read/write — so a finding
    # leaves Linear untouched (no partial release) rather than gating only
    # the PR body while a no-go/cannot-reproduce comment ships unredacted.
    if [ -n "$note" ]; then
      note_file="$(mktemp)"
      trap 'rm -f "$note_file"' EXIT
      printf '%s\n' "$note" > "$note_file"
      bash "$BIN/redact.sh" scan "$note_file" \
        || die "redaction findings in release comment — redact and retry (no Linear write performed)"
      rm -f "$note_file"; trap - EXIT
    fi
    me="$(linear_viewer_id)"
    cur="$(fetch_issue issue_release "$issue_id")"
    uuid="$(printf '%s' "$cur" | jq -r .id)"
    prior_assignee="$("$LEDGER" get "$run_id" '.linear_prior.assigneeId')"
    prior_state="$("$LEDGER" get "$run_id" '.linear_prior.stateId')"
    claimed_state="$("$LEDGER" get "$run_id" '.linear_prior.claimed_state_id')"
    cur_assignee="$(printf '%s' "$cur" | jq -r '.assignee.id // ""')"
    cur_state="$(printf '%s' "$cur" | jq -r '.state.id // ""')"
    restored=""; drifted=""
    input='{}'
    if [ "$cur_assignee" = "$me" ]; then
      input="$(printf '%s' "$input" | jq --arg a "$prior_assignee" \
        '.assigneeId = (if $a == "null" or $a == "" then null else $a end)')"
      restored="$restored assigneeId"
    else
      "$LEDGER" drift "$run_id" assigneeId "$me" "$cur_assignee"
      drifted="$drifted assigneeId"
    fi
    # restore state ONLY if it still sits where this run put it; a human
    # moving it mid-run (e.g. to In Review) must not be silently undone.
    if [ "$cur_state" = "$claimed_state" ]; then
      input="$(printf '%s' "$input" | jq --arg s "$prior_state" '.stateId = $s')"
      restored="$restored stateId"
    else
      "$LEDGER" drift "$run_id" stateId "$claimed_state" "$cur_state"
      drifted="$drifted stateId"
    fi
    if [ -n "$fail_label" ]; then
      cur_labels="$(printf '%s' "$cur" | jq '[.labels.nodes[].id]')"
      input="$(printf '%s' "$input" | jq --argjson l "$cur_labels" --arg f "$AUTOFIX_FAILED_LABEL_ID" \
        '.labelIds = ($l + [$f] | unique)')"
    fi
    [ "$input" = "{}" ] || update_issue "$uuid" "$input"
    [ -n "$note" ] && comment "$uuid" "$note"
    "$LEDGER" history "$run_id" release restored \
      "restored:[${restored# }] drifted:[${drifted# }]"
    log "released $issue_id" ;;
  *) die "unknown subcommand: $cmd" ;;
esac
