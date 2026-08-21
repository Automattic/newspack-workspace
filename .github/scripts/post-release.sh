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
#     sync (then restore workspace:* there too).
#
# A merge that conflicts is not discarded: the conflicted tree is committed to a
# `post-release/conflicts/<target>-<timestamp>` branch and opened as a draft PR,
# and the Slack alert links to it. Conflicts here are fresh each release rather
# than one recurring conflict, so escalations are timestamped and older
# untouched ones are closed as superseded. The job still exits non-zero.
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

# Branch prefix for escalation PRs (see escalate_conflict). Supersede keys on
# this prefix rather than on the commit subject: the subject is deliberately the
# same conventional `chore(release): merge in release <tag>` form every other
# back-merge uses, and a reviewer's `commit --amend --no-edit` carries it
# forward unchanged — so it cannot distinguish a bot escalation from a finished
# one. A branch name survives amends.
ESCALATION_BRANCH_PREFIX="post-release/conflicts"

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
#
# Split in two: stage_workspace_deps_restore does the rewrite and staging,
# restore_workspace_deps_and_commit wraps it in a commit. The escalation path
# needs the staging without the commit, because its branch has to carry exactly
# one commit for the `git commit --amend --no-edit` in the PR body to work.
stage_workspace_deps_restore() {
  node -e '
    const fs = require("fs"), path = require("path");
    const WS_PACKAGES = ["newspack-scripts", "newspack-components", "newspack-colors", "newspack-icons"];
    // "packages" belongs here too: msr pins the shared packages the same way
    // it pins plugins and themes, and pnpm-lock.yaml is keyed to workspace:*
    // for all three roots. Leaving packages out is what made every release
    // since need a hand-written restore PR.
    const roots = ["packages", "plugins", "themes"];
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
}

restore_workspace_deps_and_commit() {
  local branch="$1"
  stage_workspace_deps_restore
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

# Close open escalation PRs for $1 that nobody has started resolving, pointing
# them at the new one ($2 = its URL). $3 is this run's own bot identity, used to
# tell an untouched escalation from one someone is working on. Each release
# produces a *different* conflict set rather than re-presenting one unresolved
# conflict, so a stale escalation PR describes a merge that no longer exists.
# Once a human has pushed a resolution commit the PR is theirs, and closing it
# would discard their work.
#
# The test is the head commit's **committer**, not its author. The resolution
# recipe in the PR body ends in `git commit --amend --no-edit`, and amending
# rewrites the committer while carrying the original author forward — so an
# author check would still read as the bot and close the very PR a person had
# just finished. Anything unresolvable (a missing head, an API error) skips the
# PR: leaving a stale escalation open costs noise, closing a live one costs work.
supersede_escalations() {
  local target="$1" new_url="$2" bot_identity="$3"
  local new_number="${2##*/}"
  local prs n head_sha committer
  prs=$(TARGET="$target" PREFIX="$ESCALATION_BRANCH_PREFIX" \
    gh pr list --base "$target" --state open --limit 100 \
      --json number,headRefName \
      --jq '.[]
            | select(.headRefName | startswith(env.PREFIX + "/" + env.TARGET + "-"))
            | .number' 2>/dev/null) || prs=""
  for n in $prs; do
    [ "$n" = "$new_number" ] && continue
    head_sha=$(gh pr view "$n" --json headRefOid --jq '.headRefOid' 2>/dev/null) || head_sha=""
    [ -z "$head_sha" ] && continue
    # {owner}/{repo} is resolved by gh from the checkout's remote, so this needs
    # no GITHUB_REPOSITORY.
    committer=$(gh api "repos/{owner}/{repo}/commits/$head_sha" --jq '.commit.committer.email' 2>/dev/null) || committer=""
    if [ -z "$committer" ] || [ "$committer" != "$bot_identity" ]; then
      continue
    fi
    gh pr comment "$n" --body "Superseded by $new_url. Each release conflicts on a different set of files, so this one describes a merge that no longer exists." > /dev/null 2>&1 || true
    if gh pr close "$n" > /dev/null 2>&1; then
      echo "[post-release] Superseded escalation PR #$n."
    else
      echo "[post-release] Could not close superseded escalation PR #$n."
    fi
  done
}

# Push a conflicted merge to a branch and open a draft PR, instead of throwing
# the conflicted tree away.
#
# Why: this used to be `git merge --abort`, which left the resolution with
# nowhere to happen on GitHub. Every reconciliation then landed as a direct push
# to alpha/main with no PR and a bare "Merge branch 'release'" subject —
# unreviewed, and not greppable alongside the bot's back-merges. Committing the
# conflict here gives the resolution a reviewable home, and the conventional
# subject means `commit --amend --no-edit` leaves it matching every other
# back-merge.
#
# $1 = target branch. $2 = the commit to restore the local branch to when done,
# captured before the merge. $3 = the newline-separated conflicting paths the
# caller captured. Prints the PR URL on stdout, empty if escalation could not
# complete; everything else goes to stderr so it cannot contaminate that value.
#
# Always returns 0 and always leaves the tree clean on $2: this runs on the
# already-failed path, and an escalation hiccup must not preempt the Slack alert
# or strand the second sync target.
escalate_conflict() {
  local target="$1" saved="$2" conflicts="$3"
  local url="" marker_files="" body="" branch="" bot_identity="" err_file=""
  branch="$ESCALATION_BRANCH_PREFIX/${target}-$(date -u +%Y%m%d-%H%M%S)"

  # Staging is what resolves the unmerged index entries, since git refuses to
  # commit while any path is still unmerged, and it puts the markers in the tree
  # verbatim, which is the point. Scoped to the conflicting paths rather than
  # `-u` or `-A`: either of those would also sweep up unrelated work sitting in
  # the workspace, and the `git reset --hard` below then destroys it locally
  # after publishing it on the escalation branch. `-A` within the pathspec so a
  # modify/delete conflict resolved as a deletion still stages.
  if ! printf '%s\n' "$conflicts" | git add -A --pathspec-from-file=- >&2; then
    echo "[post-release] Could not stage the conflicted paths; skipping escalation." >&2
    git merge --abort > /dev/null 2>&1 || true
    git reset --hard "$saved" >&2 || true
    return 0
  fi

  # Fold the workspace:* restore into this same commit. Every other merge in
  # this script pairs one with the other, and a back-merge that skips it carries
  # the release's concretized internal versions into the target, where
  # `pnpm install --frozen-lockfile` rejects them. It has to be *this* commit,
  # not a second one, because the PR body's `git commit --amend --no-edit`
  # only reaches the tip. Guarded: a package.json that is itself conflicted
  # won't parse, and that must not preempt the alert.
  if ! stage_workspace_deps_restore >&2; then
    echo "[post-release] Could not restore workspace:* on the escalation branch; the resolver has to run it." >&2
  fi

  if ! git commit -q -m "chore(release): merge in release $LATEST_VERSION_TAG" >&2; then
    echo "[post-release] Could not commit the conflicted tree; skipping escalation." >&2
    git merge --abort > /dev/null 2>&1 || true
    git reset --hard "$saved" >&2 || true
    return 0
  fi
  # Taken from the commit this run just made rather than hardcoded, so the
  # supersede check can never drift out of step with the identity `Configure
  # git` sets in release.yml.
  bot_identity=$(git log -1 --format='%ce') || bot_identity=""

  # --cached, not HEAD: searching a rev prefixes every result with "HEAD:",
  # which the PR body then presents as paths that don't exist. The index is
  # identical to the commit we just made.
  marker_files=$(git grep --cached -lE '^<<<<<<< |^>>>>>>> ' 2>/dev/null | head -50 || true)

  if ! git push origin "HEAD:refs/heads/$branch" >&2; then
    echo "[post-release] Could not push $branch; skipping escalation." >&2
    git reset --hard "$saved" >&2 || true
    return 0
  fi

  body=$(cat <<EOF
The post-release sync could not merge \`release\` into \`$target\`, so the conflicted merge is pushed here for a person to finish.

- Release: \`$LATEST_VERSION_TAG\`
- Build: ${GITHUB_SERVER_URL:-https://github.com}/${GITHUB_REPOSITORY:-}/actions/runs/${GITHUB_RUN_ID:-}

**CI on this branch will be red, and that is expected** — the tree still carries conflict markers. It goes green once they are resolved.

To resolve:

\`\`\`
gh pr checkout $branch
# resolve the conflict markers
git add -u
git commit --amend --no-edit
git push --force-with-lease
\`\`\`

Then mark this PR ready for review and **merge it — do not squash**. \`$target\` has to genuinely contain \`release\`, or the next release reproduces this conflict.

Files with conflict markers:

\`\`\`
${marker_files:-(none — the conflict is structural, e.g. delete/modify)}
\`\`\`
EOF
)

  # stderr goes to a file, never into $url. gh writes advisory lines there on a
  # *successful* create (an untracked file left in the tree draws "uncommitted
  # changes"), and folding them in would leave the URL behind a warning, fail
  # the prefix match below, and report a PR that exists as missing.
  err_file=$(mktemp 2>/dev/null) || err_file=""
  url=$(gh pr create --draft --base "$target" --head "$branch" \
    --title "Post-release sync conflict: release into $target" \
    --body "$body" 2>"${err_file:-/dev/null}") || url=""
  url=$(printf '%s\n' "$url" | grep -m1 '^https://' || true)
  if [ -z "$url" ] && [ -n "$err_file" ]; then
    echo "[post-release] gh pr create failed: $(tr '\n' ' ' < "$err_file")" >&2
  fi
  [ -n "$err_file" ] && rm -f "$err_file"

  if [ -n "$url" ]; then
    echo "[post-release] Opened escalation PR $url for $target." >&2
    supersede_escalations "$target" "$url" "$bot_identity" >&2
  else
    echo "[post-release] Conflict pushed to $branch, but no PR was opened." >&2
  fi

  git reset --hard "$saved" >&2 || true
  printf '%s' "$url"
}

# Notify Slack about a failed post-release merge into $1, if Slack is configured.
# $2 (optional) is a newline-separated list of "<file><TAB><PR><TAB><author>"
# rows (see attribute_conflicts); when present the files are named in the message
# — with the incoming PR/author — so readers can tell what needs reconciling and
# who to ping without opening the build log.
# $3 (optional) is the escalation PR URL from escalate_conflict; when present it
# is linked last, so the alert routes straight to where the fix goes.
notify_slack() {
  local target="$1"
  local conflicts="${2:-}"
  local pr_url="${3:-}"
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
  payload=$(TARGET="$target" CONFLICTS="$conflicts" PR_URL="$pr_url" SLACK_CHANNEL_ID="$SLACK_CHANNEL_ID" node -e '
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
    // The PR link rides in the footer, not the body: the trim below only ever
    // slices body, so the two links a reader needs can never be cut.
    const prLink = process.env.PR_URL
      ? `\n<${process.env.PR_URL}|Open the resolution PR>`
      : "";
    const footer = `\nCheck <${runUrl}|the build> for details.${prLink}`;
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
    local fallback_pr=""
    [ -n "$pr_url" ] && fallback_pr=" Resolution PR: $pr_url"
    payload="{\"channel\":\"$SLACK_CHANNEL_ID\",\"blocks\":[{\"type\":\"section\",\"text\":{\"type\":\"mrkdwn\",\"text\":\"⚠️ Post-release merge to \`$target\` failed for: \`$GITHUB_REPOSITORY\`. Check <$GITHUB_SERVER_URL/$GITHUB_REPOSITORY/actions/runs/$GITHUB_RUN_ID|the build> for details.$fallback_pr\"}}]}"
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
# Captured before each merge so escalate_conflict can put the branch back
# exactly where it was after committing the conflicted tree away to its own
# branch. The two targets are independent and both can fail in one run.
saved=$(git rev-parse HEAD)

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
    # Capture the conflicting paths while the index still has unmerged entries;
    # the escalation commit below stages them and clears that state.
    # core.quotePath=false keeps non-ASCII paths literal (not octal-escaped &
    # double-quoted), so attribution lookups match and the alert shows real names.
    conflicts=$(git -c core.quotePath=false diff --name-only --diff-filter=U)
    # Attribute before escalating: attribute_conflicts resolves `git merge-base
    # HEAD release`, and the escalation commit moves HEAD, which would silently
    # change the merge base and blame the wrong commits.
    attributed=$(attribute_conflicts "$conflicts")
    echo "[post-release] Post-release merge to alpha failed."
    pr_url=$(escalate_conflict alpha "$saved" "$conflicts")
    notify_slack alpha "$attributed" "$pr_url"
    sync_failed=1
  fi
fi

echo "[post-release] Merging release into $DEFAULT_BRANCH."
git checkout "$DEFAULT_BRANCH"
saved=$(git rev-parse HEAD)
if git merge --no-ff release -m "chore(release): merge in release $LATEST_VERSION_TAG"; then
  restore_workspace_deps_and_commit "$DEFAULT_BRANCH"
  git push origin "$DEFAULT_BRANCH"
else
  # Capture the conflicting paths while the index still has unmerged entries;
  # the escalation commit below stages them and clears that state.
  # core.quotePath=false keeps non-ASCII paths literal (not octal-escaped &
  # double-quoted), so attribution lookups match and the alert shows real names.
  conflicts=$(git -c core.quotePath=false diff --name-only --diff-filter=U)
  # See the alpha branch above: attribution must precede the escalation commit.
  attributed=$(attribute_conflicts "$conflicts")
  echo "[post-release] Post-release merge to $DEFAULT_BRANCH failed."
  pr_url=$(escalate_conflict "$DEFAULT_BRANCH" "$saved" "$conflicts")
  notify_slack "$DEFAULT_BRANCH" "$attributed" "$pr_url"
  sync_failed=1
fi

if [ "$sync_failed" -ne 0 ]; then
  echo "[post-release] One or more post-release syncs hit conflicts (see above); failing the job."
  exit 1
fi
