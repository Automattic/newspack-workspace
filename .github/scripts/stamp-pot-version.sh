#!/usr/bin/env bash
# Write the released version into each .pot's Project-Id-Version. Runs from a
# package directory as the version-bump callback, so the header is already
# bumped and the zip not yet built. Must never fail the release.
set -u

version=$(sed -nE '/^[[:space:]*]*Version:/{s/^[[:space:]*]*Version:[[:space:]]*([0-9][^[:space:]]*).*/\1/p;q;}' "${1:-}")
if [ -z "$version" ]; then
	echo "stamp-pot-version: no Version header in ${1:-<missing arg>}" >&2
	exit 0
fi

find . -path ./node_modules -prune -o -path ./release -prune -o -path '*/languages/*.pot' -print | while read -r pot; do
	sed -E 's/^("Project-Id-Version: .*[^ 0-9])( [0-9][^ ]*)?\\n"$/\1 '"$version"'\\n"/' "$pot" > "$pot.tmp" && mv "$pot.tmp" "$pot"
done
exit 0
