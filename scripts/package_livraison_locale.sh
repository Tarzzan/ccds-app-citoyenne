#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAMP="$(date +%s)"
BUNDLE_DIR="/tmp/ma-commune-livraison-bundle-${STAMP}"
OUTPUT_ZIP="/tmp/ma-commune-livraison-bundle-${STAMP}.zip"
OUTPUT_ZIP_SHA="${OUTPUT_ZIP}.sha256"

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    printf 'ECHEC: commande requise absente: %s\n' "$1" >&2
    exit 1
  }
}

require_cmd bash
require_cmd jq
require_cmd zip
require_cmd sha256sum

AUDIT_OUTPUT="$(cd "$ROOT_DIR" && bash scripts/generate_audit_final_local.sh)"
AUDIT_MD="$(printf '%s' "$AUDIT_OUTPUT" | sed -n 's/^OUTPUT=//p')"

[[ -n "${AUDIT_MD:-}" && -f "${AUDIT_MD:-}" ]] || {
  printf 'ECHEC: audit final local introuvable\n' >&2
  exit 1
}

MANIFEST_JSON="$(sed -n 's/^- manifeste livraison locale : `\(.*\)`/\1/p' "$AUDIT_MD")"
GATE_MD="$(sed -n 's/^- gate avant tablette : `\(.*\)`/\1/p' "$AUDIT_MD")"
PREP_MD="$(sed -n 's/^- preparation livraison : `\(.*\)`/\1/p' "$AUDIT_MD")"
SEED_JSON="$(sed -n 's/^- seed demonstration : `\(.*\)`/\1/p' "$AUDIT_MD")"
BRANDING_LOG="$(sed -n 's/^- branding log : `\(.*\)`/\1/p' "$AUDIT_MD")"
TABLET_APK="$(sed -n 's/^- APK cible tablette [^:]* : `\(.*\)` (.*/\1/p' "$AUDIT_MD")"

for required_file in "$MANIFEST_JSON" "$GATE_MD" "$PREP_MD" "$SEED_JSON" "$BRANDING_LOG" "$TABLET_APK"; do
  [[ -n "${required_file:-}" && -f "$required_file" ]] || {
    printf 'ECHEC: artefact manquant pour le bundle: %s\n' "${required_file:-<vide>}" >&2
    exit 1
  }
done

rm -rf "$BUNDLE_DIR"
mkdir -p \
  "$BUNDLE_DIR/artifacts" \
  "$BUNDLE_DIR/apk" \
  "$BUNDLE_DIR/docs" \
  "$BUNDLE_DIR/checksums"

cp "$AUDIT_MD" "$BUNDLE_DIR/artifacts/"
cp "$MANIFEST_JSON" "$BUNDLE_DIR/artifacts/"
cp "$GATE_MD" "$BUNDLE_DIR/artifacts/"
cp "$PREP_MD" "$BUNDLE_DIR/artifacts/"
cp "$SEED_JSON" "$BUNDLE_DIR/artifacts/"
cp "$BRANDING_LOG" "$BUNDLE_DIR/artifacts/"
cp "$TABLET_APK" "$BUNDLE_DIR/apk/"

bash "$ROOT_DIR/scripts/publish_latest_handoff.sh" \
  "$MANIFEST_JSON" \
  "$BUNDLE_DIR/artifacts/ma-commune-handoff-livraison.md" \
  >/tmp/ma-commune-bundle-handoff.log

cp "$ROOT_DIR/README.md" "$BUNDLE_DIR/docs/"
cp "$ROOT_DIR/docs/DOSSIER_LIVRAISON_LOCALE_MA_COMMUNE_2026-03-18.md" "$BUNDLE_DIR/docs/"
cp "$ROOT_DIR/docs/MATRICE_READINESS_MA_COMMUNE_2026-03-18.md" "$BUNDLE_DIR/docs/"
cp "$ROOT_DIR/docs/CREDENTIALS_DEMO_MA_COMMUNE_2026-03-18.md" "$BUNDLE_DIR/docs/"
cp "$ROOT_DIR/docs/MODE_OPERATOIRE_DEMO_MA_COMMUNE_2026-03-18.md" "$BUNDLE_DIR/docs/"
cp "$ROOT_DIR/docs/RECETTE_MVP_CITOYEN_AGENT_ADMIN_2026-03-18.md" "$BUNDLE_DIR/docs/"
cp "$ROOT_DIR/docs/AUDIT_PRE_DEPLOIEMENT_LOCAL_MA_COMMUNE_2026-03-18.md" "$BUNDLE_DIR/docs/"

cat > "$BUNDLE_DIR/README_BUNDLE.md" <<EOF
# Bundle Livraison Locale - Ma Commune

Date de generation : $(date '+%d/%m/%Y %H:%M:%S')

## Contenu

- \`artifacts/\` : audit final local, manifeste, gate, preparation, seed, branding log et handoff operateur
- \`apk/\` : APK cible tablette courant
- \`docs/\` : documents actifs utiles pour la demonstration et la validation
- \`checksums/SHA256SUMS.txt\` : empreintes des fichiers copies

## Statut

- bundle local : PRET
- validation tablette : NON REALISEE
- livraison finale appareil : NON AUTORISEE A CE STADE
EOF

(
  cd "$BUNDLE_DIR"
  find README_BUNDLE.md artifacts apk docs -type f -print0 | sort -z | xargs -0 sha256sum > "$BUNDLE_DIR/checksums/SHA256SUMS.txt"
)

rm -f "$OUTPUT_ZIP"
(cd /tmp && zip -qr "$(basename "$OUTPUT_ZIP")" "$(basename "$BUNDLE_DIR")")
sha256sum "$OUTPUT_ZIP" > "$OUTPUT_ZIP_SHA"

bash "$ROOT_DIR/scripts/verify_livraison_bundle.sh" "$OUTPUT_ZIP" >/tmp/ma-commune-verify-bundle.log
bash "$ROOT_DIR/scripts/publish_latest_livraison_aliases.sh" "$OUTPUT_ZIP" "$BUNDLE_DIR" "$OUTPUT_ZIP_SHA" >/tmp/ma-commune-publish-latest.log
bash "$ROOT_DIR/scripts/verify_latest_livraison_aliases.sh" >/tmp/ma-commune-verify-latest.log

printf 'OUTPUT=%s\n' "$OUTPUT_ZIP"
printf 'DIR=%s\n' "$BUNDLE_DIR"
printf 'SHA256=%s\n' "$OUTPUT_ZIP_SHA"
