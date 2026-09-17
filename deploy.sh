#!/usr/bin/env bash
set -Eeuo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

require_command() {
    command -v "$1" >/dev/null 2>&1 || {
        echo "Required command not found: $1" >&2
        exit 1
    }
}

read_env_value() {
    local key="$1"
    sed -n "s/^${key}=//p" .env | tail -n 1
}

set_env_value() {
    local key="$1" value="$2" temporary
    temporary="$(mktemp)"
    awk -v key="$key" -v value="$value" '
        BEGIN { updated = 0 }
        $0 ~ "^" key "=" { print key "=" value; updated = 1; next }
        { print }
        END { if (!updated) print key "=" value }
    ' .env > "$temporary"
    mv "$temporary" .env
}

valid_domain() {
    local domain="$1" label
    local -a labels
    [[ ${#domain} -le 253 && "$domain" =~ ^[A-Za-z0-9.-]+\.[A-Za-z]{2,63}$ ]] || return 1
    IFS='.' read -r -a labels <<< "$domain"
    for label in "${labels[@]}"; do
        [[ ${#label} -le 63 && "$label" =~ ^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?$ ]] || return 1
    done
}

require_command docker
require_command openssl
docker compose version >/dev/null

backup_existing_database() {
    [[ -f .env ]] || return 0
    local db_container backup_container
    db_container="$(docker compose --env-file .env ps -a -q db 2>/dev/null || true)"
    [[ -n "$db_container" ]] || return 0
    backup_container="$(docker compose --env-file .env ps -a -q backup 2>/dev/null || true)"
    if [[ -z "$backup_container" || "$(docker inspect -f '{{.State.Running}}' "$backup_container")" != "true" ]]; then
        echo "Existing database detected, but the backup service is not running." >&2
        exit 1
    fi
    docker compose --env-file .env exec -T backup /usr/local/bin/backup.sh
    docker compose --env-file .env exec -T backup sh -lc '
        latest="$(ls -1t /backups/*.sql.gz | head -n 1)"
        test -n "$latest"
        gzip -t "$latest"
        echo "Backup verified: $latest"
    '
}

backup_existing_database

if command -v git >/dev/null 2>&1 && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    branch="$(git branch --show-current)"
    if [[ -n "$branch" ]] && git config --get "branch.${branch}.remote" >/dev/null 2>&1; then
        git pull --ff-only
    fi
fi

if [[ ! -f .env ]]; then
    cp .env.example .env
fi
chmod 600 .env

for key in DB_PASSWORD DB_ROOT_PASSWORD REDIS_PASSWORD APP_KEY; do
    value="$(read_env_value "$key")"
    if [[ -z "$value" || "$value" == replace-* ]]; then
        set_env_value "$key" "$(openssl rand -hex 32)"
    fi
done

if [[ -n "${DOMAIN:-}" ]]; then
    valid_domain "$DOMAIN" || {
        echo "DOMAIN has an invalid hostname format." >&2
        exit 1
    }
    set_env_value DOMAIN "$DOMAIN"
fi
if [[ -n "${CERTBOT_EMAIL:-}" ]]; then
    [[ "$CERTBOT_EMAIL" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] || {
        echo "CERTBOT_EMAIL has an invalid format." >&2
        exit 1
    }
    set_env_value CERTBOT_EMAIL "$CERTBOT_EMAIL"
fi

domain="$(read_env_value DOMAIN)"
email="$(read_env_value CERTBOT_EMAIL)"

if ! valid_domain "$domain" || [[ "$domain" == "forum.example.com" ]]; then
    echo "Set a public DOMAIN in .env or pass DOMAIN=forum.example.org to this script." >&2
    exit 1
fi
if [[ ! "$email" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ || "$email" == "admin@example.com" ]]; then
    echo "Set CERTBOT_EMAIL in .env or pass it in the environment." >&2
    exit 1
fi
if [[ ! -f app/database/schema.sql ]]; then
    echo "Missing database schema: app/database/schema.sql" >&2
    exit 1
fi

docker compose --env-file .env config --quiet
docker compose --env-file .env pull db redis certbot
docker compose --env-file .env up -d --wait db redis
bash ./migrate.sh
docker compose --env-file .env build --pull app nginx backup
docker compose --env-file .env up -d --wait app nginx

docker compose --env-file .env run --rm --no-deps --entrypoint certbot certbot \
    certonly \
    --webroot \
    --webroot-path /var/www/certbot \
    --domain "$domain" \
    --email "$email" \
    --agree-tos \
    --no-eff-email \
    --non-interactive \
    --keep-until-expiring

docker compose --env-file .env up -d --remove-orphans
docker compose --env-file .env restart nginx
docker compose --env-file .env exec -T nginx nginx -t
docker compose --env-file .env ps

echo "Deployment complete: https://${domain}"
echo "Run a backup now with: docker compose --env-file .env exec -T backup /usr/local/bin/backup.sh"
