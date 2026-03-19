#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MANIFEST="$ROOT_DIR/assets/visual-production/generated/installed-visual-assets.json"

if [[ ! -f "$MANIFEST" ]]; then
  echo "[installed-visual-assets] ABSENT"
  echo "- manifeste introuvable: $MANIFEST"
  exit 1
fi

python3 - "$ROOT_DIR" "$MANIFEST" <<'PY'
import json
import sys
from pathlib import Path

root = Path(sys.argv[1])
manifest_path = Path(sys.argv[2])
manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
errors = []

assets = manifest.get("assets", [])
if not assets:
    errors.append("aucun asset installe dans le manifeste")

for asset in assets:
    for target_name, rel_path in asset.get("targets", {}).items():
        path = root / rel_path
        if not path.exists():
            errors.append(f"{asset.get('id')}: cible absente pour {target_name} -> {rel_path}")

if errors:
    print("[installed-visual-assets] ECHEC")
    for error in errors:
        print(f"- {error}")
    raise SystemExit(1)

print("[installed-visual-assets] OK")
print(f"- assets: {len(assets)}")
print(f"- manifeste: {manifest_path}")
PY
