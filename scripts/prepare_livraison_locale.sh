#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAMP="$(date +%s)"
OUTPUT_MD="/tmp/ma-commune-livraison-locale-${STAMP}.md"
RELEASE_OUTPUT_DIR="${ROOT_DIR}/mobile/android/app/build/outputs/apk/release"
LOCK_FILE="/tmp/ma-commune-prepare-livraison.lock"
TABLET_ABI_TARGET="${TABLET_ABI_TARGET:-}"

log() {
  printf '[prepare-livraison] %s\n' "$1"
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    printf 'ECHEC: commande requise absente: %s\n' "$1" >&2
    exit 1
  }
}

require_cmd bash
require_cmd jq
require_cmd flock

exec 9>"$LOCK_FILE"
flock 9

log "Execution de l'audit local"
(cd "$ROOT_DIR" && bash scripts/audit_local_predeploy.sh >/tmp/ma-commune-prepare-audit.log)

log "Controle des residus de marque"
(cd "$ROOT_DIR" && bash scripts/check_branding_residuals.sh >/tmp/ma-commune-prepare-branding.log)

log "Controle du systeme visuel categories"
(cd "$ROOT_DIR" && bash scripts/check_category_visuals.sh >/tmp/ma-commune-prepare-category-visuals.log)

RELEASE_APK="${RELEASE_OUTPUT_DIR}/app-release.apk"
RELEASE_APK_SNAPSHOT=""
RELEASE_APK_SIZE=""

if [[ -f "$RELEASE_APK" ]]; then
  RELEASE_APK_SNAPSHOT="/tmp/ma-commune-app-release-universal-${STAMP}.apk"
  cp "$RELEASE_APK" "$RELEASE_APK_SNAPSHOT"
  RELEASE_APK_SIZE="$(stat -c '%s' "$RELEASE_APK_SNAPSHOT")"
fi

log "Execution du seed de demonstration"
(cd "$ROOT_DIR" && bash scripts/seed_demo_local.sh >/tmp/ma-commune-prepare-seed.log)

if [[ -z "${TABLET_ABI_TARGET:-}" ]]; then
  TABLET_ABI_TARGET="$(cd "$ROOT_DIR" && bash scripts/detect_tablette_abi.sh | sed -n 's/^ABI=//p')"
fi

log "Construction de l'APK cible tablette ${TABLET_ABI_TARGET}"
TABLET_BUILD_OUTPUT="$(cd "$ROOT_DIR" && bash scripts/build_release_tablette.sh "${TABLET_ABI_TARGET}")"
printf '%s\n' "$TABLET_BUILD_OUTPUT" >/tmp/ma-commune-prepare-build-tablette.log

LATEST_SEED="$(ls -t /tmp/ma-commune-demo-seed-*.json 2>/dev/null | head -n1)"
if [[ -z "${LATEST_SEED:-}" ]]; then
  printf 'ECHEC: aucun resume de seed trouve dans /tmp\n' >&2
  exit 1
fi

TABLET_ABI="$(printf '%s' "$TABLET_BUILD_OUTPUT" | sed -n 's/^ABI=//p')"
TABLET_APK="$(printf '%s' "$TABLET_BUILD_OUTPUT" | sed -n 's/^APK=//p')"
TABLET_SIZE="$(printf '%s' "$TABLET_BUILD_OUTPUT" | sed -n 's/^SIZE_BYTES=//p')"
RELEASE_APK_LINE='- APK universel : non disponible'
TABLET_APK_LINE='- APK cible tablette : non genere dans cette passe'

if [[ -n "${RELEASE_APK_SNAPSHOT:-}" && -f "$RELEASE_APK_SNAPSHOT" && "${RELEASE_APK_SIZE:-}" =~ ^[0-9]+$ ]]; then
  RELEASE_APK_LINE="- APK universel : \`${RELEASE_APK_SNAPSHOT}\` (${RELEASE_APK_SIZE} octets)"
fi

if [[ -n "${TABLET_ABI:-}" && -n "${TABLET_APK:-}" && -f "$TABLET_APK" && "${TABLET_SIZE:-}" =~ ^[0-9]+$ ]]; then
  TABLET_APK_LINE="- APK cible tablette ${TABLET_ABI} : \`${TABLET_APK}\` (${TABLET_SIZE} octets)"
fi

ADMIN_EMAIL="$(jq -r '.credentials.admin.email' "$LATEST_SEED")"
AGENT_EMAIL="$(jq -r '.credentials.agent.email' "$LATEST_SEED")"
CITIZEN_PASSWORD="$(jq -r '.credentials.citizen_password' "$LATEST_SEED")"
CITIZEN_EMAILS="$(jq -r '.credentials.citizens[].email' "$LATEST_SEED" | tr '\n' ',' | sed 's/,$//' | sed 's/,/, /g')"
INCIDENTS="$(jq -r '.incidents[] | "- " + .reference + " (" + .status + ")"' "$LATEST_SEED")"
POLL_TITLE="$(jq -r '.poll.title' "$LATEST_SEED")"
EVENT_TITLE="$(jq -r '.event.title' "$LATEST_SEED")"

cat > "$OUTPUT_MD" <<EOF
# Preparation Livraison Locale - Ma Commune

Date de generation : $(date '+%d/%m/%Y %H:%M:%S')

## Etat

- audit local : OK
- coherence de marque couche active : OK
- coherence systeme visuel categories : OK
- seed demonstration : OK
- validation tablette finale : NON REALISEE

## Comptes

- admin : \`${ADMIN_EMAIL}\`
- agent : \`${AGENT_EMAIL}\`
- citoyens : ${CITIZEN_EMAILS}
- mot de passe citoyen : \`${CITIZEN_PASSWORD}\`

## Jeu de demonstration

Incidents :
${INCIDENTS}

- consultation active : ${POLL_TITLE}
- evenement a venir : ${EVENT_TITLE}

## Fichiers de reference

- seed json : \`${LATEST_SEED}\`
- audit log : \`/tmp/ma-commune-prepare-audit.log\`
- branding log : \`/tmp/ma-commune-prepare-branding.log\`
- category visuals log : \`/tmp/ma-commune-prepare-category-visuals.log\`
- seed log : \`/tmp/ma-commune-prepare-seed.log\`
- build tablette log : \`/tmp/ma-commune-prepare-build-tablette.log\`
${RELEASE_APK_LINE}
${TABLET_APK_LINE}

## Decision

- GO demonstration locale
- NO GO livraison finale appareil tant que la phase tablette n'est pas rejouee
EOF

log "Preparation terminee"
printf 'OUTPUT=%s\n' "$OUTPUT_MD"
printf 'SEED=%s\n' "$LATEST_SEED"
printf 'RELEASE_APK=%s\n' "${RELEASE_APK_SNAPSHOT:-}"
printf 'RELEASE_SIZE=%s\n' "${RELEASE_APK_SIZE:-}"
printf 'TABLET_ABI=%s\n' "${TABLET_ABI:-}"
printf 'TABLET_APK=%s\n' "${TABLET_APK:-}"
printf 'TABLET_SIZE=%s\n' "${TABLET_SIZE:-}"
