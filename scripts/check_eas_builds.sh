#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MOBILE_DIR="$ROOT_DIR/mobile"

ANDROID_BUILD_ID="${1:-46aa616a-0ffe-492e-9037-da4e138e4117}"
IOS_BUILD_ID="${2:-9729ea19-6acd-4d7d-a4db-b41a7b37e8fb}"

if [[ -z "${EXPO_TOKEN:-}" ]]; then
  echo "EXPO_TOKEN absent. Exporte d'abord un token Expo pour interroger l'etat des builds." >&2
  exit 1
fi

fetch_build_json() {
  local build_id="$1"
  (
    cd "$MOBILE_DIR"
    eas build:view "$build_id" --json 2>/dev/null | python3 -c '
import sys
raw = sys.stdin.read()
start = raw.find("{")
if start == -1:
    raise SystemExit("Impossible de trouver le JSON de sortie Expo.")
print(raw[start:].strip())
'
  )
}

print_build_summary() {
  local label="$1"
  local build_id="$2"
  local json="$3"

  local status url commit profile platform
  status="$(printf '%s\n' "$json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["status"])')"
  commit="$(printf '%s\n' "$json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["gitCommitHash"])')"
  profile="$(printf '%s\n' "$json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["buildProfile"])')"
  platform="$(printf '%s\n' "$json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["platform"])')"
  url="https://expo.dev/accounts/william.meri/projects/ma-commune-guyane/builds/${build_id}"

  echo "${label}"
  echo "  id: ${build_id}"
  echo "  platform: ${platform}"
  echo "  profile: ${profile}"
  echo "  status: ${status}"
  echo "  commit: ${commit}"
  echo "  url: ${url}"

  local artifact_url
  artifact_url="$(printf '%s\n' "$json" | python3 -c 'import json,sys; data=json.load(sys.stdin); print((data.get("artifacts") or {}).get("buildUrl",""))')"
  if [[ -n "$artifact_url" ]]; then
    echo "  artifact: ${artifact_url}"
  fi
}

ANDROID_JSON="$(fetch_build_json "$ANDROID_BUILD_ID")"
IOS_JSON="$(fetch_build_json "$IOS_BUILD_ID")"

echo "Ma Commune — Etat EAS"
echo
print_build_summary "Android" "$ANDROID_BUILD_ID" "$ANDROID_JSON"
echo
print_build_summary "iOS" "$IOS_BUILD_ID" "$IOS_JSON"
