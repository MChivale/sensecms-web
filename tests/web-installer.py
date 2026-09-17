"""Isolated HTTP acceptance of the real generated web installer; never uses production."""
import contextlib
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile

PROJECT = Path(__file__).resolve().parents[1]
checks = 0
if sys.argv[1:] not in ([], ['--nginx']):
    raise SystemExit('Use no arguments for PHP development server or --nginx for isolated Linux Nginx/FPM.')
nginx = sys.argv[1:] == ['--nginx']
if nginx and (os.name != 'posix' or os.geteuid() != 0):
    raise SystemExit('The isolated Nginx fixture requires Linux root; it never changes system services.')


def check(ok, label):
    global checks
    assert ok, label
    checks += 1
    print('PASS', label, flush=True)


def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def request(browser, url, data=None):
    req = urllib.request.Request(url, data=urllib.parse.urlencode(data).encode() if data is not None else None)
    try:
        response = browser.open(req, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.read().decode(), response.headers


@contextlib.contextmanager
def fixture(local=True, empty_storage=False):
    with tempfile.TemporaryDirectory(prefix='sense-web-installer-') as temp:
        root = Path(temp) / 'web'
        public = root / 'public'
        public.mkdir(parents=True)
        if empty_storage:
            (root / 'storage').mkdir(mode=0o700)
        for name in ('index.php', 'install.zip'):
            shutil.copy2(PROJECT / '.install/web' / name, public / name)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        env = dict(os.environ)
        env['SENSE_LOCAL_HTTP'] = '1' if local else '0'
        processes = []
        with open(Path(temp) / 'http.log', 'w+') as log:
            try:
                if nginx:
                    import pwd
                    account = pwd.getpwnam('www-data')
                    os.chmod(temp, 0o711)
                    for path in [root, public, *public.iterdir(), *([root/'storage'] if empty_storage else [])]:
                        os.chown(path, account.pw_uid, account.pw_gid)
                    with socket.socket() as sock:
                        sock.bind(('127.0.0.1', 0))
                        fpm_port = sock.getsockname()[1]
                    fpm_config = Path(temp) / 'fpm.conf'
                    fpm_config.write_text(f'''[global]
daemonize = no
error_log = {temp}/fpm.log
[installer-qa]
user = www-data
group = www-data
listen = 127.0.0.1:{fpm_port}
listen.allowed_clients = 127.0.0.1
pm = static
pm.max_children = 1
clear_env = yes
env[SENSE_LOCAL_HTTP] = {env['SENSE_LOCAL_HTTP']}
php_admin_value[memory_limit] = 256M
php_admin_flag[log_errors] = on
php_admin_value[error_log] = {root}/php-error.log
''')
                    processes.append(subprocess.Popen(['php-fpm8.5', '-F', '-y', str(fpm_config)], stdout=log, stderr=log))
                    for _ in range(100):
                        try:
                            with socket.create_connection(('127.0.0.1', fpm_port), timeout=.1):
                                break
                        except OSError:
                            time.sleep(.05)
                    config = Path(temp) / 'nginx.conf'
                    config.write_text(f'''daemon off;
user www-data;
worker_processes 1;
pid {temp}/nginx.pid;
error_log {temp}/nginx.log;
events {{ worker_connections 32; }}
http {{
 access_log off;
 client_body_temp_path {temp}/body;
 fastcgi_temp_path {temp}/fastcgi;
 server {{
  listen 127.0.0.1:{port};
  server_name localhost;
  root {public};
  index index.php;
  location ~ /\\. {{ return 404; }}
  location ~ ^/(?:app|config|database|storage)(?:/|$) {{ return 404; }}
  location / {{ try_files $uri $uri/ /index.php?$query_string; }}
  location = /index.php {{
   include /etc/nginx/fastcgi_params;
   fastcgi_param SCRIPT_FILENAME $document_root/index.php;
   fastcgi_pass 127.0.0.1:{fpm_port};
  }}
  location ~* \\.php(?:/|$) {{ return 404; }}
 }}
}}
''')
                    processes.append(subprocess.Popen(['nginx', '-p', temp, '-c', str(config)], stdout=log, stderr=log))
                else:
                    processes.append(subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(public)], env=env, stdout=log, stderr=log))
                for _ in range(100):
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=.1):
                            break
                    except OSError:
                        time.sleep(.05)
                yield root, f'http://127.0.0.1:{port}'
            finally:
                for process in reversed(processes):
                    process.terminate()
                    try:
                        process.wait(timeout=10)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.wait()
                log.seek(0)
                logs = log.read()
                for path in [Path(temp)/'nginx.log', Path(temp)/'fpm.log', root/'php-error.log']:
                    if path.exists():
                        logs += path.read_text(errors='replace')
                check(not re.search(r'PHP (Warning|Fatal|Notice|Parse)|Uncaught|\[(?:error|crit|alert|emerg)\]', logs), 'No PHP or web-server errors')


def start(browser, url):
    code, body, headers = request(browser, url)
    check(code == 200 and 'Prepare your workspace.' in body, 'Branded bootstrap loads')
    check('no-store' in headers.get('Cache-Control', '') and "frame-ancestors 'none'" in headers.get('Content-Security-Policy', ''), 'Private non-cacheable bootstrap response')
    return re.search(r'csrf:"([a-f0-9]{64})"', body)[1]


with fixture(False) as (root, url):
    check(request(client(), url)[0] == 422, 'Remote insecure HTTP refused')

with fixture() as (root, url):
    browser = client()
    csrf = start(browser, url)
    (root / 'storage').mkdir()
    (root / 'storage/keep.txt').write_text('existing private data')
    code, body, _ = request(browser, url + '/index.php', {'action': 'extract', 'csrf': csrf})
    check(code == 422 and 'above public' in body and (root / 'storage/keep.txt').read_text() == 'existing private data', 'Nonempty storage is refused without modifying private data')

with fixture() as (root, url):
    browser = client()
    csrf = start(browser, url)
    (root / 'keep.txt').write_text('existing data')
    code, body, _ = request(browser, url + '/index.php', {'action': 'extract', 'csrf': csrf})
    check(code == 422 and 'not empty' in body and (root / 'keep.txt').read_text() == 'existing data', 'Existing root files preserved')

with fixture() as (root, url):
    browser = client()
    csrf = start(browser, url)
    with open(root / 'public/install.zip', 'ab') as archive:
        archive.write(b'tampered')
    code, body, _ = request(browser, url + '/index.php', {'action': 'extract', 'csrf': csrf})
    check(code == 422 and 'checksum mismatch' in body and not (root / 'app').exists(), 'Tampered ZIP rejected before extraction')

with fixture(empty_storage=True) as (root, url):
    browser = client()
    csrf = start(browser, url)
    code, body, _ = request(browser, url + '/index.php', {'action': 'extract', 'csrf': csrf})
    check(code == 200, 'Empty storage passes initial check')
    (root / 'storage/keep.txt').write_text('new private data')
    for _ in range(100):
        code, body, _ = request(browser, url + '/index.php', {'action': 'extract', 'csrf': csrf})
        if code != 200:
            break
    check(code == 422 and 'above public' in body and not (root / 'app').exists() and (root / 'storage/keep.txt').read_text() == 'new private data', 'Storage is rechecked before publishing; new data preserved')

if os.name == 'posix':
    with fixture() as (root, url):
        browser = client()
        csrf = start(browser, url)
        outside = root.parent / 'outside'
        outside.mkdir()
        (root / 'storage').symlink_to(outside, target_is_directory=True)
        code, body, _ = request(browser, url + '/index.php', {'action': 'extract', 'csrf': csrf})
        check(code == 422 and not list(outside.iterdir()), 'Linked storage is rejected without writing outside installation')

with fixture(empty_storage=True) as (root, url):
    browser = client()
    csrf = start(browser, url)
    check(request(browser, url + '/index.php', {'action': 'extract', 'csrf': 'bad'})[0] == 419, 'Extraction requires CSRF')
    code, body, _ = request(browser, url + '/index.php', {'action': 'extract', 'csrf': csrf})
    first = json.loads(body)
    check(code == 200 and 0 < first['progress'] < 100 and not (root / 'app').exists(), 'Empty prepared storage is allowed; first batch keeps unpublished Core private')
    other = client()
    other_csrf = start(other, url)
    code, body, _ = request(other, url + '/index.php', {'action': 'extract', 'csrf': other_csrf})
    check(code == 422 and 'another browser' in body, 'Other session cannot take over extraction')
    csrf = start(browser, url)
    previous = first['progress']
    for _ in range(100):
        code, body, _ = request(browser, url + '/index.php', {'action': 'extract', 'csrf': csrf})
        data = json.loads(body)
        assert code == 200, body
        assert data['progress'] >= previous
        previous = data['progress']
        if data.get('redirect'):
            break
    check(data.get('redirect') == '/install' and previous == 100, 'Refresh resumes extraction to verified completion')
    check(not (root / 'public/install.zip').exists(), 'Public archive removed after activation')
    with zipfile.ZipFile(PROJECT / '.install/web/install.zip') as archive:
        manifest = json.loads(archive.read('installer-manifest.json'))
    check(all(hashlib.sha256((root / name).read_bytes()).hexdigest() == digest for name, digest in manifest['files'].items()), 'Every deployed Core file matches archive inventory')
    check(json.loads((root / 'storage/setup.json').read_text())['base_url'] == 'https://localhost', 'Canonical domain prepared without CLI')
    code, body, _ = request(browser, url + '/install')
    check(code == 200 and 'Activate your license' in body and 'name="license_key"' in body, 'Actual installer begins with license key')
    check('name="db_host"' not in body and 'name="admin_email"' not in body, 'Database and owner inputs hidden before licence')
    core_csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', body)[1]
    check(request(browser, url + '/install/complete', {'csrf': core_csrf})[0] == 409, 'Cannot bypass licence to install database')
    check(not (root / 'storage/installed.json').exists(), 'Preparation never fabricates completed installation')
    if os.name == 'posix':
        check((root / 'storage').stat().st_mode & 0o777 == 0o700, 'Prepared storage remains private')
    if nginx:
        for path in ['/storage/setup.json', '/app/Installer/Installer.php', '/.sense-bootstrap/state.json', '/.htaccess', '/other.php', '/index.php/extra']:
            check(request(browser, url + path)[0] == 404, 'Private or non-front-controller path denied: ' + path)

print(f'{checks} web installer checks passed ({"Nginx/FPM" if nginx else "PHP development server"}); licensed database completion is a separate gate.')
