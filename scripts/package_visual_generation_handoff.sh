#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$ROOT_DIR/assets/visual-production/generated"
STAMP="$(date +%s)"
PACKAGE_DIR="$OUT_DIR/visual-generation-handoff-$STAMP"
ZIP_PATH="$OUT_DIR/visual-generation-handoff-$STAMP.zip"

python3 "$ROOT_DIR/scripts/export_visual_generation_batch.py" >/tmp/ma-commune-visual-generation-export.log

mkdir -p "$PACKAGE_DIR"
mkdir -p "$PACKAGE_DIR/docs"
mkdir -p "$PACKAGE_DIR/assets"
mkdir -p "$PACKAGE_DIR/batches"

cp "$ROOT_DIR/docs/DIRECTION_ARTISTIQUE_COMPAGNON_ET_IMAGERIE_TERRAIN_MA_COMMUNE_2026-03-18.md" "$PACKAGE_DIR/docs/"
cp "$ROOT_DIR/docs/BIBLE_PERSONNAGES_MA_COMMUNE_2026-03-18.md" "$PACKAGE_DIR/docs/"
cp "$ROOT_DIR/docs/PIPELINE_GENERATION_IA_VISUELS_MA_COMMUNE_2026-03-18.md" "$PACKAGE_DIR/docs/"
cp "$ROOT_DIR/docs/MATRICE_PRODUCTION_VISUELS_MA_COMMUNE_2026-03-18.md" "$PACKAGE_DIR/docs/"
cp "$ROOT_DIR/docs/PROMPTS_PRODUCTION_VISUELS_MA_COMMUNE_2026-03-18.md" "$PACKAGE_DIR/docs/"
cp "$ROOT_DIR/docs/PLANCHES_GENERATION_PERSONNAGES_BATCH_01_MA_COMMUNE_2026-03-18.md" "$PACKAGE_DIR/docs/"
cp "$ROOT_DIR/docs/PLANCHES_GENERATION_BADGES_CATEGORIES_BATCH_01_MA_COMMUNE_2026-03-18.md" "$PACKAGE_DIR/docs/"
cp "$ROOT_DIR/docs/PLANCHES_GENERATION_SCENES_TERRAIN_BATCH_01_MA_COMMUNE_2026-03-18.md" "$PACKAGE_DIR/docs/"

cp "$ROOT_DIR/assets/visual-production/visual-production-manifest.json" "$PACKAGE_DIR/assets/"
cp "$ROOT_DIR/assets/visual-production/character-batch-01.json" "$PACKAGE_DIR/batches/"
cp "$ROOT_DIR/assets/visual-production/badge-batch-01.json" "$PACKAGE_DIR/batches/"
cp "$ROOT_DIR/assets/visual-production/terrain-batch-01.json" "$PACKAGE_DIR/batches/"
cp "$ROOT_DIR/assets/visual-production/generated/visual-generation-first-drop.json" "$PACKAGE_DIR/"
cp "$ROOT_DIR/assets/visual-production/generated/visual-generation-first-drop.csv" "$PACKAGE_DIR/"
cp "$ROOT_DIR/assets/visual-production/generated/visual-generation-first-drop.md" "$PACKAGE_DIR/"

cat >"$PACKAGE_DIR/README_HANDOFF.md" <<EOF
# Visual Generation Handoff

- generated_at_epoch: \`$STAMP\`
- profile: \`first-drop-batch-01\`
- assets_count: \`22\`

## Contenu

- \`visual-generation-first-drop.json\`
- \`visual-generation-first-drop.csv\`
- \`visual-generation-first-drop.md\`
- \`assets/visual-production-manifest.json\`
- \`batches/*.json\`
- \`docs/*.md\`

## Usage

1. Lire \`docs/DIRECTION_ARTISTIQUE_COMPAGNON_ET_IMAGERIE_TERRAIN_MA_COMMUNE_2026-03-18.md\`
2. Produire les assets listés dans \`visual-generation-first-drop.csv\`
3. Respecter exactement les noms de fichiers
4. Installer le drop via \`python3 scripts/install_visual_asset_drop.py /chemin/vers/le/drop\`
EOF

(
  cd "$OUT_DIR"
  zip -qr "$(basename "$ZIP_PATH")" "$(basename "$PACKAGE_DIR")"
)

printf 'package_dir=%s\n' "$PACKAGE_DIR"
printf 'zip=%s\n' "$ZIP_PATH"
