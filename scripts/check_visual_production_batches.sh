#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VISUAL_DIR="$ROOT_DIR/assets/visual-production"
MANIFEST="$VISUAL_DIR/visual-production-manifest.json"

python3 - "$VISUAL_DIR" "$MANIFEST" <<'PY'
import json
import sys
from pathlib import Path

visual_dir = Path(sys.argv[1])
manifest_path = Path(sys.argv[2])

manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
batches = []
asset_ids = set()
errors = []

for path in sorted(visual_dir.glob("*.json")):
    if path == manifest_path:
        continue
    data = json.loads(path.read_text(encoding="utf-8"))
    batch_id = data.get("batch_id")
    assets = data.get("assets", [])
    if not batch_id:
        errors.append(f"{path.name}: batch_id manquant")
        continue
    if not isinstance(assets, list) or not assets:
        errors.append(f"{path.name}: assets manquants ou vides")
        continue
    batches.append((path.name, data))
    for asset in assets:
        asset_id = asset.get("id")
        if not asset_id:
            errors.append(f"{path.name}: asset sans id")
            continue
        if asset_id in asset_ids:
            errors.append(f"{path.name}: id duplique {asset_id}")
        asset_ids.add(asset_id)
        for field in ("label", "ratio", "filename", "prompt"):
            if not asset.get(field):
                errors.append(f"{path.name}: {asset_id} -> champ manquant {field}")

manifest_asset_order = manifest.get("asset_order", [])
missing_from_order = sorted(asset_ids - set(manifest_asset_order))
if missing_from_order:
    errors.append("asset_order incomplet dans visual-production-manifest.json: " + ", ".join(missing_from_order))

for category in manifest.get("categories", []):
    badge_id = category.get("asset_badge_id")
    scene_id = category.get("asset_scene_id")
    for ref in (badge_id, scene_id):
        if ref and ref not in asset_ids and ref not in set(manifest_asset_order):
            errors.append(f"manifest categories: reference inconnue {ref}")

if errors:
    print("[visual-production] ECHEC")
    for error in errors:
        print(f"- {error}")
    raise SystemExit(1)

print("[visual-production] OK")
print(f"- batches: {len(batches)}")
print(f"- assets: {len(asset_ids)}")
print(f"- manifest: {manifest_path}")
PY
