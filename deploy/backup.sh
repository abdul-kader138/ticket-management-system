#!/usr/bin/env bash
# Database backup for the Ticket Management System — see docs/ROADMAP.md,
# Phase 10 ("formal backup/restore drill"). Dumps the whole database
# (bookings and payments are the records that actually matter here; this
# doesn't try to back up only some tables since a partial restore of a
# relational schema this interconnected is its own hazard).
#
# Usage:
#   deploy/backup.sh                # writes to /var/backups/ticket-management-system
#   BACKUP_DIR=/mnt/nfs deploy/backup.sh
#
# Restore (on a target where this is safe — see the warning below):
#   gunzip -c /var/backups/ticket-management-system/tms-2026-08-25T120000Z.sql.gz \
#     | mysql -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"
#
# Actually DRILLING this (restoring into a scratch database, not the
# production one, and verifying the app boots against it) is a process
# step for the team to schedule, not something this script can do safely
# on its own — running the restore command above against a live database
# overwrites it.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/ticket-management-system}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/ticket-management-system}"
RETAIN_DAYS="${RETAIN_DAYS:-30}"

cd "$APP_DIR"

if [[ ! -f .env ]]; then
    echo "Error: $APP_DIR is not a configured Laravel application (.env is required)." >&2
    exit 1
fi

# shellcheck disable=SC1091
set -a; source .env; set +a

if [[ "${DB_CONNECTION:-}" != "mysql" && "${DB_CONNECTION:-}" != "mariadb" ]]; then
    echo "Error: this script only knows how to dump mysql/mariadb (DB_CONNECTION=${DB_CONNECTION:-unset})." >&2
    exit 1
fi

mkdir -p "$BACKUP_DIR"
TIMESTAMP="$(date -u +%Y-%m-%dT%H%M%SZ)"
DEST="$BACKUP_DIR/${DB_DATABASE}-${TIMESTAMP}.sql.gz"

echo "Backing up ${DB_DATABASE} to ${DEST}..."
# MYSQL_PWD rather than --password=... — the latter is visible to any other
# user on the box via `ps aux` for as long as the process runs.
MYSQL_PWD="${DB_PASSWORD:-}" mysqldump \
    --single-transaction \
    --routines \
    --triggers \
    -h "${DB_HOST:-127.0.0.1}" \
    -P "${DB_PORT:-3306}" \
    -u "${DB_USERNAME:-root}" \
    "${DB_DATABASE}" | gzip > "$DEST"

echo "Backup complete: $(du -h "$DEST" | cut -f1)"

# Prune backups older than RETAIN_DAYS — this is disk hygiene for the
# backup directory itself, unrelated to App\Console\Commands\PruneRetentionData,
# which prunes application data, not backup files.
find "$BACKUP_DIR" -name "${DB_DATABASE}-*.sql.gz" -mtime "+${RETAIN_DAYS}" -delete
