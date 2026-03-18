#!/usr/bin/env bash
set -euo pipefail

LATEST_ACCESS_BRIEF="/tmp/ma-commune-latest-access-brief.txt"
ACCESS_BRIEF_CHECK_LOG="/tmp/ma-commune-latest-access-brief-check.log"
LATEST_MANIFEST="/tmp/ma-commune-latest-manifest-livraison-locale.json"

[[ -f "$LATEST_ACCESS_BRIEF" ]] || {
  printf 'ECHEC: brief acces latest introuvable: %s\n' "$LATEST_ACCESS_BRIEF" >&2
  exit 1
}

[[ -f "$ACCESS_BRIEF_CHECK_LOG" ]] || {
  printf 'ECHEC: controle latest du brief acces introuvable: %s\n' "$ACCESS_BRIEF_CHECK_LOG" >&2
  exit 1
}

[[ -f "$LATEST_MANIFEST" ]] || {
  printf 'ECHEC: manifeste latest introuvable: %s\n' "$LATEST_MANIFEST" >&2
  exit 1
}

CURRENT_HEAD="$(git -C "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)" rev-parse --short HEAD)"
LATEST_HEAD="$(jq -r '.project.git.head_commit_short // "inconnu"' "$LATEST_MANIFEST")"
LATEST_HEAD_SUBJECT="$(jq -r '.project.git.head_subject // "inconnu"' "$LATEST_MANIFEST")"
if [[ "$CURRENT_HEAD" == "$LATEST_HEAD" ]]; then
  HEAD_ALIGNMENT="ALIGNE"
else
  HEAD_ALIGNMENT="EN_RETARD"
fi

cat "$LATEST_ACCESS_BRIEF"
printf '\n'
printf 'Controle latest du brief acces\n'
printf '%s\n' '-------------------------------'
sed -n '1,4p' "$ACCESS_BRIEF_CHECK_LOG"
printf '\n'
printf 'Alignement HEAD local vs bundle latest\n'
printf '%s\n' '--------------------------------------'
printf 'commit bundle latest : %s\n' "$LATEST_HEAD"
printf 'message bundle latest : %s\n' "$LATEST_HEAD_SUBJECT"
printf 'HEAD local courant : %s\n' "$CURRENT_HEAD"
printf 'alignement : %s\n' "$HEAD_ALIGNMENT"
