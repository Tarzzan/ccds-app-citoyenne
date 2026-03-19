#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$ROOT_DIR/assets/visual-production/generated"

latest_file() {
  find "$OUT_DIR" -maxdepth 1 -type f -name "$1" ! -name '*-latest*' -printf '%T@ %p\n' \
    | sort -nr \
    | head -n 1 \
    | cut -d' ' -f2-
}

latest_dir() {
  find "$OUT_DIR" -maxdepth 1 -type d -name "$1" ! -name '*-latest*' -printf '%T@ %p\n' \
    | sort -nr \
    | head -n 1 \
    | cut -d' ' -f2-
}

LATEST_ZIP="$(latest_file 'visual-generation-handoff-*.zip')"
LATEST_DIR="$(latest_dir 'visual-generation-handoff-*')"

[[ -n "${LATEST_ZIP:-}" && -f "$LATEST_ZIP" ]] || {
  printf 'ECHEC: aucun zip handoff visuel recent trouve dans %s\n' "$OUT_DIR" >&2
  exit 1
}

[[ -n "${LATEST_DIR:-}" && -d "$LATEST_DIR" ]] || {
  printf 'ECHEC: aucun dossier handoff visuel recent trouve dans %s\n' "$OUT_DIR" >&2
  exit 1
}

ln -sfn "$LATEST_ZIP" "$OUT_DIR/visual-generation-handoff-latest.zip"
ln -sfn "$LATEST_DIR" "$OUT_DIR/visual-generation-handoff-latest"
ln -sfn "$OUT_DIR/visual-generation-first-drop.json" "$OUT_DIR/visual-generation-first-drop-latest.json"
ln -sfn "$OUT_DIR/visual-generation-first-drop.csv" "$OUT_DIR/visual-generation-first-drop-latest.csv"
ln -sfn "$OUT_DIR/visual-generation-first-drop.md" "$OUT_DIR/visual-generation-first-drop-latest.md"

cat >"$OUT_DIR/visual-generation-handoff-latest.md" <<EOF
# Latest Visual Generation Handoff

- zip : \`$LATEST_ZIP\`
- dossier : \`$LATEST_DIR\`
- drop json : \`$OUT_DIR/visual-generation-first-drop.json\`
- drop csv : \`$OUT_DIR/visual-generation-first-drop.csv\`
- drop md : \`$OUT_DIR/visual-generation-first-drop.md\`
EOF

printf 'latest_zip=%s\n' "$OUT_DIR/visual-generation-handoff-latest.zip"
printf 'latest_dir=%s\n' "$OUT_DIR/visual-generation-handoff-latest"
