#!/usr/bin/env bash
set -euo pipefail

LATEST_ACCESS_BRIEF="/tmp/ma-commune-latest-access-brief.txt"

[[ -f "$LATEST_ACCESS_BRIEF" ]] || {
  printf 'ECHEC: brief acces latest introuvable: %s\n' "$LATEST_ACCESS_BRIEF" >&2
  exit 1
}

cat "$LATEST_ACCESS_BRIEF"
