#!/bin/bash
set -o pipefail
FAILURES=0
assert_eq() { # expected actual label
  if [ "$1" = "$2" ]; then printf 'ok    %s\n' "$3"; else
    printf 'FAIL  %s\n  expected: %s\n  actual:   %s\n' "$3" "$1" "$2"; FAILURES=$((FAILURES+1)); fi
}
assert_contains() { # haystack needle label
  case "$1" in *"$2"*) printf 'ok    %s\n' "$3" ;;
  *) printf 'FAIL  %s\n  missing: %s\n  in: %s\n' "$3" "$2" "$1"; FAILURES=$((FAILURES+1)) ;; esac
}
assert_fail() { # label
  printf 'FAIL  %s\n' "$1"; FAILURES=$((FAILURES+1))
}
finish() { [ "$FAILURES" -eq 0 ] || { printf '%d failure(s)\n' "$FAILURES"; exit 1; }; }
