#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAMP="$(date +%s)"
OUTPUT_MD="/tmp/ma-commune-gate-avant-tablette-${STAMP}.md"
TARGET_MAX_BYTES=52428800

pass() {
  printf '[gate-tablette] OK   %s\n' "$1"
}

fail() {
  printf '[gate-tablette] KO   %s\n' "$1" >&2
  exit 1
}

latest_file() {
  local pattern="$1"
  ls -t ${pattern} 2>/dev/null | head -n1 || true
}

log() {
  printf '[gate-tablette] %s\n' "$1"
}

log "Preparation locale complete"
PREP_OUTPUT="$(cd "$ROOT_DIR" && bash scripts/prepare_livraison_locale.sh)"
PREP_MD="$(printf '%s' "$PREP_OUTPUT" | sed -n 's/^OUTPUT=//p')"
SEED_JSON="$(printf '%s' "$PREP_OUTPUT" | sed -n 's/^SEED=//p')"
RELEASE_APK="$(printf '%s' "$PREP_OUTPUT" | sed -n 's/^RELEASE_APK=//p')"
APK_SIZE="$(printf '%s' "$PREP_OUTPUT" | sed -n 's/^RELEASE_SIZE=//p')"
TABLET_ABI="$(printf '%s' "$PREP_OUTPUT" | sed -n 's/^TABLET_ABI=//p')"
TABLET_APK="$(printf '%s' "$PREP_OUTPUT" | sed -n 's/^TABLET_APK=//p')"
TABLET_SIZE="$(printf '%s' "$PREP_OUTPUT" | sed -n 's/^TABLET_SIZE=//p')"

[[ -n "$PREP_MD" && -f "$PREP_MD" ]] || fail "resume preparation livraison introuvable"
pass "resume preparation livraison genere"

[[ -n "$SEED_JSON" && -f "$SEED_JSON" ]] || fail "resume seed introuvable"
pass "resume seed present"

[[ -n "$RELEASE_APK" && -f "$RELEASE_APK" ]] || fail "APK release absent"
[[ "$APK_SIZE" =~ ^[0-9]+$ ]] || fail "taille APK release invalide"
[[ "$APK_SIZE" -gt 0 ]] || fail "APK release vide"
pass "APK release disponible"

[[ -n "$TABLET_ABI" ]] || fail "ABI tablette introuvable"
[[ -n "$TABLET_APK" && -f "$TABLET_APK" ]] || fail "APK cible tablette introuvable"
[[ "$TABLET_SIZE" =~ ^[0-9]+$ ]] || fail "taille APK cible tablette invalide"
[[ "$TABLET_SIZE" -gt 0 ]] || fail "APK cible tablette vide"
[[ "$TABLET_SIZE" -lt "$APK_SIZE" ]] || fail "APK cible tablette pas plus leger que l'APK universel"
[[ "$TABLET_SIZE" -lt "$TARGET_MAX_BYTES" ]] || fail "APK cible tablette trop lourd pour le seuil de validation"
pass "APK cible tablette genere et conforme"

README_OK="$(rg -n 'DOSSIER_LIVRAISON_LOCALE|MATRICE_READINESS' "$ROOT_DIR/README.md" -S || true)"
[[ -n "$README_OK" ]] || fail "README ne pointe pas vers le dossier de livraison"
pass "README aligne sur les points d'entree livraison"

MATRIX_DOC="${ROOT_DIR}/docs/MATRICE_READINESS_MA_COMMUNE_2026-03-18.md"
DOSSIER_DOC="${ROOT_DIR}/docs/DOSSIER_LIVRAISON_LOCALE_MA_COMMUNE_2026-03-18.md"
[[ -f "$MATRIX_DOC" ]] || fail "matrice readiness absente"
[[ -f "$DOSSIER_DOC" ]] || fail "dossier livraison absent"
pass "documentation de decision presente"

if ! rg -q '`NO GO` livraison finale appareil|NON PRET POUR LIVRAISON FINALE' "$MATRIX_DOC"; then
  fail "matrice readiness ne rappelle pas le blocage tablette final"
fi
pass "blocage livraison finale appareil explicitement trace"

BRANDING_OUTPUT="$(cd "$ROOT_DIR" && bash scripts/check_branding_residuals.sh)"
[[ -n "$BRANDING_OUTPUT" ]] || fail "controle de residus de marque silencieux"
pass "coherence de marque verrouillee sur la couche active"

cat > "$OUTPUT_MD" <<EOF
# Gate Avant Tablette - Ma Commune

Date de generation : $(date '+%d/%m/%Y %H:%M:%S')

## Resultat

- gate local avant tablette : PASS
- livraison finale appareil : NON AUTORISEE A CE STADE

## Verifications

- preparation livraison locale regeneree : \`${PREP_MD}\`
- seed demonstration present : \`${SEED_JSON}\`
- APK release present : \`${RELEASE_APK}\`
- taille APK universel : ${APK_SIZE} octets
- APK cible tablette : \`${TABLET_APK}\`
- ABI cible tablette : \`${TABLET_ABI}\`
- taille APK cible tablette : ${TABLET_SIZE} octets
- dossier livraison : \`${DOSSIER_DOC}\`
- matrice readiness : \`${MATRIX_DOC}\`
- controle branding actif : \`bash scripts/check_branding_residuals.sh\`

## Decision

Le projet est autorise a entrer en phase de validation tablette.

La livraison finale appareil reste bloquee tant que les parcours n'ont pas ete rejoues sur la tablette cible.
EOF

printf 'OUTPUT=%s\n' "$OUTPUT_MD"
