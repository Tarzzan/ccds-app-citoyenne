#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LAN_IP="$("$SCRIPT_DIR/detect_primary_lan_ip.sh")"
SSH_USER="$(getent passwd 1000 2>/dev/null | cut -d: -f1 || true)"

if [[ -z "${SSH_USER:-}" ]]; then
  SSH_USER="$(whoami)"
fi

printf 'Ma Commune - Acces Reseau Local\n'
printf '\n'
printf 'Machine:\n'
printf -- '- IP LAN: %s\n' "$LAN_IP"
printf -- '- SSH: ssh %s@%s\n' "$SSH_USER" "$LAN_IP"
printf '\n'
printf 'Services:\n'
printf -- '- API mobile: http://%s:8080/api\n' "$LAN_IP"
printf -- '- admin web: http://%s:8080/admin/?page=login\n' "$LAN_IP"
printf -- '- status: http://%s:8080/status\n' "$LAN_IP"
printf -- '- api-docs: http://%s:8080/api-docs/\n' "$LAN_IP"
printf -- '- websocket: ws://%s:8081\n' "$LAN_IP"
printf '\n'
printf 'Note:\n'
printf -- '- en mode LAN tablette, utiliser l URL API ci-dessus dans l app et ne pas compter sur adb reverse\n'
