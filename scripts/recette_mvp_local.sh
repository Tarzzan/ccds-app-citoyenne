#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-http://127.0.0.1:8080/api}"
EMAIL="citoyen.$(date +%s)@macommune.local"
PASSWORD="Citoyen@MaCommune2026!"

extract_json_field() {
  local field="$1"
  sed -n "s/.*\"${field}\":\"\\([^\"]*\\)\".*/\\1/p"
}

extract_json_number() {
  local field="$1"
  sed -n "s/.*\"${field}\":\\([0-9][0-9]*\\).*/\\1/p"
}

REGISTER="$(curl -sS -X POST "${BASE_URL}/register" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"${EMAIL}\",\"password\":\"${PASSWORD}\",\"full_name\":\"Citoyen Recette\"}")"
CITIZEN_TOKEN="$(printf '%s' "${REGISTER}" | extract_json_field token)"

INCIDENT="$(curl -sS -X POST "${BASE_URL}/incidents" \
  -H "Authorization: Bearer ${CITIZEN_TOKEN}" \
  -F category_id=1 \
  -F title='Recette agent terrain' \
  -F description='Nid de poule recette automatique pour validation du flux' \
  -F latitude=5.1597 \
  -F longitude=-52.6498 \
  -F address='Kourou centre')"
INCIDENT_ID="$(printf '%s' "${INCIDENT}" | extract_json_number id)"
REFERENCE="$(printf '%s' "${INCIDENT}" | extract_json_field reference)"

AGENT_LOGIN="$(curl -sS -X POST "${BASE_URL}/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"agent@macommune.local","password":"agent@test.fr"}')"
AGENT_TOKEN="$(printf '%s' "${AGENT_LOGIN}" | extract_json_field token)"

UPDATE="$(curl -sS -X PUT "${BASE_URL}/incidents/${INCIDENT_ID}" \
  -H "Authorization: Bearer ${AGENT_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"status":"resolved","note":"Execution validee sur le terrain via recette automatique.","priority":"high","assigned_to":4}')"

ADMIN_LOGIN="$(curl -sS -X POST "${BASE_URL}/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@macommune.local","password":"admin@test.fr"}')"
ADMIN_TOKEN="$(printf '%s' "${ADMIN_LOGIN}" | extract_json_field token)"

DETAIL="$(curl -sS "${BASE_URL}/incidents/${INCIDENT_ID}" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}")"

printf 'EMAIL=%s\n' "${EMAIL}"
printf 'INCIDENT_ID=%s\n' "${INCIDENT_ID}"
printf 'REFERENCE=%s\n' "${REFERENCE}"
printf 'UPDATE=%s\n' "${UPDATE}"
printf 'DETAIL=%s\n' "${DETAIL}"
