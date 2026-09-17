#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

state_file=$(realpath "${1:?Usage: rollback-release.sh /www/wwwroot/forum.example.com/shared/deployments/RELEASE/state.env}")
[[ "$state_file" == /www/wwwroot/forum.example.com/shared/deployments/*/state.env && -s "$state_file" ]] || exit 2
# This file is generated with Bash %q quoting inside a mode-700 server directory.
source "$state_file"
[[ "$site_root" == /www/wwwroot/forum.example.com && "$nginx_config" == /www/server/panel/vhost/nginx/forum.example.com.conf ]] || exit 2
[[ "$previous_release" == "$site_root/releases/"* && -d "$previous_release" ]] || exit 2
[[ -s "$state_dir/nginx.previous.conf" && -x "$nginx_binary" ]] || exit 2
if [[ "${DEPLOY_LOCK_HELD:-0}" != 1 ]]; then
    exec 9>"$site_root/shared/deploy.lock"
    flock -n 9 || { echo 'Another release or rollback is running.' >&2; exit 2; }
fi

[[ "$(docker inspect -f '{{.Image}}' "$previous_container")" == "$previous_image" ]] || {
    echo 'The retained rollback container no longer matches its recorded image.' >&2
    exit 1
}
if [[ "$(docker inspect -f '{{.State.Running}}' "$previous_container")" != true ]]; then
    docker start "$previous_container" >/dev/null
fi
ln -s "$previous_release" "$site_root/.rollback-$release_id"
mv -Tf "$site_root/.rollback-$release_id" "$site_root/current"
install -m 644 "$state_dir/nginx.previous.conf" "$nginx_config"
"$nginx_binary" -t
"$nginx_binary" -s reload
curl --fail --silent --show-error --connect-timeout 5 --max-time 20 \
    --resolve forum.example.com:443:127.0.0.1 https://forum.example.com/health.php > "$state_dir/rollback-health.json"
python3 - "$state_dir/rollback-health.json" <<'PY'
import json
from pathlib import Path
import sys
health = json.loads(Path(sys.argv[1]).read_text())
if health.get('status') != 'ok' or health.get('database') is not True:
    raise SystemExit('Restored application health check failed.')
PY
if docker inspect "$candidate_container" >/dev/null 2>&1; then
    docker stop "$candidate_container" >/dev/null
fi
printf 'Restored current=%s\nRestored image=%s\nDatabase backup remains available; additive migrations were retained.\n' "$previous_release" "$previous_image" > "$state_dir/ROLLBACK.txt"
cat "$state_dir/ROLLBACK.txt"
