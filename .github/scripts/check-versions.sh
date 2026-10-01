#!/usr/bin/env bash
# Fails unless every place that states the plugin version agrees, and readme.txt
# has a changelog entry for it. With an argument (a release tag), the tag must
# match as well.
set -euo pipefail

cd "$(dirname "$0")/../.."

header=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' straumur-payments-for-woocommerce.php | head -n1)
constant=$(sed -n "s/.*define([[:space:]]*'STRAUMUR_PAYMENTS_VERSION',[[:space:]]*'\([^']*\)'.*/\1/p" straumur-payments-for-woocommerce.php | head -n1)
stable=$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' readme.txt | head -n1)
package=$(node -p "require('./package.json').version")

echo "Plugin header:       ${header}"
echo "Version constant:    ${constant}"
echo "readme Stable tag:   ${stable}"
echo "package.json:        ${package}"

status=0
for value in "$constant" "$stable" "$package"; do
	if [ "$value" != "$header" ]; then
		status=1
	fi
done
if [ "$status" -ne 0 ]; then
	echo "::error::Version numbers do not agree."
fi

if ! grep -qx "= ${header} =" readme.txt; then
	echo "::error file=readme.txt::No changelog entry '= ${header} =' in readme.txt."
	status=1
fi

if [ "$#" -gt 0 ]; then
	echo "Release tag:         $1"
	if [ "$1" != "$header" ]; then
		echo "::error::Tag $1 does not match plugin version ${header}."
		status=1
	fi
fi

exit "$status"
