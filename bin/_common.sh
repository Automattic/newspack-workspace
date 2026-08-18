#!/bin/bash

# Derived unless the caller already set it to a real workspace root: `n` resolves
# symlinks with `pwd -P` and that form has to survive being sourced here. An
# inherited value is checked rather than trusted — bin/worktree.sh removes trees
# under $NABSPATH, so a root arriving from the environment would delete elsewhere.
[[ -n "${NABSPATH:-}" && -f "$NABSPATH/n" ]] ||
    NABSPATH="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Names that become git branches and path components. Slashes are allowed here
# and not in validate_env_name, since `fix/some-thing` is the normal branch
# shape. The first character may not be a dash, so an option is never taken as
# a name: bin/worktree.sh and the --worktree parsing in bin/env.sh both read
# these positionally. A leading dot or underscore stays legal because branches
# like _pr738 are in use, and git rejects the refnames that are truly invalid.
validate_name() {
    if [[ ! "$1" =~ ^[a-zA-Z0-9._][a-zA-Z0-9._/-]*$ ]] || [[ "$1" == *..* ]] || [[ "$1" == /* ]]; then
        echo "Error: invalid $2 '$1' (must not start with '-'; only alphanumeric, dots, hyphens, underscores, slashes allowed; no '..' or leading '/')"
        exit 1
    fi
}

# Stricter validation for env names — no slashes (Docker rejects them in
# container/service names), and no leading dash. The dash rule is what stops an
# option being read as a name: bin/env.sh takes the name positionally, so
# without it `n env create --help` validates cleanly and creates an environment
# called "--help" instead of printing usage. It excludes a leading dash and
# nothing else, deliberately. This validator also gates `up`, `down` and
# `destroy`, so a rule that rejected leading dots or underscores would strand an
# environment created under an older, laxer one — unmanageable and removable
# only by hand.
validate_env_name() {
    # The `..` clause matches validate_name's. `n env destroy ..` would otherwise
    # validate and reach `rm -rf "$NABSPATH/envs/.."`, i.e. the workspace root.
    # POSIX rm refuses a trailing `.` or `..` component, so that is not a live
    # escape today -- but the guard then lives in rm rather than here, and moves
    # out from under us the moment a call site builds the path differently.
    if [[ ! "$1" =~ ^[a-zA-Z0-9._][a-zA-Z0-9._-]*$ ]] || [[ "$1" == *..* ]]; then
        echo "Error: invalid environment name '$1' (must not start with '-' or contain '..'; only alphanumeric, dots, hyphens, underscores allowed)"
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
