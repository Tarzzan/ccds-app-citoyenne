#!/usr/bin/env bash
set -euo pipefail

# Prefer the project container when it is reachable; fall back to the host PHP
# so editors can still lint files when Docker is down or inaccessible.
if command -v docker >/dev/null 2>&1; then
  if docker inspect -f {{.State.Running}} ma_commune_php >/dev/null 2>&1; then
    exec docker exec -i ma_commune_php php "$@"
  fi
fi

exec /usr/bin/php "$@"
