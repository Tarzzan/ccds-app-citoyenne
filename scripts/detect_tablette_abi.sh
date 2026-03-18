#!/usr/bin/env bash
set -euo pipefail

TABLET_SERIAL="${1:-${ANDROID_SERIAL:-}}"

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    printf 'ECHEC: commande requise absente: %s\n' "$1" >&2
    exit 1
  }
}

require_cmd adb

if [[ -z "$TABLET_SERIAL" ]]; then
  TABLET_SERIAL="$(adb devices | awk 'NR>1 && $2=="device" {print $1; exit}')"
fi

[[ -n "${TABLET_SERIAL:-}" ]] || {
  printf 'ECHEC: aucun appareil adb detecte pour determiner l ABI tablette\n' >&2
  exit 1
}

ABI="$(adb -s "$TABLET_SERIAL" shell getprop ro.product.cpu.abi | tr -d '\r')"
[[ -n "${ABI:-}" ]] || {
  printf 'ECHEC: ABI tablette introuvable pour %s\n' "$TABLET_SERIAL" >&2
  exit 1
}

printf 'SERIAL=%s\n' "$TABLET_SERIAL"
printf 'ABI=%s\n' "$ABI"
