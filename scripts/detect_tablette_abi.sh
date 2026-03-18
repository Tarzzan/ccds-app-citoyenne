#!/usr/bin/env bash
set -euo pipefail

TABLET_SERIAL="${1:-${ANDROID_SERIAL:-}}"
DEFAULT_ABI="${DEFAULT_TABLET_ABI:-armeabi-v7a}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TABLET_OUTPUT_DIR="${ROOT_DIR}/mobile/android/app/build/outputs/apk/tablette"

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    printf 'ECHEC: commande requise absente: %s\n' "$1" >&2
    exit 1
  }
}

latest_built_abi() {
  local latest_apk
  latest_apk="$(find "$TABLET_OUTPUT_DIR" -maxdepth 1 -type f -name 'app-release-*.apk' -printf '%T@ %f\n' 2>/dev/null | sort -nr | head -n1 | awk '{print $2}')"
  [[ -n "${latest_apk:-}" ]] || return 1
  printf '%s\n' "$latest_apk" | sed -n 's/^app-release-\(.*\)-[0-9][0-9]*\.apk$/\1/p'
}

if ! command -v adb >/dev/null 2>&1; then
  ABI="$(latest_built_abi || true)"
  ABI="${ABI:-$DEFAULT_ABI}"
  printf 'SERIAL=\n'
  printf 'ABI=%s\n' "$ABI"
  printf 'SOURCE=%s\n' "fallback"
  exit 0
fi

if [[ -z "$TABLET_SERIAL" ]]; then
  TABLET_SERIAL="$(adb devices | awk 'NR>1 && $2=="device" {print $1; exit}')"
fi

if [[ -z "${TABLET_SERIAL:-}" ]]; then
  ABI="$(latest_built_abi || true)"
  ABI="${ABI:-$DEFAULT_ABI}"
  printf 'SERIAL=\n'
  printf 'ABI=%s\n' "$ABI"
  printf 'SOURCE=%s\n' "fallback"
  exit 0
fi

ABI="$(adb -s "$TABLET_SERIAL" shell getprop ro.product.cpu.abi | tr -d '\r')"
[[ -n "${ABI:-}" ]] || {
  printf 'ECHEC: ABI tablette introuvable pour %s\n' "$TABLET_SERIAL" >&2
  exit 1
}

printf 'SERIAL=%s\n' "$TABLET_SERIAL"
printf 'ABI=%s\n' "$ABI"
printf 'SOURCE=%s\n' "adb"
