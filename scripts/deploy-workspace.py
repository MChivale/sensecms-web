"""One-time initial Core -> Workspace production cutover on the verified Sense host."""
import fcntl
import hashlib
from http.cookiejar import CookieJar
import json
import os
from pathlib import Path, PurePosixPath
import pwd
import grp
import re
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile

web = Path('/home/sensecms.com/web')
base = 'https://www.sensecms.com'
if os.geteuid() != 0 or len(sys.argv) != 3 or web.resolve() != web or not re.fullmatch(r'[a-f0-9]{64}', sys.argv[2]):
    raise SystemExit('Run as root on the Sense host: deploy-workspace.py <tested.zip> <verified-sha256>')
archive = Path(sys.argv[1]).resolve()
if hashlib.sha256(archive.read_bytes()).hexdigest() != sys.argv[2]:
    raise SystemExit('Candidate archive digest mismatch.')
os.umask(0o077)
lock = open('/root/sensecms-private/deploy-workspace.lock', 'a')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
installed = json.loads((web / 'storage/installed.json').read_text())
if installed['database']['name'] != 'sensecms_site' or installed['base_url'] != base or (web / 'storage/workspace.json').exists():
    raise SystemExit('Expected the installed initial Sense Core; do not rerun this cutover.')
stamp = time.strftime('%Y%m%dT%H%M%SZ', time.gmtime())
backup = Path('/root/sensecms-backups') / (stamp + '-workspace')
backup.mkdir(mode=0o700)
stage = backup / 'candidate'
stage.mkdir(mode=0o700)
jar = CookieJar()
http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
maintenance = published = False
front = web / 'public/index.php'
original_front = front.read_bytes()
hidden_front = web / 'public/.sense-deploy-index.php'
if hidden_front.exists():
    raise SystemExit('An earlier candidate front controller requires inspection.')


def run(args, **kwargs):
    result = subprocess.run(args, capture_output=True, **kwargs)
    if result.returncode:
        raise RuntimeError('Operation failed: ' + str(args[0]) + ' (private command output suppressed)')
    return result.stdout


def check(ok, label):
    if not ok:
        raise RuntimeError(label)
    print('PASS ' + label, flush=True)


def atomic(path, content):
    temp = path.with_name(path.name + '.deploy-temp')
    if temp.exists() or temp.is_symlink() or path.is_symlink():
        raise RuntimeError('Unsafe deployment file target.')
    temp.write_bytes(content)
    temp.chmod(0o644)
    os.replace(temp, path)


def request(path, data=None):
    req = urllib.request.Request(base + path, data=urllib.parse.urlencode(data).encode() if data is not None else None, headers={'Accept': 'application/json'} if data is not None else {})
    try:
        with http.open(req, timeout=35) as response:
            return response.status, response.read(), response.headers
    except urllib.error.HTTPError as error:
        return error.code, error.read(), error.headers


def wait_status(path, expected):
    for _ in range(20):
        if request(path)[0] == expected:
            return
        time.sleep(1)
    raise RuntimeError('Expected HTTP state did not become available.')


def sql(statement):
    return run(['mariadb', '--batch', '--skip-column-names'], input=statement.encode())


def fpm(path):
    env = dict(os.environ, SCRIPT_FILENAME=str(hidden_front), SCRIPT_NAME='/index.php', REQUEST_URI=path, REQUEST_METHOD='GET', QUERY_STRING='', SERVER_PROTOCOL='HTTP/1.1', GATEWAY_INTERFACE='CGI/1.1', SERVER_NAME='www.sensecms.com', HTTP_HOST='www.sensecms.com', SERVER_PORT='443', HTTPS='on', REMOTE_ADDR='127.0.0.1', HTTP_COOKIE='; '.join(cookie.name + '=' + cookie.value for cookie in jar))
    raw = run(['cgi-fcgi', '-bind', '-connect', '/run/php/php8.5-sensecms.sock'], env=env)
    headers, body = raw.split(b'\r\n\r\n', 1)
    match = re.search(rb'(?im)^Status: (\d+)', headers)
    return int(match[1]) if match else 200, body, headers


try:
    initial = b'activity_log\nlogin_attempts\nmigrations\nroles\nsettings\nuser_roles\nusers'
    check(sql('SHOW TABLES FROM sensecms_site').strip() == initial, 'Verified initial production schema')
    run(['nginx', '-t']); run(['php-fpm8.5', '-t'])
    with zipfile.ZipFile(archive) as bundle:
        inventory = json.loads(bundle.read('installer-manifest.json'))
        files = inventory['files']
        check(inventory['product'] == 'Sense CMS' and inventory['channel'] == 'development', 'Verified development candidate identity')
        check(set(bundle.namelist()) == set(files) | {'installer-manifest.json'} and len(bundle.namelist()) == len(files) + 1, 'Exact archive inventory')
        for name, digest in files.items():
            path = PurePosixPath(name)
            check_path = not path.is_absolute() and '..' not in path.parts and '\\' not in name and path.parts[0] in ['app', 'config', 'database', 'lang', 'public', 'scripts', 'bootstrap.php', 'README.md', '.htaccess']
            if not check_path or (bundle.getinfo(name).external_attr >> 16) & 0o170000 != 0o100000:
                raise RuntimeError('Invalid candidate archive path or file type.')
            data = bundle.read(name)
            if hashlib.sha256(data).hexdigest() != digest:
                raise RuntimeError('Invalid candidate file digest.')
            target = stage / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
    for file in stage.rglob('*.php'):
        run(['php', '-l', str(file)])
    check(True, 'All candidate PHP files pass server lint')
    routes = ['/', '/platform', '/extensions', '/download', '/docs', '/docs/installation', '/docs/licensing', '/docs/packages', '/docs/server', '/contact', '/theme-assets/site.css', '/theme-assets/site.js', '/sitemap.xml', '/robots.txt']
    before = {}
    for path in routes:
        status, body, _ = request(path)
        check(status == 200, 'Pre-cutover public route ' + path)
        before[path] = body
    owner = json.loads(Path('/root/sensecms-private/owner.json').read_text())
    _, page, _ = request('/login')
    token = re.search(rb'name="csrf" value="([a-f0-9]{64})"', page)[1].decode()
    status, body, _ = request('/login', dict(csrf=token, email=owner['email'], password=owner['password']))
    check(status == 200 and json.loads(body).get('redirect') == '/dashboard', 'Real owner authenticates before cutover')
    del owner
    private_paths = ['installed.json', 'theme.json', 'license/key.bin', 'license/license.lic']
    private_hashes = {p: hashlib.sha256((web / 'storage' / p).read_bytes()).hexdigest() for p in private_paths}
    users_before = sql('SELECT id,email,password,active,session_version FROM sensecms_site.users ORDER BY id')
    roles_before = sql('SELECT * FROM sensecms_site.user_roles ORDER BY user_id,role_id')
    run(['tar', '-czf', str(backup / 'web-before.tgz'), '-C', str(web.parent), 'web'])
    check(run(['tar', '-tzf', str(backup / 'web-before.tgz')]).find(b'web/storage/installed.json') >= 0, 'Full code and private storage backup verified')
    (backup / 'original-index.php').write_bytes(original_front)
    (backup / 'candidate.sha256').write_text(sys.argv[2] + '\n')
    maintenance = True
    atomic(front, b'<?php http_response_code(503); header("Retry-After: 60"); header("Cache-Control: no-store"); echo "Sense CMS maintenance. Please retry shortly.";')
    wait_status('/login', 503)
    time.sleep(3)
    started = time.monotonic()
    with (backup / 'database-before.sql').open('wb') as output:
        result = subprocess.run(['mariadb-dump', '--single-transaction', '--skip-lock-tables', '--hex-blob', '--no-tablespaces', 'sensecms_site'], stdout=output, stderr=subprocess.PIPE)
        if result.returncode:
            raise RuntimeError('Production database backup failed.')
    check((backup / 'database-before.sql').stat().st_size > 1000, 'Fresh maintenance-window database backup retained')
    # Overlay only inventoried source, preserving storage, uploads and existing releases.
    for name in files:
        if name == 'public/index.php':
            continue
        target = web / name
        for parent in reversed(target.parents):
            if parent == web or web in parent.parents:
                if parent.is_symlink():
                    raise RuntimeError('Symlink encountered in deployment target.')
                if not parent.exists():
                    parent.mkdir(mode=0o755); parent.chmod(0o755)
        atomic(target, (stage / name).read_bytes())
    uid, gid, nginx_gid = pwd.getpwnam('sensecms').pw_uid, grp.getgrnam('sensecms').gr_gid, grp.getgrnam('www-data').gr_gid
    for relative in ['themes', 'plugins', 'addons', 'public/media', 'public/sensecms/audio/custom']:
        directory = web / relative
        if directory.is_symlink():
            raise RuntimeError('Unsafe writable runtime directory.')
        directory.mkdir(mode=0o750, parents=True, exist_ok=True)
        os.chown(directory, uid, nginx_gid if relative.startswith('public/') else gid)
        directory.chmod(0o2750 if relative.startswith('public/') else 0o750)
    migrate = ['runuser', '-u', 'sensecms', '--', 'php', str(web / 'scripts/migrate-workspace.php')]
    check(not json.loads(run(migrate + ['--status']))['ready'], 'Runtime user preflight detects pending migrations')
    run(migrate)
    check(json.loads(run(migrate + ['--status']))['ready'], 'Runtime user completed every Workspace migration')
    run(migrate + ['--enable'])
    check(users_before == sql('SELECT id,email,password,active,session_version FROM sensecms_site.users ORDER BY id') and roles_before == sql('SELECT * FROM sensecms_site.user_roles ORDER BY user_id,role_id'), 'Existing accounts, hashes and role assignments preserved')
    check(all(hashlib.sha256((web / 'storage' / p).read_bytes()).hexdigest() == h for p, h in private_hashes.items()), 'Production configuration, license keys and public theme preserved')
    atomic(hidden_front, (stage / 'public/index.php').read_bytes())
    for path in routes:
        status, body, _ = fpm(path)
        check(status == 200 and body == before[path], 'Real FPM preserves public response ' + path)
    panels = ['/dashboard', '/settings', '/content/facilities', '/content/pages', '/content/posts', '/content/categories', '/content/navigation', '/content/media', '/system/access', '/system/email', '/appearance/themes', '/system/extensions', '/license']
    for path in panels:
        status, body, headers = fpm(path)
        check(status == 200 and b'class="app-menu"' in body and b'nonce-' in headers, 'Real FPM owner panel ' + path)
    atomic(front, (stage / 'public/index.php').read_bytes())
    wait_status('/', 200)
    for path in routes:
        status, body, headers = request(path)
        check(status == 200 and body == before[path] and headers.get('Set-Cookie') is None, 'Live HTTPS public route ' + path)
    for path in panels:
        status, body, headers = request(path)
        check(status == 200 and b'class="app-menu"' in body and 'nonce-' in headers.get('Content-Security-Policy', ''), 'Live HTTPS owner panel ' + path)
    for path in ['/install', '/storage/installed.json', '/storage/workspace.json', '/config/workspace.php', '/public/.sense-deploy-index.php', '/.sense-deploy-index.php']:
        check(request(path)[0] == 404, 'Live private/installer route blocked ' + path)
    check(all(hashlib.sha256((web / name).read_bytes()).hexdigest() == digest for name, digest in files.items()), 'Every deployed source file matches the tested archive')
    error_log = web / 'storage/php-error.log'
    check(not error_log.exists() or error_log.stat().st_size == 0, 'No production PHP errors during cutover')
    published = True
    maintenance = False
    print('Workspace production cutover verified. Backup: ' + str(backup), flush=True)
    print('Measured cutover verification: ' + str(round(time.monotonic() - started, 1)) + ' seconds.', flush=True)
except Exception:
    if maintenance:
        # Restore old code, retain additive DB changes and all private data for recovery.
        restored = backup / 'restore-code'
        restored.mkdir(mode=0o700)
        run(['tar', '-xzf', str(backup / 'web-before.tgz'), '--exclude=web/storage', '-C', str(restored)])
        run(['rsync', '-a', '--exclude=storage', '--exclude=public/index.php', str(restored / 'web') + '/', str(web) + '/'])
        if (web / 'storage/workspace.json').exists():
            shutil.copy2(web / 'storage/workspace.json', backup / 'failed-workspace.json')
        atomic(front, original_front)
        wait_status('/', 200)
        print('Previous source restored. Additive database state and private keys retained; inspect backup before retrying.', flush=True)
    raise
finally:
    if hidden_front.exists():
        hidden_front.unlink()
