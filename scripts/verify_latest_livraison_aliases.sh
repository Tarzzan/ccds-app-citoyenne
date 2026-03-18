#!/usr/bin/env bash
set -euo pipefail

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    printf 'ECHEC: commande requise absente: %s\n' "$1" >&2
    exit 1
  }
}

require_cmd bash
require_cmd jq
require_cmd readlink

LATEST_BUNDLE_ZIP="/tmp/ma-commune-latest-livraison-bundle.zip"
LATEST_BUNDLE_ZIP_SHA="/tmp/ma-commune-latest-livraison-bundle.zip.sha256"
LATEST_BUNDLE_DIR="/tmp/ma-commune-latest-livraison-bundle"
LATEST_MANIFEST="/tmp/ma-commune-latest-manifest-livraison-locale.json"
LATEST_AUDIT="/tmp/ma-commune-latest-audit-final-local.md"
LATEST_GATE="/tmp/ma-commune-latest-gate-avant-tablette.md"
LATEST_PREP="/tmp/ma-commune-latest-preparation-livraison.md"
LATEST_SEED="/tmp/ma-commune-latest-demo-seed.json"
LATEST_APK="/tmp/ma-commune-latest-app-release-tablette.apk"
LATEST_INDEX="/tmp/ma-commune-latest-livraison-index.md"
LATEST_HANDOFF="/tmp/ma-commune-latest-handoff.md"
LATEST_ACCESS_BRIEF="/tmp/ma-commune-latest-access-brief.txt"
LATEST_ACCESS_BRIEF_LOG="/tmp/ma-commune-latest-access-brief-check.log"

for required_link in \
  "$LATEST_BUNDLE_ZIP" \
  "$LATEST_BUNDLE_ZIP_SHA" \
  "$LATEST_BUNDLE_DIR" \
  "$LATEST_MANIFEST" \
  "$LATEST_AUDIT" \
  "$LATEST_GATE" \
  "$LATEST_PREP" \
  "$LATEST_SEED" \
  "$LATEST_APK" \
  "$LATEST_INDEX" \
  "$LATEST_HANDOFF" \
  "$LATEST_ACCESS_BRIEF" \
  "$LATEST_ACCESS_BRIEF_LOG"; do
  [[ -e "$required_link" ]] || {
    printf 'ECHEC: alias latest absent: %s\n' "$required_link" >&2
    exit 1
  }
done

BUNDLE_ZIP_REAL="$(readlink -f "$LATEST_BUNDLE_ZIP")"
BUNDLE_ZIP_SHA_REAL="$(readlink -f "$LATEST_BUNDLE_ZIP_SHA")"
BUNDLE_DIR_REAL="$(readlink -f "$LATEST_BUNDLE_DIR")"
MANIFEST_REAL="$(readlink -f "$LATEST_MANIFEST")"
AUDIT_REAL="$(readlink -f "$LATEST_AUDIT")"
APK_REAL="$(readlink -f "$LATEST_APK")"
HANDOFF_REAL="$(readlink -f "$LATEST_HANDOFF")"
ACCESS_BRIEF_REAL="$(readlink -f "$LATEST_ACCESS_BRIEF")"
ACCESS_BRIEF_LOG_REAL="$(readlink -f "$LATEST_ACCESS_BRIEF_LOG")"
INDEX_REAL="$(readlink -f "$LATEST_INDEX")"

[[ -f "$BUNDLE_ZIP_REAL" && -d "$BUNDLE_DIR_REAL" ]] || {
  printf 'ECHEC: resolution latest bundle invalide\n' >&2
  exit 1
}

[[ -f "$BUNDLE_ZIP_SHA_REAL" ]] || {
  printf 'ECHEC: resolution checksum latest invalide\n' >&2
  exit 1
}

case "$MANIFEST_REAL" in
  "$BUNDLE_DIR_REAL"/*) ;;
  *)
    printf 'ECHEC: le manifeste latest ne pointe pas vers le bundle latest\n' >&2
    exit 1
    ;;
esac

case "$AUDIT_REAL" in
  "$BUNDLE_DIR_REAL"/*) ;;
  *)
    printf 'ECHEC: l audit latest ne pointe pas vers le bundle latest\n' >&2
    exit 1
    ;;
esac

case "$APK_REAL" in
  "$BUNDLE_DIR_REAL"/*) ;;
  *)
    printf 'ECHEC: l APK latest ne pointe pas vers le bundle latest\n' >&2
    exit 1
    ;;
esac

case "$HANDOFF_REAL" in
  "$BUNDLE_DIR_REAL"/*) ;;
  *)
    printf 'ECHEC: le handoff latest ne pointe pas vers le bundle latest\n' >&2
    exit 1
    ;;
esac

case "$ACCESS_BRIEF_REAL" in
  "$BUNDLE_DIR_REAL"/*) ;;
  *)
    printf 'ECHEC: le brief acces latest ne pointe pas vers le bundle latest\n' >&2
    exit 1
    ;;
esac

case "$ACCESS_BRIEF_LOG_REAL" in
  "$BUNDLE_DIR_REAL"/*) ;;
  *)
    printf 'ECHEC: le log latest du brief acces ne pointe pas vers le bundle latest\n' >&2
    exit 1
    ;;
esac

[[ -f "$ACCESS_BRIEF_LOG_REAL" ]] || {
  printf 'ECHEC: le log de controle du brief acces latest est introuvable\n' >&2
  exit 1
}

grep -q '/tmp/ma-commune-latest-livraison-bundle.zip' "$INDEX_REAL" || {
  printf 'ECHEC: index latest incomplet sur le bundle zip\n' >&2
  exit 1
}

grep -q 'commit bundle latest :' "$INDEX_REAL" || {
  printf 'ECHEC: index latest ne rappelle pas le commit du bundle\n' >&2
  exit 1
}

grep -q 'worktree propre au moment du bundle :' "$INDEX_REAL" || {
  printf 'ECHEC: index latest ne rappelle pas l etat du worktree\n' >&2
  exit 1
}

grep -q '/tmp/ma-commune-latest-livraison-bundle.zip.sha256' "$INDEX_REAL" || {
  printf 'ECHEC: index latest incomplet sur le checksum du bundle\n' >&2
  exit 1
}

grep -q '/tmp/ma-commune-latest-handoff.md' "$INDEX_REAL" || {
  printf 'ECHEC: index latest incomplet sur le handoff\n' >&2
  exit 1
}

grep -q '/tmp/ma-commune-latest-access-brief.txt' "$INDEX_REAL" || {
  printf 'ECHEC: index latest incomplet sur le brief acces\n' >&2
  exit 1
}

grep -q '/tmp/ma-commune-latest-access-brief-check.log' "$INDEX_REAL" || {
  printf 'ECHEC: index latest incomplet sur le controle du brief acces\n' >&2
  exit 1
}

grep -q '/tmp/ma-commune-latest-livraison-bundle.zip' "$HANDOFF_REAL" || {
  printf 'ECHEC: handoff latest incomplet sur le bundle zip\n' >&2
  exit 1
}

grep -q '/tmp/ma-commune-latest-livraison-bundle.zip.sha256' "$HANDOFF_REAL" || {
  printf 'ECHEC: handoff latest incomplet sur le checksum du bundle\n' >&2
  exit 1
}

grep -q '/tmp/ma-commune-latest-app-release-tablette.apk' "$HANDOFF_REAL" || {
  printf 'ECHEC: handoff latest ne reference pas l alias APK latest\n' >&2
  exit 1
}

grep -q 'Dernier commit local:' "$ACCESS_BRIEF_REAL" || {
  printf 'ECHEC: brief acces latest incomplet sur le commit local\n' >&2
  exit 1
}

grep -q 'commit bundle latest :' "$HANDOFF_REAL" || {
  printf 'ECHEC: handoff latest ne rappelle pas le commit du bundle\n' >&2
  exit 1
}

grep -q '\[access-brief\] OK' "$ACCESS_BRIEF_LOG_REAL" || {
  printf 'ECHEC: log latest du brief acces ne contient pas le statut OK\n' >&2
  exit 1
}

grep -q '^sha256: ' "$ACCESS_BRIEF_LOG_REAL" || {
  printf 'ECHEC: log latest du brief acces ne contient pas l empreinte SHA-256\n' >&2
  exit 1
}

ACCESS_BRIEF_SHA="$(sed -n 's/^sha256: //p' "$ACCESS_BRIEF_LOG_REAL")"
HANDOFF_BRIEF_SHA="$(sed -n 's/^- empreinte brief acces : `\(.*\)`/\1/p' "$HANDOFF_REAL")"

[[ -n "$ACCESS_BRIEF_SHA" && -n "$HANDOFF_BRIEF_SHA" ]] || {
  printf 'ECHEC: empreinte du brief acces absente du handoff ou du log latest\n' >&2
  exit 1
}

[[ "$ACCESS_BRIEF_SHA" = "$HANDOFF_BRIEF_SHA" ]] || {
  printf 'ECHEC: empreinte du brief acces incoherente entre handoff et log latest\n' >&2
  exit 1
}

grep -q 'gate local avant tablette : PASS' "$HANDOFF_REAL" || {
  printf 'ECHEC: handoff latest ne rappelle pas le PASS local\n' >&2
  exit 1
}

GATE_STATUS="$(jq -r '.decision.gate_local_avant_tablette' "$MANIFEST_REAL")"
DEVICE_STATUS="$(jq -r '.decision.livraison_finale_appareil' "$MANIFEST_REAL")"
BRANDING_STATUS="$(jq -r '.decision.coherence_marque_couche_active' "$MANIFEST_REAL")"
CATEGORY_VISUALS_STATUS="$(jq -r '.decision.coherence_systeme_visuel_categories' "$MANIFEST_REAL")"
LATEST_HEAD="$(jq -r '.project.git.head_commit_short // ""' "$MANIFEST_REAL")"
LATEST_HEAD_SUBJECT="$(jq -r '.project.git.head_subject // ""' "$MANIFEST_REAL")"

[[ "$GATE_STATUS" = "PASS" ]] || {
  printf 'ECHEC: gate latest non PASS\n' >&2
  exit 1
}

[[ "$DEVICE_STATUS" = "NON_AUTORISEE_A_CE_STADE" ]] || {
  printf 'ECHEC: statut appareil latest inattendu\n' >&2
  exit 1
}

[[ "$BRANDING_STATUS" = "PASS" ]] || {
  printf 'ECHEC: coherence marque latest non PASS\n' >&2
  exit 1
}

[[ "$CATEGORY_VISUALS_STATUS" = "PASS" ]] || {
  printf 'ECHEC: coherence categories visuelles latest non PASS\n' >&2
  exit 1
}

[[ -n "$LATEST_HEAD" && -n "$LATEST_HEAD_SUBJECT" ]] || {
  printf 'ECHEC: identite git du bundle latest absente du manifeste\n' >&2
  exit 1
}

EXPECTED_BUNDLE_SHA="$(awk '{print $1}' "$BUNDLE_ZIP_SHA_REAL")"
ACTUAL_BUNDLE_SHA="$(sha256sum "$BUNDLE_ZIP_REAL" | awk '{print $1}')"

[[ "$EXPECTED_BUNDLE_SHA" = "$ACTUAL_BUNDLE_SHA" ]] || {
  printf 'ECHEC: checksum du bundle latest incoherent\n' >&2
  exit 1
}

printf '[latest-aliases] OK   alias stables coherents avec le dernier bundle verifie\n'
