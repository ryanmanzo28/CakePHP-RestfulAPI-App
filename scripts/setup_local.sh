#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

echo "Starting containers (build if needed)..."
docker compose up -d --build

# wait for DB to accept connections
echo "Waiting for DB to be ready..."
RETRIES=30
until docker compose exec -T db sh -c 'mysql -u"${MYSQL_USER:-root}" -p"${MYSQL_PASSWORD:-}" -e "SELECT 1;" >/dev/null 2>&1' || [ $RETRIES -le 0 ]; do
  echo "  waiting... ($RETRIES)"
  sleep 2
  RETRIES=$((RETRIES-1))
done
if [ $RETRIES -le 0 ]; then
  echo "DB did not become ready in time. Check containers with 'docker compose ps' and logs.'"
  exit 1
fi

# Apply migrations by piping SQL files into the db container's mysql client
echo "Applying SQL migrations..."
MIG_DIR="api/db/migrations"
if [ -d "$MIG_DIR" ]; then
  for f in "$MIG_DIR"/*.sql; do
    [ -e "$f" ] || continue
    echo "  Applying: $f"
    docker compose exec -T db sh -c 'mysql -u"${MYSQL_USER:-root}" -p"${MYSQL_PASSWORD:-}" "${MYSQL_DATABASE:-cakephp}"' < "$f"
  done
else
  echo "No migrations directory found at $MIG_DIR"
fi

# Optional seeds
SEED_DIR="api/db/seed"
if [ -d "$SEED_DIR" ]; then
  for s in "$SEED_DIR"/*.sql; do
    [ -e "$s" ] || continue
    echo "  Seeding: $s"
    docker compose exec -T db sh -c 'mysql -u"${MYSQL_USER:-root}" -p"${MYSQL_PASSWORD:-}" "${MYSQL_DATABASE:-cakephp}"' < "$s"
  done
fi

# Simple smoke test: hit the API index (expected 401 if JWT not provided)
echo "Running API smoke test..."
sleep 1
if command -v curl >/dev/null 2>&1; then
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/api/workouts || echo "000")
  echo "  GET /api/workouts returned HTTP $STATUS (401 expected when not authenticated)"
else
  echo "  curl not found; skip HTTP smoke test"
fi

echo "Setup complete. Visit http://localhost:8080 to use the app (if front-end routes are also hosted)."
