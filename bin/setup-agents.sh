#!/usr/bin/env bash
#
# Set up AI agent tooling for Newspack development.
# Installs recommended plugins at user scope so they are
# available across all repositories in the workspace.
#
# Usage: n setup-agents

set -euo pipefail

# ── Configuration ──────────────────────────────────────────────

# Claude Code marketplaces (GitHub owner/repo)
marketplaces=(
  Automattic/newspack-devkit
  kenryu42/cc-marketplace
  skills-directory/skill-codex
  ChromeDevTools/chrome-devtools-mcp
)

# Claude Code plugins (plugin@marketplace, installed at user scope)
plugins=(
  newspack@newspack-devkit
  superpowers@claude-plugins-official
  context7@claude-plugins-official
  linear@claude-plugins-official
  figma@claude-plugins-official
  safety-net@cc-marketplace
  skill-codex@skill-codex
  chrome-devtools-mcp@chrome-devtools-plugins
)

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

echo ""
echo -e "${cyan}📦 Adding marketplaces...${reset}"
for m in "${marketplaces[@]}"; do
  echo -e "   ${dim}${m}${reset}"
  claude plugin marketplace add "$m" 2>/dev/null || true
done

echo ""
echo -e "${cyan}🔌 Installing plugins...${reset}"
for p in "${plugins[@]}"; do
  echo -e "   ${dim}${p}${reset}"
  if claude plugin install "$p" --scope user 2>/dev/null; then
    echo -e "   ${green}✓${reset} ${p}"
  else
    echo -e "   ${yellow}⚠${reset} ${p} ${dim}(may already be installed)${reset}"
  fi
done

echo ""
echo -e "${green}✅ Done!${reset} Restart Claude Code to load the new plugins and MCP servers."
echo ""
echo -e "${yellow}💡 Optional:${reset} set environment variables in your shell profile (~/.zshrc):"
echo -e "   ${dim}export CONTEXT7_API_KEY=\"ctx7sk-...\"  # Higher rate limits (free key at context7.com/dashboard)${reset}"
echo ""
