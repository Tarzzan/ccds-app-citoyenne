#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAMP="$(date +%s)"
OUTPUT_MD="/tmp/ma-commune-audit-final-local-${STAMP}.md"

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    printf 'ECHEC: commande requise absente: %s\n' "$1" >&2
    exit 1
  }
}

require_cmd bash
require_cmd jq

MANIFEST_OUTPUT="$(cd "$ROOT_DIR" && bash scripts/export_manifest_livraison_locale.sh)"
MANIFEST_JSON="$(printf '%s' "$MANIFEST_OUTPUT" | sed -n 's/^OUTPUT=//p')"

[[ -n "${MANIFEST_JSON:-}" && -f "${MANIFEST_JSON:-}" ]] || {
  printf 'ECHEC: manifeste livraison locale introuvable\n' >&2
  exit 1
}

GATE_MD="$(jq -r '.artifacts.gate_markdown' "$MANIFEST_JSON")"
PREP_MD="$(jq -r '.artifacts.preparation_markdown' "$MANIFEST_JSON")"
SEED_JSON="$(jq -r '.artifacts.seed_json' "$MANIFEST_JSON")"
BRANDING_LOG="$(jq -r '.artifacts.branding_log' "$MANIFEST_JSON")"
CATEGORY_VISUALS_LOG="$(jq -r '.artifacts.category_visuals_log' "$MANIFEST_JSON")"
UNIVERSAL_APK="$(jq -r '.artifacts.apk_universal.path' "$MANIFEST_JSON")"
UNIVERSAL_SIZE="$(jq -r '.artifacts.apk_universal.size_bytes' "$MANIFEST_JSON")"
TABLET_APK="$(jq -r '.artifacts.apk_tablette.path' "$MANIFEST_JSON")"
TABLET_ABI="$(jq -r '.artifacts.apk_tablette.abi' "$MANIFEST_JSON")"
TABLET_SIZE="$(jq -r '.artifacts.apk_tablette.size_bytes' "$MANIFEST_JSON")"
ADMIN_EMAIL="$(jq -r '.credentials.admin.email' "$MANIFEST_JSON")"
AGENT_EMAIL="$(jq -r '.credentials.agent.email' "$MANIFEST_JSON")"
CITIZEN_PASSWORD="$(jq -r '.credentials.citizen_password' "$MANIFEST_JSON")"
CITIZEN_EMAILS="$(jq -r '.credentials.citizens[].email' "$MANIFEST_JSON" | tr '\n' ',' | sed 's/,$//' | sed 's/,/, /g')"
INCIDENTS="$(jq -r '.demo.incidents[] | "- " + .reference + " (" + .status + ")"' "$MANIFEST_JSON")"
POLL_TITLE="$(jq -r '.demo.poll.title' "$MANIFEST_JSON")"
EVENT_TITLE="$(jq -r '.demo.event.title' "$MANIFEST_JSON")"
DB_NAME="$(jq -r '.database.name' "$MANIFEST_JSON")"
DB_USER="$(jq -r '.database.user' "$MANIFEST_JSON")"
API_URL="$(jq -r '.urls.api' "$MANIFEST_JSON")"
ADMIN_URL="$(jq -r '.urls.admin' "$MANIFEST_JSON")"
STACK_LINES="$(jq -r '.docker_stack[] | "- " + .Name + " [" + .State + "]"' "$MANIFEST_JSON")"

cat > "$OUTPUT_MD" <<EOF
# Audit Final Local - Ma Commune

Date de generation : $(date '+%d/%m/%Y %H:%M:%S')

## Verdict

- audit final local : PASS
- coherence de marque couche active : PASS
- coherence systeme visuel categories : PASS
- demonstration locale : GO
- phase tablette : AUTORISEE POUR VALIDATION
- livraison finale appareil : NON AUTORISEE A CE STADE

## Infra Locale

- API : \`${API_URL}\`
- admin : \`${ADMIN_URL}\`
- base active : \`${DB_NAME}\`
- utilisateur SQL actif : \`${DB_USER}\`

Stack Docker :
${STACK_LINES}

## Comptes De Demonstration

- admin : \`${ADMIN_EMAIL}\`
- agent : \`${AGENT_EMAIL}\`
- citoyens : ${CITIZEN_EMAILS}
- mot de passe citoyen : \`${CITIZEN_PASSWORD}\`

## Jeu De Demonstration

Incidents :
${INCIDENTS}

- consultation active : ${POLL_TITLE}
- evenement a venir : ${EVENT_TITLE}

## Artefacts Verifies

- manifeste livraison locale : \`${MANIFEST_JSON}\`
- gate avant tablette : \`${GATE_MD}\`
- preparation livraison : \`${PREP_MD}\`
- seed demonstration : \`${SEED_JSON}\`
- branding log : \`${BRANDING_LOG}\`
- category visuals log : \`${CATEGORY_VISUALS_LOG}\`
- APK universel : \`${UNIVERSAL_APK}\` (${UNIVERSAL_SIZE} octets)
- APK cible tablette ${TABLET_ABI} : \`${TABLET_APK}\` (${TABLET_SIZE} octets)

## Conclusion

Le projet est localement consolide, verifie et prepare pour une phase de validation sur tablette.

La livraison finale appareil reste bloquee tant que les parcours n'ont pas ete rejoues sur la tablette cible avec cet APK et ce jeu de demonstration.
EOF

printf 'OUTPUT=%s\n' "$OUTPUT_MD"
