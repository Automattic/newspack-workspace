#!/bin/bash
# One-time setup for passwordless networking management on macOS.
#
# Installs the newspack-manage-host wrapper to /usr/local/bin and creates
# a sudoers drop-in that allows the current user to run it without a password.
# After this, `n start` and `n env create` can set up loopback aliases and
# /etc/hosts entries without interactive sudo prompts.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
WRAPPER_SRC="$SCRIPT_DIR/newspack-manage-host"
WRAPPER_DEST="/usr/local/bin/newspack-manage-host"
SUDOERS_FILE="/etc/sudoers.d/newspack-manage-host"

if [[ "$(uname)" != "Darwin" ]]; then
    echo "This setup is only needed on macOS. Linux routes all 127.x.x.x by default."
    exit 0
fi

if [[ ! -f "$WRAPPER_SRC" ]]; then
    echo "Error: wrapper script not found at $WRAPPER_SRC" >&2
    exit 1
fi

echo "This will:"
echo "  1. Copy newspack-manage-host to $WRAPPER_DEST (owned by root)"
echo "  2. Create $SUDOERS_FILE so '$(whoami)' can run it without a password"
echo ""
echo "After this, 'n start' and 'n env create' will manage networking automatically."
echo ""
read -p "Continue? (Y/n): " choice
choice=$(echo "$choice" | tr '[:upper:]' '[:lower:]')
if [[ "$choice" == "n" ]]; then
    echo "Aborted."
    exit 0
fi

echo "Installing wrapper script (requires sudo)..."
sudo cp "$WRAPPER_SRC" "$WRAPPER_DEST"
sudo chown root:wheel "$WRAPPER_DEST"
sudo chmod 755 "$WRAPPER_DEST"

echo "Creating sudoers rule..."
CURRENT_USER="$(whoami)"
sudo tee "$SUDOERS_FILE" > /dev/null <<EOF
# Allow $CURRENT_USER to manage loopback aliases and /etc/hosts for Newspack Docker.
$CURRENT_USER ALL=(root) NOPASSWD: $WRAPPER_DEST *
EOF
sudo chmod 440 "$SUDOERS_FILE"

# Validate the sudoers file.
if sudo visudo -cf "$SUDOERS_FILE"; then
    echo ""
    echo "Done! Networking setup will now work without password prompts."
    echo "To undo, run: sudo rm $SUDOERS_FILE $WRAPPER_DEST"
else
    echo "Error: sudoers file is invalid. Removing it." >&2
    sudo rm -f "$SUDOERS_FILE"
    exit 1
fi
