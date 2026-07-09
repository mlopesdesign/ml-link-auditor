#!/usr/bin/env bash
# Sync-version check (Linux/CI). Exits 1 on mismatch.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_FILE="$ROOT/ml-link-auditor/ml-link-auditor.php"
README_FILE="$ROOT/ml-link-auditor/readme.txt"

if [ ! -f "$PLUGIN_FILE" ]; then echo "[sync] Missing $PLUGIN_FILE" >&2; exit 1; fi
if [ ! -f "$README_FILE" ]; then echo "[sync] Missing $README_FILE" >&2; exit 1; fi

header=$(grep -E '^\s*\*\s*Version:' "$PLUGIN_FILE" | head -1 | sed -E 's/.*Version:\s*([0-9]+\.[0-9]+\.[0-9]+).*/\1/')
const=$(grep -E "define\(\s*'MLLA_VERSION'" "$PLUGIN_FILE" | head -1 | sed -E "s/.*'MLLA_VERSION',\s*'([0-9.]+)'.*/\1/")
readme=$(grep -E '^\s*Stable tag:' "$README_FILE" | head -1 | sed -E 's/.*Stable tag:\s*([0-9.]+).*/\1/')

echo "Header   : $header"
echo "Constante: $const"
echo "Readme   : $readme"

if [ -z "$header" ] || [ -z "$const" ] || [ -z "$readme" ]; then
  echo "[sync] FAIL - versao nao encontrada em algum lugar" >&2
  exit 1
fi

if [ "$header" = "$const" ] && [ "$const" = "$readme" ]; then
  echo "[sync] OK - todas as versoes batem em $header"
  exit 0
fi

echo "[sync] FAIL - header/constante/readme estao diferentes" >&2
exit 1