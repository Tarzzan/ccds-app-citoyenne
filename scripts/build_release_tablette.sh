#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ANDROID_DIR="${ROOT_DIR}/mobile/android"
STAMP="$(date +%s)"
ABI="${1:-}"
OUTPUT_DIR="${ROOT_DIR}/mobile/android/app/build/outputs/apk/tablette"
SOURCE_APK="${ANDROID_DIR}/app/build/outputs/apk/release/app-release.apk"
TARGET_APK=""
LOCK_FILE="/tmp/ma-commune-android-build.lock"

ensure_android_sdk() {
  if [[ -n "${ANDROID_HOME:-}" && -d "${ANDROID_HOME}" ]]; then
    export ANDROID_SDK_ROOT="${ANDROID_SDK_ROOT:-$ANDROID_HOME}"
    return
  fi

  local candidates=(
    "/usr/lib/android-sdk"
    "/home/tarzzan/Android/Sdk"
    "/opt/android-sdk"
    "/opt/android-sdk-linux"
  )

  for candidate in "${candidates[@]}"; do
    if [[ -d "$candidate" ]]; then
      export ANDROID_HOME="$candidate"
      export ANDROID_SDK_ROOT="$candidate"
      return
    fi
  done

  printf 'ECHEC: Android SDK introuvable\n' >&2
  exit 1
}

log() {
  printf '[build-tablette] %s\n' "$1"
}

ensure_android_sdk
command -v flock >/dev/null 2>&1 || {
  printf 'ECHEC: commande requise absente: flock\n' >&2
  exit 1
}

if [[ -z "${ABI:-}" ]]; then
  ABI="$(bash "$ROOT_DIR/scripts/detect_tablette_abi.sh" | sed -n 's/^ABI=//p')"
fi

[[ -n "${ABI:-}" ]] || {
  printf 'ECHEC: ABI tablette cible introuvable\n' >&2
  exit 1
}

TARGET_APK="${OUTPUT_DIR}/app-release-${ABI}-${STAMP}.apk"
exec 9>"$LOCK_FILE"
flock 9
mkdir -p "$OUTPUT_DIR"

log "Build release cible ABI ${ABI}"
(
  cd "$ANDROID_DIR"
  NODE_ENV=production ./gradlew assembleRelease -PreactNativeArchitectures="${ABI}" >/tmp/ma-commune-build-tablette.log
)

[[ -f "$SOURCE_APK" ]] || {
  printf 'ECHEC: APK release introuvable apres build\n' >&2
  exit 1
}

cp "$SOURCE_APK" "$TARGET_APK"

printf 'ABI=%s\n' "$ABI"
printf 'APK=%s\n' "$TARGET_APK"
printf 'SIZE_BYTES=%s\n' "$(stat -c '%s' "$TARGET_APK")"
