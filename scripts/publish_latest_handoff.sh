#!/usr/bin/env bash
set -euo pipefail

MANIFEST="${1:-/tmp/ma-commune-latest-manifest-livraison-locale.json}"
OUTPUT_MD="${2:-/tmp/ma-commune-latest-handoff.md}"
ACCESS_BRIEF_CHECK_LOG="${3:-/tmp/ma-commune-latest-access-brief-check.log}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    printf 'ECHEC: commande requise absente: %s\n' "$1" >&2
    exit 1
  }
}

require_cmd jq

[[ -f "$MANIFEST" ]] || {
  printf 'ECHEC: manifeste latest introuvable: %s\n' "$MANIFEST" >&2
  exit 1
}

ADMIN_EMAIL="$(jq -r '.credentials.admin.email' "$MANIFEST")"
ADMIN_PASSWORD="$(jq -r '.credentials.admin.password' "$MANIFEST")"
AGENT_EMAIL="$(jq -r '.credentials.agent.email' "$MANIFEST")"
AGENT_PASSWORD="$(jq -r '.credentials.agent.password' "$MANIFEST")"
CITIZEN_PASSWORD="$(jq -r '.credentials.citizen_password' "$MANIFEST")"
CITIZENS="$(jq -r '.credentials.citizens[] | "- " + .email + " (" + .full_name + ")"' "$MANIFEST")"
INCIDENTS="$(jq -r '.demo.incidents[] | "- " + .reference + " [" + .status + "] : " + .title' "$MANIFEST")"
POLL_TITLE="$(jq -r '.demo.poll.title' "$MANIFEST")"
EVENT_TITLE="$(jq -r '.demo.event.title' "$MANIFEST")"
API_URL="$(jq -r '.urls.api' "$MANIFEST")"
ADMIN_URL="$(jq -r '.urls.admin' "$MANIFEST")"
APK_TABLETTE="$(jq -r '.artifacts.apk_tablette.path' "$MANIFEST")"
APK_TABLETTE_ABI="$(jq -r '.artifacts.apk_tablette.abi' "$MANIFEST")"
LATEST_HEAD="$(jq -r '.project.git.head_commit_short // "inconnu"' "$MANIFEST")"
LATEST_HEAD_SUBJECT="$(jq -r '.project.git.head_subject // "inconnu"' "$MANIFEST")"
LATEST_UPSTREAM_REF="$(jq -r '.project.git.upstream_ref // ""' "$MANIFEST")"
LATEST_UPSTREAM_COMMIT="$(jq -r '.project.git.upstream_commit_short // ""' "$MANIFEST")"
LATEST_WORKTREE_CLEAN="$(jq -r '.project.git.worktree_clean' "$MANIFEST")"
ACCESS_BRIEF_SHA=""
if [[ -f "$ACCESS_BRIEF_CHECK_LOG" ]]; then
  ACCESS_BRIEF_SHA="$(sed -n 's/^sha256: //p' "$ACCESS_BRIEF_CHECK_LOG")"
fi
LAN_IP="$("$SCRIPT_DIR/detect_primary_lan_ip.sh" 2>/dev/null || true)"
SSH_USER="$(getent passwd 1000 2>/dev/null | cut -d: -f1 || true)"

if [[ -z "${SSH_USER:-}" ]]; then
  SSH_USER="$(whoami)"
fi

cat > "$OUTPUT_MD" <<EOF
# Handoff Latest - Ma Commune

Date de generation : $(date '+%d/%m/%Y %H:%M:%S')

## Decision

- gate local avant tablette : $(jq -r '.decision.gate_local_avant_tablette' "$MANIFEST")
- coherence marque couche active : $(jq -r '.decision.coherence_marque_couche_active' "$MANIFEST")
- coherence systeme visuel categories : $(jq -r '.decision.coherence_systeme_visuel_categories' "$MANIFEST")
- livraison finale appareil : $(jq -r '.decision.livraison_finale_appareil' "$MANIFEST")
- commit bundle latest : \`${LATEST_HEAD}\`
- message bundle latest : ${LATEST_HEAD_SUBJECT}
$(if [[ -n "$LATEST_UPSTREAM_REF" ]]; then
  printf '%s\n' "- upstream bundle latest : \`${LATEST_UPSTREAM_REF}\` (\`${LATEST_UPSTREAM_COMMIT:-inconnu}\`)"
fi)
- worktree propre au moment du bundle : \`${LATEST_WORKTREE_CLEAN}\`

## URLs

- API mobile : \`${API_URL}\`
- admin web : \`${ADMIN_URL}\`
$(if [[ -n "${LAN_IP:-}" ]]; then
  printf '%s\n' "- API mobile LAN : \`http://${LAN_IP}:8080/api\`"
  printf '%s\n' "- admin web LAN : \`http://${LAN_IP}:8080/admin/?page=login\`"
  printf '%s\n' "- SSH LAN : \`ssh ${SSH_USER}@${LAN_IP}\`"
fi)

## Comptes

- admin : \`${ADMIN_EMAIL}\` / \`${ADMIN_PASSWORD}\`
- agent : \`${AGENT_EMAIL}\` / \`${AGENT_PASSWORD}\`
- mot de passe citoyen : \`${CITIZEN_PASSWORD}\`

Citoyens de demonstration :
${CITIZENS}

## Jeu De Demo

${INCIDENTS}

- consultation active : ${POLL_TITLE}
- evenement a venir : ${EVENT_TITLE}

## Artefacts A Utiliser

- bundle zip : \`/tmp/ma-commune-latest-livraison-bundle.zip\`
- checksum bundle : \`/tmp/ma-commune-latest-livraison-bundle.zip.sha256\`
- audit final local : \`/tmp/ma-commune-latest-audit-final-local.md\`
- manifeste latest : \`/tmp/ma-commune-latest-manifest-livraison-locale.json\`
- brief acces latest : \`/tmp/ma-commune-latest-access-brief.txt\`
- controle brief acces latest : \`/tmp/ma-commune-latest-access-brief-check.log\`
$(if [[ -n "${ACCESS_BRIEF_SHA:-}" ]]; then
  printf '%s\n' "- empreinte brief acces : \`${ACCESS_BRIEF_SHA}\`"
fi)
- APK tablette : \`/tmp/ma-commune-latest-app-release-tablette.apk\`
- ABI tablette : \`${APK_TABLETTE_ABI}\`
- APK tablette source : \`${APK_TABLETTE}\`

## Quand La Phase Tablette Sera Autorisee

1. verifier le bundle latest et son checksum
2. utiliser uniquement l'APK tablette cible \`${APK_TABLETTE_ABI}\`
3. configurer l'URL serveur dans l'application, en USB ou via l'URL LAN reelle
4. jouer le parcours citoyen, puis agent, puis admin
5. verifier logo, splash, notifications et "Mon bilan" sur appareil reel
EOF

printf 'OUTPUT=%s\n' "$OUTPUT_MD"
