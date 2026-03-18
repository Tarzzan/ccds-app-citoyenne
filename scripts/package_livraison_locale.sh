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
CATEGORY_VISUALS_LOG="$(sed -n 's/^- category visuals log : `\(.*\)`/\1/p' "$AUDIT_MD")"
TABLET_APK="$(sed -n 's/^- APK cible tablette [^:]* : `\(.*\)` (.*/\1/p' "$AUDIT_MD")"
MANIFEST_GIT_HEAD="$(jq -r '.project.git.head_commit_short // "inconnu"' "$MANIFEST_JSON")"
MANIFEST_GIT_SUBJECT="$(jq -r '.project.git.head_subject // "inconnu"' "$MANIFEST_JSON")"
MANIFEST_GIT_UPSTREAM_REF="$(jq -r '.project.git.upstream_ref // ""' "$MANIFEST_JSON")"
MANIFEST_GIT_UPSTREAM_SHORT="$(jq -r '.project.git.upstream_commit_short // ""' "$MANIFEST_JSON")"
MANIFEST_GIT_WORKTREE_CLEAN="$(jq -r '.project.git.worktree_clean' "$MANIFEST_JSON")"
MANIFEST_TABLET_SHA256="$(jq -r '.artifacts.apk_tablette.sha256 // "inconnu"' "$MANIFEST_JSON")"
ACCESS_BRIEF_SHA=""

for required_file in "$MANIFEST_JSON" "$GATE_MD" "$PREP_MD" "$SEED_JSON" "$BRANDING_LOG" "$CATEGORY_VISUALS_LOG" "$TABLET_APK"; do
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
cp "$CATEGORY_VISUALS_LOG" "$BUNDLE_DIR/artifacts/"
cp "$TABLET_APK" "$BUNDLE_DIR/apk/"

MANIFEST_PATH="$MANIFEST_JSON" \
OUTPUT_FILE="$BUNDLE_DIR/artifacts/macommune.txt" \
SECONDARY_OUTPUT_FILE="$BUNDLE_DIR/artifacts/macommune.txt" \
bash "$ROOT_DIR/scripts/publish_macommune_access_brief.sh" \
  >/tmp/ma-commune-bundle-brief.log

MANIFEST_PATH="$MANIFEST_JSON" \
bash "$ROOT_DIR/scripts/publish_macommune_access_brief.sh" \
  >/tmp/ma-commune-publish-brief.log

bash "$ROOT_DIR/scripts/verify_access_brief_consistency.sh" \
  "/home/tarzzan/Bureau/macommune.txt" \
  "/home/tarzzan/Desktop/macommune.txt" \
  "$BUNDLE_DIR/artifacts/macommune.txt" \
  > "$BUNDLE_DIR/artifacts/ma-commune-access-brief-consistency.log"

ACCESS_BRIEF_SHA="$(sed -n 's/^sha256: //p' "$BUNDLE_DIR/artifacts/ma-commune-access-brief-consistency.log")"

bash "$ROOT_DIR/scripts/publish_latest_handoff.sh" \
  "$MANIFEST_JSON" \
  "$BUNDLE_DIR/artifacts/ma-commune-handoff-livraison.md" \
  "$BUNDLE_DIR/artifacts/ma-commune-access-brief-consistency.log" \
  >/tmp/ma-commune-bundle-handoff.log

cat > "$BUNDLE_DIR/artifacts/ma-commune-livraison-index.md" <<EOF
# Livraison Locale - Ma Commune

Date de generation : $(date '+%d/%m/%Y %H:%M:%S')

- commit embarque : \`${MANIFEST_GIT_HEAD}\`
- message embarque : ${MANIFEST_GIT_SUBJECT}
$(if [[ -n "$MANIFEST_GIT_UPSTREAM_REF" ]]; then
  printf '%s\n' "- upstream embarque : \`${MANIFEST_GIT_UPSTREAM_REF}\` (\`${MANIFEST_GIT_UPSTREAM_SHORT:-inconnu}\`)"
fi)
- worktree propre au moment du bundle : \`${MANIFEST_GIT_WORKTREE_CLEAN}\`

## Artefacts embarques

- audit final local : \`artifacts/$(basename "$AUDIT_MD")\`
- manifeste : \`artifacts/$(basename "$MANIFEST_JSON")\`
- gate : \`artifacts/$(basename "$GATE_MD")\`
- preparation : \`artifacts/$(basename "$PREP_MD")\`
- seed demo : \`artifacts/$(basename "$SEED_JSON")\`
- handoff operateur : \`artifacts/ma-commune-handoff-livraison.md\`
- brief acces : \`artifacts/macommune.txt\`
- controle brief acces : \`artifacts/ma-commune-access-brief-consistency.log\`
$(if [[ -n "${ACCESS_BRIEF_SHA:-}" ]]; then
  printf '%s\n' "- empreinte brief acces : \`${ACCESS_BRIEF_SHA}\`"
fi)
- branding log : \`artifacts/$(basename "$BRANDING_LOG")\`
- category visuals log : \`artifacts/$(basename "$CATEGORY_VISUALS_LOG")\`
- APK tablette : \`apk/$(basename "$TABLET_APK")\`
- SHA-256 APK tablette : \`${MANIFEST_TABLET_SHA256}\`
- checksums : \`checksums/SHA256SUMS.txt\`

## Alias latest publies hors bundle

- bundle zip : \`/tmp/ma-commune-latest-livraison-bundle.zip\`
- checksum bundle zip : \`/tmp/ma-commune-latest-livraison-bundle.zip.sha256\`
- index latest : \`/tmp/ma-commune-latest-livraison-index.md\`
- handoff latest : \`/tmp/ma-commune-latest-handoff.md\`
- brief acces latest : \`/tmp/ma-commune-latest-access-brief.txt\`
- controle brief acces latest : \`/tmp/ma-commune-latest-access-brief-check.log\`
- APK tablette latest : \`/tmp/ma-commune-latest-app-release-tablette.apk\`
EOF

cp "$ROOT_DIR/README.md" "$BUNDLE_DIR/docs/"
cp "$ROOT_DIR/docs/DOSSIER_LIVRAISON_LOCALE_MA_COMMUNE_2026-03-18.md" "$BUNDLE_DIR/docs/"
cp "$ROOT_DIR/docs/MATRICE_READINESS_MA_COMMUNE_2026-03-18.md" "$BUNDLE_DIR/docs/"
cp "$ROOT_DIR/docs/CREDENTIALS_DEMO_MA_COMMUNE_2026-03-18.md" "$BUNDLE_DIR/docs/"
cp "$ROOT_DIR/docs/MODE_OPERATOIRE_DEMO_MA_COMMUNE_2026-03-18.md" "$BUNDLE_DIR/docs/"
cp "$ROOT_DIR/docs/RECETTE_MVP_CITOYEN_AGENT_ADMIN_2026-03-18.md" "$BUNDLE_DIR/docs/"
cp "$ROOT_DIR/docs/AUDIT_PRE_DEPLOIEMENT_LOCAL_MA_COMMUNE_2026-03-18.md" "$BUNDLE_DIR/docs/"
cp "$ROOT_DIR/docs/CATEGORIES_VISUELLES_MA_COMMUNE_2026-03-18.md" "$BUNDLE_DIR/docs/"
mkdir -p "$BUNDLE_DIR/previews"
cp "$ROOT_DIR/assets/category-visuals/index.html" "$BUNDLE_DIR/previews/category-visuals-index.html"
cp "$ROOT_DIR/assets/category-visuals/generated/category-visuals-preview.png" "$BUNDLE_DIR/previews/"

cat > "$BUNDLE_DIR/README_BUNDLE.md" <<EOF
# Bundle Livraison Locale - Ma Commune

Date de generation : $(date '+%d/%m/%Y %H:%M:%S')

## Contenu

- \`artifacts/\` : audit final local, manifeste, gate, preparation, seed, branding log, category visuals log, handoff operateur, brief acces et controle de coherence du brief
- \`apk/\` : APK cible tablette courant
- \`docs/\` : documents actifs utiles pour la demonstration et la validation
- \`previews/\` : apercus categories visuelles embarques pour controle rapide
- \`checksums/SHA256SUMS.txt\` : empreintes des fichiers copies

## Statut

- bundle local : PRET
- validation tablette : NON REALISEE
- livraison finale appareil : NON AUTORISEE A CE STADE
- commit embarque : ${MANIFEST_GIT_HEAD}
- message embarque : ${MANIFEST_GIT_SUBJECT}
$(if [[ -n "$MANIFEST_GIT_UPSTREAM_REF" ]]; then
  printf '%s\n' "- upstream embarque : ${MANIFEST_GIT_UPSTREAM_REF} (${MANIFEST_GIT_UPSTREAM_SHORT:-inconnu})"
fi)
- worktree propre au moment du bundle : ${MANIFEST_GIT_WORKTREE_CLEAN}
EOF

(
  cd "$BUNDLE_DIR"
  find README_BUNDLE.md artifacts apk docs previews -type f -print0 | sort -z | xargs -0 sha256sum > "$BUNDLE_DIR/checksums/SHA256SUMS.txt"
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
