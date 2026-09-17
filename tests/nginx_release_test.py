"""Check the two deployment paths keep their public request boundary aligned."""
from pathlib import Path
import os
import re
import shlex
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(sys.argv.pop(1)).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]


class NginxReleaseTest(unittest.TestCase):
    def test_candidate_config_binds_static_files_and_php_to_the_same_release(self):
        deploy = (ROOT / 'scripts/deploy-bt-release.sh').read_text()
        start = deploy.index('from pathlib import Path\nimport re\nimport sys\nsource, target, port, release')
        generator = deploy[start:deploy.index('\nPY\n', start)]
        with tempfile.TemporaryDirectory(prefix='forum-candidate-config-') as directory:
            target = Path(directory) / 'candidate.conf'
            command = [sys.executable, '-c', generator, str(ROOT / 'infra/nginx/forum.example.com.conf'),
                       str(target), '9007', '/www/wwwroot/forum.example.com/releases/fixture']
            result = subprocess.run(command, text=True, capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            generated = target.read_text()
            self.assertIn('root /www/wwwroot/forum.example.com/releases/fixture/app/public;', generated)
            self.assertIn('fastcgi_pass 127.0.0.1:9007;', generated)
            self.assertNotIn('/current/app/public', generated)

    def test_both_edges_have_the_same_boundaries(self):
        for name in ("forum.example.com.conf", "entrypoint.sh"):
            with self.subTest(config=name):
                text = (ROOT / "infra/nginx" / name).read_text(encoding="utf-8")
                text = text.replace(r"\$", "$")
                self.assertIn("map $request_method $forum2_write_key", text)
                self.assertIn("limit_req zone=forum2_write", text)
                self.assertIn("limit_req_status 429", text)
                self.assertIn("fastcgi_param HTTP_AUTHORIZATION $http_authorization;", text)
                self.assertRegex(text, r"fastcgi_param HTTPS (?:on|\$https)(?: if_not_empty)?;")
                self.assertIn('fastcgi_param HTTP_PROXY "";', text)
                self.assertIn("location ^~ /uploads/", text)
                self.assertIn("location ^~ /protected-downloads/", text)
                self.assertIn("location ^~ /downloads/", text)
                self.assertIn("-release", text)
                self.assertIn("location ~* \\.php", text)
                self.assertRegex(text, r"location ~ \^/\(\?:index\|api\|download\|health\|seo\)\\\.php\$")
                for route in ("/robots.txt", "/sitemap.xml", "software|platform|category"):
                    self.assertIn(route, text)
                for file in ("sw.js", "app-update.json"):
                    self.assertRegex(text, rf"/{re.escape(file)}\s+\"[^\"]*no-store")

    def test_response_headers_are_not_lost_by_location_inheritance(self):
        for name in ("forum.example.com.conf", "entrypoint.sh"):
            with self.subTest(config=name):
                text = (ROOT / "infra/nginx" / name).read_text(encoding="utf-8")
                # Every add_header must precede the first application location in
                # each generated server. Locations may set variables, not headers.
                for location in re.finditer(r"\blocation\s+[^\n{]+\{", text):
                    depth = 1
                    end = location.end()
                    while depth and end < len(text):
                        depth += (text[end] == "{") - (text[end] == "}")
                        end += 1
                    self.assertNotIn("add_header", text[location.end():end])
                for header in ("X-Content-Type-Options", "X-Frame-Options", "Referrer-Policy",
                               "Permissions-Policy", "Content-Security-Policy"):
                    self.assertRegex(text, rf"add_header {header} [^\n]+ always;")


@unittest.skipIf(os.name == 'nt', 'Release shell fixtures require Linux symlink and flock semantics.')
class ReleaseRollbackTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='forum-release-test-')
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.site = self.base / 'forum.example.com'
        self.old = self.site / 'releases' / 'old'
        self.new = self.site / 'releases' / 'new'
        self.state = self.site / 'shared' / 'deployments' / 'new'
        self.bin = self.base / 'bin'
        for directory in (self.old, self.new, self.state, self.bin):
            directory.mkdir(parents=True, exist_ok=True)
        self.config = self.base / 'forum.example.com.conf'
        self.config.write_text('new configuration\n')
        (self.state / 'nginx.previous.conf').write_text('old configuration\n')
        (self.site / 'current').symlink_to(self.new)
        self.log = self.base / 'commands.log'
        self.command('docker', '''
printf 'docker %s\\n' "$*" >> "$FIXTURE_LOG"
if [ "$1" = inspect ] && [ "${3:-}" = '{{.Image}}' ]; then printf 'sha256:old\\n'; fi
if [ "$1" = inspect ] && [ "${3:-}" = '{{.State.Running}}' ]; then printf 'true\\n'; fi
''')
        self.command('nginx', 'printf "nginx %s\\n" "$*" >> "$FIXTURE_LOG"\n')
        self.command('curl', 'printf \'{"status":"ok","database":true,"redis":true}\\n\'\n')
        values = dict(site_root=self.site, nginx_config=self.config, nginx_binary=self.bin / 'nginx',
                      previous_release=self.old, previous_container='abc123', previous_image='sha256:old',
                      candidate_container='candidate', candidate_port='9003', release_id='new', state_dir=self.state)
        self.state_file = self.state / 'state.env'
        self.state_file.write_text(''.join(f'{key}={shlex.quote(str(value))}\n' for key, value in values.items()))
        self.rollback = self.copy_script('rollback-release.sh')
        self.env = {**os.environ, 'PATH': str(self.bin) + os.pathsep + os.environ['PATH'], 'FIXTURE_LOG': str(self.log)}

    def command(self, name, body):
        path = self.bin / name
        path.write_text('#!/bin/sh\nset -eu\n' + body)
        path.chmod(0o755)

    def copy_script(self, name):
        text = (ROOT / 'scripts' / name).read_text()
        text = text.replace('/www/wwwroot/forum.example.com', str(self.site))
        text = text.replace('/www/server/panel/vhost/nginx/forum.example.com.conf', str(self.config))
        text = text.replace('/www/server/nginx/sbin/nginx', str(self.bin / 'nginx'))
        path = self.base / name
        path.write_text(text)
        path.chmod(0o755)
        return path

    def test_rollback_restores_link_config_and_retained_image(self):
        result = subprocess.run(['bash', str(self.rollback), str(self.state_file)], env=self.env, text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual((self.site / 'current').resolve(), self.old)
        self.assertEqual(self.config.read_text(), 'old configuration\n')
        self.assertIn('Restored image=sha256:old', result.stdout)
        self.assertIn('docker stop candidate', self.log.read_text())
        self.assertTrue((self.state / 'ROLLBACK.txt').is_file())

    def test_changed_retained_image_stops_rollback(self):
        self.command('docker', 'printf "sha256:unexpected\\n"\n')
        result = subprocess.run(['bash', str(self.rollback), str(self.state_file)], env=self.env, text=True, capture_output=True)
        self.assertEqual(result.returncode, 1)
        self.assertEqual((self.site / 'current').resolve(), self.new)
        self.assertEqual(self.config.read_text(), 'new configuration\n')

    def test_migration_failure_never_builds_or_switches(self):
        # Create a separate release because an existing state directory is rejected.
        release = self.site / 'releases' / 'failed'
        for name in ('docker-compose.yml', 'docker-compose.bt.yml', 'app/public/health.php',
                     'app/public/seo.php', 'scripts/rollback-release.sh', 'app/database/migrations/20260917_test.sql'):
            path = release / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('INTENTIONALLY_FAIL\n')
        (self.old / '.env').write_text('COMPOSE_PROJECT_NAME=fixture\n')
        (release / 'app/public/app-update.json').write_text('{"releaseAvailable":false}\n')
        self.config.write_text('fastcgi_pass 127.0.0.1:9002;\n')
        docker = '''
printf 'docker %s\\n' "$*" >> "$FIXTURE_LOG"
case "$1" in
  ps) case "$*" in *service=db*) echo db123;; *) echo abc123;; esac ;;
  inspect)
    case "${3:-}" in
      '{{.Image}}') echo sha256:old;;
      *NetworkSettings*) echo fixture_internal;;
      *working_dir*) echo "$FIXTURE_OLD";;
      *forum.env_file*) echo '<no value>';;
      *) exit 1;;
    esac ;;
  exec)
    case "$*" in
      *mariadb-dump*) echo 'CREATE TABLE backup_probe(id INT);';;
      *) sql=$(cat); case "$sql" in *INTENTIONALLY_FAIL*) exit 42;; *'COUNT(*)'*) echo 0;; esac;;
    esac ;;
esac
'''
        self.command('docker', docker)
        env = {**self.env, 'FIXTURE_OLD': str(self.old), 'CANDIDATE_PORT': '9003'}
        deploy = self.copy_script('deploy-bt-release.sh')
        result = subprocess.run(['bash', str(deploy), str(release)], env=env, text=True, capture_output=True)
        self.assertEqual(result.returncode, 42, result.stdout + result.stderr)
        self.assertEqual((self.site / 'current').resolve(), self.new)
        self.assertEqual(self.config.read_text(), 'fastcgi_pass 127.0.0.1:9002;\n')
        self.assertNotIn('docker build', self.log.read_text())
        self.assertIn('Database backup verified:', result.stdout)
        self.assertTrue((self.site / 'backups' / 'pre-release-failed.sql.gz').is_file())


if __name__ == "__main__":
    unittest.main()
