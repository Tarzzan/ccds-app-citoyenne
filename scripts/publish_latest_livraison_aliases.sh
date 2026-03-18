#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

latest_file() {
  local pattern="$1"
  ls -t ${pattern} 2>/dev/null | head -n1 || true
}

link_latest() {
  local target="$1"
  local link_path="$2"
  [[ -n "${target:-}" && -e "$target" ]] || return 0
  ln -sfn "$target" "$link_path"
}

BUNDLE_ZIP="${1:-$(latest_file /tmp/ma-commune-livraison-bundle-*.zip)}"
BUNDLE_DIR="${2:-$(latest_file /tmp/ma-commune-livraison-bundle-*)}"
BUNDLE_ZIP_SHA="${3:-${BUNDLE_ZIP}.sha256}"

[[ -n "${BUNDLE_ZIP:-}" && -f "$BUNDLE_ZIP" ]] || {
  printf 'ECHEC: bundle zip introuvable\n' >&2
  exit 1
}

[[ -n "${BUNDLE_DIR:-}" && -d "$BUNDLE_DIR" ]] || {
  printf 'ECHEC: bundle dir introuvable\n' >&2
  exit 1
}

[[ -n "${BUNDLE_ZIP_SHA:-}" && -f "$BUNDLE_ZIP_SHA" ]] || {
  printf 'ECHEC: checksum du bundle introuvable\n' >&2
  exit 1
}

AUDIT_MD="$(latest_file "$BUNDLE_DIR"/artifacts/ma-commune-audit-final-local-*.md)"
MANIFEST_JSON="$(latest_file "$BUNDLE_DIR"/artifacts/ma-commune-manifest-livraison-locale-*.json)"
GATE_MD="$(latest_file "$BUNDLE_DIR"/artifacts/ma-commune-gate-avant-tablette-*.md)"
PREP_MD="$(latest_file "$BUNDLE_DIR"/artifacts/ma-commune-livraison-locale-*.md)"
SEED_JSON="$(latest_file "$BUNDLE_DIR"/artifacts/ma-commune-demo-seed-*.json)"
HANDOFF_MD="$BUNDLE_DIR/artifacts/ma-commune-handoff-livraison.md"
ACCESS_BRIEF="$BUNDLE_DIR/artifacts/macommune.txt"
BRANDING_LOG="$BUNDLE_DIR/artifacts/ma-commune-prepare-branding.log"
ACCESS_BRIEF_LOG="$BUNDLE_DIR/artifacts/ma-commune-access-brief-consistency.log"
CHECKSUMS_FILE="$BUNDLE_DIR/checksums/SHA256SUMS.txt"
TABLET_APK="$(latest_file "$BUNDLE_DIR"/apk/*.apk)"
INDEX_MD="$BUNDLE_DIR/artifacts/ma-commune-livraison-index.md"

link_latest "$BUNDLE_ZIP" /tmp/ma-commune-latest-livraison-bundle.zip
link_latest "$BUNDLE_ZIP_SHA" /tmp/ma-commune-latest-livraison-bundle.zip.sha256
link_latest "$BUNDLE_DIR" /tmp/ma-commune-latest-livraison-bundle
link_latest "$AUDIT_MD" /tmp/ma-commune-latest-audit-final-local.md
link_latest "$MANIFEST_JSON" /tmp/ma-commune-latest-manifest-livraison-locale.json
link_latest "$GATE_MD" /tmp/ma-commune-latest-gate-avant-tablette.md
link_latest "$PREP_MD" /tmp/ma-commune-latest-preparation-livraison.md
link_latest "$SEED_JSON" /tmp/ma-commune-latest-demo-seed.json
link_latest "$HANDOFF_MD" /tmp/ma-commune-latest-handoff.md
link_latest "$ACCESS_BRIEF" /tmp/ma-commune-latest-access-brief.txt
link_latest "$BRANDING_LOG" /tmp/ma-commune-latest-branding.log
link_latest "$ACCESS_BRIEF_LOG" /tmp/ma-commune-latest-access-brief-check.log
link_latest "$CHECKSUMS_FILE" /tmp/ma-commune-latest-checksums.txt
link_latest "$TABLET_APK" /tmp/ma-commune-latest-app-release-tablette.apk
link_latest "$INDEX_MD" /tmp/ma-commune-latest-livraison-index.md

printf 'INDEX=%s\n' "/tmp/ma-commune-latest-livraison-index.md"
