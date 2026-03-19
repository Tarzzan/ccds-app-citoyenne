#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$ROOT_DIR/assets/visual-production/generated"

LATEST_ZIP_ALIAS="$OUT_DIR/visual-generation-handoff-latest.zip"
LATEST_DIR_ALIAS="$OUT_DIR/visual-generation-handoff-latest"
LATEST_JSON_ALIAS="$OUT_DIR/visual-generation-first-drop-latest.json"
LATEST_CSV_ALIAS="$OUT_DIR/visual-generation-first-drop-latest.csv"
LATEST_MD_ALIAS="$OUT_DIR/visual-generation-first-drop-latest.md"
LATEST_STATUS="$OUT_DIR/visual-generation-handoff-latest.md"

for path in \
  "$LATEST_ZIP_ALIAS" \
  "$LATEST_DIR_ALIAS" \
  "$LATEST_JSON_ALIAS" \
  "$LATEST_CSV_ALIAS" \
  "$LATEST_MD_ALIAS" \
  "$LATEST_STATUS"; do
  [[ -e "$path" ]] || {
    printf 'ECHEC: alias latest introuvable: %s\n' "$path" >&2
    exit 1
  }
done

LATEST_ZIP_REAL="$(readlink -f "$LATEST_ZIP_ALIAS")"
LATEST_DIR_REAL="$(readlink -f "$LATEST_DIR_ALIAS")"
LATEST_JSON_REAL="$(readlink -f "$LATEST_JSON_ALIAS")"
LATEST_CSV_REAL="$(readlink -f "$LATEST_CSV_ALIAS")"
LATEST_MD_REAL="$(readlink -f "$LATEST_MD_ALIAS")"

[[ -f "$LATEST_ZIP_REAL" ]] || {
  printf 'ECHEC: zip latest invalide: %s\n' "$LATEST_ZIP_REAL" >&2
  exit 1
}

[[ -d "$LATEST_DIR_REAL" ]] || {
  printf 'ECHEC: dossier latest invalide: %s\n' "$LATEST_DIR_REAL" >&2
  exit 1
}

[[ -f "$LATEST_JSON_REAL" && -f "$LATEST_CSV_REAL" && -f "$LATEST_MD_REAL" ]] || {
  printf 'ECHEC: drop latest incomplet\n' >&2
  exit 1
}

[[ -f "$LATEST_DIR_REAL/README_HANDOFF.md" ]] || {
  printf 'ECHEC: README handoff absent dans %s\n' "$LATEST_DIR_REAL" >&2
  exit 1
}

grep -Fq "$LATEST_ZIP_REAL" "$LATEST_STATUS" || {
  printf 'ECHEC: le statut latest ne reference pas le zip reel\n' >&2
  exit 1
}

grep -Fq "$LATEST_DIR_REAL" "$LATEST_STATUS" || {
  printf 'ECHEC: le statut latest ne reference pas le dossier reel\n' >&2
  exit 1
}

python3 - "$LATEST_JSON_REAL" <<'PY'
import json
import sys
from pathlib import Path

data = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
assets = data.get("assets", [])
duo = data.get("recommended_duo", {})

assert data.get("profile") == "first-drop-batch-01", "profile inattendu"
assert data.get("product") == "Ma Commune", "produit inattendu"
assert len(assets) == 22, "nombre d assets inattendu"
assert duo.get("mascot") and duo.get("agent"), "duo recommande incomplet"
PY

printf '[visual-generation-latest] OK\n'
printf 'zip=%s\n' "$LATEST_ZIP_REAL"
printf 'dir=%s\n' "$LATEST_DIR_REAL"
