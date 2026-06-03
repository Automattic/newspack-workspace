#!/usr/bin/env bash
#
# Release-success Slack notification for the monorepo, run in the `release` job
# right after multi-semantic-release publishes.
#
# A single push to `release`/`alpha` publishes a GitHub release + git tag
# (`<pkg>@<version>`) for every plugin/theme with releasable commits. This posts
# ONE summary message listing all of them, routed by branch to a workspace:
#
#   - release branch -> a8c workspace (stable),  reusing SLACK_AUTH_TOKEN.
#   - alpha   branch -> Newspack workspace (alpha), using SLACK_NEWSPACK_BOT_TOKEN.
#
# hotfix/* and epic/* are intentionally silent (the workflow step's `if` already
# excludes them; the case below is defence in depth).
#
# Released packages are detected by diffing the tag list captured before the
# release step ($TAGS_BEFORE_FILE) against the tags present now. The legacy
# per-repo ":ship: ... released: <url>" pings were sent by Zapier, which the
# monorepo no longer drives; this restores that notification in-repo.
#
# A failure here never fails the job: the release has already happened, so a
# Slack hiccup must not turn the run red. Set SLACK_DRY_RUN=1 to print the
# payload instead of posting (for local testing).

set -euo pipefail

# A failure here must never fail the release job (the release has already
# happened). Any unhandled error under `set -e` degrades to a warning and a
# clean exit, rather than turning the run red. Intentional control-flow
# "failures" (grep -q, comm, the guarded curl) are exempt because bash does not
# trigger ERR for commands whose status is tested.
trap 'echo "[notify-release] WARNING: unexpected error in: ${BASH_COMMAND}. Skipping notification."; exit 0' ERR

REF="${GITHUB_REF_NAME:-}"

case "$REF" in
  release)
    TOKEN="${SLACK_AUTH_TOKEN:-}"
    CHANNEL="${SLACK_A8C_RELEASE_CHANNEL_ID:-}"
    VERB="released"
    CC_SUBTEAM="${SLACK_SUPPORT_TEAM_SUBTEAM_ID:-}"
    ;;
  alpha)
    TOKEN="${SLACK_NEWSPACK_BOT_TOKEN:-}"
    CHANNEL="${SLACK_NEWSPACK_RELEASE_CHANNEL_ID:-}"
    VERB="alpha version released"
    CC_SUBTEAM=""
    ;;
  *)
    echo "[notify-release] Branch '$REF' does not notify. Skipping."
    exit 0
    ;;
esac

if [ -z "${TAGS_BEFORE_FILE:-}" ] || [ ! -f "$TAGS_BEFORE_FILE" ]; then
  echo "[notify-release] No tag snapshot at '${TAGS_BEFORE_FILE:-}'. Cannot determine released packages. Skipping."
  exit 0
fi

# Tags created by this release run = present now but not in the pre-release
# snapshot. Both sides must be sorted for comm.
NEW_TAGS=$(comm -13 <(sort "$TAGS_BEFORE_FILE") <(git tag | sort) || true)

if [ -z "$NEW_TAGS" ]; then
  echo "[notify-release] No new tags; nothing was released. Skipping."
  exit 0
fi

if [ -z "$TOKEN" ] || [ -z "$CHANNEL" ]; then
  echo "[notify-release] Missing Slack token and/or channel for '$REF'. Cannot notify."
  exit 0
fi

# Build the chat.postMessage JSON in node so package names are escaped safely.
# Each tag becomes a line: ":ship: <Display Name> <verb>: <release url>", with
# the URL wrapped in <> so Slack links it reliably despite the '@' in the tag.
PAYLOAD=$(
  TAGS="$NEW_TAGS" \
  VERB="$VERB" \
  CC_SUBTEAM="$CC_SUBTEAM" \
  CHANNEL="$CHANNEL" \
  SERVER="${GITHUB_SERVER_URL:-https://github.com}" \
  REPO="${GITHUB_REPOSITORY:-}" \
  node -e '
    const tags = process.env.TAGS.split("\n").map(s => s.trim()).filter(Boolean);
    const verb = process.env.VERB;
    const server = process.env.SERVER.replace(/\/+$/, "");
    const repo = process.env.REPO;
    const display = pkg => pkg.split("-").filter(Boolean)
      .map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(" ");
    const lines = tags.map(tag => {
      const at = tag.lastIndexOf("@");
      const pkg = at === -1 ? tag : tag.slice(0, at);
      const url = `${server}/${repo}/releases/tag/${tag}`;
      return `:ship: ${display(pkg)} ${verb}: <${url}>`;
    });
    let text = lines.join("\n");
    if (process.env.CC_SUBTEAM) {
      text += `\n(cc <!subteam^${process.env.CC_SUBTEAM}>)`;
    }
    process.stdout.write(JSON.stringify({ channel: process.env.CHANNEL, text }));
  '
)

if [ -n "${SLACK_DRY_RUN:-}" ]; then
  echo "[notify-release] DRY RUN -- would post to Slack:"
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
  echo "[notify-release] WARNING: curl to Slack failed."
  exit 0
}

if echo "$RESPONSE" | grep -q '"ok":true'; then
  echo "[notify-release] Notified Slack for '$REF'."
else
  echo "[notify-release] WARNING: Slack notification failed: $RESPONSE"
fi
exit 0
