#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# Deploy an already uploaded release. Database/Redis containers and the serving
# PHP container stay running; Nginx switches to the verified candidate port.
site_root=/www/wwwroot/forum.example.com
nginx_config=${NGINX_CONFIG:-/www/server/panel/vhost/nginx/forum.example.com.conf}
nginx_binary=${NGINX_BIN:-/www/server/nginx/sbin/nginx}
project=${COMPOSE_PROJECT_NAME:-resource_forum_2}
release_dir=$(realpath "${1:?Usage: deploy-bt-release.sh /www/wwwroot/forum.example.com/releases/RELEASE}")
release_id=$(basename "$release_dir")
[[ "$release_dir" == "$site_root/releases/$release_id" && "$release_id" =~ ^[A-Za-z0-9_-]+$ ]] || exit 2
[[ "$nginx_config" == /www/server/panel/vhost/nginx/forum.example.com.conf ]] || exit 2
[[ -L "$site_root/current" && -s "$nginx_config" && -x "$nginx_binary" ]] || exit 2
for command in docker python3 curl gzip flock; do command -v "$command" >/dev/null; done
for file in docker-compose.yml docker-compose.bt.yml app/public/health.php app/public/seo.php scripts/rollback-release.sh; do
    [[ -f "$release_dir/$file" ]] || { echo "Missing release file: $file" >&2; exit 2; }
done

mkdir -p "$site_root/shared/deployments" "$site_root/backups"
exec 9>"$site_root/shared/deploy.lock"
flock -n 9 || { echo 'Another release or rollback is running.' >&2; exit 2; }
previous_release=$(readlink -f "$site_root/current")
[[ "$previous_release" != "$release_dir" ]] || { echo 'Release is already current.' >&2; exit 2; }
state_dir="$site_root/shared/deployments/$release_id"
[[ ! -e "$state_dir" ]] || { echo 'Release already has deployment state; use a new release ID.' >&2; exit 2; }
mkdir -m 700 "$state_dir"

previous_port=$(sed -n 's/^[[:space:]]*fastcgi_pass 127\.0\.0\.1:\([0-9][0-9]*\);/\1/p' "$nginx_config")
[[ "$previous_port" =~ ^[0-9]+$ ]] || { echo 'Expected one loopback PHP upstream.' >&2; exit 2; }
previous_container=$(docker ps --filter "publish=$previous_port" --format '{{.ID}}')
[[ "$previous_container" =~ ^[a-f0-9]+$ ]] || { echo 'Expected one serving PHP container.' >&2; exit 2; }
previous_image=$(docker inspect -f '{{.Image}}' "$previous_container")
db_container=$(docker ps --filter "label=com.docker.compose.project=$project" --filter label=com.docker.compose.service=db --format '{{.ID}}')
[[ "$db_container" =~ ^[a-f0-9]+$ ]] || { echo 'Expected one running database container.' >&2; exit 2; }
network=$(docker inspect -f '{{range $name, $_ := .NetworkSettings.Networks}}{{println $name}}{{end}}' "$previous_container")
[[ "$network" =~ ^[A-Za-z0-9_-]+$ ]] || { echo 'Expected one application network.' >&2; exit 2; }
compose_dir=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project.working_dir"}}' "$previous_container")
retained_env=$(docker inspect -f '{{index .Config.Labels "forum.env_file"}}' "$previous_container")
[[ "$retained_env" == '<no value>' ]] && retained_env=''
env_file=${ENV_FILE:-${retained_env:-$compose_dir/.env}}
[[ -f "$env_file" ]] || { echo 'Set ENV_FILE to the existing server Compose environment file.' >&2; exit 2; }
docker compose --project-name "$project" --env-file "$env_file" -f "$release_dir/docker-compose.yml" -f "$release_dir/docker-compose.bt.yml" config --quiet

python3 - "$release_dir/app/public" <<'PY'
from pathlib import Path
import hashlib
import json
import re
import subprocess
import sys
from urllib.parse import urlsplit

public = Path(sys.argv[1])
update = json.loads((public / 'app-update.json').read_text(encoding='utf-8'))
apks = set(public.rglob('*.apk'))
if update.get('releaseAvailable') is False:
    if apks:
        raise SystemExit('APK files must not be public while releaseAvailable is false.')
elif update.get('releaseAvailable') is True:
    url = urlsplit(update.get('downloadUrl') or '')
    if url.scheme != 'https' or url.netloc != 'forum.example.com' or url.query or url.fragment:
        raise SystemExit('Release APK must use the same HTTPS origin.')
    if not re.fullmatch(r'/downloads/resource-forum-v[0-9]+\.[0-9]+\.[0-9]+-release\.apk', url.path):
        raise SystemExit('Release APK filename does not match the public download rule.')
    apk = public / url.path.lstrip('/')
    if apks != {apk} or apk.stat().st_size != update.get('fileSize'):
        raise SystemExit('Public APK files do not match the update manifest.')
    if hashlib.sha256(apk.read_bytes()).hexdigest() != update.get('sha256'):
        raise SystemExit('Release APK checksum differs from the manifest.')
    signature = subprocess.run(['apksigner', 'verify', '--print-certs', str(apk)], check=True, capture_output=True, text=True)
    if 'android debug' in signature.stdout.lower():
        raise SystemExit('Debug signing certificates are not public releases.')
    metadata = subprocess.run(['aapt', 'dump', 'badging', str(apk)], check=True, capture_output=True, text=True)
    if 'application-debuggable' in metadata.stdout:
        raise SystemExit('Debuggable APK files are not public releases.')
    version = re.search(r"versionCode='([^']+)' versionName='([^']+)'", metadata.stdout)
    if not version or int(version[1]) != update.get('versionCode') or version[2] != update.get('versionName'):
        raise SystemExit('APK version differs from the manifest.')
else:
    raise SystemExit('The update manifest must declare releaseAvailable as a boolean.')
print('Public Android release gate passed.')
PY

candidate_port=${CANDIDATE_PORT:-$(python3 - <<'PY'
import socket
for port in range(9002, 9013):
    with socket.socket() as sock:
        try:
            sock.bind(('127.0.0.1', port))
        except OSError:
            continue
        print(port)
        break
else:
    raise SystemExit('No candidate PHP port is available.')
PY
)}
[[ "$candidate_port" =~ ^[0-9]+$ && "$candidate_port" -ge 1024 && "$candidate_port" -le 65535 && "$candidate_port" != "$previous_port" ]] || exit 2
candidate_container="${project}-release-${release_id}"
candidate_image="${project}-app:release-${release_id,,}"
cp -p "$nginx_config" "$state_dir/nginx.previous.conf"
for key in site_root nginx_config nginx_binary previous_release previous_container previous_image candidate_container candidate_image candidate_port release_id state_dir; do
    printf '%s=%q\n' "$key" "${!key}"
done > "$state_dir/state.env"
cp "$release_dir/scripts/rollback-release.sh" "$state_dir/ROLLBACK.sh"
chmod 700 "$state_dir/ROLLBACK.sh"

restore_on_error() {
    local status=${1:-$?}
    trap - ERR INT TERM
    if [[ -f "$state_dir/switch-started" ]]; then
        DEPLOY_LOCK_HELD=1 bash "$state_dir/ROLLBACK.sh" "$state_dir/state.env" || {
            echo "Automatic rollback needs attention: $state_dir" >&2
            exit 1
        }
    elif docker inspect "$candidate_container" >/dev/null 2>&1; then
        docker stop "$candidate_container" >/dev/null || true
    fi
    echo "Release stopped before completion; evidence: $state_dir" >&2
    exit "${status:-1}"
}
trap restore_on_error ERR
trap 'restore_on_error 130' INT
trap 'restore_on_error 143' TERM

# Snapshot before DDL. A failed migration remains unrecorded and is never followed
# by a traffic switch. MariaDB DDL itself is not transactionally reversible.
database_backup="$site_root/backups/pre-release-${release_id}.sql.gz"
docker exec "$db_container" sh -lc 'export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"; exec mariadb-dump -uroot --single-transaction --quick --routines --events --triggers --databases "$MARIADB_DATABASE"' | gzip -9 > "$database_backup"
gzip -t "$database_backup"
echo "Database backup verified: $database_backup"

database_query() {
    docker exec -i "$db_container" sh -lc 'export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"; exec mariadb -uroot --abort-source-on-error --batch --skip-column-names "$MARIADB_DATABASE"'
}
database_query <<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(190) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
SQL
shopt -s nullglob
for migration in "$release_dir"/app/database/migrations/*.sql; do
    version=$(basename "$migration" .sql)
    [[ "$version" =~ ^[A-Za-z0-9._-]+$ ]] || exit 2
    applied=$(database_query <<< "SELECT COUNT(*) FROM schema_migrations WHERE version = '$version';")
    if [[ "$applied" == 1 ]]; then
        echo "Already applied: $version"
        continue
    fi
    echo "Applying migration: $version"
    database_query < "$migration"
    database_query <<< "INSERT INTO schema_migrations (version) VALUES ('$version');"
done

# This application-only release inherits the serving runtime's installed PHP
# extensions; no base image pull or dependency rebuild changes the runtime.
docker build --build-arg "BASE_IMAGE=$previous_image" --tag "$candidate_image" --file - "$release_dir" <<'DOCKERFILE'
ARG BASE_IMAGE
FROM ${BASE_IMAGE}
USER root
RUN rm -rf /var/www/html
COPY --chown=www-data:www-data app/ /var/www/html/
COPY infra/php/php.ini /usr/local/etc/php/conf.d/99-production.ini
COPY infra/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf
USER www-data
DOCKERFILE
docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$previous_container" > "$state_dir/runtime.env"
docker run --detach --name "$candidate_container" --restart unless-stopped \
    --log-opt max-size=10m --log-opt max-file=3 \
    --label "forum.release=$release_id" --label "forum.project=$project" --label "forum.env_file=$env_file" \
    --network "$network" --publish "127.0.0.1:$candidate_port:9000" \
    --env-file "$state_dir/runtime.env" \
    --mount "type=bind,source=$site_root/shared/uploads,target=/var/www/html/public/uploads" \
    "$candidate_image" > "$state_dir/candidate.id"
docker exec "$candidate_container" php-fpm -t
docker exec "$candidate_container" php -r 'require "/var/www/html/src/bootstrap.php"; \App\Database::connection()->query("SELECT 1"); if (!\App\Cache::healthy()) { exit(1); } echo "Candidate database and Redis healthy\n";'
docker exec "$candidate_container" php -r 'require "/var/www/html/src/bootstrap.php"; \App\Cache::forgetPrefix("software:"); echo "Resource query caches invalidated\n";'

python3 - "$release_dir/infra/nginx/forum.example.com.conf" "$state_dir/nginx.candidate.conf" "$candidate_port" "$release_dir" <<'PY'
from pathlib import Path
import re
import sys
source, target, port, release = sys.argv[1:]
text, count = re.subn(r'fastcgi_pass 127\.0\.0\.1:9002;', f'fastcgi_pass 127.0.0.1:{port};', Path(source).read_text())
if count != 1:
    raise SystemExit('Expected exactly one PHP upstream in the release config.')
text, count = re.subn(r'root /www/wwwroot/forum\.example\.com/current/app/public;', f'root {release}/app/public;', text)
if count != 1:
    raise SystemExit('Expected exactly one public document root in the release config.')
Path(target).write_text(text)
PY
printf 'events {}\nhttp { include /www/server/nginx/conf/mime.types; include "%s"; }\n' "$state_dir/nginx.candidate.conf" > "$state_dir/nginx-check.conf"
"$nginx_binary" -t -c "$state_dir/nginx-check.conf"

# One Nginx reload switches both static files and PHP. The current link follows
# only after smoke checks pass; prior workers keep their previous document root.
touch "$state_dir/switch-started"
ln -s "$release_dir" "$site_root/.current-$release_id"
install -m 644 "$state_dir/nginx.candidate.conf" "$nginx_config"
"$nginx_binary" -t
"$nginx_binary" -s reload

python3 - "$state_dir" <<'PY'
import json
from pathlib import Path
import subprocess
import sys

state = Path(sys.argv[1])
for index, path in enumerate(('/', '/health.php', '/robots.txt', '/sitemap.xml', '/sw.js', '/app-update.json', '/assets/app.min.js')):
    headers, body = state / f'smoke-{index}.headers', state / f'smoke-{index}.body'
    subprocess.run(['curl', '--fail', '--silent', '--show-error', '--connect-timeout', '5', '--max-time', '20',
                    '--resolve', 'forum.example.com:443:127.0.0.1', '-H', 'Accept-Encoding: identity',
                    '-D', str(headers), '-o', str(body), 'https://forum.example.com' + path], check=True)
    response_headers = headers.read_text().lower()
    for header in ('content-security-policy:', 'x-content-type-options: nosniff', 'x-frame-options: sameorigin'):
        if header not in response_headers:
            raise SystemExit(f'Missing {header} on {path}')
    if path in ('/sw.js', '/app-update.json') and 'no-store' not in response_headers:
        raise SystemExit(f'Missing no-store on {path}')
    if path == '/health.php':
        data = json.loads(body.read_text())
        if data.get('status') != 'ok' or data.get('database') is not True or data.get('redis') is not True:
            raise SystemExit('Candidate public health check failed.')
    print(f'HTTPS smoke passed: {path}')
PY
mv -Tf "$site_root/.current-$release_id" "$site_root/current"
printf 'release=%s\ncontainer=%s\nimage=%s\nprevious_release=%s\n' "$release_id" "$candidate_container" "$candidate_image" "$previous_release" > "$state_dir/SUCCESS.txt"
trap - ERR INT TERM
echo "Release active: https://forum.example.com/ ($release_id)"
echo "Rollback: bash $state_dir/ROLLBACK.sh $state_dir/state.env"
