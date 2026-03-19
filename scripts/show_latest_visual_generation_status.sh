#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$ROOT_DIR/assets/visual-production/generated"
LATEST_MD="$OUT_DIR/visual-generation-handoff-latest.md"
LATEST_JSON="$OUT_DIR/visual-generation-first-drop-latest.json"

[[ -f "$LATEST_MD" ]] || {
  printf 'ECHEC: handoff visuel latest introuvable: %s\n' "$LATEST_MD" >&2
  exit 1
}

[[ -f "$LATEST_JSON" ]] || {
  printf 'ECHEC: drop visuel latest introuvable: %s\n' "$LATEST_JSON" >&2
  exit 1
}

printf 'Ma Commune - Latest Visual Generation Handoff\n\n'
cat "$LATEST_MD"
printf '\n'
python3 - "$LATEST_JSON" <<'PY'
import json, sys
from pathlib import Path
data = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
print("## Resume")
print(f"- profile : `{data.get('profile', 'n/a')}`")
print(f"- product : `{data.get('product', 'n/a')}`")
print(f"- assets : `{len(data.get('assets', []))}`")
duo = data.get("recommended_duo", {})
print(f"- duo recommande : `{duo.get('mascot', 'n/a')} + {duo.get('agent', 'n/a')}`")
PY
