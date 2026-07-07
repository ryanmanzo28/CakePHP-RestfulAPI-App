#!/usr/bin/env sh
set -eu

APP_DIR="/var/www/html/api"

if [ -f "$APP_DIR/bin/cake" ]; then
  echo "[entrypoint] Waiting for database and running migrations..."
  ATTEMPTS=30
  i=1
  while [ "$i" -le "$ATTEMPTS" ]; do
    if (cd "$APP_DIR" && ./bin/cake migrations migrate >/tmp/migrate.log 2>&1); then
      echo "[entrypoint] Migrations complete."
      break
    fi

    if [ "$i" -eq "$ATTEMPTS" ]; then
      echo "[entrypoint] Migration failed after $ATTEMPTS attempts."
      cat /tmp/migrate.log || true
      exit 1
    fi

    echo "[entrypoint] Migration attempt $i failed, retrying in 2s..."
    i=$((i + 1))
    sleep 2
  done
else
  echo "[entrypoint] Skipping migrations: $APP_DIR/bin/cake not found"
fi

exec "$@"
