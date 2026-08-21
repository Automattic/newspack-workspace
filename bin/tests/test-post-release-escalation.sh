#!/usr/bin/env bash
#
# test-post-release-escalation.sh
#
# Self-proving spec for the conflict-escalation path in
# .github/scripts/post-release.sh, exercised against a throwaway origin with gh
# and curl stubbed, so nothing reaches GitHub or Slack.
#
# The path only runs on a push to `release`, so a PR can never exercise it: the
# first time a change here is observed for real is during a release, on the
# already-failed branch, where a mistake either strands `main` or destroys work.
# That is what these cases stand in for.
#
# Two of them guard defects this code has actually had:
#
#   - Superseding must key on the head commit's *committer*, not its author.
#     `git commit --amend --no-edit` — the last step of the recipe the
#     escalation PR itself prints — rewrites the committer but carries the
#     original author forward, so an author check reads a PR someone just
#     finished as untouched and closes it.
#   - The escalation commit must also restore `workspace:*`. Every other merge
#     in the script pairs the two, and a back-merge that skips it carries the
#     release's concretized internal versions into the target, where
#     `pnpm install --frozen-lockfile` rejects them. It has to be that same
#     commit: the amend recipe only reaches the tip.
#
# Run: bash bin/tests/test-post-release-escalation.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SUT="$SCRIPT_DIR/../../.github/scripts/post-release.sh"
BOT_EMAIL="newspack-release-bot@users.noreply.github.com"

[ -f "$SUT" ] || { echo "FAIL: $SUT not found"; exit 1; }
command -v node > /dev/null || { echo "FAIL: node is required (post-release.sh uses it)"; exit 1; }

WORK=$(mktemp -d -t post-release-XXXXXX)
trap 'rm -rf "$WORK"' EXIT

# Isolate from whatever git environment this happens to run in. A global
# commit.gpgsign or tag.gpgSign is enough to fail every commit here for reasons
# that have nothing to do with the code under test, and husky's core.hooksPath
# would run the workspace's hooks against these scratch repos.
#
# Scrubbing the whole GIT_* namespace, not just pointing the two config files at
# scratch: GIT_CONFIG_GLOBAL and GIT_CONFIG_SYSTEM neutralise config *files*,
# and several variables override those per process. Measured against this spec
# before the scrub — GIT_CONFIG_COUNT/KEY/VALUE injected a user.email straight
# past GIT_CONFIG_GLOBAL, GIT_TEMPLATE_DIR seeded a failing hook into every
# scratch repo, and GIT_COMMITTER_EMAIL broke the one case that distinguishes a
# committer from an author. GIT_DIR and GIT_WORK_TREE are the sharp end of the
# same class: they aim git at a repo this spec never created.
for _git_var in $(compgen -v | grep '^GIT_'); do unset "$_git_var"; done
unset _git_var
export GIT_CONFIG_GLOBAL="$WORK/gitconfig"
export GIT_CONFIG_SYSTEM=/dev/null
export HUSKY=0
: > "$GIT_CONFIG_GLOBAL"

failures=0
pass() { echo "  ok   $1"; }
fail() { echo "  FAIL $1"; failures=$((failures + 1)); }
check() { # check <label> <expected> <actual>
  if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (expected '$2', got '$3')"; fi
}

# ---------------------------------------------------------------------------
# Fixture: a bare origin with main/alpha/release, where a hotfix on `release`
# and unrelated work on both targets touch the same line of the same file --
# the shape of the 2026-08-20 sync failure. `release` also carries a
# concretized workspace dep, the way semantic-release leaves one.
# ---------------------------------------------------------------------------
setup_fixture() { # setup_fixture <case-name>; echoes the case root
  local root="$WORK/$1"
  mkdir -p "$root/bin" "$root/state"

  cat > "$root/bin/gh" <<'GH'
#!/usr/bin/env bash
set -uo pipefail
[ "${GH_STUB_FAIL:-0}" = "1" ] && { echo "stubbed gh failure" >&2; exit 4; }
case "${1:-} ${2:-}" in
  "pr create")
    # Real gh writes advisory lines to stderr on a *successful* create; the
    # script must not fold them into the URL it captures.
    echo "Warning: 1 uncommitted change" >&2
    n=$(( $(cat "$CASE_ROOT/state/counter" 2>/dev/null || echo 0) + 1 ))
    echo "$n" > "$CASE_ROOT/state/counter"
    head=""; base=""
    while [ $# -gt 0 ]; do
      case "$1" in
        --head) head="$2" ;;
        --base) base="$2" ;;
        --body) printf '%s' "$2" > "$CASE_ROOT/state/body-$n.md" ;;
      esac
      shift
    done
    printf '%s\t%s\t%s\n' "$n" "$head" "$base" >> "$CASE_ROOT/state/open-prs"
    echo "https://github.com/acme/repo/pull/$n"
    ;;
  "pr list")
    base=""; prev=""
    for a in "$@"; do [ "$prev" = "--base" ] && base="$a"; prev="$a"; done
    while IFS=$'\t' read -r n head b; do
      [ "$b" = "$base" ] || continue
      grep -qx "$n" "$CASE_ROOT/state/closed" 2>/dev/null && continue
      case "$head" in "post-release/conflicts/$base-"*) echo "$n" ;; esac
    done < "$CASE_ROOT/state/open-prs" 2>/dev/null || true
    ;;
  "pr view")
    # Resolved live, so a force-push by a "human" is visible here the way it
    # would be to real gh.
    head=$(grep "^$3	" "$CASE_ROOT/state/open-prs" 2>/dev/null | tail -1 | cut -f2)
    git -C "$CASE_ROOT/work" rev-parse "refs/remotes/origin/$head" 2>/dev/null
    ;;
  "api"*)
    # Honour which field the caller asked for. Returning the committer no matter
    # what would make the author-vs-committer case below pass against an author
    # check too, which is the one thing it exists to catch.
    fmt='%ce'
    for a in "$@"; do case "$a" in *author*) fmt='%ae' ;; esac; done
    git -C "$CASE_ROOT/work" log -1 --format="$fmt" "${2##*/}" 2>/dev/null
    ;;
  "pr comment") echo "$3" >> "$CASE_ROOT/state/commented" ;;
  "pr close")   echo "$3" >> "$CASE_ROOT/state/closed" ;;
esac
GH

  cat > "$root/bin/curl" <<'CURL'
#!/usr/bin/env bash
prev=""
for a in "$@"; do [ "$prev" = "--data" ] && printf '%s\n' "$a" >> "$CASE_ROOT/state/slack.json"; prev="$a"; done
CURL
  chmod +x "$root/bin/gh" "$root/bin/curl"

  git init -q --bare "$root/origin.git"
  git -C "$root/origin.git" symbolic-ref HEAD refs/heads/main

  git init -q -b main "$root/seed"
  (
    cd "$root/seed"
    git config user.email seed@example.com
    git config user.name seed
    mkdir -p packages/components/src/wizard plugins/newspack-plugin
    printf 'export const wizard = 1;\n' > packages/components/src/wizard/index.js
    printf '{"name":"p","dependencies":{"newspack-components":"workspace:*"}}\n' > plugins/newspack-plugin/package.json
    git add -A && git commit -qm base
    git branch alpha && git branch release

    git checkout -q release
    printf 'export const wizard = 2; // hotfix\n' > packages/components/src/wizard/index.js
    printf '{"name":"p","dependencies":{"newspack-components":"3.4.5"}}\n' > plugins/newspack-plugin/package.json
    git commit -qam "fix(wizard): refetch after the installer adds plugins (#893)"
    git tag newspack-plugin@1.2.3
    git commit -q --allow-empty -m "chore(release): 1.2.3 [skip ci]"

    for b in main alpha; do
      git checkout -q "$b"
      printf 'export const wizard = 3; // %s\n' "$b" > packages/components/src/wizard/index.js
      git commit -qam "feat(components): shared EmptyState component (#854)"
    done
    git checkout -q main
    git remote add origin "$root/origin.git"
    git push -q origin main alpha release --tags
  )

  git clone -q "$root/origin.git" "$root/work"
  (
    cd "$root/work"
    git config user.name newspack-release-bot
    git config user.email "$BOT_EMAIL"
    git config pull.ff only
    git checkout -q alpha && git checkout -q main && git checkout -q release
  )
  # Kept outside the work tree: an untracked file there is exactly what the
  # scoped staging protects, so the runner must not depend on one surviving.
  cp "$SUT" "$root/post-release.sh"
  chmod +x "$root/post-release.sh"
  echo "$root"
}

run_sut() { # run_sut <case-root> [env assignments...]; echoes the exit code
  local root="$1"; shift
  local rc=0
  (
    cd "$root/work"
    export CASE_ROOT="$root"
    export PATH="$root/bin:$PATH"
    export SLACK_CHANNEL_ID=C123 SLACK_AUTH_TOKEN=tok
    export GITHUB_SERVER_URL=https://github.com GITHUB_REPOSITORY=acme/repo GITHUB_RUN_ID=999
    for kv in "$@"; do export "${kv?}"; done
    "$root/post-release.sh"
  ) > "$root/state/run.log" 2>&1 || rc=$?
  echo "$rc"
}

esc_branch() { git -C "$1/work" branch -r | grep -o "post-release/conflicts/$2-[0-9a-f-]*" | tail -1; }

# ---------------------------------------------------------------------------
echo "case: a conflict escalates instead of being discarded"
# ---------------------------------------------------------------------------
R=$(setup_fixture escalates)
check "job fails" 1 "$(run_sut "$R")"
B=$(esc_branch "$R" main)
check "an escalation branch for main was pushed" 1 "$([ -n "$B" ] && echo 1 || echo 0)"
check "one for alpha too" 1 "$([ -n "$(esc_branch "$R" alpha)" ] && echo 1 || echo 0)"
check "two draft PRs opened" 2 "$(wc -l < "$R/state/open-prs" | tr -d ' ')"
check "gh stderr did not swallow the URL" 0 "$(grep -c 'no PR was opened' "$R/state/run.log")"
check "the tip is a merge commit" 2 "$(git -C "$R/work" log -1 --format=%p "origin/$B" | wc -w | tr -d ' ')"
check "exactly one commit above the target (the amend recipe needs this)" \
  1 "$(git -C "$R/work" rev-list --count --first-parent "main..origin/$B")"
check "subject matches every other back-merge" \
  "chore(release): merge in release newspack-plugin@1.2.3" \
  "$(git -C "$R/work" log -1 --format=%s "origin/$B")"
check "conflict markers are on the branch" 1 \
  "$(git -C "$R/work" show "origin/$B:packages/components/src/wizard/index.js" | grep -c '^<<<<<<< ')"
check "workspace:* restored in that same commit" \
  'workspace:*' \
  "$(git -C "$R/work" show "origin/$B:plugins/newspack-plugin/package.json" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>process.stdout.write(JSON.parse(s).dependencies["newspack-components"]))')"
for b in alpha main; do
  check "$b was restored to its pre-merge commit" \
    "$(git -C "$R/work" rev-parse "origin/$b")" "$(git -C "$R/work" rev-parse "$b")"
done
check "working tree left clean" "" "$(git -C "$R/work" status --porcelain)"
check "both targets alerted Slack" 2 "$(wc -l < "$R/state/slack.json" | tr -d ' ')"
check "the alert links the escalation PR" 2 "$(grep -c 'Open the resolution PR' "$R/state/slack.json")"

# ---------------------------------------------------------------------------
echo "case: superseding spares a PR someone has resolved"
# ---------------------------------------------------------------------------
R=$(setup_fixture supersede)
run_sut "$R" > /dev/null                       # PRs 1 (alpha) and 2 (main)
AB=$(esc_branch "$R" alpha)
(
  cd "$R/work"
  # Exactly the recipe the escalation PR body prints.
  git checkout -q -B resolve "origin/$AB"
  printf 'export const wizard = 4; // resolved\n' > packages/components/src/wizard/index.js
  git add -u
  git -c user.name=Human -c user.email=human@example.com commit -q --amend --no-edit
  git push -q --force origin "HEAD:$AB"
  git checkout -q main
)
check "the resolver's amend left the author as the bot" "$BOT_EMAIL" \
  "$(git -C "$R/work" log -1 --format=%ae "origin/$AB")"
check "...and moved the committer to the person" "human@example.com" \
  "$(git -C "$R/work" log -1 --format=%ce "origin/$AB")"
(
  cd "$R/work"
  git checkout -q release
  git commit -q --allow-empty -m "chore(release): next [skip ci]"
  git push -q origin release
)
run_sut "$R" > /dev/null                       # PRs 3 (alpha) and 4 (main)
check "the untouched escalation is superseded" 1 "$(grep -cx 2 "$R/state/closed")"
check "the resolved one survives" 0 "$(grep -cx 1 "$R/state/closed" || true)"
check "a superseded PR is told why before it closes" 1 "$(grep -cx 2 "$R/state/commented")"
check "the new escalations stay open" 0 "$(grep -cxE '3|4' "$R/state/closed" || true)"

# ---------------------------------------------------------------------------
echo "case: a clean merge is untouched by any of this"
# ---------------------------------------------------------------------------
R=$(setup_fixture clean)
(
  cd "$R/work"
  for b in alpha main; do
    git checkout -q "$b"
    git checkout -q release -- packages/components/src/wizard/index.js
    git commit -qam "align $b"
    git push -q origin "$b"
  done
  git checkout -q release
)
check "job passes" 0 "$(run_sut "$R")"
check "no escalation branch" 0 "$(git -C "$R/work" branch -r | grep -c post-release/conflicts)"
check "no Slack alert" 0 "$([ -f "$R/state/slack.json" ] && wc -l < "$R/state/slack.json" | tr -d ' ' || echo 0)"
for b in alpha main; do
  check "$b was pushed" "$(git -C "$R/work" rev-parse "$b")" "$(git -C "$R/work" rev-parse "origin/$b")"
  check "workspace:* restored on $b" 'workspace:*' \
    "$(git -C "$R/work" show "$b:plugins/newspack-plugin/package.json" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>process.stdout.write(JSON.parse(s).dependencies["newspack-components"]))')"
done

# ---------------------------------------------------------------------------
echo "case: an escalation that cannot complete still alerts and still cleans up"
# ---------------------------------------------------------------------------
R=$(setup_fixture degraded)
check "job still fails" 1 "$(run_sut "$R" GH_STUB_FAIL=1)"
check "no PR was opened" 2 "$(grep -c 'no PR was opened' "$R/state/run.log")"
check "Slack was still told about both targets" 2 "$(wc -l < "$R/state/slack.json" | tr -d ' ')"
check "the alert carries no dead PR link" 0 "$(grep -c 'Open the resolution PR' "$R/state/slack.json" || true)"
for b in alpha main; do
  check "$b was still restored" "$(git -C "$R/work" rev-parse "origin/$b")" "$(git -C "$R/work" rev-parse "$b")"
done
check "working tree still clean" "" "$(git -C "$R/work" status --porcelain)"

# ---------------------------------------------------------------------------
if [ "$failures" -ne 0 ]; then
  echo "$failures check(s) failed"
  exit 1
fi
echo "all checks passed"
