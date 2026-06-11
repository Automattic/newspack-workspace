#!/usr/bin/env bash
#
# lib.sh — shared helpers for the legacy → monorepo migration scripts.
#
# Sourced by sync-legacy.sh (daily trunk sync). Provides:
#
#   • The legacy-repo manifest: name → target subdir in the monorepo.
#   • Path-rewrite / merge helpers used to land legacy commits cleanly:
#       - apply_structural_overrides: drop legacy .github/, package-lock.json,
#         and pin workspace:* in plugin/theme package.json.
#       - restore_release_artifacts: pin release-stamped files (CHANGELOG.md,
#         theme SCSS + plugin-PHP version headers, package.json name/version) to
#         the monorepo side so a late legacy hotfix release can't downgrade it.
#       - normalize_package_repos: rewrite repository.url in every workspace
#         package.json so semantic-release resolves to the monorepo, not the
#         standalone legacy repo.
#       - route_extracted_packages: relocate any
#         plugins/newspack-plugin/packages/{colors,components,icons}/* to
#         packages/<pkg>/* (those package homes moved during extraction).
#   • Dry-run wrappers around git push and gh pr create.
#
# The whole bin/migration/ tree is intended to be deleted after cutover —
# none of this code is needed once development has moved to the monorepo.

# Manifest: legacy repo name → monorepo target subdir. Stable order; the
# integrate phase of sync-legacy.sh merges in this order, so changing it
# changes the resulting merge-commit shape on monorepo-integration.
LEGACY_REPOS=(
  newspack-plugin:plugins/newspack-plugin
  newspack-blocks:plugins/newspack-blocks
  newspack-popups:plugins/newspack-popups
  newspack-newsletters:plugins/newspack-newsletters
  newspack-ads:plugins/newspack-ads
  newspack-network:plugins/newspack-network
  newspack-multibranded-site:plugins/newspack-multibranded-site
  newspack-listings:plugins/newspack-listings
  newspack-sponsors:plugins/newspack-sponsors
  newspack-story-budget:plugins/newspack-story-budget
  # super-cool-ad-inserter is no longer synced: its legacy repo is frozen and
  # the monorepo dir was renamed from super-cool-ad-inserter-plugin to its
  # wp.org canonical slug, so the old path/name no longer maps cleanly.
  republication-tracker-tool:plugins/republication-tracker-tool
  newspack-theme:themes/newspack-theme
  newspack-block-theme:themes/newspack-block-theme
  newspack-scripts:packages/scripts
)

# Wrap git push so DRY_RUN=1 prints the intended push instead of running it.
# Callers set DRY_RUN themselves; we read it from the environment.
git_push() {
  if [ "${DRY_RUN:-0}" = "1" ]; then
    echo "    [dry-run] would push: git push $*"
  else
    git push "$@"
  fi
}

# Wrap gh pr create the same way. Errors are warned but not fatal: in the
# escalation path the conflict branch is already on origin, so a missing PR
# can be opened by hand later.
gh_pr_create() {
  if [ "${DRY_RUN:-0}" = "1" ]; then
    echo "    [dry-run] would create draft PR: gh pr create $*" | head -c 400
    echo
  else
    gh pr create "$@" \
      || echo "WARN: gh pr create failed; conflict branch is at origin"
  fi
}

# Drop legacy per-plugin .github/ (CI runs at the monorepo root),
# package-lock.json (the monorepo uses pnpm-lock.yaml at the root; per-plugin
# lockfiles are vestigial and would otherwise be re-added on every sync run
# that touches them upstream), per-plugin LICENSE/LICENSE.md (the monorepo
# carries a single root LICENSE under GPL-2.0-or-later, with each plugin
# header declaring the same; per-plugin copies were folded out at cutover),
# and per-plugin commitlint.config.js (root package.json declares commitlint
# config workspace-wide).
# Also restore workspace:* in any conflicting plugin/theme package.json —
# the legacy repo bumps newspack-{scripts,components,colors,icons} to concrete
# versions, which would break the pnpm workspace if landed.
apply_structural_overrides() {
  local target=$1
  git rm -rf --ignore-unmatch \
    "$target/.github" "$target/package-lock.json" \
    "$target/LICENSE" "$target/LICENSE.md" \
    "$target/commitlint.config.js" \
    > /dev/null 2>&1 || true
  while IFS= read -r f; do
    case "$f" in
      plugins/*/package.json|themes/*/package.json)
        git checkout --ours -- "$f"
        git add "$f"
        ;;
    esac
  done < <(git diff --name-only --diff-filter=U)
}

# Pin release-stamped files to the monorepo (HEAD) side after a merge.
#
# The monorepo owns its own release versioning: semantic-release at the root
# bumps every version-bearing file and regenerates each CHANGELOG, so "the sync
# brings code, not the release commits" (see lib-versions.sh). The legacy repos
# are frozen but still occasionally cut a late hotfix; that hotfix's
# `chore(release)` commit stamps a *regressed* version (the legacy line sits
# below the monorepo's) into the package's version-bearing files. A clean
# "legacy wins" merge (legacy advanced the file, the monorepo had not touched
# it) lands the stale stamp unchallenged and downgrades the monorepo;
# apply_structural_overrides only rescues package.json, and only on a conflict.
#
# Two kinds of release-stamped file, handled differently:
#
#   • Pure artifacts — CHANGELOG.md and the theme stylesheet header SCSS
#     (classic `theme-description.scss`, the block theme's underscore-prefixed
#     `_theme-description.scss`). Comment / generated content only, no SCSS
#     rules or source, so they are restored to HEAD wholesale.
#   • Source files that merely carry a stamped version — package.json
#     (name+version), each plugin's main PHP file (the WordPress `Version:`
#     header that WP itself reads, plus any `*_VERSION` constant kept in lockstep
#     with it; see config/release.js `files: [ phpFile ]`), and a theme
#     `functions.php`. Restoring these wholesale would drop the real fix the
#     hotfix carried, so only the version token is reset to HEAD's value; every
#     other line the legacy commit touched survives.
#
# `git checkout HEAD -- <path>` resolves to the pre-merge monorepo blob whether
# the path conflicted or merged cleanly (`--ours` only works for conflicts), and
# also marks any conflicted path resolved. HEAD is the pre-merge monorepo tip
# here because integrate runs `git merge --no-commit`.
restore_release_artifacts() {
  local target=$1

  # Pure artifacts: restore wholesale.
  while IFS= read -r f; do
    if git cat-file -e "HEAD:$f" 2> /dev/null; then
      git checkout HEAD -- "$f"
    fi
  done < <(git ls-files "$target" \
    | grep -E '(^|/)(CHANGELOG\.md|_?theme-description\.scss)$' || true)

  # package.json: pin name + version, keeping any real dependency change. The
  # node block is the `if` condition so a malformed package.json skips that one
  # file (exit 1) instead of aborting the whole sync under `set -e`. Any
  # conflicted package.json was already taken --ours by apply_structural_overrides
  # above; the later normalize_package_repos / restore_workspace_deps passes
  # rewrite other fields, so this is not the last word on the file.
  while IFS= read -r pj; do
    if node -e '
      const fs = require( "fs" ), cp = require( "child_process" );
      const pj = process.argv[1];
      let head;
      try { head = JSON.parse( cp.execSync( "git show HEAD:" + pj, { encoding: "utf8", stdio: [ "pipe", "pipe", "ignore" ] } ) ); }
      catch ( e ) { process.exit( 0 ); }   // not in HEAD (new package) — leave as-is.
      const src = fs.readFileSync( pj, "utf8" );
      const indentMatch = src.match( /\n(\t+|[ ]+)"/ );
      const indent = indentMatch ? indentMatch[1] : "  ";
      const trail = src.endsWith( "\n" ) ? "\n" : "";
      const j = JSON.parse( src );
      let dirty = false;
      for ( const k of [ "name", "version" ] ) {
        if ( head[k] !== undefined && j[k] !== head[k] ) { j[k] = head[k]; dirty = true; }
      }
      if ( dirty ) fs.writeFileSync( pj, JSON.stringify( j, null, indent ) + trail );
    ' "$pj"; then
      git add -- "$pj"
    fi
  done < <(git ls-files "$target" | grep -E '(^|/)package\.json$' || true)

  # Main plugin PHP file + theme functions.php: reset the WordPress `Version:`
  # header (and any `*_VERSION` constant) to HEAD's version, leaving real code
  # intact. The version is read from HEAD's own copy of the file, so a package
  # without a stamped header is a no-op. Restricted to files directly under the
  # target so a vendored library header is never touched. An unmerged PHP file
  # (a conflict -Xtheirs couldn't auto-resolve) is skipped rather than edited:
  # a surgical rewrite + `git add` would mark it resolved with conflict markers
  # still inside, hiding it from the post-merge unmerged-paths check that
  # escalates. Leaving it unmerged lets that escalation fire.
  while IFS= read -r f; do
    if git ls-files --unmerged -- "$f" | grep -q .; then continue; fi
    if node -e '
      const fs = require( "fs" ), cp = require( "child_process" );
      const f = process.argv[1];
      let head;
      try { head = cp.execSync( "git show HEAD:" + f, { encoding: "utf8", stdio: [ "pipe", "pipe", "ignore" ] } ); }
      catch ( e ) { process.exit( 0 ); }
      const headVer = ( head.match( /^[\s*#\/]*Version:\s*(\S+)/m ) || [] )[1];
      if ( ! headVer ) process.exit( 0 );   // not version-stamped in HEAD.
      const src = fs.readFileSync( f, "utf8" );
      const next = src
        .replace( /^([\s*#\/]*Version:\s*)\S+/m, "$1" + headVer )
        .replace( /(define\(\s*[\x27"][A-Z0-9_]*_VERSION[\x27"]\s*,\s*[\x27"])[^\x27"]+([\x27"])/g, "$1" + headVer + "$2" );
      if ( next !== src ) fs.writeFileSync( f, next );
    ' "$f"; then
      git add -- "$f"
    fi
  done < <(git ls-files "$target" | grep -E "^${target}/[^/]+\.php$" || true)
}

# Rewrite repository field in every workspace package.json so semantic-release
# resolves to the monorepo. Without this, multi-semantic-release ls-remotes
# the legacy standalone repo of each plugin (which has no alpha/release
# branches) and aborts. Idempotent — safe to call after every merge.
normalize_package_repos() {
  node -e '
    const fs = require("fs"), path = require("path");
    const url = "git+https://github.com/Automattic/newspack-workspace.git";
    const roots = ["plugins", "themes", "packages"];
    const changed = [];
    for (const r of roots) {
      if (!fs.existsSync(r)) continue;
      for (const name of fs.readdirSync(r)) {
        const dir = path.join(r, name);
        const pj = path.join(dir, "package.json");
        if (!fs.existsSync(pj)) continue;
        const src = fs.readFileSync(pj, "utf8");
        const indentMatch = src.match(/\n(\t+|[ ]+)"/);
        const indent = indentMatch ? indentMatch[1] : "  ";
        const trail = src.endsWith("\n") ? "\n" : "";
        const j = JSON.parse(src);
        const want = { type: "git", url, directory: dir };
        if (JSON.stringify(j.repository) === JSON.stringify(want)) continue;
        j.repository = want;
        fs.writeFileSync(pj, JSON.stringify(j, null, indent) + trail);
        changed.push(pj);
      }
    }
    for (const f of changed) process.stdout.write(f + "\0");
  ' | while IFS= read -r -d "" f; do
    git add -- "$f"
  done
}

# Restore workspace:* for any dependency on a workspace-published package
# (newspack-scripts/components/colors/icons) in any plugin/theme package.json.
# Legacy commits bump these against published semver (e.g. "^5.9.7"); a clean
# merge into the monorepo brings that bump in unchallenged, which breaks
# pnpm's frozen-lockfile install because pnpm-lock.yaml expects workspace:*.
# Idempotent — safe to call after every merge.
restore_workspace_deps() {
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
}

# Refresh pnpm-lock.yaml when plugin/theme package.json files have changed
# during the integration run. Without this, a legacy commit that adds/removes
# a dependency cleanly merges into the monorepo but leaves the lockfile out
# of sync, and CI's `pnpm install --frozen-lockfile` fails on the next PR.
# Silently no-ops when pnpm isn't available (e.g. local DRY_RUN on a machine
# without it) — only commits a change if the lockfile actually moved.
regenerate_lockfile() {
  command -v pnpm > /dev/null 2>&1 || { echo "==> pnpm not found, skipping lockfile refresh"; return; }
  echo "==> Refreshing pnpm-lock.yaml"
  pnpm install --lockfile-only > /dev/null 2>&1 || {
    echo "    WARN: pnpm install --lockfile-only failed; leaving lockfile untouched"
    return
  }
  if [ -n "$(git status --porcelain pnpm-lock.yaml)" ]; then
    git add pnpm-lock.yaml
    git commit -m "chore(sync): refresh pnpm-lock.yaml after legacy merges" > /dev/null
    echo "    lockfile updated"
  else
    echo "    lockfile already in sync"
  fi
}

# For newspack-plugin: redirect any path under
# plugins/newspack-plugin/packages/{colors,components,icons}/ to the workspace
# path packages/<pkg>/<rest>. Three cases:
#   1. Path is conflicted: 3-way merge legacy's change into the workspace file.
#   2. Path is cleanly merged in (legacy added/modified, no monorepo-side
#      change): move/overwrite at the workspace path.
#   3. Path is deleted in legacy: drop it.
# Returns 1 if any routed file ends up with conflict markers, or if a modified
# file has no workspace target.
route_extracted_packages() {
  local rc=0

  # Process conflicts first (the unresolved index entries hold the base/theirs
  # blobs we need for a real 3-way merge).
  while IFS= read -r path; do
    case "$path" in
      plugins/newspack-plugin/packages/colors/*|\
      plugins/newspack-plugin/packages/components/*|\
      plugins/newspack-plugin/packages/icons/*) ;;
      *) continue ;;
    esac

    local rel="${path#plugins/newspack-plugin/packages/}"
    local target="packages/$rel"
    local theirs_blob
    theirs_blob=$(git ls-files -s -- "$path" | awk '$3==3{print $2}')

    # Legacy deleted the file: drop it from the workspace path too.
    if [ -z "$theirs_blob" ]; then
      git rm -f -- "$path" > /dev/null
      continue
    fi

    # Take legacy's version of the routed file wholesale, mirroring the run-wide
    # "legacy wins" -Xtheirs policy. A hunk-level 3-way merge here is wrong: when
    # legacy and the monorepo's extracted copy both touched overlapping regions
    # (e.g. packages/components/src/card-feature/index.tsx after #4721), splicing
    # the two sides yields an internally inconsistent file — referencing an
    # identifier only one side declares — which Babel/ESLint on .tsx won't catch
    # and only breaks at runtime. Replacing wholesale keeps the file consistent.
    mkdir -p "$(dirname "$target")"
    git show "$theirs_blob" > "$target"
    git add "$target"
    git rm -f -- "$path" > /dev/null
  done < <(git diff --name-only --diff-filter=U)

  # Sweep any remaining cleanly-merged files under the extracted dirs (the
  # conflict pass missed them because there was no conflict — just legacy's
  # content landing at the legacy path).
  while IFS= read -r path; do
    [ -z "$path" ] && continue
    local rel="${path#plugins/newspack-plugin/packages/}"
    local target="packages/$rel"
    mkdir -p "$(dirname "$target")"
    git show ":0:$path" > "$target"
    git add "$target"
    git rm -f -- "$path" > /dev/null
  done < <(git ls-files -- \
    'plugins/newspack-plugin/packages/colors/*' \
    'plugins/newspack-plugin/packages/components/*' \
    'plugins/newspack-plugin/packages/icons/*')

  # Resolve the directory/file conflict the extraction shim creates. The
  # monorepo keeps plugins/newspack-plugin/packages/{colors,components,icons}
  # as symlinks into the workspace home (../../../packages/<pkg>); legacy still
  # carries real directories there. When legacy diverges under one, the merge
  # can't keep a symlink and a directory at the same path, so it renames our
  # symlink to <pkg>~HEAD (an unmerged stage-2 entry) and unpacks legacy's tree
  # at <pkg>/. The loops above route that tree's files to the workspace home;
  # here we restore our symlink at the canonical path and drop the ~HEAD
  # artifact, so the in-plugin shim survives and no unmerged entry is left to
  # escalate on. Packages that merged cleanly (still a stage-0 symlink) have no
  # ~HEAD artifact and are skipped.
  while IFS= read -r artifact; do
    [ -z "$artifact" ] && continue
    local canon="${artifact%\~HEAD}"
    local sym_blob
    sym_blob=$(git ls-files -s -- "$artifact" | awk '$3==2{print $2; exit}')
    git rm -q --cached --ignore-unmatch -- "$artifact" > /dev/null 2>&1 || true
    rm -f -- "$artifact" 2> /dev/null || true
    if [ -n "$sym_blob" ]; then
      git update-index --add --cacheinfo "120000,$sym_blob,$canon"
      git checkout-index -f -- "$canon" 2> /dev/null || true
    fi
  done < <(git ls-files -- \
    'plugins/newspack-plugin/packages/colors~HEAD' \
    'plugins/newspack-plugin/packages/components~HEAD' \
    'plugins/newspack-plugin/packages/icons~HEAD')

  return "$rc"
}
