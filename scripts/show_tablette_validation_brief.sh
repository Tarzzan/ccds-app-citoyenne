#!/usr/bin/env bash
set -euo pipefail

MANIFEST="/tmp/ma-commune-latest-manifest-livraison-locale.json"
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

printf 'Ma Commune - Brief Validation Tablette\n'
printf '\n'
printf 'Decision:\n'
printf -- '- gate local: %s\n' "$(jq -r '.decision.gate_local_avant_tablette' "$MANIFEST")"
printf -- '- coherence marque: %s\n' "$(jq -r '.decision.coherence_marque_couche_active' "$MANIFEST")"
printf -- '- coherence categories visuelles: %s\n' "$(jq -r '.decision.coherence_systeme_visuel_categories' "$MANIFEST")"
printf -- '- livraison finale appareil: %s\n' "$(jq -r '.decision.livraison_finale_appareil' "$MANIFEST")"
printf '\n'
printf 'URLs:\n'
printf -- '- API mobile: %s\n' "$(jq -r '.urls.api' "$MANIFEST")"
printf -- '- admin web: %s\n' "$(jq -r '.urls.admin' "$MANIFEST")"
if [[ -n "${LAN_IP:-}" ]]; then
  printf -- '- API mobile LAN: http://%s:8080/api\n' "$LAN_IP"
  printf -- '- admin web LAN: http://%s:8080/admin/?page=login\n' "$LAN_IP"
  printf -- '- SSH LAN: ssh %s@%s\n' "$SSH_USER" "$LAN_IP"
fi
printf '\n'
printf 'Compte metier:\n'
printf -- '- admin: %s / %s\n' \
  "$(jq -r '.credentials.admin.email' "$MANIFEST")" \
  "$(jq -r '.credentials.admin.password' "$MANIFEST")"
printf -- '- agent: %s / %s\n' \
  "$(jq -r '.credentials.agent.email' "$MANIFEST")" \
  "$(jq -r '.credentials.agent.password' "$MANIFEST")"
printf '\n'
printf 'Comptes citoyens:\n'
jq -r '.credentials.citizens[] | "- " + .email + " (" + .full_name + ")"' "$MANIFEST"
printf -- '- mot de passe commun: %s\n' "$(jq -r '.credentials.citizen_password' "$MANIFEST")"
printf '\n'
printf 'Jeu de demo:\n'
jq -r '.demo.incidents[] | "- incident " + .reference + " [" + .status + "] : " + .title' "$MANIFEST"
printf -- '- consultation: %s\n' "$(jq -r '.demo.poll.title' "$MANIFEST")"
printf -- '- evenement: %s\n' "$(jq -r '.demo.event.title' "$MANIFEST")"
printf '\n'
printf 'Artefacts a utiliser:\n'
printf -- '- APK tablette: %s\n' "$(jq -r '.artifacts.apk_tablette.path' "$MANIFEST")"
printf -- '- ABI tablette: %s\n' "$(jq -r '.artifacts.apk_tablette.abi' "$MANIFEST")"
printf -- '- bundle latest: /tmp/ma-commune-latest-livraison-bundle.zip\n'
printf -- '- checksum bundle: /tmp/ma-commune-latest-livraison-bundle.zip.sha256\n'
printf -- '- audit final local: /tmp/ma-commune-latest-audit-final-local.md\n'
printf -- '- index latest: /tmp/ma-commune-latest-livraison-index.md\n'
printf -- '- brief acces latest: /tmp/ma-commune-latest-access-brief.txt\n'
printf -- '- alias APK tablette: /tmp/ma-commune-latest-app-release-tablette.apk\n'
printf '\n'
printf 'Checklist immediate quand la phase tablette sera autorisee:\n'
printf -- '1. verifier le bundle latest et son checksum\n'
printf -- '2. utiliser uniquement l APK tablette cible correspondant a l ABI reelle de l appareil\n'
printf -- '3. configurer l URL serveur dans l application, en USB ou via l URL LAN reelle de la machine\n'
printf -- '4. jouer le parcours citoyen, puis agent, puis admin\n'
printf -- '5. verifier logo, splash, notifications et Mon bilan sur appareil reel\n'
