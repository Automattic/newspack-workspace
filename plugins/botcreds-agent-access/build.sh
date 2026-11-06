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
VERSION=$(sed -n "s/^define( 'AGENT_ACCESS_VERSION', '\([^']*\)' );/\1/p" "$PLUGIN_DIR/botcreds-agent-access.php")
if [[ -z "$VERSION" || ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][a-zA-Z0-9.-]+)?$ ]]; then
  echo "Invalid or missing plugin version" >&2
  exit 1
fi
OUT="$HOME/Public/${PLUGIN_SLUG}-${VERSION}.zip"
TMP=$(mktemp -d)

echo "Building ${PLUGIN_SLUG} v${VERSION}..."

# Copy into a correctly-named subfolder
mkdir "$TMP/$PLUGIN_SLUG"
for entry in assets includes botcreds-agent-access.php uninstall.php index.php LICENSE readme.txt; do
  cp -R "$PLUGIN_DIR/$entry" "$TMP/$PLUGIN_SLUG/"
done

# Build zip from the parent so the folder name is the root entry
cd "$TMP"
zip -r "$OUT" "$PLUGIN_SLUG" -x "*.DS_Store"

chmod 644 "$OUT"
rm -rf "$TMP"

echo "Done: $OUT"
unzip -l "$OUT" | grep "\.php$" | head -3
