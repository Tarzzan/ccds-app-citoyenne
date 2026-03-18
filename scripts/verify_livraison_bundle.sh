#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  printf 'Usage: %s /tmp/ma-commune-livraison-bundle-<stamp>.zip\n' "$(basename "$0")" >&2
  exit 1
fi

BUNDLE_ZIP="$1"
[[ -f "$BUNDLE_ZIP" ]] || {
  printf 'ECHEC: bundle zip introuvable: %s\n' "$BUNDLE_ZIP" >&2
  exit 1
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    printf 'ECHEC: commande requise absente: %s\n' "$1" >&2
    exit 1
  }
}

require_cmd unzip
require_cmd zipinfo
require_cmd sha256sum

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

unzip -q "$BUNDLE_ZIP" -d "$WORK_DIR"

ROOT_ENTRY="$(zipinfo -1 "$BUNDLE_ZIP" | head -n1 | cut -d/ -f1)"
BUNDLE_DIR="$WORK_DIR/$ROOT_ENTRY"

[[ -d "$BUNDLE_DIR" ]] || {
  printf 'ECHEC: dossier racine du bundle introuvable apres extraction\n' >&2
  exit 1
}

for required_path in \
  "$BUNDLE_DIR/README_BUNDLE.md" \
  "$BUNDLE_DIR/checksums/SHA256SUMS.txt" \
  "$BUNDLE_DIR/artifacts" \
  "$BUNDLE_DIR/apk" \
  "$BUNDLE_DIR/docs"; do
  [[ -e "$required_path" ]] || {
    printf 'ECHEC: element requis absent du bundle: %s\n' "$required_path" >&2
    exit 1
  }
done

[[ -f "$BUNDLE_DIR/artifacts/ma-commune-handoff-livraison.md" ]] || {
  printf 'ECHEC: handoff operateur absent du bundle\n' >&2
  exit 1
}

[[ -f "$BUNDLE_DIR/artifacts/macommune.txt" ]] || {
  printf 'ECHEC: brief acces macommune absent du bundle\n' >&2
  exit 1
}

[[ -f "$BUNDLE_DIR/artifacts/ma-commune-access-brief-consistency.log" ]] || {
  printf 'ECHEC: controle de coherence du brief acces absent du bundle\n' >&2
  exit 1
}

grep -q '\[access-brief\] OK' "$BUNDLE_DIR/artifacts/ma-commune-access-brief-consistency.log" || {
  printf 'ECHEC: controle du brief acces embarque sans statut OK\n' >&2
  exit 1
}

(
  cd "$BUNDLE_DIR"
  sha256sum -c checksums/SHA256SUMS.txt >/tmp/ma-commune-verify-bundle.log
)

APK_COUNT="$(find "$BUNDLE_DIR/apk" -maxdepth 1 -type f -name '*.apk' | wc -l | tr -d ' ')"
[[ "$APK_COUNT" -ge 1 ]] || {
  printf 'ECHEC: aucun APK present dans le bundle\n' >&2
  exit 1
}

printf '[bundle-verify] OK   bundle valide et checksums conformes\n'
