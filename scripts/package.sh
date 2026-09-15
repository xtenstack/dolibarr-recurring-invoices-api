#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"
VERSION="1.0.5"
ZIP_NAME="dolirecurring-${VERSION}.zip"

echo "==> Packaging Dolibarr Recurring Invoices API Module ($ZIP_NAME)..."
cd "$REPO_DIR"

rm -f "$ZIP_NAME"

# Dolibarr module installer requires naming syntax: modulename-x[.y.z].zip, where
# modulename is the zip's top-level folder. The folder is dolirecurring (not
# dolirecurringapi) because Dolibarr's API router strips a trailing "api" from the
# module name when locating its folder (getModuleDirForApiClass).
zip -r "$ZIP_NAME" dolirecurring -x "*.DS_Store" "*__MACOSX*"


echo "==> Successfully packaged:"
echo "    - $REPO_DIR/$ZIP_NAME"
ls -lh "$ZIP_NAME"
