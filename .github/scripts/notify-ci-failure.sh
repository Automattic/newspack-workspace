#!/usr/bin/env bash
#
# Slack notification for a CI run that broke on main, posted from the
# "Notify CI failure" workflow.
#
# The message names the failing jobs, not just the run, because CI's matrix is
# per-package: "PHPUnit / @automattic/newspack-blocks" says which package to
# look at, where "CI failed" does not.
#
# A failure here must never fail the workflow — the notification is the whole
# job, and a red run reporting a second red run helps nobody. Set
# SLACK_DRY_RUN=1 to print the payload instead of posting.

set -euo pipefail

trap 'echo "[notify-ci-failure] WARNING: unexpected error in: ${BASH_COMMAND}. Skipping notification."; exit 0' ERR

TOKEN="${SLACK_AUTH_TOKEN:-}"
CHANNEL="${SLACK_CHANNEL_ID:-}"

if [ -z "$TOKEN" ] || [ -z "$CHANNEL" ]; then
  echo "[notify-ci-failure] No Slack token and/or channel. Skipping."
  exit 0
fi

# Failing job names, one per line. A run cancelled before any job reported
# leaves this empty, which the message handles rather than treating as an error.
FAILED_JOBS=$(
  gh api "repos/${GITHUB_REPOSITORY}/actions/runs/${RUN_ID}/jobs?per_page=100" --paginate \
    --jq '.jobs[] | select(.conclusion == "failure" or .conclusion == "timed_out" or .conclusion == "cancelled") | .name' \
    2>/dev/null || true
)

# The aggregate job fails whenever anything under it does, so listing it beside
# the real culprits adds a line that names nothing.
FAILED_JOBS=$(printf '%s\n' "$FAILED_JOBS" | grep -vx 'CI OK' || true)

# Build the payload in node so commit subjects and job names are escaped safely.
PAYLOAD=$(
  CHANNEL="$CHANNEL" \
  FAILED_JOBS="$FAILED_JOBS" \
  SERVER="${GITHUB_SERVER_URL:-https://github.com}" \
  REPO="${GITHUB_REPOSITORY:-}" \
  node -e '
    const jobs = process.env.FAILED_JOBS.split("\n").map(s => s.trim()).filter(Boolean);
    const server = process.env.SERVER.replace(/\/+$/, "");
    const repo = process.env.REPO;
    const branch = process.env.HEAD_BRANCH;
    const sha = process.env.HEAD_SHA;
    const shortSha = sha.slice(0, 9);
    const conclusion = process.env.RUN_CONCLUSION;

    const verb = conclusion === "timed_out" ? "timed out" : conclusion === "cancelled" ? "was cancelled" : "failed";
    let text = `:rotating_light: CI ${verb} on \`${branch}\`: <${process.env.RUN_URL}|view the run>`;

    if (jobs.length) {
      text += "\n" + jobs.map(j => `• ${j}`).join("\n");
    }

    // From the event payload rather than git: the checkout is shallow, so the
    // commit the run was for is usually not in it. First line only.
    const subject = (process.env.COMMIT_SUBJECT || "").split("\n")[0].trim();
    const author = (process.env.COMMIT_AUTHOR || "").trim();
    const commitUrl = `${server}/${repo}/commit/${sha}`;
    text += `\n<${commitUrl}|${shortSha}>`;
    if (subject) {
      text += ` ${subject}`;
    }
    if (author) {
      text += ` (${author})`;
    }

    // Why this needs saying out loud: the next push that touches a different
    // package produces a green run, and the breakage stops being visible
    // anywhere until someone touches this package again.
    text += "\nThis will not show up on later runs unless they change the same package.";

    process.stdout.write(JSON.stringify({ channel: process.env.CHANNEL, text }));
  '
)

if [ -n "${SLACK_DRY_RUN:-}" ]; then
  echo "[notify-ci-failure] DRY RUN -- would post to Slack:"
  echo "$PAYLOAD"
  exit 0
fi

RESPONSE=$(
  curl -sS \
    --data "$PAYLOAD" \
    -H 'Content-type: application/json; charset=utf-8' \
    -H "Authorization: Bearer $TOKEN" \
    -X POST https://slack.com/api/chat.postMessage
) || {
  echo "[notify-ci-failure] WARNING: curl to Slack failed."
  exit 0
}

if echo "$RESPONSE" | grep -q '"ok":true'; then
  echo "[notify-ci-failure] Notified Slack about run ${RUN_ID}."
else
  echo "[notify-ci-failure] WARNING: Slack notification failed: $RESPONSE"
fi
exit 0
