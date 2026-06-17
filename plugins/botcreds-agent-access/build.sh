#!/usr/bin/env bash
# build.sh — package botcreds-agent-access for WordPress upload
# Usage: ./build.sh
# Output: ~/Public/botcreds-agent-access-<version>.zip
#
# The zip always contains a single top-level folder named exactly
# "botcreds-agent-access" so WordPress replaces rather than installs
# a new plugin.

set -euo pipefail

PLUGIN_SLUG="botcreds-agent-access"
PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
VERSION=$(grep "define( 'AGENT_ACCESS_VERSION'" "$PLUGIN_DIR/botcreds-agent-access.php" | grep -o "'[0-9.]*'" | tr -d "'")
OUT="$HOME/Public/${PLUGIN_SLUG}-${VERSION}.zip"
TMP=$(mktemp -d)

echo "Building ${PLUGIN_SLUG} v${VERSION}..."

# Copy into a correctly-named subfolder
cp -r "$PLUGIN_DIR" "$TMP/$PLUGIN_SLUG"
rm -rf "$TMP/$PLUGIN_SLUG/.git"

# Build zip from the parent so the folder name is the root entry
cd "$TMP"
zip -r "$OUT" "$PLUGIN_SLUG" -x "*.DS_Store"

chmod 644 "$OUT"
rm -rf "$TMP"

echo "Done: $OUT"
unzip -l "$OUT" | grep "\.php$" | head -3
