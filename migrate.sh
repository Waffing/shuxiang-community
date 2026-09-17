#!/usr/bin/env bash
set -Eeuo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

[[ -f .env ]] || {
    echo "Missing .env" >&2
    exit 1
}

migration_dir="app/database/migrations"
[[ -d "$migration_dir" ]] || {
    echo "Missing migration directory: $migration_dir" >&2
    exit 1
}

compose=(docker compose --env-file .env)
database_command='mariadb --abort-source-on-error --batch --skip-column-names -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'

database_query() {
    "${compose[@]}" exec -T db sh -lc "$database_command" <<< "$1"
}

database_query 'CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(190) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;'

shopt -s nullglob
migrations=("$migration_dir"/*.sql)
for file in "${migrations[@]}"; do
    version="$(basename "$file" .sql)"
    [[ "$version" =~ ^[A-Za-z0-9._-]+$ ]] || {
        echo "Invalid migration filename: $file" >&2
        exit 1
    }
    applied="$(database_query "SELECT COUNT(*) FROM schema_migrations WHERE version = '${version}';" | tr -d '\r\n')"
    if [[ "$applied" == "1" ]]; then
        echo "Already applied: $version"
        continue
    fi
    echo "Applying migration: $version"
    if ! "${compose[@]}" exec -T db sh -lc "$database_command" < "$file"; then
        echo "Migration failed and was not recorded: $version" >&2
        exit 1
    fi
    database_query "INSERT INTO schema_migrations (version) VALUES ('${version}');"
done

echo "Database migrations complete."
