#!/usr/bin/env bash
#
# Regenerate WordPress translation files for monorepo units (plugins/* and
# themes/*). Called by release.yml on every release run and by i18n.yml on
# manual dispatch; runnable locally too (see --dry-run).
#
# For each unit this script:
#
#   1. Rebuilds the .pot template with `wp i18n make-pot`. The text domain
#      comes from the unit's own Plugin/Theme header, NOT the directory name:
#      the two are not always the same (super-cool-ad-inserter's domain is
#      `scaip`), and extracting with the wrong domain yields a template holding
#      only the file-header strings while looking perfectly healthy.
#   2. Where the unit ships .po translations (newspack-blocks and the classic
#      newspack-theme), folds the new template into each .po with msgmerge,
#      rebuilds the .mo with msgfmt, and regenerates the JS translation JSON
#      with `wp i18n make-json --no-purge` (no-purge so translations for JS
#      files absent from this build survive for the next run, mirroring the
#      legacy workflow and newspack-blocks/bin/update-translations.sh).
#   3. Reverts units whose only change is header churn. Regenerating always
#      rewrites POT-Creation-Date, and rewrites Project-Id-Version whenever the
#      unit released since the last run, so a no-op regeneration still produces
#      a diff. substantive_changes_in ignores exactly those headers rather than
#      counting lines against a threshold, so no constant has to track how many
#      volatile headers there are. New (untracked) files always count as a real
#      change: a fresh translation .json is often a single minified line,
#      sometimes with no trailing newline, so line counts understate it.
#      A unit that already has uncommitted work under languages/ is skipped
#      untouched, because the revert path is destructive (see restore_langdir).
#
# A unit that fails to regenerate is restored to its committed state and
# reported as a workflow warning; without --strict the script still exits 0.
# release.yml relies on that: a restored template is exactly what ships today,
# while failing an otherwise-good product release over a template regeneration
# would be strictly worse. i18n.yml passes --strict because a dispatched run
# exists only to regenerate, so a failure there must fail the run.
#
# Usage:
#   update-translations.sh [--dry-run] [--strict] [unit ...]
#
#   unit       plugins/<name> or themes/<name>. Default: every unit.
#   --dry-run  Print the resolved unit table (root, domain, pot, .po count)
#              and exit without running any generator or touching any file.
#              Needs only git; use it to audit domain/path resolution.
#   --strict   Exit 1 if any unit failed to regenerate or was skipped.
#
# Requires php and wp (WP-CLI) on PATH unless --dry-run, plus msgmerge and
# msgfmt for the two units that ship .po files (newspack-blocks and the classic
# newspack-theme). On macOS: `brew install wp-cli gettext`, and gettext is
# keg-only so its bin directory needs adding to PATH.

set -euo pipefail
shopt -s nullglob

DRY_RUN=false
STRICT=false
UNITS=()

while [ $# -gt 0 ]; do
	case "$1" in
		--dry-run ) DRY_RUN=true ;;
		--strict ) STRICT=true ;;
		-* )
			echo "Unknown option: $1" >&2
			exit 2
			;;
		* ) UNITS+=( "$1" ) ;;
	esac
	shift
done

REPO_ROOT=$(git rev-parse --show-toplevel)
cd "$REPO_ROOT"

if [ ${#UNITS[@]} -eq 0 ]; then
	for dir in plugins/*/ themes/*/; do
		UNITS+=( "${dir%/}" )
	done
	# nullglob means a layout change (plugins/ renamed, the checkout rooted
	# somewhere unexpected) leaves this empty, and every loop below then
	# becomes a no-op that still exits 0. Automation reporting success while
	# regenerating nothing is the exact failure this script exists to end, so
	# refuse it rather than pass silently.
	if [ ${#UNITS[@]} -eq 0 ]; then
		echo "ERROR: no units found under plugins/ or themes/ in $REPO_ROOT" >&2
		exit 2
	fi
fi

# Unit names can arrive from a workflow_dispatch input, so treat them as
# untrusted before they reach any path or command: exactly one path component
# under plugins/ or themes/, lowercase slug characters only. This rejects
# `..`, absolute paths, whitespace, globs and option-shaped values outright.
# [[ =~ ]] keeps the test in-process (no printf | grep pipe to lose a status
# to). The directory must also exist, so a typo fails here rather than
# surfacing later as make-pot writing a template for nothing.
for unit in "${UNITS[@]}"; do
	if ! [[ "$unit" =~ ^(plugins|themes)/[a-z0-9][a-z0-9-]*$ ]]; then
		echo "ERROR: invalid unit '$unit' (expected plugins/<name> or themes/<name>)" >&2
		exit 2
	fi
	if [ ! -d "$unit" ]; then
		echo "ERROR: no such unit directory: $unit" >&2
		exit 2
	fi
done

if ! $DRY_RUN; then
	# type -P resolves PATH executables only, never shell functions or aliases:
	# wp is invoked as `php <path>` below and needs a real file. php is checked
	# because this monorepo runs PHP in Docker, so a host without it is the
	# normal developer setup, and every unit would otherwise fail in turn.
	# gettext is checked in generate_unit instead: only the two units shipping
	# .po files need it.
	for tool in php wp; do
		if ! type -P "$tool" > /dev/null; then
			echo "ERROR: '$tool' not found on PATH (PHP and WP-CLI are required; see i18n.yml for the CI install)" >&2
			exit 2
		fi
	done
fi

# ---------------------------------------------------------------------------
# Per-unit resolution. Sets: UNIT_ROOT (the directory make-pot runs in),
# UNIT_DOMAIN, UNIT_POT (repo-relative), UNIT_LANGDIR (repo-relative),
# UNIT_EXCLUDE (make-pot --exclude list).
# ---------------------------------------------------------------------------
resolve_unit() {
	local unit="$1"
	UNIT_ROOT="$unit"
	# release/ holds gitignored zip staging from `release:archive`. It is
	# absent in CI checkouts, but present in local working copies, where
	# make-pot would otherwise extract every string a second time from the
	# staged copy under release/<name>/.
	#
	# Do NOT add `src` here to match the classic theme below. It looks like the
	# same fix and is not: the theme's src is js/src, JS source only, whereas
	# newspack-blocks/src is a mixed tree holding 90-odd runtime PHP references
	# (src/block-patterns/*.php and friends) alongside its JS. Excluding it
	# drops 75 entries, every block-pattern title among them, out of the
	# template. The dead src-keyed JSON that make-json emits for such units is
	# inert: WordPress resolves translation JSON by md5 of the enqueued script
	# path, so a file keyed to a source path is simply never opened.
	UNIT_EXCLUDE="release"

	if [ "$unit" = "themes/newspack-theme" ]; then
		# The classic theme is nested one level down; the package root holds
		# the build tooling shared with its five style-variation child themes
		# (which ship no translations of their own; they are cosmetic
		# variants, and the legacy i18n workflow never templated them either).
		UNIT_ROOT="$unit/newspack-theme"
		# --exclude=src: make-json names each JSON translation file after the
		# md5 of the JS path the .po references. With js/src included, the
		# references land on source files instead of the built js/dist bundles
		# WordPress actually enqueues, and the editor never loads the JSON.
		# See https://github.com/Automattic/newspack-theme/pull/2458.
		UNIT_EXCLUDE="src,$UNIT_EXCLUDE"
	fi

	# The text domain comes from the unit's declared header. WordPress
	# defaults a missing Text Domain to the plugin/theme slug, so mirror that
	# for the fallback (newspack-ads declares no header; its gettext calls all
	# use the slug).
	local header_file
	if [ "${unit%%/*}" = "themes" ]; then
		header_file="$UNIT_ROOT/style.css"
	else
		# The main plugin file is the only root-level PHP file with a
		# "Plugin Name:" header, and it is not always <dir>.php
		# (super-cool-ad-inserter-plugin.php, newspack.php). Collect matches
		# and fail loudly on anything but exactly one: silently picking a file
		# here means silently extracting with the wrong domain.
		local f matches=()
		for f in "$UNIT_ROOT"/*.php; do
			if grep -qE '^[[:space:]]*\*?[[:space:]]*Plugin Name:' "$f"; then
				matches+=( "$f" )
			fi
		done
		if [ ${#matches[@]} -ne 1 ]; then
			echo "ERROR: expected exactly one main plugin file in $UNIT_ROOT, found ${#matches[@]}" >&2
			return 1
		fi
		header_file="${matches[0]}"
	fi
	if [ ! -f "$header_file" ]; then
		echo "ERROR: header file not found: $header_file" >&2
		return 1
	fi
	# sed quits at the first match rather than piping to `head -n 1`, which would
	# kill sed with SIGPIPE and turn the substitution into a 141 under pipefail.
	UNIT_DOMAIN=$(sed -nE '/^[[:space:]*]*Text Domain:/{s/^[[:space:]*]*Text Domain:[[:space:]]*//;s/[[:space:]]*$//;p;q;}' "$header_file")
	UNIT_DOMAIN=${UNIT_DOMAIN:-$(basename "$unit")}
	# For a unit with no existing template the domain becomes the filename, so a
	# header reading `Text Domain: ../../x` would send make-pot outside the
	# repository, where neither the restore nor the churn check can reach it.
	if ! [[ "$UNIT_DOMAIN" =~ ^[a-z0-9][a-z0-9-]*$ ]]; then
		echo "ERROR: invalid text domain '$UNIT_DOMAIN' in $header_file" >&2
		return 1
	fi

	UNIT_LANGDIR="$UNIT_ROOT/languages"
	local pots=( "$UNIT_LANGDIR"/*.pot )
	case ${#pots[@]} in
		0 ) UNIT_POT="$UNIT_LANGDIR/$UNIT_DOMAIN.pot" ;;
		1 )
			# Keep the shipped template's filename even where it does not
			# match the text domain: super-cool-ad-inserter ships
			# super-cool-ad-inserter-plugin.pot (named after its pre-monorepo
			# repository) while its runtime domain is scaip. Translation tools
			# discover the template by scanning languages/, so a stable name
			# beats a rename that strands the old file.
			UNIT_POT="${pots[0]}"
			;;
		* )
			echo "ERROR: multiple .pot files in $UNIT_LANGDIR; cannot pick a template" >&2
			return 1
			;;
	esac
}

# ---------------------------------------------------------------------------
# Generation for one resolved unit. Runs inside `( set -e; ... )` so the first
# failing command aborts the whole unit, which the caller then restores.
# ---------------------------------------------------------------------------
generate_unit() {
	local wp_bin
	wp_bin=$(type -P wp)
	local pot_rel="${UNIT_POT#"$UNIT_ROOT/"}"

	mkdir -p "$UNIT_LANGDIR"

	# Before make-pot, not before the .po loop below, so a missing gettext fails
	# the unit without first doing the expensive extraction and reverting it.
	local pos=( "$UNIT_LANGDIR"/*.po )
	if [ ${#pos[@]} -gt 0 ]; then
		local tool
		for tool in msgmerge msgfmt; do
			if ! type -P "$tool" > /dev/null; then
				echo "ERROR: '$tool' not found on PATH; $UNIT_ROOT ships .po files and needs gettext" >&2
				return 1
			fi
		done
	fi

	# make-pot's JS parser fatals on newspack-blocks under PHP's 128M default; it
	# needs ~200M today and grows with the bundles. The nesting bump is for the
	# same parser, and is a no-op when xdebug is absent.
	(
		cd "$UNIT_ROOT"
		php -d memory_limit=512M -d xdebug.max_nesting_level=512 "$wp_bin" i18n make-pot . \
			"$pot_rel" "--domain=$UNIT_DOMAIN" "--exclude=$UNIT_EXCLUDE"
	)

	local po base
	for po in "$UNIT_LANGDIR"/*.po; do
		base=$(basename "$po")
		(
			cd "$UNIT_LANGDIR"
			msgmerge --quiet "$base" "$(basename "$UNIT_POT")" -o "$base.tmp"
			mv "$base.tmp" "$base"
			msgfmt "$base" -o "${base%.po}.mo"
			php -d memory_limit=512M -d xdebug.max_nesting_level=512 "$wp_bin" i18n make-json --no-purge "$base" .
		)
	done
}

# Changed lines under a path, ignoring the two headers every regeneration
# rewrites whether or not a string moved: POT-Creation-Date always, and
# Project-Id-Version whenever the unit released since the last run, because
# regeneration precedes the version bump. Filtering them is exact, where a
# line-count threshold would have to track how many volatile headers exist.
#
# `git diff HEAD`, not `git diff`: the latter reads staged work as no change.
# awk consumes all its input, so pipefail has no early-exit reader to trip on.
substantive_changes_in() {
	git diff HEAD -U0 -- "$1" | awk '
		/^(\+\+\+ |--- )/ { next }
		/^[+-]"(POT-Creation-Date|Project-Id-Version):/ { next }
		/^[+-]/ { n++ }
		END { print n + 0 }
	'
}

untracked_in() {
	git ls-files --others --exclude-standard -- "$1"
}

restore_langdir() {
	local rc=0
	# HEAD, not the index: `git checkout -- <path>` would hand back whatever was
	# staged rather than the committed state this script promises.
	#
	# The ls-files guard covers a new unit whose languages/ holds nothing tracked
	# yet, where git exits non-zero on the unmatched pathspec. Swallowing errors
	# outright instead would hide a real one, a locked index say, and claim a
	# restore that never happened over a half-written template.
	if [ -n "$(git ls-files -- "$1")" ]; then
		git checkout --quiet HEAD -- "$1" || rc=$?
	fi
	# No -d: given a pathspec, clean already removes files inside untracked
	# directories, so this reaches a languages/ the script created itself.
	if [ "$rc" -eq 0 ] && [ -n "$(untracked_in "$1")" ]; then
		git clean -fq -- "$1" || rc=$?
	fi
	if [ "$rc" -ne 0 ]; then
		echo "ERROR: could not restore $1 (git exited $rc); it may hold partially generated files" >&2
	fi
	return "$rc"
}

# ---------------------------------------------------------------------------
# Dry run: print the resolution table and exit.
# ---------------------------------------------------------------------------
if $DRY_RUN; then
	printf '%-42s %-30s %-62s %-14s %s\n' "UNIT" "DOMAIN" "POT" "EXCLUDE" "PO"
	failed=false
	for unit in "${UNITS[@]}"; do
		if ! resolve_unit "$unit"; then
			failed=true
			continue
		fi
		pos=( "$UNIT_LANGDIR"/*.po )
		printf '%-42s %-30s %-62s %-14s %s\n' "$unit" "$UNIT_DOMAIN" "$UNIT_POT" "$UNIT_EXCLUDE" "${#pos[@]}"
	done
	if $failed; then
		exit 1
	fi
	exit 0
fi

# ---------------------------------------------------------------------------
# Main loop.
# ---------------------------------------------------------------------------
FAILED=()
SKIPPED=()
UPDATED=()
DIRTY=()
RESTORE_FAILED=()

for unit in "${UNITS[@]}"; do
	if ! resolve_unit "$unit"; then
		FAILED+=( "$unit" )
		continue
	fi
	# Every path below can end in restore_langdir, whose `git clean` would delete
	# a hand-merged .po with no prompt and nothing in the reflog. CI checkouts
	# are always clean, so this only ever fires locally.
	if [ -n "$(git status --porcelain -- "$UNIT_LANGDIR")" ]; then
		echo "==> $unit skipped: $UNIT_LANGDIR has uncommitted changes"
		DIRTY+=( "$unit" )
		continue
	fi
	echo "==> $unit (domain: $UNIT_DOMAIN)"
	set +e
	( set -e; generate_unit )
	status=$?
	set -e
	if [ "$status" -ne 0 ]; then
		# Restore the committed translation files: shipping yesterday's
		# template is the status quo, shipping a half-written one is not.
		if ! restore_langdir "$UNIT_LANGDIR"; then
			RESTORE_FAILED+=( "$unit" )
		fi
		FAILED+=( "$unit" )
		continue
	fi
	if [ -z "$(untracked_in "$UNIT_LANGDIR")" ] && [ "$(substantive_changes_in "$UNIT_LANGDIR")" -eq 0 ]; then
		if ! restore_langdir "$UNIT_LANGDIR"; then
			RESTORE_FAILED+=( "$unit" )
			FAILED+=( "$unit" )
			continue
		fi
		SKIPPED+=( "$unit" )
	else
		UPDATED+=( "$unit" )
	fi
done

echo
echo "Updated:   ${UPDATED[*]:-none}"
echo "Unchanged: ${SKIPPED[*]:-none} (header-only churn reverted)"
echo "Skipped:   ${DIRTY[*]:-none} (uncommitted changes in languages/)"
echo "Failed:    ${FAILED[*]:-none}"

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
	{
		echo "### Translation files"
		echo
		echo "- Updated: ${UPDATED[*]:-none}"
		echo "- Unchanged (churn reverted): ${SKIPPED[*]:-none}"
		echo "- Skipped (uncommitted changes): ${DIRTY[*]:-none}"
		echo "- Failed (restored to committed state): ${FAILED[*]:-none}"
	} >> "$GITHUB_STEP_SUMMARY"
fi

# For the calling workflow to put in the Slack release message. A ::warning on
# a green run is read by nobody, which is how a permanently broken unit would go
# unnoticed for months.
if [ -n "${GITHUB_ENV:-}" ]; then
	echo "I18N_FAILED_UNITS=${FAILED[*]:-}" >> "$GITHUB_ENV"
fi

if [ ${#RESTORE_FAILED[@]} -gt 0 ]; then
	# Louder than the others on purpose: these units may be sitting on
	# partially generated files that nothing has put back.
	echo "::error title=i18n::Could not restore: ${RESTORE_FAILED[*]} (may hold partially generated files)"
fi

if [ ${#FAILED[@]} -gt 0 ] || [ ${#DIRTY[@]} -gt 0 ]; then
	if [ ${#FAILED[@]} -gt 0 ]; then
		echo "::warning title=i18n::Translation regeneration failed for: ${FAILED[*]} (committed files restored)"
	fi
	if [ ${#DIRTY[@]} -gt 0 ]; then
		echo "::warning title=i18n::Skipped (uncommitted changes in languages/): ${DIRTY[*]}"
	fi
	if $STRICT; then
		exit 1
	fi
fi
