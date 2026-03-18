#!/usr/bin/env bash
set -euo pipefail

LATEST_ACCESS_BRIEF="/tmp/ma-commune-latest-access-brief.txt"
ACCESS_BRIEF_CHECK_LOG="/tmp/ma-commune-latest-access-brief-check.log"

[[ -f "$LATEST_ACCESS_BRIEF" ]] || {
  printf 'ECHEC: brief acces latest introuvable: %s\n' "$LATEST_ACCESS_BRIEF" >&2
  exit 1
}

[[ -f "$ACCESS_BRIEF_CHECK_LOG" ]] || {
  printf 'ECHEC: controle latest du brief acces introuvable: %s\n' "$ACCESS_BRIEF_CHECK_LOG" >&2
  exit 1
}

cat "$LATEST_ACCESS_BRIEF"
printf '\n'
printf 'Controle latest du brief acces\n'
printf '%s\n' '-------------------------------'
sed -n '1p' "$ACCESS_BRIEF_CHECK_LOG"
