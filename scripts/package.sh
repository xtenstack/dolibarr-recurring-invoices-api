#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"
ZIP_NAME="dolirecurringapi.zip"

echo "==> Packaging Dolibarr Recurring Invoices API Module..."
cd "$REPO_DIR"

rm -f "$ZIP_NAME" "/Users/travissaron/Downloads/$ZIP_NAME"

# Dolibarr expects the zip archive to contain the module root folder 'dolirecurringapi'
zip -r "$ZIP_NAME" dolirecurringapi -x "*.DS_Store" "*__MACOSX*"

cp "$ZIP_NAME" "/Users/travissaron/Downloads/$ZIP_NAME"

echo "==> Successfully packaged:"
echo "    - $REPO_DIR/$ZIP_NAME"
echo "    - /Users/travissaron/Downloads/$ZIP_NAME"
ls -lh "$ZIP_NAME"
