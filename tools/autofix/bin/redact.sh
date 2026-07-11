#!/bin/bash
# redact.sh — scan-and-block redaction scanner for outward artifacts (PR
# bodies, Linear comments, committed fixtures). Never edits files: it only
# scans and reports. Exit 0 = clean, exit 1 = findings.
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"

[ "${1:-}" = "scan" ] || die "usage: redact.sh scan <file>..."
shift
[ $# -ge 1 ] || die "no files given"

allow="${AUTOFIX_REDACT_ALLOWLIST:-}"
found=0

is_allowlisted() { # line
  local line="$1" a
  [ -n "$allow" ] && [ -f "$allow" ] || return 1
  while IFS= read -r a; do
    [ -n "$a" ] || continue
    case "$line" in *"$a"*) return 0 ;; esac
  done < "$allow"
  return 1
}

emit() { # file class matches(file:line:text lines on stdin)
  local file="$1" class="$2" line
  while IFS= read -r line; do
    [ -n "$line" ] || continue
    is_allowlisted "$line" && continue
    printf '%s: [%s] %s\n' "$file" "$class" "$(printf '%s' "$line" | head -c 160)"
    found=1
  done
}

scan_class() { # file class pattern
  local file="$1" class="$2" pat="$3"
  emit "$file" "$class" < <(grep -nEi "$pat" "$file" 2>/dev/null || true)
}

for f in "$@"; do
  [ -f "$f" ] || continue
  scan_class "$f" secret-store 'mc\.a8c\.com/secret-store'
  scan_class "$f" private-key '\-\-\-\-\-BEGIN [A-Z ]*PRIVATE KEY'
  scan_class "$f" aws-key 'AKIA[0-9A-Z]{16}'
  scan_class "$f" stripe-live 'sk_live_[0-9a-zA-Z]{10,}'
  scan_class "$f" credential-assign "(api[_-]?key|secret|token|passw(or)?d)['\"]?[[:space:]]*[:=][[:space:]]*['\"][^'\"]{8,}"
  emit "$f" email < <(grep -nE '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' "$f" 2>/dev/null \
    | grep -viE '@example\.com|@[A-Za-z0-9.-]+\.test|noreply@|@wordpress\.com' || true)
done

exit "$found"
