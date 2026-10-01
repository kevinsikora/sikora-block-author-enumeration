#!/usr/bin/env bash
# Build an installable WordPress plugin zip in the project root.
set -euo pipefail

PLUGIN_SLUG="sikora-block-author-enumeration"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ZIP_NAME="${PLUGIN_SLUG}.zip"
ZIP_PATH="${ROOT_DIR}/${ZIP_NAME}"
STAGING_DIR="$(mktemp -d "${TMPDIR:-/tmp}/${PLUGIN_SLUG}.XXXXXX")"
PLUGIN_DIR="${STAGING_DIR}/${PLUGIN_SLUG}"

cleanup() {
	rm -rf "${STAGING_DIR}"
}
trap cleanup EXIT

mkdir -p "${PLUGIN_DIR}"

# Files shipped in the installable plugin package.
cp "${ROOT_DIR}/sikora-block-author-enumeration.php" "${PLUGIN_DIR}/"
cp "${ROOT_DIR}/readme.txt" "${PLUGIN_DIR}/"

rm -f "${ZIP_PATH}"

# Zip from the staging parent so the archive root is the plugin folder.
(
	cd "${STAGING_DIR}"
	zip -X -r "${ZIP_PATH}" "${PLUGIN_SLUG}"
)

echo "Created ${ZIP_PATH}"
