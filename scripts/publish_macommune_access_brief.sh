#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MOBILE_DIR="$ROOT_DIR/mobile"
DESKTOP_DIR="/home/tarzzan/Desktop"
BUREAU_DIR="/home/tarzzan/Bureau"

ANDROID_BUILD_ID="${ANDROID_BUILD_ID:-46aa616a-0ffe-492e-9037-da4e138e4117}"
IOS_BUILD_ID="${IOS_BUILD_ID:-9729ea19-6acd-4d7d-a4db-b41a7b37e8fb}"
MOBILE_REFERENCE_COMMIT="${MOBILE_REFERENCE_COMMIT:-e054767}"

LANDING_URL="${LANDING_URL:-https://netetfix.com}"
API_URL="${API_URL:-https://api.netetfix.com/api}"
ADMIN_URL="${ADMIN_URL:-https://admin.netetfix.com/admin/?page=login}"
PIPL_URL="${PIPL_URL:-https://pipl.netetfix.com}"
LOCAL_API_URL="${LOCAL_API_URL:-http://192.168.1.55:8080/api}"
LOCAL_ADMIN_URL="${LOCAL_ADMIN_URL:-http://192.168.1.55:8080/admin/?page=login}"
LOCAL_SSH="${LOCAL_SSH:-ssh tarzzan@192.168.1.55}"

ADMIN_EMAIL="${ADMIN_EMAIL:-admin@macommune.local}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-Admin@MaCommune2026!}"
AGENT_EMAIL="${AGENT_EMAIL:-agent@macommune.local}"
AGENT_PASSWORD="${AGENT_PASSWORD:-Agent@MaCommune2026!}"
CITIZEN_PASSWORD="${CITIZEN_PASSWORD:-Citoyen@MaCommune2026!}"

OUTPUT_FILE="${OUTPUT_FILE:-$BUREAU_DIR/macommune.txt}"
SECONDARY_OUTPUT_FILE="${SECONDARY_OUTPUT_FILE:-$DESKTOP_DIR/macommune.txt}"
MANIFEST_PATH="${MANIFEST_PATH:-/tmp/ma-commune-latest-manifest-livraison-locale.json}"
LATEST_BUNDLE="/tmp/ma-commune-latest-livraison-bundle.zip"
LATEST_BUNDLE_SHA="/tmp/ma-commune-latest-livraison-bundle.zip.sha256"
LATEST_HANDOFF="/tmp/ma-commune-latest-handoff.md"
LATEST_APK="/tmp/ma-commune-latest-app-release-tablette.apk"

build_view_json() {
  local build_id="$1"
  (
    cd "$MOBILE_DIR"
    eas build:view "$build_id" --json 2>/dev/null | python3 -c '
import sys
raw = sys.stdin.read()
start = raw.find("{")
if start == -1:
    raise SystemExit(1)
print(raw[start:].strip())
'
  )
}

read_build_field() {
  local json="$1"
  local field="$2"
  printf '%s\n' "$json" | python3 -c '
import json, sys
data = json.load(sys.stdin)
field = sys.argv[1]
value = data
for part in field.split("."):
    if isinstance(value, dict):
        value = value.get(part)
    else:
        value = None
        break
if value is None:
    print("")
else:
    print(value)
' "$field"
}

populate_build_info() {
  local platform_label="$1"
  local build_id="$2"

  local default_url="https://expo.dev/accounts/william.meri/projects/ma-commune-guyane/builds/${build_id}"
  local status="UNKNOWN"
  local commit="$MOBILE_REFERENCE_COMMIT"
  local artifact=""
  local url="$default_url"

  if [[ -n "${EXPO_TOKEN:-}" ]]; then
    if json="$(build_view_json "$build_id" 2>/dev/null)"; then
      status="$(read_build_field "$json" "status")"
      commit="$(read_build_field "$json" "gitCommitHash")"
      artifact="$(read_build_field "$json" "artifacts.buildUrl")"
    fi
  fi

  printf '%s_status=%q\n' "$platform_label" "$status"
  printf '%s_commit=%q\n' "$platform_label" "$commit"
  printf '%s_url=%q\n' "$platform_label" "$url"
  printf '%s_artifact=%q\n' "$platform_label" "$artifact"
}

eval "$(populate_build_info android "$ANDROID_BUILD_ID")"
eval "$(populate_build_info ios "$IOS_BUILD_ID")"

repo_commit="$(git -C "$ROOT_DIR" rev-parse --short HEAD)"
repo_commit_message="$(git -C "$ROOT_DIR" log -1 --pretty=%s)"
upstream_ref="$(git -C "$ROOT_DIR" rev-parse --abbrev-ref --symbolic-full-name '@{upstream}' 2>/dev/null || true)"
repo_sync_status="Depot local sans upstream Git configure"
last_pushed_commit="inconnu"
repo_sync_note="Etat distant GitHub non reverifie dans cette passe"

if [[ -n "${upstream_ref:-}" ]]; then
  upstream_commit="$(git -C "$ROOT_DIR" rev-parse --short "$upstream_ref" 2>/dev/null || true)"
  last_pushed_commit="${upstream_commit:-inconnu}"
  if [[ -n "${upstream_commit:-}" && "$upstream_commit" == "$repo_commit" ]]; then
    repo_sync_status="Depot local et reference locale ${upstream_ref} alignes"
  else
    repo_sync_status="Depot local en avance sur la reference locale ${upstream_ref}"
  fi
fi

latest_gate_status="inconnu"
latest_branding_status="inconnu"
latest_category_visuals_status="inconnu"
latest_device_status="inconnu"
latest_tablet_abi="inconnue"
latest_citizen_examples=$'demo.citoyen.a.1773821871@macommune.local\ndemo.citoyen.b.1773821871@macommune.local\ndemo.citoyen.c.1773821871@macommune.local'

if [[ -f "$MANIFEST_PATH" ]]; then
  latest_gate_status="$(jq -r '.decision.gate_local_avant_tablette' "$MANIFEST_PATH")"
  latest_branding_status="$(jq -r '.decision.coherence_marque_couche_active' "$MANIFEST_PATH")"
  latest_category_visuals_status="$(jq -r '.decision.coherence_systeme_visuel_categories' "$MANIFEST_PATH")"
  latest_device_status="$(jq -r '.decision.livraison_finale_appareil' "$MANIFEST_PATH")"
  latest_tablet_abi="$(jq -r '.artifacts.apk_tablette.abi' "$MANIFEST_PATH")"
  latest_citizen_examples="$(jq -r '.credentials.citizens[].email' "$MANIFEST_PATH")"
fi

mkdir -p "$(dirname "$OUTPUT_FILE")" "$(dirname "$SECONDARY_OUTPUT_FILE")"

cat > "$OUTPUT_FILE" <<EOF
MA COMMUNE
==========

Etat du depot
-------------
- ${repo_sync_status}
- ${repo_sync_note}
- Dernier commit pousse sur main: ${last_pushed_commit}
- Dernier commit local: ${repo_commit}
- Message: ${repo_commit_message}
- Commit mobile de reference pour les builds EAS actuels: ${MOBILE_REFERENCE_COMMIT}
- GitHub: https://github.com/Tarzzan/ccds-app-citoyenne

Acces production
----------------
- Landing: ${LANDING_URL}
- API application: ${API_URL}
- Admin web: ${ADMIN_URL}
- PIPL: ${PIPL_URL}

Acces local reseau
------------------
- API locale: ${LOCAL_API_URL}
- Admin local: ${LOCAL_ADMIN_URL}
- SSH local: ${LOCAL_SSH}

Identifiants de demonstration
-----------------------------
- Admin
  email: ${ADMIN_EMAIL}
  mot de passe: ${ADMIN_PASSWORD}

- Agent
  email: ${AGENT_EMAIL}
  mot de passe: ${AGENT_PASSWORD}

- Citoyens de demo
  mot de passe commun: ${CITIZEN_PASSWORD}
  exemples seed recents:
$(printf '%s\n' "$latest_citizen_examples" | sed 's/^/  /')

Livraison locale latest
-----------------------
- gate local avant tablette: ${latest_gate_status}
- coherence marque couche active: ${latest_branding_status}
- coherence systeme visuel categories: ${latest_category_visuals_status}
- livraison finale appareil: ${latest_device_status}
- ABI tablette latest: ${latest_tablet_abi}
- bundle latest: ${LATEST_BUNDLE}
- checksum bundle latest: ${LATEST_BUNDLE_SHA}
- handoff latest: ${LATEST_HANDOFF}
- APK tablette latest: ${LATEST_APK}

Builds EAS de reference
-----------------------
- Android preview
  id: ${ANDROID_BUILD_ID}
  statut au moment du memo: ${android_status}
  commit embarque: ${android_commit:-$MOBILE_REFERENCE_COMMIT}
  lien: ${android_url}
EOF

if [[ -n "${android_artifact:-}" ]]; then
  cat >> "$OUTPUT_FILE" <<EOF
  artefact: ${android_artifact}
EOF
fi

cat >> "$OUTPUT_FILE" <<EOF

- iOS production
  id: ${IOS_BUILD_ID}
  statut au moment du memo: ${ios_status}
  commit embarque: ${ios_commit:-$MOBILE_REFERENCE_COMMIT}
  lien: ${ios_url}
EOF

if [[ -n "${ios_artifact:-}" ]]; then
  cat >> "$OUTPUT_FILE" <<EOF
  artefact: ${ios_artifact}
EOF
fi

cat >> "$OUTPUT_FILE" <<EOF

Suivi EAS
---------
- Script local de controle:
  ${ROOT_DIR}/scripts/check_eas_builds.sh
- Exemple:
  EXPO_TOKEN=... bash ${ROOT_DIR}/scripts/check_eas_builds.sh

Documentation collection categories
-----------------------------------
- Doc active:
  ${ROOT_DIR}/docs/CATEGORIES_VISUELLES_MA_COMMUNE_2026-03-18.md
- Apercu HTML:
  ${ROOT_DIR}/assets/category-visuals/index.html
- Apercu PNG:
  ${ROOT_DIR}/assets/category-visuals/generated/category-visuals-preview.png
- Regeneration:
  python3 ${ROOT_DIR}/scripts/generate_category_icons.py
- Controle:
  bash ${ROOT_DIR}/scripts/check_category_visuals.sh

Adresse a saisir dans l application
-----------------------------------
- Production: ${API_URL}
- Local reseau: ${LOCAL_API_URL}

Note securite
-------------
- Revoquer maintenant le token GitHub et le token Expo partages dans la conversation.
EOF

if [[ "$SECONDARY_OUTPUT_FILE" != "$OUTPUT_FILE" ]]; then
  cp "$OUTPUT_FILE" "$SECONDARY_OUTPUT_FILE"
fi

chown tarzzan:tarzzan "$OUTPUT_FILE" 2>/dev/null || true
if [[ "$SECONDARY_OUTPUT_FILE" != "$OUTPUT_FILE" ]]; then
  chown tarzzan:tarzzan "$SECONDARY_OUTPUT_FILE" 2>/dev/null || true
fi

echo "Brief publie:"
echo "  $OUTPUT_FILE"
if [[ "$SECONDARY_OUTPUT_FILE" != "$OUTPUT_FILE" ]]; then
  echo "  $SECONDARY_OUTPUT_FILE"
fi
