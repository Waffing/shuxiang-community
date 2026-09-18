#!/usr/bin/env bash
set -euo pipefail
umask 077

repo_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
app_image=${1:-shuxiang-php-ci}
for dependency in docker python3 curl timeout; do
  if ! command -v "$dependency" >/dev/null; then
    printf 'Required command not found: %s\n' "$dependency" >&2
    exit 1
  fi
done
docker info >/dev/null
docker image inspect "$app_image" >/dev/null

scratch=$(mktemp -d -t shuxiang-test.XXXXXXXX)
network=$(basename "$scratch")
database="$network-db"
redis="$network-redis"
http="$network-http"
fixture="$network-fixture"
cleanup() {
  docker rm -fv "$http" "$fixture" "$database" "$redis" >/dev/null 2>&1 || true
  docker network rm "$network" >/dev/null 2>&1 || true
  rm -rf -- "$scratch"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

mkdir "$scratch/project"
cp -R "$repo_root/app" "$repo_root/tests" "$scratch/project/"
python3 - "$scratch" <<'PY'
import secrets
import sys
from pathlib import Path

directory = Path(sys.argv[1])
password = secrets.token_hex(32)
redis_password = secrets.token_hex(32)
settings = {
    'db.env': {
        'MARIADB_DATABASE': 'shuxiang_ci_test', 'MARIADB_USER': 'shuxiang_test',
        'MARIADB_PASSWORD': password, 'MARIADB_ROOT_PASSWORD': secrets.token_hex(32),
        'TZ': 'Asia/Shanghai',
    },
    'redis.env': {'REDIS_PASSWORD': redis_password},
    'app.env': {
        'APP_ENV': 'test', 'APP_KEY': secrets.token_hex(32), 'DOMAIN': 'localhost',
        'DB_HOST': 'db', 'DB_PORT': '3306', 'DB_DATABASE': 'shuxiang_ci_test',
        'DB_USERNAME': 'shuxiang_test', 'DB_PASSWORD': password,
        'REDIS_HOST': 'redis', 'REDIS_PORT': '6379', 'REDIS_PASSWORD': redis_password,
        'TZ': 'Asia/Shanghai',
    },
}
for name, values in settings.items():
    (directory / name).write_text(''.join(f'{key}={value}\n' for key, value in values.items()))
PY

docker network create "$network" >/dev/null
docker run -d --name "$database" --network "$network" --network-alias db \
  --env-file "$scratch/db.env" \
  --mount "type=bind,src=$repo_root/app/database/schema.sql,dst=/docker-entrypoint-initdb.d/001_schema.sql,readonly" \
  --health-cmd 'healthcheck.sh --connect --innodb_initialized' \
  --health-interval 2s --health-timeout 3s --health-retries 30 \
  mariadb:10.11 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci \
  --transaction-isolation=READ-COMMITTED >/dev/null
docker run -d --name "$redis" --network "$network" --network-alias redis \
  --user redis --env-file "$scratch/redis.env" \
  --health-cmd 'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli ping | grep -qx PONG' \
  --health-interval 2s --health-timeout 3s --health-retries 30 \
  redis:7-alpine sh -ec 'exec redis-server --save "" --appendonly no --requirepass "$REDIS_PASSWORD"' >/dev/null

for service in "$database" "$redis"; do
  ready=false
  for ((attempt = 0; attempt < 90; attempt++)); do
    if [[ $(docker inspect --format '{{.State.Health.Status}}' "$service") == healthy ]]; then
      ready=true
      break
    fi
    sleep 1
  done
  if [[ $ready != true ]]; then
    printf 'Service did not become healthy: %s\n' "$service" >&2
    exit 1
  fi
done

runtime=(--network "$network" --env-file "$scratch/app.env"
  --user "$(id -u):$(id -g)" --workdir /workspace
  --mount "type=bind,src=$scratch/project,dst=/workspace")
timeout 180s docker run --rm --name "$fixture" "${runtime[@]}" "$app_image" sh -ec '
  php tests/backend_release_test.php --database
  php tests/api_fixture.php
'
docker run -d --name "$http" "${runtime[@]}" \
  -p 127.0.0.1:18790:18790 -e PHP_CLI_SERVER_WORKERS=4 \
  "$app_image" php -S 0.0.0.0:18790 -t app/public app/public/router.php >/dev/null

ready=false
for ((attempt = 0; attempt < 30; attempt++)); do
  if curl --noproxy '*' -fsS --max-time 3 http://127.0.0.1:18790/health.php > "$scratch/health.json" 2>/dev/null; then
    ready=true
    break
  fi
  sleep 1
done
if [[ $ready != true ]]; then
  printf 'HTTP health check failed\n' >&2
  docker inspect --format 'HTTP state={{.State.Status}} exit={{.State.ExitCode}} ports={{json .NetworkSettings.Ports}}' "$http" >&2
  docker logs --tail 20 "$http" >&2
  exit 1
fi
python3 - "$scratch/health.json" <<'PY'
import json
import sys
from pathlib import Path
assert json.loads(Path(sys.argv[1]).read_text()) == {'status': 'ok', 'database': True, 'redis': True}
print('Isolated database and Redis health checks passed')
PY

export NO_PROXY=127.0.0.1,localhost no_proxy=127.0.0.1,localhost
TEST_ORIGIN=http://127.0.0.1:18790 timeout 120s python3 "$scratch/project/tests/api_integration_test.py"
timeout 120s python3 "$scratch/project/tests/upload_test.py"
printf 'Database, HTTP and upload integration checks passed\n'
