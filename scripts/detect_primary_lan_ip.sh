#!/usr/bin/env bash
set -euo pipefail

if command -v ip >/dev/null 2>&1; then
  ROUTE_IP="$(ip route get 1.1.1.1 2>/dev/null | awk '/src/ {for (i = 1; i <= NF; i++) if ($i == "src") { print $(i + 1); exit }}')"
  if [[ -n "${ROUTE_IP:-}" ]]; then
    printf '%s\n' "$ROUTE_IP"
    exit 0
  fi
fi

if command -v hostname >/dev/null 2>&1; then
  HOST_IPS="$(hostname -I 2>/dev/null || true)"
  if [[ -n "${HOST_IPS:-}" ]]; then
    printf '%s\n' "$HOST_IPS" | tr ' ' '\n' | awk '
      /^192\.168\./ { print; found = 1; exit }
      /^10\./ { print; found = 1; exit }
      /^172\.(1[6-9]|2[0-9]|3[0-1])\./ { print; found = 1; exit }
      END { if (!found) exit 1 }
    '
    exit 0
  fi
fi

printf 'ECHEC: impossible de detecter l IP LAN principale\n' >&2
exit 1
