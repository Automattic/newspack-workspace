#!/usr/bin/env bash
#
# Bootstrap AI agent tooling for Newspack contributors.
#
# For Claude Code: reads `extraKnownMarketplaces` and `enabledPlugins`
# from .claude/settings.json and:
#
#   1. Registers each declared marketplace.
#   2. Installs each plugin at user scope so it loads in every Claude
#      Code session, not just inside this workspace.
#   3. Removes any leftover project scope plugin record.
#
# Safe to re-run: existing installs are skipped; new entries in
# .claude/settings.json are picked up on the next run.
#
# Usage: n setup-agents

set -euo pipefail

# ── Resolve workspace root ────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
SETTINGS_FILE="$ROOT_DIR/.claude/settings.json"

if [[ ! -f "$SETTINGS_FILE" ]]; then
  echo "Error: $SETTINGS_FILE not found" >&2
  exit 1
fi

if ! command -v jq &>/dev/null; then
  echo "Error: jq is required but not installed" >&2
  exit 1
fi

# ── Colors ─────────────────────────────────────────────────────

bold="\033[1m"
dim="\033[2m"
green="\033[32m"
yellow="\033[33m"
cyan="\033[36m"
reset="\033[0m"

# ── Claude Code ────────────────────────────────────────────────

echo ""
echo -e "${bold}🤖 Setting up AI agent tooling...${reset}"

# Add marketplaces from extraKnownMarketplaces (github sources only)
echo ""
echo -e "${cyan}📦 Adding marketplaces...${reset}"
jq -r '.extraKnownMarketplaces // {} | to_entries[] | select(.value.source.source == "github") | .value.source | if .ref then "\(.repo)#\(.ref)" else .repo end' "$SETTINGS_FILE" | while read -r m; do
  echo -e "   ${dim}${m}${reset}"
  claude plugin marketplace add "$m" 2>/dev/null || true
done

# Detect legacy project-scope copies. Abort on query failure so we don't silently skip cleanup.
if ! plugin_list_json=$(claude plugin list --json); then
  echo "==> Could not query installed plugins. See error above." >&2
  exit 1
fi
needs_cleanup=$(jq -r --arg path "$ROOT_DIR" '[.[] | select(.scope == "project" and .projectPath == $path)] | length' <<< "$plugin_list_json")

# Project-scope uninstall also strips entries from `enabledPlugins`, our source
# of truth, so snapshot the file and let the trap restore it on any exit.
if [[ "${needs_cleanup:-0}" -gt 0 ]]; then
  settings_backup="$(mktemp "${SETTINGS_FILE}.bak.XXXXXX")"
  cp "$SETTINGS_FILE" "$settings_backup"
  trap 'mv -f "$settings_backup" "$SETTINGS_FILE"' EXIT; trap 'exit 130' INT; trap 'exit 143' TERM HUP
fi

echo ""
echo -e "${cyan}🔌 Installing plugins...${reset}"
jq -r '.enabledPlugins // {} | to_entries[] | select(.value == true) | .key' "$SETTINGS_FILE" | while read -r p; do
  echo -e "   ${dim}${p}${reset}"
  if [[ "${needs_cleanup:-0}" -gt 0 ]]; then
    claude plugin uninstall "$p" --scope project -y >/dev/null 2>&1 || true
  fi
  claude plugin install "$p" --scope user
done

echo ""
echo -e "${green}✅ Done!${reset} Restart Claude Code to load the new plugins and MCP servers."
echo ""
echo -e "${yellow}💡 Optional:${reset} set environment variables in your shell profile (~/.zshrc):"
echo -e "   ${dim}export CONTEXT7_API_KEY=\"ctx7sk-...\"  # Higher rate limits (free key at context7.com/dashboard)${reset}"
echo ""
