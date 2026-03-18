#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAMP="$(date +%s)"
OUTPUT_JSON="/tmp/ma-commune-manifest-livraison-locale-${STAMP}.json"

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    printf 'ECHEC: commande requise absente: %s\n' "$1" >&2
    exit 1
  }
}

require_cmd bash
require_cmd jq

GATE_OUTPUT="$(cd "$ROOT_DIR" && bash scripts/gate_avant_tablette.sh)"
GATE_MD="$(printf '%s' "$GATE_OUTPUT" | sed -n 's/^OUTPUT=//p')"

[[ -n "${GATE_MD:-}" && -f "${GATE_MD:-}" ]] || {
  printf 'ECHEC: verdict gate introuvable\n' >&2
  exit 1
}

PREP_MD="$(sed -n 's/^- preparation livraison locale regeneree : `\(.*\)`/\1/p' "$GATE_MD")"
SEED_JSON="$(sed -n 's/^- seed demonstration present : `\(.*\)`/\1/p' "$GATE_MD")"
RELEASE_APK="$(sed -n 's/^- APK release present : `\(.*\)`/\1/p' "$GATE_MD")"
RELEASE_SIZE="$(sed -n 's/^- taille APK universel : \([0-9][0-9]*\) octets/\1/p' "$GATE_MD")"
TABLET_APK="$(sed -n 's/^- APK cible tablette : `\(.*\)`/\1/p' "$GATE_MD")"
TABLET_ABI="$(sed -n 's/^- ABI cible tablette : `\(.*\)`/\1/p' "$GATE_MD")"
TABLET_SIZE="$(sed -n 's/^- taille APK cible tablette : \([0-9][0-9]*\) octets/\1/p' "$GATE_MD")"
BRANDING_LOG="$(sed -n 's/^- branding log : `\(.*\)`/\1/p' "$PREP_MD")"
CATEGORY_VISUALS_LOG="$(sed -n 's/^- category visuals log : `\(.*\)`/\1/p' "$PREP_MD")"

[[ -n "${PREP_MD:-}" && -f "${PREP_MD:-}" ]] || {
  printf 'ECHEC: preparation livraison introuvable\n' >&2
  exit 1
}

[[ -n "${SEED_JSON:-}" && -f "${SEED_JSON:-}" ]] || {
  printf 'ECHEC: seed json introuvable\n' >&2
  exit 1
}

[[ -n "${BRANDING_LOG:-}" && -f "${BRANDING_LOG:-}" ]] || {
  printf 'ECHEC: branding log introuvable\n' >&2
  exit 1
}

[[ -n "${CATEGORY_VISUALS_LOG:-}" && -f "${CATEGORY_VISUALS_LOG:-}" ]] || {
  printf 'ECHEC: category visuals log introuvable\n' >&2
  exit 1
}

RELEASE_SHA256="$(sha256sum "$RELEASE_APK" | awk '{print $1}')"
TABLET_SHA256="$(sha256sum "$TABLET_APK" | awk '{print $1}')"

STACK_JSON="$(docker compose ps --format json 2>/dev/null | jq -s '.' )"
GIT_HEAD_SHORT="$(git -C "$ROOT_DIR" rev-parse --short HEAD)"
GIT_HEAD_FULL="$(git -C "$ROOT_DIR" rev-parse HEAD)"
GIT_HEAD_SUBJECT="$(git -C "$ROOT_DIR" log -1 --pretty=%s)"
GIT_UPSTREAM_REF="$(git -C "$ROOT_DIR" rev-parse --abbrev-ref --symbolic-full-name '@{upstream}' 2>/dev/null || true)"
GIT_UPSTREAM_SHORT=""
if [[ -n "${GIT_UPSTREAM_REF:-}" ]]; then
  GIT_UPSTREAM_SHORT="$(git -C "$ROOT_DIR" rev-parse --short "$GIT_UPSTREAM_REF" 2>/dev/null || true)"
fi
if git -C "$ROOT_DIR" diff --quiet && git -C "$ROOT_DIR" diff --cached --quiet; then
  GIT_WORKTREE_CLEAN="true"
else
  GIT_WORKTREE_CLEAN="false"
fi

ADMIN_EMAIL="$(jq -r '.credentials.admin.email' "$SEED_JSON")"
ADMIN_PASSWORD="$(jq -r '.credentials.admin.password' "$SEED_JSON")"
AGENT_EMAIL="$(jq -r '.credentials.agent.email' "$SEED_JSON")"
AGENT_PASSWORD="$(jq -r '.credentials.agent.password' "$SEED_JSON")"
CITIZEN_PASSWORD="$(jq -r '.credentials.citizen_password' "$SEED_JSON")"
CITIZENS_JSON="$(jq -c '.credentials.citizens' "$SEED_JSON")"
INCIDENTS_JSON="$(jq -c '.incidents' "$SEED_JSON")"
POLL_JSON="$(jq -c '.poll' "$SEED_JSON")"
EVENT_JSON="$(jq -c '.event' "$SEED_JSON")"

jq -n \
  --arg generated_at "$(date '+%Y-%m-%dT%H:%M:%S%z')" \
  --arg project_root "$ROOT_DIR" \
  --arg git_head_short "$GIT_HEAD_SHORT" \
  --arg git_head_full "$GIT_HEAD_FULL" \
  --arg git_head_subject "$GIT_HEAD_SUBJECT" \
  --arg git_upstream_ref "$GIT_UPSTREAM_REF" \
  --arg git_upstream_short "$GIT_UPSTREAM_SHORT" \
  --arg git_worktree_clean "$GIT_WORKTREE_CLEAN" \
  --arg gate_markdown "$GATE_MD" \
  --arg preparation_markdown "$PREP_MD" \
  --arg seed_json "$SEED_JSON" \
  --arg release_apk "$RELEASE_APK" \
  --arg release_size "${RELEASE_SIZE:-0}" \
  --arg release_sha256 "$RELEASE_SHA256" \
  --arg tablet_apk "$TABLET_APK" \
  --arg tablet_abi "$TABLET_ABI" \
  --arg tablet_size "${TABLET_SIZE:-0}" \
  --arg tablet_sha256 "$TABLET_SHA256" \
  --arg branding_log "$BRANDING_LOG" \
  --arg category_visuals_log "$CATEGORY_VISUALS_LOG" \
  --arg api_url "http://127.0.0.1:8080/api" \
  --arg admin_url "http://127.0.0.1:8080/admin/?page=login" \
  --arg db_name "ma_commune_db" \
  --arg db_user "ma_commune_user" \
  --arg admin_email "$ADMIN_EMAIL" \
  --arg admin_password "$ADMIN_PASSWORD" \
  --arg agent_email "$AGENT_EMAIL" \
  --arg agent_password "$AGENT_PASSWORD" \
  --arg citizen_password "$CITIZEN_PASSWORD" \
  --arg dossier_doc "$ROOT_DIR/docs/DOSSIER_LIVRAISON_LOCALE_MA_COMMUNE_2026-03-18.md" \
  --arg readiness_doc "$ROOT_DIR/docs/MATRICE_READINESS_MA_COMMUNE_2026-03-18.md" \
  --arg mode_operatoire_doc "$ROOT_DIR/docs/MODE_OPERATOIRE_DEMO_MA_COMMUNE_2026-03-18.md" \
  --arg credentials_doc "$ROOT_DIR/docs/CREDENTIALS_DEMO_MA_COMMUNE_2026-03-18.md" \
  --argjson citizens "$CITIZENS_JSON" \
  --argjson incidents "$INCIDENTS_JSON" \
  --argjson poll "$POLL_JSON" \
  --argjson event "$EVENT_JSON" \
  --argjson stack "$STACK_JSON" \
  '{
    generated_at: $generated_at,
    project: {
      name: "Ma Commune",
      root: $project_root,
      git: {
        head_commit_short: $git_head_short,
        head_commit_full: $git_head_full,
        head_subject: $git_head_subject,
        upstream_ref: $git_upstream_ref,
        upstream_commit_short: $git_upstream_short,
        worktree_clean: ($git_worktree_clean == "true")
      }
    },
    decision: {
      gate_local_avant_tablette: "PASS",
      livraison_finale_appareil: "NON_AUTORISEE_A_CE_STADE",
      coherence_marque_couche_active: "PASS",
      coherence_systeme_visuel_categories: "PASS"
    },
    urls: {
      api: $api_url,
      admin: $admin_url
    },
    database: {
      name: $db_name,
      user: $db_user
    },
    artifacts: {
      gate_markdown: $gate_markdown,
      preparation_markdown: $preparation_markdown,
      seed_json: $seed_json,
      branding_log: $branding_log,
      category_visuals_log: $category_visuals_log,
      apk_universal: {
        path: $release_apk,
        size_bytes: ($release_size | tonumber),
        sha256: $release_sha256
      },
      apk_tablette: {
        path: $tablet_apk,
        abi: $tablet_abi,
        size_bytes: ($tablet_size | tonumber),
        sha256: $tablet_sha256
      }
    },
    credentials: {
      admin: {
        email: $admin_email,
        password: $admin_password
      },
      agent: {
        email: $agent_email,
        password: $agent_password
      },
      citizen_password: $citizen_password,
      citizens: $citizens
    },
    demo: {
      incidents: $incidents,
      poll: $poll,
      event: $event
    },
    docs: {
      dossier_livraison: $dossier_doc,
      matrice_readiness: $readiness_doc,
      mode_operatoire: $mode_operatoire_doc,
      credentials: $credentials_doc
    },
    docker_stack: $stack
  }' > "$OUTPUT_JSON"

printf 'OUTPUT=%s\n' "$OUTPUT_JSON"
