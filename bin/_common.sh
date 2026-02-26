#!/bin/bash

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

validate_port() {
    if [[ ! "$1" =~ ^[0-9]+$ ]] || [[ "$1" -lt 1 || "$1" -gt 65535 ]]; then
        echo "Error: invalid port '$1' (must be a number between 1 and 65535)"
        exit 1
    fi
}
