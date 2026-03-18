#!/usr/bin/env bash
set -euo pipefail

MANIFEST="/tmp/ma-commune-latest-manifest-livraison-locale.json"
INDEX="/tmp/ma-commune-latest-livraison-index.md"
VERIFY_LOG="/tmp/ma-commune-verify-latest.log"
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

LAN_IP="$("$SCRIPT_DIR/detect_primary_lan_ip.sh" 2>/dev/null || true)"
SSH_USER="$(getent passwd 1000 2>/dev/null | cut -d: -f1 || true)"

if [[ -z "${SSH_USER:-}" ]]; then
  SSH_USER="$(whoami)"
fi

printf 'Ma Commune - Latest Livraison Locale\n'
printf '\n'
printf 'Decision:\n'
printf -- '- gate local avant tablette: %s\n' "$(jq -r '.decision.gate_local_avant_tablette' "$MANIFEST")"
printf -- '- coherence marque couche active: %s\n' "$(jq -r '.decision.coherence_marque_couche_active' "$MANIFEST")"
printf -- '- coherence systeme visuel categories: %s\n' "$(jq -r '.decision.coherence_systeme_visuel_categories' "$MANIFEST")"
printf -- '- livraison finale appareil: %s\n' "$(jq -r '.decision.livraison_finale_appareil' "$MANIFEST")"
printf '\n'
printf 'Credentials:\n'
printf -- '- admin: %s\n' "$(jq -r '.credentials.admin.email' "$MANIFEST")"
printf -- '- agent: %s\n' "$(jq -r '.credentials.agent.email' "$MANIFEST")"
printf -- '- mot de passe citoyen: %s\n' "$(jq -r '.credentials.citizen_password' "$MANIFEST")"
printf '\n'
if [[ -n "${LAN_IP:-}" ]]; then
  printf 'Acces LAN:\n'
  printf -- '- IP machine: %s\n' "$LAN_IP"
  printf -- '- API mobile: http://%s:8080/api\n' "$LAN_IP"
  printf -- '- admin web: http://%s:8080/admin/?page=login\n' "$LAN_IP"
  printf -- '- SSH: ssh %s@%s\n' "$SSH_USER" "$LAN_IP"
  printf '\n'
fi
printf 'Artefacts:\n'
printf -- '- bundle zip: /tmp/ma-commune-latest-livraison-bundle.zip\n'
printf -- '- checksum bundle zip: /tmp/ma-commune-latest-livraison-bundle.zip.sha256\n'
printf -- '- audit final local: /tmp/ma-commune-latest-audit-final-local.md\n'
printf -- '- manifeste: %s\n' "$MANIFEST"
printf -- '- handoff latest: /tmp/ma-commune-latest-handoff.md\n'
printf -- '- APK tablette: /tmp/ma-commune-latest-app-release-tablette.apk\n'
printf -- '- ABI tablette: %s\n' "$(jq -r '.artifacts.apk_tablette.abi' "$MANIFEST")"
printf '\n'
printf 'References:\n'
printf -- '- index latest: %s\n' "$INDEX"
printf -- '- verification latest: %s\n' "$VERIFY_LOG"
printf -- '- acces reseau local: %s/show_local_network_access.sh\n' "$SCRIPT_DIR"
