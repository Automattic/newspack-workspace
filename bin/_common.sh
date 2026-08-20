#!/bin/bash

# Derived unless the caller already set it to a real workspace root: `n` resolves
# symlinks with `pwd -P` and that form has to survive being sourced here. An
# inherited value is checked rather than trusted — bin/worktree.sh removes trees
# under $NABSPATH, so a root arriving from the environment would delete elsewhere.
[[ -n "${NABSPATH:-}" && -f "$NABSPATH/n" ]] ||
    NABSPATH="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

validate_name() {
    if [[ ! "$1" =~ ^[a-zA-Z0-9._/-]+$ ]] || [[ "$1" == *..* ]] || [[ "$1" == /* ]]; then
        echo "Error: invalid $2 '$1' (only alphanumeric, dots, hyphens, underscores, slashes allowed; no '..' or leading '/')"
        exit 1
    fi
}

# Stricter validation for env names — no slashes (Docker rejects them in container/service names)
validate_env_name() {
    if [[ ! "$1" =~ ^[a-zA-Z0-9._-]+$ ]]; then
        echo "Error: invalid environment name '$1' (only alphanumeric, dots, hyphens, underscores allowed)"
        exit 1
    fi
}

validate_domain() {
    if [[ ! "$1" =~ ^[a-zA-Z0-9.-]+$ ]] || [[ ${#1} -gt 253 ]]; then
        echo "Error: invalid domain '$1'"
        exit 1
    fi
}

validate_port() {
    if [[ ! "$1" =~ ^[0-9]+$ ]] || [[ "$1" -lt 1 || "$1" -gt 65535 ]]; then
        echo "Error: invalid port '$1' (must be a number between 1 and 65535)"
        exit 1
    fi
}

# Is this loopback alias up on lo0? (macOS only — Linux routes all 127.x.x.x by
# default.) The address is compared whole, as a field of its own: loopback
# addresses are prefixes of each other (127.0.0.2 sits inside 127.0.0.24) and the
# low ones are recycled while higher envs stay up, so a substring test reports a
# free address as already aliased — and the env then dies binding a port on an
# address the host does not have. Returns 0 (shell true) when the address is up;
# `found` is unset, and so awk-numeric 0, when it is not. An unreadable lo0 is
# therefore reported absent, which makes the caller create the alias.
lo0_alias_exists() {
    ifconfig lo0 2>/dev/null | awk -v ip="$1" '$1 == "inet" && $2 == ip { found = 1 } END { exit !found }'
}

# Whether a project's vendor/ was installed with its dev dependencies.
#
# `n env up` provisions vendor/ with --no-dev (see ensure-vendor.sh): enough to
# activate a plugin, but without PHPUnit or the Yoast polyfills the WordPress
# test bootstrap requires. Both kinds of install write vendor/autoload.php, so
# its presence says nothing about whether the tests can run — composer's own
# record of which install produced the directory, installed.json's top-level
# "dev" flag, is what separates them.
#
# Read without a JSON parser: this is sourced on the host, in the containers and
# in CI, and no single parser is present in all three. Two things make the
# plain-text read safe. The value must be a bare boolean alone on its line,
# which skips the `"dev": "Development related task"` entries real packages
# carry in their metadata; and of the lines surviving that, the last is the
# top-level flag, because composer writes it after the packages array closes.
# Leading whitespace is not matched, so re-indenting the file changes nothing.
#
# The two unhappy paths answer differently, on purpose:
#
# - No readable installed.json means nothing was installed here, so the tests
#   cannot run whatever else is true. Report absent and let the caller explain
#   how to fix it.
# - A file that exists but yields no recognisable flag means composer wrote a
#   shape this function does not know — a format change, say. Report present.
#   Guessing "absent" there would block every project at once, with no way past
#   it; reporting present costs only a return to the bootstrap error this guard
#   was added to replace, for whoever hits it first.
project_has_dev_deps() {
    local installed="$1/vendor/composer/installed.json"
    [ -r "$installed" ] || return 1

    local flag
    flag=$(grep -E '^[[:space:]]*"dev": *(true|false),?$' "$installed" | tail -1)
    [ -n "$flag" ] || return 0

    [[ "$flag" == *true* ]]
}

# Logging helpers — mirror the colored output used by bin/site-setup.sh.
NP_RED='\033[0;31m'
NP_GREEN='\033[0;32m'
NP_YELLOW='\033[1;33m'
NP_BLUE='\033[0;34m'
NP_NC='\033[0m'

log_info() { echo -e "${NP_BLUE}[INFO]${NP_NC} ${1}"; }
log_success() { echo -e "${NP_GREEN}[SUCCESS]${NP_NC} ${1}"; }
log_warning() { echo -e "${NP_YELLOW}[WARNING]${NP_NC} ${1}"; }
log_error() { echo -e "${NP_RED}[ERROR]${NP_NC} ${1}"; }
