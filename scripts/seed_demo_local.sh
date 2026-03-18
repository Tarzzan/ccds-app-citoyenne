#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-http://127.0.0.1:8080/api}"
STAMP="$(date +%s)"

ADMIN_EMAIL="admin@macommune.local"
ADMIN_PASSWORD="admin@test.fr"
AGENT_EMAIL="agent@macommune.local"
AGENT_PASSWORD="agent@test.fr"
CITIZEN_PASSWORD="Citoyen@MaCommune2026!"

SUMMARY_FILE="/tmp/ma-commune-demo-seed-${STAMP}.json"

log() {
  printf '[seed-demo] %s\n' "$1"
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    printf 'ECHEC: commande requise absente: %s\n' "$1" >&2
    exit 1
  }
}

request_json() {
  curl -sS "$@"
}

assert_success() {
  local payload="$1"
  local label="$2"

  if ! printf '%s' "$payload" | jq -e '.success == true' >/dev/null 2>&1; then
    printf 'ECHEC: %s\n%s\n' "$label" "$payload" >&2
    exit 1
  fi
}

extract_json() {
  local payload="$1"
  local expr="$2"
  printf '%s' "$payload" | jq -r "$expr"
}

register_citizen() {
  local email="$1"
  local name="$2"

  request_json -X POST "${BASE_URL}/register" \
    -H 'Content-Type: application/json' \
    -d "{\"email\":\"${email}\",\"password\":\"${CITIZEN_PASSWORD}\",\"full_name\":\"${name}\"}"
}

create_incident() {
  local token="$1"
  local category_id="$2"
  local title="$3"
  local description="$4"
  local latitude="$5"
  local longitude="$6"
  local address="$7"

  request_json -X POST "${BASE_URL}/incidents" \
    -H "Authorization: Bearer ${token}" \
    -F "category_id=${category_id}" \
    -F "title=${title}" \
    -F "description=${description}" \
    -F "latitude=${latitude}" \
    -F "longitude=${longitude}" \
    -F "address=${address}"
}

log "Verification des prerequis"
require_cmd curl
require_cmd jq

log "Recuperation des categories"
CATEGORIES="$(request_json "${BASE_URL}/categories")"
assert_success "$CATEGORIES" 'liste categories'
CAT_PRIMARY="$(extract_json "$CATEGORIES" '.data[0].id // .[0].id // empty')"
CAT_SECONDARY="$(extract_json "$CATEGORIES" '.data[1].id // .[1].id // .data[0].id // .[0].id // empty')"

if [[ -z "$CAT_PRIMARY" || "$CAT_PRIMARY" == "null" ]]; then
  printf 'ECHEC: impossible de trouver une categorie pour le seed demo\n' >&2
  exit 1
fi

log "Connexion admin et agent"
ADMIN_LOGIN="$(request_json -X POST "${BASE_URL}/login" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"${ADMIN_EMAIL}\",\"password\":\"${ADMIN_PASSWORD}\"}")"
assert_success "$ADMIN_LOGIN" 'connexion admin'
ADMIN_TOKEN="$(extract_json "$ADMIN_LOGIN" '.data.token // .token // empty')"

AGENT_LOGIN="$(request_json -X POST "${BASE_URL}/login" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"${AGENT_EMAIL}\",\"password\":\"${AGENT_PASSWORD}\"}")"
assert_success "$AGENT_LOGIN" 'connexion agent'
AGENT_TOKEN="$(extract_json "$AGENT_LOGIN" '.data.token // .token // empty')"

DEMO_CITIZEN_A_EMAIL="demo.citoyen.a.${STAMP}@macommune.local"
DEMO_CITIZEN_B_EMAIL="demo.citoyen.b.${STAMP}@macommune.local"
DEMO_CITIZEN_C_EMAIL="demo.citoyen.c.${STAMP}@macommune.local"

log "Creation des comptes citoyens de demonstration"
CITIZEN_A_REGISTER="$(register_citizen "$DEMO_CITIZEN_A_EMAIL" 'Camille Demo Kourou')"
assert_success "$CITIZEN_A_REGISTER" 'creation citoyen A'
CITIZEN_A_TOKEN="$(extract_json "$CITIZEN_A_REGISTER" '.data.token // .token // empty')"

CITIZEN_B_REGISTER="$(register_citizen "$DEMO_CITIZEN_B_EMAIL" 'Noa Demo Pariacabo')"
assert_success "$CITIZEN_B_REGISTER" 'creation citoyen B'
CITIZEN_B_TOKEN="$(extract_json "$CITIZEN_B_REGISTER" '.data.token // .token // empty')"

CITIZEN_C_REGISTER="$(register_citizen "$DEMO_CITIZEN_C_EMAIL" 'Lina Demo Bourg')"
assert_success "$CITIZEN_C_REGISTER" 'creation citoyen C'
CITIZEN_C_TOKEN="$(extract_json "$CITIZEN_C_REGISTER" '.data.token // .token // empty')"

log "Creation des signalements de demonstration"
INCIDENT_SUBMITTED="$(create_incident \
  "$CITIZEN_A_TOKEN" \
  "$CAT_PRIMARY" \
  "Eclairage public en panne - Demo ${STAMP}" \
  "Deux lampadaires restent eteints a l entree du quartier en fin de journee." \
  "5.1597" \
  "-52.6498" \
  "Kourou centre - entree de quartier")"
assert_success "$INCIDENT_SUBMITTED" 'creation incident soumis'
INCIDENT_SUBMITTED_ID="$(extract_json "$INCIDENT_SUBMITTED" '.data.id // .id // empty')"
INCIDENT_SUBMITTED_REF="$(extract_json "$INCIDENT_SUBMITTED" '.data.reference // .reference // empty')"

INCIDENT_PROGRESS="$(create_incident \
  "$CITIZEN_B_TOKEN" \
  "$CAT_SECONDARY" \
  "Depot sauvage sur accotement - Demo ${STAMP}" \
  "Un amas de dechets grossit pres du carrefour et gene la circulation pietonne." \
  "5.1621" \
  "-52.6509" \
  "Kourou - carrefour du quartier")"
assert_success "$INCIDENT_PROGRESS" 'creation incident en cours'
INCIDENT_PROGRESS_ID="$(extract_json "$INCIDENT_PROGRESS" '.data.id // .id // empty')"
INCIDENT_PROGRESS_REF="$(extract_json "$INCIDENT_PROGRESS" '.data.reference // .reference // empty')"

INCIDENT_RESOLVED="$(create_incident \
  "$CITIZEN_A_TOKEN" \
  "$CAT_PRIMARY" \
  "Banc degrade aire de repos - Demo ${STAMP}" \
  "Le banc en bois est descelle et devient dangereux pour les familles." \
  "5.1649" \
  "-52.6470" \
  "Kourou - aire de repos communale")"
assert_success "$INCIDENT_RESOLVED" 'creation incident resolu'
INCIDENT_RESOLVED_ID="$(extract_json "$INCIDENT_RESOLVED" '.data.id // .id // empty')"
INCIDENT_RESOLVED_REF="$(extract_json "$INCIDENT_RESOLVED" '.data.reference // .reference // empty')"

log "Mise en scene agent"
UPDATE_PROGRESS="$(request_json -X PUT "${BASE_URL}/incidents/${INCIDENT_PROGRESS_ID}" \
  -H "Authorization: Bearer ${AGENT_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"status":"in_progress","note":"Le service environnement planifie un passage demain matin.","priority":"high","assigned_to":4}')"
assert_success "$UPDATE_PROGRESS" 'passage incident en cours'

COMMENT_PROGRESS="$(request_json -X POST "${BASE_URL}/incidents/${INCIDENT_PROGRESS_ID}/comments" \
  -H "Authorization: Bearer ${AGENT_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"comment":"Equipe terrain informee. Une intervention courte est engagee."}')"
assert_success "$COMMENT_PROGRESS" 'commentaire incident en cours'

UPDATE_RESOLVED="$(request_json -X PUT "${BASE_URL}/incidents/${INCIDENT_RESOLVED_ID}" \
  -H "Authorization: Bearer ${AGENT_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"status":"resolved","note":"Le mobilier a ete remis en securite par les services techniques.","priority":"medium","assigned_to":4}')"
assert_success "$UPDATE_RESOLVED" 'passage incident resolu'

COMMENT_RESOLVED="$(request_json -X POST "${BASE_URL}/incidents/${INCIDENT_RESOLVED_ID}/comments" \
  -H "Authorization: Bearer ${AGENT_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"comment":"Le banc a ete renforce et controle. Le secteur est a nouveau utilisable."}')"
assert_success "$COMMENT_RESOLVED" 'commentaire incident resolu'

log "Mobilisation citoyenne sur le dossier en cours"
VOTE_PROGRESS_A="$(request_json -X POST "${BASE_URL}/incidents/${INCIDENT_PROGRESS_ID}/vote" \
  -H "Authorization: Bearer ${CITIZEN_A_TOKEN}")"
assert_success "$VOTE_PROGRESS_A" 'vote citoyen A incident en cours'

VOTE_PROGRESS_C="$(request_json -X POST "${BASE_URL}/incidents/${INCIDENT_PROGRESS_ID}/vote" \
  -H "Authorization: Bearer ${CITIZEN_C_TOKEN}")"
assert_success "$VOTE_PROGRESS_C" 'vote citoyen C incident en cours'

log "Creation d une consultation flash"
POLL_CREATE="$(request_json -X POST "${BASE_URL}/polls" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d "{\"title\":\"Priorite cadre de vie - Demo ${STAMP}\",\"description\":\"Quelle action visible la commune doit-elle traiter en priorite ce mois-ci ?\",\"type\":\"single\",\"ends_at\":\"2026-03-31 18:00:00\",\"options\":[\"Eclairage public\",\"Proprete de quartier\",\"Mobilier urbain\"]}")"
assert_success "$POLL_CREATE" 'creation consultation demo'
POLL_ID="$(extract_json "$POLL_CREATE" '.data.id // .id // empty')"

POLL_LIST_A="$(request_json "${BASE_URL}/polls" -H "Authorization: Bearer ${CITIZEN_A_TOKEN}")"
assert_success "$POLL_LIST_A" 'lecture consultation citoyen A'
OPTION_LIGHT="$(extract_json "$POLL_LIST_A" '.data[] | select(.id == '"${POLL_ID}"') | .options[0].id // empty')"
OPTION_CLEAN="$(extract_json "$POLL_LIST_A" '.data[] | select(.id == '"${POLL_ID}"') | .options[1].id // empty')"

VOTE_POLL_A="$(request_json -X POST "${BASE_URL}/polls/${POLL_ID}/vote" \
  -H "Authorization: Bearer ${CITIZEN_A_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d "{\"option_id\":${OPTION_LIGHT}}")"
assert_success "$VOTE_POLL_A" 'vote consultation citoyen A'

VOTE_POLL_B="$(request_json -X POST "${BASE_URL}/polls/${POLL_ID}/vote" \
  -H "Authorization: Bearer ${CITIZEN_B_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d "{\"option_id\":${OPTION_CLEAN}}")"
assert_success "$VOTE_POLL_B" 'vote consultation citoyen B'

VOTE_POLL_C="$(request_json -X POST "${BASE_URL}/polls/${POLL_ID}/vote" \
  -H "Authorization: Bearer ${CITIZEN_C_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d "{\"option_id\":${OPTION_LIGHT}}")"
assert_success "$VOTE_POLL_C" 'vote consultation citoyen C'

POLL_RESULTS="$(request_json "${BASE_URL}/polls/${POLL_ID}/results" -H "Authorization: Bearer ${CITIZEN_A_TOKEN}")"
assert_success "$POLL_RESULTS" 'lecture resultats consultation'

log "Creation d un rendez-vous communal"
EVENT_CREATE="$(request_json -X POST "${BASE_URL}/events" \
  -H "Authorization: Bearer ${AGENT_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d "{\"title\":\"Reunion de quartier - Demo ${STAMP}\",\"description\":\"Temps d echange avec les habitants sur les priorites de terrain et le suivi des interventions.\",\"location\":\"Maison de quartier de Kourou\",\"event_date\":\"2026-03-28 18:30:00\"}")"
assert_success "$EVENT_CREATE" 'creation evenement demo'
EVENT_ID="$(extract_json "$EVENT_CREATE" '.data.id // .id // empty')"

RSVP_EVENT_A="$(request_json -X POST "${BASE_URL}/events/${EVENT_ID}/rsvp" \
  -H "Authorization: Bearer ${CITIZEN_A_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"status":"attending"}')"
assert_success "$RSVP_EVENT_A" 'rsvp evenement citoyen A'

RSVP_EVENT_B="$(request_json -X POST "${BASE_URL}/events/${EVENT_ID}/rsvp" \
  -H "Authorization: Bearer ${CITIZEN_B_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"status":"interested"}')"
assert_success "$RSVP_EVENT_B" 'rsvp evenement citoyen B'

NOTIFICATIONS_A="$(request_json "${BASE_URL}/notifications" -H "Authorization: Bearer ${CITIZEN_A_TOKEN}")"
assert_success "$NOTIFICATIONS_A" 'notifications citoyen A'

cat > "$SUMMARY_FILE" <<EOF
{
  "generated_at": "${STAMP}",
  "base_url": "${BASE_URL}",
  "credentials": {
    "admin": {
      "email": "${ADMIN_EMAIL}",
      "password": "${ADMIN_PASSWORD}"
    },
    "agent": {
      "email": "${AGENT_EMAIL}",
      "password": "${AGENT_PASSWORD}"
    },
    "citizen_password": "${CITIZEN_PASSWORD}",
    "citizens": [
      { "email": "${DEMO_CITIZEN_A_EMAIL}", "full_name": "Camille Demo Kourou" },
      { "email": "${DEMO_CITIZEN_B_EMAIL}", "full_name": "Noa Demo Pariacabo" },
      { "email": "${DEMO_CITIZEN_C_EMAIL}", "full_name": "Lina Demo Bourg" }
    ]
  },
  "incidents": [
    { "id": ${INCIDENT_SUBMITTED_ID}, "reference": "${INCIDENT_SUBMITTED_REF}", "status": "submitted", "title": "Eclairage public en panne - Demo ${STAMP}" },
    { "id": ${INCIDENT_PROGRESS_ID}, "reference": "${INCIDENT_PROGRESS_REF}", "status": "in_progress", "title": "Depot sauvage sur accotement - Demo ${STAMP}" },
    { "id": ${INCIDENT_RESOLVED_ID}, "reference": "${INCIDENT_RESOLVED_REF}", "status": "resolved", "title": "Banc degrade aire de repos - Demo ${STAMP}" }
  ],
  "poll": {
    "id": ${POLL_ID},
    "title": "Priorite cadre de vie - Demo ${STAMP}"
  },
  "event": {
    "id": ${EVENT_ID},
    "title": "Reunion de quartier - Demo ${STAMP}"
  }
}
EOF

printf '\n=== DEMO READY ===\n'
printf 'Admin      : %s / %s\n' "$ADMIN_EMAIL" "$ADMIN_PASSWORD"
printf 'Agent      : %s / %s\n' "$AGENT_EMAIL" "$AGENT_PASSWORD"
printf 'Citoyens   : %s, %s, %s\n' "$DEMO_CITIZEN_A_EMAIL" "$DEMO_CITIZEN_B_EMAIL" "$DEMO_CITIZEN_C_EMAIL"
printf 'Mot de passe citoyen : %s\n' "$CITIZEN_PASSWORD"
printf 'Incident soumis      : %s (%s)\n' "$INCIDENT_SUBMITTED_REF" "$INCIDENT_SUBMITTED_ID"
printf 'Incident en cours    : %s (%s)\n' "$INCIDENT_PROGRESS_REF" "$INCIDENT_PROGRESS_ID"
printf 'Incident resolu      : %s (%s)\n' "$INCIDENT_RESOLVED_REF" "$INCIDENT_RESOLVED_ID"
printf 'Consultation active  : %s (%s)\n' "Priorite cadre de vie - Demo ${STAMP}" "$POLL_ID"
printf 'Evenement a venir    : %s (%s)\n' "Reunion de quartier - Demo ${STAMP}" "$EVENT_ID"
printf 'Resume JSON          : %s\n' "$SUMMARY_FILE"
