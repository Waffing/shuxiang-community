#!/bin/sh
set -eu

: "${DB_HOST:?DB_HOST is required}"
: "${DB_DATABASE:?DB_DATABASE is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"

DB_PORT="${DB_PORT:-3306}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"

case "$BACKUP_RETENTION_DAYS" in
    ''|*[!0-9]*)
        echo "BACKUP_RETENTION_DAYS must be a non-negative integer" >&2
        exit 1
        ;;
esac

mkdir -p /backups
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
target="/backups/${DB_DATABASE}_${timestamp}.sql.gz"
temporary="${target}.tmp"

cleanup() {
    rm -f "$temporary"
}
trap cleanup EXIT HUP INT TERM

export MYSQL_PWD="$DB_PASSWORD"
mariadb-dump \
    --host="$DB_HOST" \
    --port="$DB_PORT" \
    --user="$DB_USERNAME" \
    --single-transaction \
    --quick \
    --routines \
    --events \
    --triggers \
    --databases "$DB_DATABASE" \
    | gzip -9 > "$temporary"

gzip -t "$temporary"
mv "$temporary" "$target"
trap - EXIT HUP INT TERM

find /backups -type f -name "${DB_DATABASE}_*.sql.gz" -mtime "+${BACKUP_RETENTION_DAYS}" -delete
echo "Backup created: $target"

