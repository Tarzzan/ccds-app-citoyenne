#!/usr/bin/env bash
set -euo pipefail

BUREAU_BRIEF="${1:-/home/tarzzan/Bureau/macommune.txt}"
DESKTOP_BRIEF="${2:-/home/tarzzan/Desktop/macommune.txt}"
LATEST_BRIEF="${3:-/tmp/ma-commune-latest-access-brief.txt}"

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    printf 'ECHEC: commande requise absente: %s\n' "$1" >&2
    exit 1
  }
}

require_cmd sha256sum

for required_file in "$BUREAU_BRIEF" "$DESKTOP_BRIEF" "$LATEST_BRIEF"; do
  [[ -f "$required_file" ]] || {
    printf 'ECHEC: brief acces introuvable: %s\n' "$required_file" >&2
    exit 1
  }
done

cmp -s "$BUREAU_BRIEF" "$DESKTOP_BRIEF" || {
  printf 'ECHEC: divergence entre le brief Bureau et la copie Desktop\n' >&2
  exit 1
}

cmp -s "$BUREAU_BRIEF" "$LATEST_BRIEF" || {
  printf 'ECHEC: divergence entre le brief Bureau et le brief latest\n' >&2
  exit 1
}

printf '[access-brief] OK   brief Bureau, Desktop et latest strictement alignes\n'
printf 'bureau: %s\n' "$BUREAU_BRIEF"
printf 'desktop: %s\n' "$DESKTOP_BRIEF"
printf 'latest: %s\n' "$LATEST_BRIEF"
printf 'sha256: %s\n' "$(sha256sum "$BUREAU_BRIEF" | awk '{print $1}')"
