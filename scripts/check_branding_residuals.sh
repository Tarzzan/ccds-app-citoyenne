#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP_MATCHES="$(mktemp)"
TMP_UNEXPECTED="$(mktemp)"
trap 'rm -f "$TMP_MATCHES" "$TMP_UNEXPECTED"' EXIT

cd "$ROOT_DIR"

rg -n 'CCDS|ccds_' \
  admin \
  backend \
  mobile \
  scripts \
  docker \
  README.md \
  Dockerfile \
  composer.json \
  docker-compose.yml \
  phpunit.xml \
  --glob '!scripts/check_branding_residuals.sh' \
  >"$TMP_MATCHES" || true

if [[ ! -s "$TMP_MATCHES" ]]; then
  printf '[branding-check] OK   aucun residu CCDS detecte dans la couche active\n'
  exit 0
fi

grep -Ev \
  -e '^mobile/src/services/OfflineQueue\.ts:.*LEGACY_QUEUE_KEY.*ccds_offline_queue.*$' \
  -e '^mobile/src/i18n/i18n\.ts:.*LEGACY_LANGUAGE_KEY.*@ccds_language.*$' \
  -e '^mobile/src/theme/ThemeContext\.tsx:.*LEGACY_STORAGE_KEY.*@ccds_theme_mode.*$' \
  -e '^mobile/src/screens/OnboardingScreen\.tsx:.*LEGACY_ONBOARDING_KEY.*ccds_onboarding_done.*$' \
  -e '^README\.md:.*docs/slides_ccds_content\.md.*$' \
  "$TMP_MATCHES" >"$TMP_UNEXPECTED" || true

if [[ -s "$TMP_UNEXPECTED" ]]; then
  printf '[branding-check] KO   residus CCDS inattendus detectes dans la couche active\n' >&2
  cat "$TMP_UNEXPECTED" >&2
  exit 1
fi

printf '[branding-check] OK   seuls les residus legacy/historiques autorises subsistent\n'
