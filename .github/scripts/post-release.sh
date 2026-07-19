#!/usr/bin/env bash
#
# Post-release branch maintenance for the monorepo, run after the `release` job.
#
# After a release on the `release` branch:
#   - reset the single-serving `alpha` branch onto `release` (when the release
#     came from an alpha merge), or merge `release` into `alpha` otherwise;
#   - restore `workspace:*` for any internal workspace dep that the release
#     concretized (see restore_workspace_deps below);
#   - merge `release` back into the repository's default branch so they stay in
#     sync (then restore workspace:* there too), notifying Slack on conflict.
#
# This lives in the monorepo (not packages/scripts, which mirrors the legacy
# newspack-scripts repo and is overwritten by the daily sync) and targets the
# repo's actual default branch rather than the hard-coded `trunk` the legacy
# script used. Uses node only for the small workspace-deps rewrite (preinstalled
# on ubuntu-latest runners); otherwise pure git.

set -euo pipefail

# The default branch the legacy script called "trunk"; resolved dynamically so
# this works whether the repo's default is `main` or `trunk`.
DEFAULT_BRANCH=$(git remote show origin | sed -n 's/.*HEAD branch: //p')
DEFAULT_BRANCH=${DEFAULT_BRANCH:-main}

# The last commit here is the automated release commit; the one before it
# carries the merge info used to decide whether the release came from alpha.
SECOND_TO_LAST_COMMIT_MSG=$(git log -n 1 --skip 1 --pretty=format:"%s")
LATEST_VERSION_TAG=$(git describe --tags --abbrev=0 2>/dev/null || echo "release")

# Restore workspace:* for any internal monorepo dep
# (newspack-{scripts,components,colors,icons}) in every plugin/theme
# package.json, then commit if anything changed.
#
# Why: @semantic-release/npm's prepare step rewrites "workspace:*" to a concrete
# version in package.json before publishing to npm (npm can't consume
# workspace:*), and @semantic-release/git then commits that change to the
# `release` branch as part of the release commit. Resetting alpha onto release
# (and merging release into the default branch) carries those concrete versions
# forward, but pnpm-lock.yaml at the workspace root is keyed to workspace:* —
# the next `pnpm install --frozen-lockfile` then fails with
# ERR_PNPM_OUTDATED_LOCKFILE. Restoring workspace:* here keeps both branches
# consistent with the lockfile and lets the next trunk→alpha promotion merge
# cleanly without any manual restoration dance.
#
# The commit (when needed) carries [skip ci] so it doesn't re-trigger
# release.yml — the alpha branch tip is then a chore[skip ci], same as today,
# and the team's normal promotion merge commit later (without [skip ci]) is
# what fires release.yml.
restore_workspace_deps_and_commit() {
  local branch="$1"
  node -e '
    const fs = require("fs"), path = require("path");
    const WS_PACKAGES = ["newspack-scripts", "newspack-components", "newspack-colors", "newspack-icons"];
    const roots = ["plugins", "themes"];
    const changed = [];
    for (const r of roots) {
      if (!fs.existsSync(r)) continue;
      for (const name of fs.readdirSync(r)) {
        const pj = path.join(r, name, "package.json");
        if (!fs.existsSync(pj)) continue;
        const src = fs.readFileSync(pj, "utf8");
        const indentMatch = src.match(/\n(\t+|[ ]+)"/);
        const indent = indentMatch ? indentMatch[1] : "  ";
        const trail = src.endsWith("\n") ? "\n" : "";
        const j = JSON.parse(src);
        let dirty = false;
        for (const section of ["dependencies", "devDependencies", "peerDependencies"]) {
          if (!j[section]) continue;
          for (const pkg of WS_PACKAGES) {
            if (j[section][pkg] && j[section][pkg] !== "workspace:*") {
              j[section][pkg] = "workspace:*";
              dirty = true;
            }
          }
        }
        if (!dirty) continue;
        fs.writeFileSync(pj, JSON.stringify(j, null, indent) + trail);
        changed.push(pj);
      }
    }
    for (const f of changed) process.stdout.write(f + "\0");
  ' | while IFS= read -r -d "" f; do
    git add -- "$f"
  done
  if [ -n "$(git status --porcelain)" ]; then
    git commit -m "chore(release): restore workspace:* deps after release [skip ci]"
    echo "[post-release] Restored workspace:* deps on $branch."
  fi
}

# Attribute each conflicting file to the incoming release-side commit that last
# touched it, so the alert can name who to route to. Emits one
# "<file><TAB><PR><TAB><author>" row per input path ($1 = newline-separated
# paths). This identifies the *incoming* change being merged forward, not sole
# blame — the merge is mutual (the target branch changed the file too). PR and
# author are left empty when unresolvable (commit has no "(#NNN)", etc.).
attribute_conflicts() {
  local files="$1"
  [ -z "$files" ] && return 0
  local mb f meta subj author pr
  mb=$(git merge-base HEAD release 2>/dev/null || true)
  while IFS= read -r f; do
    [ -z "$f" ] && continue
    subj=""; author=""; pr=""
    if [ -n "$mb" ]; then
      # --no-merges so attribution lands on the squash commit carrying "(#NNN)",
      # not a promotion-merge commit (which has no PR ref in its subject).
      meta=$(git log "$mb"..release -1 --no-merges --format='%s%x09%an' -- "$f" 2>/dev/null || true)
      if [ -n "$meta" ]; then
        # Split from the right: the format is "<subject><TAB><author>" and an
        # author name has no tab, so a literal tab inside the subject can't
        # corrupt the author field.
        subj=${meta%$'\t'*}
        author=${meta##*$'\t'}
        # Prefer the trailing "(#NNN)" squash-merge PR ref; fall back to the last
        # bare "#NNN" so an issue ref earlier in the subject isn't mistaken for it.
        pr=$(printf '%s' "$subj" | grep -oE '\(#[0-9]+\)' | tail -1 | tr -cd '0-9' || true)
        if [ -n "$pr" ]; then
          pr="#$pr"
        else
          pr=$(printf '%s' "$subj" | grep -oE '#[0-9]+' | tail -1 || true)
        fi
      fi
    fi
    printf '%s\t%s\t%s\n' "$f" "$pr" "$author"
  done <<< "$files"
}

# Notify Slack about a failed post-release merge into $1, if Slack is configured.
# $2 (optional) is a newline-separated list of "<file><TAB><PR><TAB><author>"
# rows (see attribute_conflicts); when present the files are named in the message
# — with the incoming PR/author — so readers can tell what needs reconciling and
# who to ping without opening the build log.
notify_slack() {
  local target="$1"
  local conflicts="${2:-}"
  if [ -z "${SLACK_CHANNEL_ID:-}" ] || [ -z "${SLACK_AUTH_TOKEN:-}" ]; then
    echo "[post-release] Missing Slack channel ID and/or token. Cannot notify."
    return
  fi
  echo "[post-release] Notifying the team on Slack."
  # Build the JSON payload with node (already used in this script) rather than
  # hand-rolling it: the conflict list is variable-length and newline-separated,
  # and raw newlines aren't valid inside a JSON string literal. node's
  # JSON.stringify escapes the message text correctly.
  # A merge with many conflicts could otherwise blow past Slack's 3000-char
  # section-text limit, which makes chat.postMessage reject the payload and drop
  # the whole alert — exactly the large-messy-merge case this naming exists for.
  # So cap the named list and hard-trim the text (the build link is appended last
  # and never trimmed, so it always survives).
  local payload
  # Pass SLACK_CHANNEL_ID inline: the node child reads it from process.env, which
  # only sees *exported* vars; forwarding it explicitly (like TARGET/CONFLICTS)
  # decouples delivery from how the workflow happens to set it. The GITHUB_* vars
  # node reads are always exported by the Actions runtime, so they stay ambient.
  payload=$(TARGET="$target" CONFLICTS="$conflicts" SLACK_CHANNEL_ID="$SLACK_CHANNEL_ID" node -e '
    const MAX_FILES = 10;
    const MAX_TEXT = 2900;
    const items = (process.env.CONFLICTS || "")
      .split("\n")
      .map((s) => s.trim())
      .filter(Boolean)
      .map((line) => {
        const [file, pr, author] = line.split("\t");
        return { file, pr: pr || "", author: author || "" };
      });
    const header = `⚠️ Post-release merge to \`${process.env.TARGET}\` failed for: \`${process.env.GITHUB_REPOSITORY}\`.`;
    const runUrl = `${process.env.GITHUB_SERVER_URL}/${process.env.GITHUB_REPOSITORY}/actions/runs/${process.env.GITHUB_RUN_ID}`;
    const footer = `\nCheck <${runUrl}|the build> for details.`;
    let body = "";
    if (items.length) {
      const shown = items.slice(0, MAX_FILES);
      const repoUrl = `${process.env.GITHUB_SERVER_URL}/${process.env.GITHUB_REPOSITORY}`;
      let list = shown.map((it) => {
        // Render the PR ref as a Slack hyperlink (<url|label>) — a bare "#297" is
        // plain text in Slack (no repo context to auto-link). GitHub redirects
        // /pull/N to the issue if N is actually an issue, so /pull/ is safe.
        const prLink = it.pr ? `<${repoUrl}/pull/${it.pr.replace(/^#/, "")}|${it.pr}>` : "";
        const who = prLink && it.author ? `${prLink} (${it.author})` : prLink || it.author;
        return who ? `• \`${it.file}\` — incoming: ${who}` : `• \`${it.file}\``;
      }).join("\n");
      if (items.length > MAX_FILES) {
        list += `\n• …and ${items.length - MAX_FILES} more`;
      }
      body = "\nConflicting files:\n" + list;
    }
    let text = header + body + footer;
    if (text.length > MAX_TEXT) {
      // Backstop for pathologically long paths: trim on a line boundary so we
      // never sever a `path` backtick pair (an unclosed backtick makes Slack
      // swallow the rest as inline code, including the build link). The MAX_FILES
      // cap above keeps this from firing in practice.
      const room = Math.max(0, MAX_TEXT - header.length - footer.length - 2);
      const trimmed = body.slice(0, room).split("\n").slice(0, -1).join("\n");
      text = header + trimmed + "\n…" + footer;
    }
    process.stdout.write(JSON.stringify({
      channel: process.env.SLACK_CHANNEL_ID,
      blocks: [{ type: "section", text: { type: "mrkdwn", text } }],
    }));
  ') || {
    # node missing/erroring must not abort the script (set -e) on this
    # already-failed path. Don't go silent either: fall back to a minimal
    # hand-rolled alert (fixed text, no variable conflict list → no JSON-escaping
    # hazard) so the team is still notified the merge failed.
    echo "[post-release] Slack payload build failed; sending minimal fallback alert."
    payload="{\"channel\":\"$SLACK_CHANNEL_ID\",\"blocks\":[{\"type\":\"section\",\"text\":{\"type\":\"mrkdwn\",\"text\":\"⚠️ Post-release merge to \`$target\` failed for: \`$GITHUB_REPOSITORY\`. Check <$GITHUB_SERVER_URL/$GITHUB_REPOSITORY/actions/runs/$GITHUB_RUN_ID|the build> for details.\"}}]}"
  }
  if [ -z "$payload" ]; then
    echo "[post-release] Empty Slack payload; skipping notification."
    return
  fi
  curl \
    --data "$payload" \
    -H "Content-type: application/json" \
    -H "Authorization: Bearer $SLACK_AUTH_TOKEN" \
    -X POST https://slack.com/api/chat.postMessage \
    -s > /dev/null
}

# Tracks whether any release -> branch sync hit a conflict. We attempt every
# sync (so each gets its own Slack ping) but exit non-zero at the end if any
# failed, so the job goes red instead of silently passing on an aborted merge.
sync_failed=0

git pull origin release
git checkout alpha

if echo "$SECOND_TO_LAST_COMMIT_MSG" | grep -q '^Merge .*alpha'; then
  echo "[post-release] Release came from the alpha branch. Resetting alpha onto release."
  # The alpha branch is single-serving; discard its history after a release.
  git reset --hard release --
  restore_workspace_deps_and_commit alpha
  git push --force origin alpha
else
  echo "[post-release] Release came from a non-alpha branch (e.g. a hotfix). Merging release into alpha."
  if git merge --no-ff release -m "chore(release): merge in release $LATEST_VERSION_TAG"; then
    restore_workspace_deps_and_commit alpha
    git push origin alpha
  else
    # Capture the conflicting paths before --abort clears the unmerged state.
    # core.quotePath=false keeps non-ASCII paths literal (not octal-escaped &
    # double-quoted), so attribution lookups match and the alert shows real names.
    conflicts=$(git -c core.quotePath=false diff --name-only --diff-filter=U)
    git merge --abort
    echo "[post-release] Post-release merge to alpha failed."
    notify_slack alpha "$(attribute_conflicts "$conflicts")"
    sync_failed=1
  fi
fi

echo "[post-release] Merging release into $DEFAULT_BRANCH."
git checkout "$DEFAULT_BRANCH"
if git merge --no-ff release -m "chore(release): merge in release $LATEST_VERSION_TAG"; then
  restore_workspace_deps_and_commit "$DEFAULT_BRANCH"
  git push origin "$DEFAULT_BRANCH"
else
  # Capture the conflicting paths before --abort clears the unmerged state.
  # core.quotePath=false keeps non-ASCII paths literal (not octal-escaped &
  # double-quoted), so attribution lookups match and the alert shows real names.
  conflicts=$(git -c core.quotePath=false diff --name-only --diff-filter=U)
  git merge --abort
  echo "[post-release] Post-release merge to $DEFAULT_BRANCH failed."
  notify_slack "$DEFAULT_BRANCH" "$(attribute_conflicts "$conflicts")"
  sync_failed=1
fi

if [ "$sync_failed" -ne 0 ]; then
  echo "[post-release] One or more post-release syncs hit conflicts (see above); failing the job."
  exit 1
fi
