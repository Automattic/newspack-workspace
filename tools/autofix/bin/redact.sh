#!/bin/bash
# redact.sh — scan-and-block redaction scanner for outward artifacts (PR
# bodies, Linear comments, committed fixtures). Never edits files: it only
# scans and reports. Exit 0 = clean, exit 1 = findings.
#
# Decisions are TOKEN-level, not line-level: each matched fragment is tested
# against the allowlist (and, for emails, the exemption regex) individually,
# so a line mixing exempt and non-exempt content still blocks.
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"

[ "${1:-}" = "scan" ] || die "usage: redact.sh scan <file>..."
shift
[ $# -ge 1 ] || die "no files given"

allow="${AUTOFIX_REDACT_ALLOWLIST:-}"
found=0

EMAIL_RE='[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}'
# Exemptions applied to an individual email token (not the whole line).
EMAIL_EXEMPT_RE='@example\.com$|\.test$|^noreply@|(@|\.)wordpress\.com$'

is_allowlisted() { # fragment
  local frag="$1" a
  [ -n "$allow" ] && [ -f "$allow" ] || return 1
  while IFS= read -r a; do
    [ -n "$a" ] || continue
    case "$frag" in *"$a"*) return 0 ;; esac
  done < "$allow"
  return 1
}

report() { # file class lineno fragment
  printf '%s: [%s] %s: %s\n' "$1" "$2" "$3" "$(printf '%s' "$4" | head -c 160)"
  found=1
}

scan_class() { # file class pattern — one finding per surviving matched fragment
  local file="$1" class="$2" pat="$3" m lineno frag
  while IFS= read -r m; do
    [ -n "$m" ] || continue
    lineno="${m%%:*}" frag="${m#*:}"
    is_allowlisted "$frag" && continue
    report "$file" "$class" "$lineno" "$frag"
  done < <(grep -onEi "$pat" "$file" 2>/dev/null || true)
}

scan_email() { # file — token-level exemption + allowlist
  local file="$1" m lineno tok
  while IFS= read -r m; do
    [ -n "$m" ] || continue
    lineno="${m%%:*}" tok="${m#*:}"
    printf '%s' "$tok" | grep -qiE "$EMAIL_EXEMPT_RE" && continue
    is_allowlisted "$tok" && continue
    report "$file" email "$lineno" "$tok"
  done < <(grep -onE "$EMAIL_RE" "$file" 2>/dev/null || true)
}

for f in "$@"; do
  [ -f "$f" ] || continue
  scan_class "$f" secret-store 'mc\.a8c\.com/secret-store'
  scan_class "$f" private-key '\-\-\-\-\-BEGIN [A-Z ]*PRIVATE KEY'
  scan_class "$f" aws-key 'AKIA[0-9A-Z]{16}'
  scan_class "$f" stripe-live 'sk_live_[0-9a-zA-Z]{10,}'
  scan_class "$f" credential-assign "(api[_-]?key|secret|token|passw(or)?d)['\"]?[[:space:]]*[:=][[:space:]]*['\"][^'\"]{8,}"
  scan_email "$f"
done

exit "$found"
