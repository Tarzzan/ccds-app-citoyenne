#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE_URL="${1:-http://127.0.0.1:8080/api}"
ADMIN_LOGIN_URL="${2:-http://127.0.0.1:8080/admin/?page=login}"
STATUS_URL="${3:-http://127.0.0.1:8080/status}"
API_DOCS_URL="${4:-http://127.0.0.1:8080/api-docs/}"

extract_json_field() {
  local field="$1"
  sed -n "s/.*\"${field}\":\"\\([^\"]*\\)\".*/\\1/p"
}

extract_json_number() {
  local field="$1"
  sed -n "s/.*\"${field}\":\\([0-9][0-9]*\\).*/\\1/p"
}

assert_contains() {
  local haystack="$1"
  local needle="$2"
  local label="$3"

  if [[ "$haystack" != *"$needle"* ]]; then
    printf 'ECHEC: %s\n' "$label" >&2
    exit 1
  fi
}

assert_not_empty() {
  local value="$1"
  local label="$2"

  if [[ -z "$value" ]]; then
    printf 'ECHEC: %s\n' "$label" >&2
    exit 1
  fi
}

step() {
  printf '\n[%s] %s\n' "$(date '+%H:%M:%S')" "$1"
}

ensure_android_sdk() {
  if [[ -n "${ANDROID_HOME:-}" && -d "${ANDROID_HOME}" ]]; then
    export ANDROID_SDK_ROOT="${ANDROID_SDK_ROOT:-$ANDROID_HOME}"
    return
  fi

  local candidates=(
    "/usr/lib/android-sdk"
    "/home/tarzzan/Android/Sdk"
    "/opt/android-sdk"
    "/opt/android-sdk-linux"
  )

  local sdk_dir=""
  for candidate in "${candidates[@]}"; do
    if [[ -d "$candidate" ]]; then
      sdk_dir="$candidate"
      break
    fi
  done

  if [[ -z "$sdk_dir" ]]; then
    printf 'ECHEC: Android SDK introuvable pour le build release\n' >&2
    exit 1
  fi

  export ANDROID_HOME="$sdk_dir"
  export ANDROID_SDK_ROOT="$sdk_dir"
}

reset_rate_limit_state() {
  rm -f "/tmp/ma-commune_rl/"*.json 2>/dev/null || true
  rm -f "/tmp/ma-commune_ratelimit/"*.json 2>/dev/null || true
  docker compose exec -T php sh -lc "rm -f /tmp/ma-commune_rl/*.json /tmp/ma-commune_ratelimit/*.json 2>/dev/null || true" >/dev/null 2>&1 || true
}

step "Typecheck mobile"
(
  cd "$ROOT_DIR/mobile"
  pnpm run typecheck >/tmp/ma-commune-typecheck.log
)
printf 'OK typecheck\n'

step "Build Android release"
ensure_android_sdk
(
  cd "$ROOT_DIR/mobile/android"
  NODE_ENV=production ./gradlew assembleRelease >/tmp/ma-commune-assemble-release.log
)
printf 'OK build release\n'

step "Verification categories API"
CATEGORIES="$(curl -sS "$BASE_URL/categories")"
assert_contains "$CATEGORIES" '"success":true' 'categories API'
printf 'OK categories\n'

step "Verification surfaces publiques"
STATUS_HTML="$(curl -sS "$STATUS_URL")"
assert_contains "$STATUS_HTML" 'Ma Commune — Statut des services' 'page status publique'
assert_contains "$STATUS_HTML" 'Base de données' 'bloc base de donnees status'

API_DOCS_HTML="$(curl -sS "$API_DOCS_URL")"
assert_contains "$API_DOCS_HTML" 'Ma Commune — API publique' 'page api docs publique'

OPENAPI_HEADERS="$(curl -sSI http://127.0.0.1:8080/api/openapi.yml)"
assert_contains "$OPENAPI_HEADERS" '200 OK' 'openapi public'
OPENAPI_BODY="$(curl -sS http://127.0.0.1:8080/api/openapi.yml)"
assert_contains "$OPENAPI_BODY" 'title: Ma Commune API' 'openapi branding'
printf 'OK surfaces publiques\n'

step "Recette citoyen -> agent -> admin"
RECETTE_OUTPUT="$(cd "$ROOT_DIR" && bash scripts/recette_mvp_local.sh "$BASE_URL")"
assert_contains "$RECETTE_OUTPUT" 'UPDATE={"success":true' 'mise a jour recette'
assert_contains "$RECETTE_OUTPUT" 'DETAIL={"success":true' 'detail recette'
printf '%s\n' "$RECETTE_OUTPUT"

step "Verification admin login"
TMPDIR="$(mktemp -d)"
curl -sS -c "$TMPDIR/cookies.txt" "$ADMIN_LOGIN_URL" >/dev/null
ADMIN_HEADERS="$(curl -sS -b "$TMPDIR/cookies.txt" -c "$TMPDIR/cookies.txt" -X POST "$ADMIN_LOGIN_URL" \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data 'email=admin@macommune.local&password=Admin@MaCommune2026!' -D - -o /tmp/ma-commune-admin-dashboard.html)"
assert_contains "$ADMIN_HEADERS" 'Location: /admin/?page=dashboard' 'redirection dashboard admin'
printf 'OK admin login\n'

ADMIN_API_LOGIN="$(curl -sS -X POST "$BASE_URL/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@macommune.local","password":"Admin@MaCommune2026!"}')"
ADMIN_TOKEN="$(printf '%s' "$ADMIN_API_LOGIN" | extract_json_field token)"
assert_not_empty "$ADMIN_TOKEN" 'token admin API'

step "Verification votes, commentaires et notifications"
reset_rate_limit_state
STAMP="$(date +%s)"
CITIZEN_REGISTER="$(curl -sS -X POST "$BASE_URL/register" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"citoyen.audit.${STAMP}@macommune.local\",\"password\":\"Citoyen@MaCommune2026!\",\"full_name\":\"Citoyen Audit Local\"}")"
CITIZEN_TOKEN="$(printf '%s' "$CITIZEN_REGISTER" | extract_json_field token)"

INCIDENT="$(curl -sS -X POST "$BASE_URL/incidents" \
  -H "Authorization: Bearer ${CITIZEN_TOKEN}" \
  -F category_id=1 \
  -F title='Audit local predeploy' \
  -F description='Verification complete avant deploiement' \
  -F latitude=5.1597 \
  -F longitude=-52.6498 \
  -F address='Kourou audit local')"
INCIDENT_ID="$(printf '%s' "$INCIDENT" | extract_json_number id)"

VOTE="$(curl -sS -X POST "$BASE_URL/incidents/${INCIDENT_ID}/vote" \
  -H "Authorization: Bearer ${CITIZEN_TOKEN}")"
assert_contains "$VOTE" '"user_has_voted":true' 'vote creation'

COMMENT="$(curl -sS -X POST "$BASE_URL/incidents/${INCIDENT_ID}/comments" \
  -H "Authorization: Bearer ${CITIZEN_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"comment":"Commentaire de verification locale."}')"
assert_contains "$COMMENT" '"success":true' 'comment creation'

AGENT_LOGIN="$(curl -sS -X POST "$BASE_URL/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"agent@macommune.local","password":"Agent@MaCommune2026!"}')"
AGENT_TOKEN="$(printf '%s' "$AGENT_LOGIN" | extract_json_field token)"

STATUS_UPDATE="$(curl -sS -X PUT "$BASE_URL/incidents/${INCIDENT_ID}" \
  -H "Authorization: Bearer ${AGENT_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"status":"in_progress","note":"Prise en charge pendant audit local.","priority":"high","assigned_to":4}')"
assert_contains "$STATUS_UPDATE" '"status":"in_progress"' 'status update'

AGENT_COMMENT="$(curl -sS -X POST "$BASE_URL/incidents/${INCIDENT_ID}/comments" \
  -H "Authorization: Bearer ${AGENT_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"comment":"Le service est mobilise sur ce dossier."}')"
assert_contains "$AGENT_COMMENT" '"success":true' 'agent comment'

NOTIFICATIONS="$(curl -sS "$BASE_URL/notifications" \
  -H "Authorization: Bearer ${CITIZEN_TOKEN}")"
assert_contains "$NOTIFICATIONS" '"type":"status_change"' 'notification status change'
assert_contains "$NOTIFICATIONS" '"type":"new_comment"' 'notification new comment'

printf 'OK votes/commentaires/notifications\n'
printf 'INCIDENT_ID=%s\n' "$INCIDENT_ID"

step "Verification photos incident"
PHOTO_UPLOAD="$(curl -sS -X POST "$BASE_URL/incidents/${INCIDENT_ID}/photos" \
  -H "Authorization: Bearer ${CITIZEN_TOKEN}" \
  -F "photo=@${ROOT_DIR}/mobile/assets/icon.png;type=image/png" \
  -F sort_order=0)"
assert_contains "$PHOTO_UPLOAD" '"success":true' 'photo upload'
PHOTO_ID="$(printf '%s' "$PHOTO_UPLOAD" | extract_json_number photo_id)"
assert_not_empty "$PHOTO_ID" 'photo id upload'

PHOTO_LIST="$(curl -sS "$BASE_URL/incidents/${INCIDENT_ID}/photos" \
  -H "Authorization: Bearer ${CITIZEN_TOKEN}")"
assert_contains "$PHOTO_LIST" "\"id\":${PHOTO_ID}" 'photo list incident'
assert_contains "$PHOTO_LIST" '"mime_type":"image/png"' 'photo mime type'

PHOTO_DELETE="$(curl -sS -X DELETE "$BASE_URL/incidents/${INCIDENT_ID}/photos/${PHOTO_ID}" \
  -H "Authorization: Bearer ${CITIZEN_TOKEN}")"
assert_contains "$PHOTO_DELETE" '"success":true' 'photo delete'
printf 'OK photos incident\n'

step "Verification admin web -> notifications citoyennes"
reset_rate_limit_state
STAMP_ADMIN="$(date +%s)"
CITIZEN_ADMIN="$(curl -sS -X POST "${BASE_URL}/register" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"citoyen.audit.admin.${STAMP_ADMIN}@macommune.local\",\"password\":\"Citoyen@MaCommune2026!\",\"full_name\":\"Citoyen Audit Admin\"}")"
CITIZEN_ADMIN_TOKEN="$(printf '%s' "$CITIZEN_ADMIN" | extract_json_field token)"

INCIDENT_ADMIN="$(curl -sS -X POST "${BASE_URL}/incidents" \
  -H "Authorization: Bearer ${CITIZEN_ADMIN_TOKEN}" \
  -F category_id=1 \
  -F title='Audit admin web' \
  -F description='Verification de coherence entre admin web et notifications citoyennes' \
  -F latitude=5.1597 \
  -F longitude=-52.6498 \
  -F address='Kourou admin audit')"
INCIDENT_ADMIN_ID="$(printf '%s' "$INCIDENT_ADMIN" | extract_json_number id)"

TMPDIR_ADMIN="$(mktemp -d)"
curl -sS -c "$TMPDIR_ADMIN/cookies.txt" "$ADMIN_LOGIN_URL" >/dev/null
curl -sS -b "$TMPDIR_ADMIN/cookies.txt" -c "$TMPDIR_ADMIN/cookies.txt" -X POST "$ADMIN_LOGIN_URL" \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data 'email=admin@macommune.local&password=Admin@MaCommune2026!' >/dev/null

curl -sS -b "$TMPDIR_ADMIN/cookies.txt" -X POST "http://127.0.0.1:8080/admin/?page=incident_detail&id=${INCIDENT_ADMIN_ID}" \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data 'action=change_status&new_status=in_progress&priority=high&note=Suivi+depuis+admin+web&send_notification=1' >/dev/null

curl -sS -b "$TMPDIR_ADMIN/cookies.txt" -X POST "http://127.0.0.1:8080/admin/?page=incident_detail&id=${INCIDENT_ADMIN_ID}" \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data 'action=add_comment&comment=Le+service+a+confirme+la+prise+en+charge' >/dev/null

ADMIN_NOTIFICATIONS="$(curl -sS "${BASE_URL}/notifications" \
  -H "Authorization: Bearer ${CITIZEN_ADMIN_TOKEN}")"
assert_contains "$ADMIN_NOTIFICATIONS" '"type":"status_change"' 'notification admin status change'
assert_contains "$ADMIN_NOTIFICATIONS" '"type":"new_comment"' 'notification admin new comment'
assert_contains "$ADMIN_NOTIFICATIONS" '"incident_id":' 'incident id notification admin'
printf 'OK admin web notifications\n'
printf 'INCIDENT_ADMIN_ID=%s\n' "$INCIDENT_ADMIN_ID"

step "Verification moderation commentaires"
reset_rate_limit_state
STAMP_MOD="$(date +%s)"
MOD_CITIZEN_A="$(curl -sS -X POST "${BASE_URL}/register" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"citoyen.mod.a.${STAMP_MOD}@macommune.local\",\"password\":\"Citoyen@MaCommune2026!\",\"full_name\":\"Citoyen Moderation A\"}")"
MOD_CITIZEN_A_TOKEN="$(printf '%s' "$MOD_CITIZEN_A" | extract_json_field token)"

MOD_CITIZEN_B="$(curl -sS -X POST "${BASE_URL}/register" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"citoyen.mod.b.${STAMP_MOD}@macommune.local\",\"password\":\"Citoyen@MaCommune2026!\",\"full_name\":\"Citoyen Moderation B\"}")"
MOD_CITIZEN_B_TOKEN="$(printf '%s' "$MOD_CITIZEN_B" | extract_json_field token)"

MOD_INCIDENT="$(curl -sS -X POST "${BASE_URL}/incidents" \
  -H "Authorization: Bearer ${MOD_CITIZEN_A_TOKEN}" \
  -F category_id=1 \
  -F title='Audit moderation' \
  -F description='Verification de la route de signalement de commentaire' \
  -F latitude=5.1597 \
  -F longitude=-52.6498 \
  -F address='Kourou moderation audit')"
MOD_INCIDENT_ID="$(printf '%s' "$MOD_INCIDENT" | extract_json_number id)"

MOD_COMMENT="$(curl -sS -X POST "${BASE_URL}/incidents/${MOD_INCIDENT_ID}/comments" \
  -H "Authorization: Bearer ${MOD_CITIZEN_A_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"comment":"Commentaire de moderation de verification."}')"
MOD_COMMENT_ID="$(printf '%s' "$MOD_COMMENT" | extract_json_number comment_id)"

MOD_REPORT="$(curl -sS -X POST "${BASE_URL}/comments/${MOD_COMMENT_ID}/report" \
  -H "Authorization: Bearer ${MOD_CITIZEN_B_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"reason":"other","description":"Signalement de verification locale."}')"
assert_contains "$MOD_REPORT" '"success":true' 'signalement commentaire'

MOD_REPORTS="$(curl -sS "${BASE_URL}/admin/moderation/reports?status=pending&limit=20" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}")"
MOD_REPORT_ID="$(printf '%s' "$MOD_REPORTS" | jq -r --arg cid "$MOD_COMMENT_ID" '.data.data[] | select((.comment_id|tostring)==$cid) | .id' | head -n1)"
assert_not_empty "$MOD_REPORT_ID" 'selection moderation report id'

MOD_REVIEW="$(curl -sS -X PUT "${BASE_URL}/admin/moderation/reports/${MOD_REPORT_ID}" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"action":"dismiss","note":"Verification locale"}')"
assert_contains "$MOD_REVIEW" '"status":"dismissed"' 'moderation review'
printf 'OK moderation commentaires\n'

step "Verification gamification et RGPD"
reset_rate_limit_state
STAMP_GAMIF="$(date +%s)"
GAMIF_CITIZEN="$(curl -sS -X POST "${BASE_URL}/register" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"citoyen.gamif.${STAMP_GAMIF}@macommune.local\",\"password\":\"Citoyen@MaCommune2026!\",\"full_name\":\"Citoyen Gamification\"}")"
GAMIF_TOKEN="$(printf '%s' "$GAMIF_CITIZEN" | extract_json_field token)"

GAMIF_INCIDENT="$(curl -sS -X POST "${BASE_URL}/incidents" \
  -H "Authorization: Bearer ${GAMIF_TOKEN}" \
  -F category_id=1 \
  -F title='Audit gamification' \
  -F description='Verification des compteurs et badges derives' \
  -F latitude=4.938408 \
  -F longitude=-52.329082 \
  -F address='Cayenne gamification audit')"
GAMIF_INCIDENT_ID="$(printf '%s' "$GAMIF_INCIDENT" | extract_json_number id)"

curl -sS -X POST "${BASE_URL}/incidents/${GAMIF_INCIDENT_ID}/comments" \
  -H "Authorization: Bearer ${GAMIF_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"comment":"Commentaire pour verifier les points derives."}' >/dev/null

GAMIF_STATS="$(curl -sS "${BASE_URL}/gamification" \
  -H "Authorization: Bearer ${GAMIF_TOKEN}")"
assert_contains "$GAMIF_STATS" '"points":13' 'gamification points derives'
assert_contains "$GAMIF_STATS" '"key":"explorer"' 'gamification explorer stats'

GAMIF_BADGES="$(curl -sS "${BASE_URL}/gamification/badges" \
  -H "Authorization: Bearer ${GAMIF_TOKEN}")"
assert_contains "$GAMIF_BADGES" '"key":"explorer"' 'gamification badges route'
assert_contains "$GAMIF_BADGES" '"earned":true' 'gamification earned badge'

GDPR_EXPORT="$(curl -sS -X POST "${BASE_URL}/gdpr/export" \
  -H "Authorization: Bearer ${GAMIF_TOKEN}")"
GDPR_FILE="$(printf '%s' "$GDPR_EXPORT" | jq -r '.data.filename')"
assert_not_empty "$GDPR_FILE" 'gdpr export filename'

GDPR_DOWNLOAD="$(curl -sS "${BASE_URL}/gdpr/download/${GDPR_FILE}" \
  -H "Authorization: Bearer ${GAMIF_TOKEN}")"
assert_contains "$GDPR_DOWNLOAD" '"user_id"' 'gdpr download user id'
assert_contains "$GDPR_DOWNLOAD" 'Commentaire pour verifier les points derives.' 'gdpr download comments'
printf 'OK gamification et RGPD\n'

step "Verification audit logs, webhooks et routes notifications"
reset_rate_limit_state
AUDIT_EXPORT_HEADERS="$(mktemp)"
AUDIT_EXPORT_BODY="$(mktemp)"
curl -sS -D "$AUDIT_EXPORT_HEADERS" -o "$AUDIT_EXPORT_BODY" "${BASE_URL}/admin/audit-logs/export" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}" >/dev/null
AUDIT_EXPORT_HEADERS_CONTENT="$(cat "$AUDIT_EXPORT_HEADERS")"
AUDIT_EXPORT_BODY_CONTENT="$(head -n2 "$AUDIT_EXPORT_BODY")"
assert_contains "$AUDIT_EXPORT_HEADERS_CONTENT" 'Content-Disposition: attachment; filename="audit_logs_' 'audit logs export headers'
assert_contains "$AUDIT_EXPORT_BODY_CONTENT" 'Date,Admin,Email,Action,Cible,"ID Cible",IP' 'audit logs export csv'

WEBHOOK_CREATE="$(curl -sS -X POST "${BASE_URL}/webhooks" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"target_url":"http://nginx/admin/?page=login","event":"*"}')"
WEBHOOK_ID="$(printf '%s' "$WEBHOOK_CREATE" | extract_json_number id)"
assert_not_empty "$WEBHOOK_ID" 'webhook id'

WEBHOOK_TEST="$(curl -sS -X POST "${BASE_URL}/webhooks/${WEBHOOK_ID}/test" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}")"
assert_contains "$WEBHOOK_TEST" '"status_code":200' 'webhook test status code'
assert_contains "$WEBHOOK_TEST" '"success":true' 'webhook test success'

STAMP_NOTIF="$(date +%s)"
NOTIF_CITIZEN="$(curl -sS -X POST "${BASE_URL}/register" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"citoyen.notif.${STAMP_NOTIF}@macommune.local\",\"password\":\"Citoyen@MaCommune2026!\",\"full_name\":\"Citoyen Notification\"}")"
NOTIF_TOKEN="$(printf '%s' "$NOTIF_CITIZEN" | extract_json_field token)"

NOTIF_REGISTER="$(curl -sS -X POST "${BASE_URL}/notifications/token" \
  -H "Authorization: Bearer ${NOTIF_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"token":"ExponentPushToken[audit-local-route]","platform":"android"}')"
assert_contains "$NOTIF_REGISTER" '"registered":true' 'notification token route'

NOTIF_READ_ALL="$(curl -sS -X PUT "${BASE_URL}/notifications/read-all" \
  -H "Authorization: Bearer ${NOTIF_TOKEN}")"
assert_contains "$NOTIF_READ_ALL" '"updated":true' 'notification read-all route'

EVENT_NOTIFICATION="$(curl -sS -X POST "${BASE_URL}/events" \
  -H "Authorization: Bearer ${AGENT_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"title":"Reunion de quartier audit","description":"Verification notification evenement","location":"Kourou centre","event_date":"2026-03-25 18:00:00"}')"
assert_contains "$EVENT_NOTIFICATION" '"success":true' 'event creation for notification'

NOTIF_EVENT_LIST="$(curl -sS "${BASE_URL}/notifications" \
  -H "Authorization: Bearer ${NOTIF_TOKEN}")"
assert_contains "$NOTIF_EVENT_LIST" '"type":"event"' 'event notification type'
printf 'OK audit logs, webhooks et notifications routes\n'

step "Verification publications communaute via admin web"
reset_rate_limit_state
STAMP_COMMUNITY="$(date +%s)"
TMPDIR_COMMUNITY="$(mktemp -d)"
curl -sS -c "$TMPDIR_COMMUNITY/cookies.txt" "$ADMIN_LOGIN_URL" >/dev/null
curl -sS -b "$TMPDIR_COMMUNITY/cookies.txt" -c "$TMPDIR_COMMUNITY/cookies.txt" -X POST "$ADMIN_LOGIN_URL" \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data 'email=admin@macommune.local&password=Admin@MaCommune2026!' >/dev/null

COMMUNITY_CITIZEN="$(curl -sS -X POST "${BASE_URL}/register" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"citoyen.community.${STAMP_COMMUNITY}@macommune.local\",\"password\":\"Citoyen@MaCommune2026!\",\"full_name\":\"Citoyen Community Audit\"}")"
COMMUNITY_TOKEN="$(printf '%s' "$COMMUNITY_CITIZEN" | extract_json_field token)"
assert_not_empty "$COMMUNITY_TOKEN" 'community citizen token'

COMMUNITY_EVENT_TITLE="Atelier_autonomie_admin_${STAMP_COMMUNITY}"
COMMUNITY_POLL_TITLE="Consultation_admin_${STAMP_COMMUNITY}"

curl -sS -b "$TMPDIR_COMMUNITY/cookies.txt" -X POST "http://127.0.0.1:8080/admin/?page=events" \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'action=create' \
  --data-urlencode "title=${COMMUNITY_EVENT_TITLE}" \
  --data-urlencode 'location=Maison de quartier' \
  --data-urlencode 'event_date=2026-03-26T18:30' \
  --data-urlencode 'description=Verification publication admin web' >/dev/null

curl -sS -b "$TMPDIR_COMMUNITY/cookies.txt" -X POST "http://127.0.0.1:8080/admin/?page=polls" \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'action=create' \
  --data-urlencode "title=${COMMUNITY_POLL_TITLE}" \
  --data-urlencode 'type=single' \
  --data-urlencode 'ends_at=2026-03-31T18:00' \
  --data-urlencode 'description=Verification consultation admin web' \
  --data-urlencode $'options=Option A\nOption B\nOption C' >/dev/null

COMMUNITY_EVENTS_PAGE="$(curl -sS -b "$TMPDIR_COMMUNITY/cookies.txt" "http://127.0.0.1:8080/admin/?page=events")"
COMMUNITY_POLLS_PAGE="$(curl -sS -b "$TMPDIR_COMMUNITY/cookies.txt" "http://127.0.0.1:8080/admin/?page=polls")"
assert_contains "$COMMUNITY_EVENTS_PAGE" "$COMMUNITY_EVENT_TITLE" 'community event visible in admin web'
assert_contains "$COMMUNITY_POLLS_PAGE" "$COMMUNITY_POLL_TITLE" 'community poll visible in admin web'
assert_contains "$COMMUNITY_POLLS_PAGE" 'En cours' 'community poll active badge'

COMMUNITY_EVENTS_API="$(curl -sS "${BASE_URL}/events" \
  -H "Authorization: Bearer ${COMMUNITY_TOKEN}")"
COMMUNITY_POLLS_API="$(curl -sS "${BASE_URL}/polls" \
  -H "Authorization: Bearer ${COMMUNITY_TOKEN}")"
COMMUNITY_NOTIFS_API="$(curl -sS "${BASE_URL}/notifications" \
  -H "Authorization: Bearer ${COMMUNITY_TOKEN}")"

COMMUNITY_POLL_ID="$(printf '%s' "$COMMUNITY_POLLS_API" | jq -r --arg title "$COMMUNITY_POLL_TITLE" '.data[] | select(.title==$title) | .id' | head -n1)"
assert_not_empty "$COMMUNITY_POLL_ID" 'community poll api id'
assert_contains "$COMMUNITY_EVENTS_API" "$COMMUNITY_EVENT_TITLE" 'community event visible in citizen api'
assert_contains "$COMMUNITY_NOTIFS_API" "$COMMUNITY_EVENT_TITLE" 'community event notification citizen'

curl -sS -b "$TMPDIR_COMMUNITY/cookies.txt" -X POST "http://127.0.0.1:8080/admin/?page=polls" \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'action=close' \
  --data-urlencode "poll_id=${COMMUNITY_POLL_ID}" >/dev/null

COMMUNITY_POLLS_AFTER_CLOSE="$(curl -sS "${BASE_URL}/polls" \
  -H "Authorization: Bearer ${COMMUNITY_TOKEN}")"
if [[ "$COMMUNITY_POLLS_AFTER_CLOSE" == *"${COMMUNITY_POLL_TITLE}"* ]]; then
  printf 'ECHEC: community poll still visible after close\n' >&2
  exit 1
fi
printf 'OK publications communaute admin web\n'

step "Audit local termine"
printf 'Toutes les verifications critiques sont passees.\n'
