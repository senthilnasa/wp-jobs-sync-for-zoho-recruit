#!/usr/bin/env bash
#
# Build a distributable ZIP of the plugin.
#
# The archive contains exactly what a site needs to run: no dev tooling, no
# node_modules, no tests, no repository metadata. Anything listed in .distignore
# is left out.
#
# Usage: bash bin/build-zip.sh

set -euo pipefail

SLUG="jobs-sync-for-zoho-recruit"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="${ROOT}/dist"
STAGE="${DIST}/${SLUG}"

cd "${ROOT}"

VERSION="$(grep -m1 -E "^ \* Version:" "${SLUG}.php" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"

if [[ -z "${VERSION}" ]]; then
	echo "Could not read the plugin version from ${SLUG}.php" >&2
	exit 1
fi

echo "Building ${SLUG} ${VERSION}"

# The built block assets must exist before packaging.
if [[ ! -f "blocks/jobs/build/index.js" ]]; then
	echo "blocks/jobs/build/index.js is missing. Run 'npm run build' first." >&2
	exit 1
fi

rm -rf "${DIST}"
mkdir -p "${STAGE}"

EXCLUDES=()

while IFS= read -r line; do
	# Skip blank lines and comments.
	[[ -z "${line}" || "${line}" == \#* ]] && continue
	EXCLUDES+=( "--exclude=${line}" )
done < .distignore

rsync -a "${EXCLUDES[@]}" --exclude="dist" ./ "${STAGE}/"

cd "${DIST}"
zip -rq "${SLUG}-${VERSION}.zip" "${SLUG}"
rm -rf "${STAGE}"

echo "Created dist/${SLUG}-${VERSION}.zip"
