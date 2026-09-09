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
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile

PROJECT = Path(__file__).resolve().parents[1]
checks = 0


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
def fixture(local=True):
    with tempfile.TemporaryDirectory(prefix='sense-web-installer-') as temp:
        root = Path(temp) / 'web'
        public = root / 'public'
        public.mkdir(parents=True)
        for name in ('index.php', 'install.zip'):
            shutil.copy2(PROJECT / '.install/web' / name, public / name)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        env = dict(os.environ)
        env['SENSE_LOCAL_HTTP'] = '1' if local else '0'
        with open(Path(temp) / 'http.log', 'w+') as log:
            server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(public)], env=env, stdout=log, stderr=log)
            try:
                for _ in range(100):
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=.1):
                            break
                    except OSError:
                        time.sleep(.05)
                yield root, f'http://127.0.0.1:{port}'
            finally:
                server.terminate()
                server.wait(timeout=10)
                log.seek(0)
                check(not re.search(r'PHP (Warning|Fatal|Notice|Parse)|Uncaught', log.read()), 'No PHP warnings or fatal errors')


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

with fixture() as (root, url):
    browser = client()
    csrf = start(browser, url)
    check(request(browser, url + '/index.php', {'action': 'extract', 'csrf': 'bad'})[0] == 419, 'Extraction requires CSRF')
    code, body, _ = request(browser, url + '/index.php', {'action': 'extract', 'csrf': csrf})
    first = json.loads(body)
    check(code == 200 and 0 < first['progress'] < 100 and not (root / 'app').exists(), 'First batch gives real progress and keeps unpublished Core private')
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

print(f'{checks} web installer checks passed.')
