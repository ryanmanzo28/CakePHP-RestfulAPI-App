#!/usr/bin/env sh
# Run SQL migrations against a MySQL/MariaDB instance.
# This script is safe to run from any directory; it locates migration files
# relative to the script's own directory.

DB_HOST=${DB_HOST:-db}
DB_NAME=${DB_NAME:-cakephp}
DB_USER=${DB_USER:-root}
DB_PASS=${DB_PASS:-}

SCRIPT_DIR="$(cd "$(dirname "$0")" >/dev/null 2>&1 && pwd)"
MIGRATIONS_DIR="$SCRIPT_DIR/migrations"
SEED_DIR="$SCRIPT_DIR/seed"

echo "Running migrations against ${DB_HOST}/${DB_NAME} as ${DB_USER}"

if [ -d "$MIGRATIONS_DIR" ]; then
  found=0
  for f in "$MIGRATIONS_DIR"/*.sql; do
    [ -e "$f" ] || continue
    found=1
    echo "Applying $f"
    mysql -h"${DB_HOST}" -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "$f"
  done
  if [ "$found" -eq 0 ]; then
    echo "No migration files found in $MIGRATIONS_DIR"
  fi
else
  echo "Migrations directory not found: $MIGRATIONS_DIR"
fi

if [ -d "$SEED_DIR" ]; then
  found=0
  for s in "$SEED_DIR"/*.sql; do
    [ -e "$s" ] || continue
    found=1
    echo "Seeding $s"
    mysql -h"${DB_HOST}" -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "$s"
  done
  if [ "$found" -eq 0 ]; then
    echo "No seed files found in $SEED_DIR"
  fi
else
  echo "Seed directory not found: $SEED_DIR"
fi

echo "Migrations complete."
