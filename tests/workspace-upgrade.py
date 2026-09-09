"""Private Linux rehearsal: read production, mutate only a disposable clone."""
import hashlib
from http.cookiejar import CookieJar
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

root = Path(__file__).resolve().parents[1]
if root.parent != Path('/root') or not re.fullmatch(r'sensecms-upgrade-test\.[A-Za-z0-9]{8}', root.name) or root.is_symlink():
    raise SystemExit('Use a new private /root/sensecms-upgrade-test.XXXXXXXX fixture.')
os.umask(0o077)
source = root / '.cms/source'
production = Path('/home/sensecms.com/web')
installed = json.loads((production / 'storage/installed.json').read_text())
origin = installed['database']['name']
if origin != 'sensecms_site' or installed['base_url'] != 'https://www.sensecms.com':
    raise SystemExit('Unexpected production installation identity.')
if (source / 'storage').exists() or (production / 'storage/workspace.json').exists():
    raise SystemExit('Rehearsal requires pristine candidate storage and an initial-Core source.')
name = 'senseqa_' + secrets.token_hex(6)
password = secrets.token_urlsafe(32)
owner_password = secrets.token_urlsafe(32)
initial = ['activity_log', 'login_attempts', 'migrations', 'roles', 'settings', 'user_roles', 'users']
checks = 0
created_db = created_user = False
server = log = None


def run(args, **kwargs):
    result = subprocess.run(args, capture_output=True, **kwargs)
    if result.returncode:
        raise RuntimeError('Rehearsal operation failed; command output suppressed to protect private data.')
    return result.stdout


def sql(statement):
    return run(['mariadb', '--batch', '--skip-column-names'], input=statement.encode()).decode().strip()


def check(ok, label):
    global checks
    if not ok:
        raise RuntimeError('FAIL ' + label)
    checks += 1
    print('PASS ' + label, flush=True)


def guard_clone():
    if not re.fullmatch(r'senseqa_[0-9a-f]{12}', name) or name == origin:
        raise RuntimeError('Invalid disposable database target.')


def snapshot(database):
    # Fixed tables; inspect original columns so additive migrations remain comparable.
    return {table: sql(f'SELECT * FROM `{database}`.`{table}` ORDER BY ' + ','.join(f'`{column}`' for column in columns[table])) for table in initial}


http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()))


def request(path, data=None):
    headers = {'Accept': 'application/json'} if data is not None else {}
    req = urllib.request.Request(base + path, headers=headers, data=urllib.parse.urlencode(data).encode() if data is not None else None)
    try:
        with http.open(req, timeout=30) as response:
            return response.status, response.read().decode(errors='replace'), response.headers
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode(errors='replace'), error.headers


def csrf(page):
    match = re.search(r'name="csrf" value="([a-f0-9]{64})"', page)
    if not match:
        raise RuntimeError('Missing CSRF field.')
    return match[1]


try:
    check(sql(f'SHOW TABLES FROM `{origin}`').splitlines() == initial, 'Production is the expected seven-table initial Core')
    columns = {}
    for table in initial:
        check(int(sql(f'SELECT COUNT(*) FROM `{origin}`.`{table}`')) <= 10000, 'Bounded snapshot for ' + table)
        columns[table] = [row.split('\t')[0] for row in sql(f'SHOW COLUMNS FROM `{origin}`.`{table}`').splitlines()]
        if any(not re.fullmatch(r'[a-z_]+', column) for column in columns[table]):
            raise RuntimeError('Unexpected Core column identifier.')
    original_data = snapshot(origin)
    private_paths = ['storage/installed.json', 'storage/theme.json', 'storage/license/key.bin', 'storage/license/license.lic']
    original_private = {path: hashlib.sha256((production / path).read_bytes()).hexdigest() for path in private_paths}
    dump = root / 'initial-core.sql'
    with dump.open('wb') as output:
        result = subprocess.run(['mariadb-dump', '--single-transaction', '--skip-lock-tables', '--hex-blob', '--no-tablespaces', '--skip-comments', origin], stdout=output, stderr=subprocess.PIPE)
        if result.returncode:
            raise RuntimeError('Read-only source dump failed.')
    guard_clone()
    sql(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
    created_db = True
    sql(f"CREATE USER '{name}'@'127.0.0.1' IDENTIFIED BY '{password}'; GRANT ALL ON `{name}`.* TO '{name}'@'127.0.0.1'")
    created_user = True
    run(['mariadb', name], input=dump.read_bytes())
    check(snapshot(name) == original_data, 'Database clone exactly preserves initial Core rows')
    # Copy only public package state/payloads, never sessions, production DB credentials or keys.
    (source / 'storage').mkdir(mode=0o700)
    shutil.copy2(production / 'storage/theme.json', source / 'storage/theme.json')
    shutil.copytree(production / 'storage/themes', source / 'storage/themes', symlinks=True)
    for path in (source / 'storage/themes').rglob('*'):
        if path.is_symlink():
            raise RuntimeError('Unexpected symbolic link in retained theme.')
    clone = dict(installed, database=dict(host='127.0.0.1', port=3306, name=name, user=name, password=password))
    (source / 'storage/installed.json').write_text(json.dumps(clone))
    license_key = None
    for line in (root / '.cfg/License.txt').read_text().splitlines():
        match = re.match(r'^\s*License\s*Key\s*[:=]\s*(\S+)\s*$', line, re.I)
        if match:
            license_key = match[1]
    if not license_key:
        raise RuntimeError('Private test license input missing.')
    license_code = r'''
    require $argv[1].'/bootstrap.php';
    $runtime=new App\Core\Runtime($argv[1]);
    $runtime->license()->install(trim(stream_get_contents(STDIN)), $runtime->baseUrl());
    '''
    run(['php', '-r', license_code, str(source)], input=license_key.encode())
    check((source / 'storage/license/key.bin').read_bytes() != (production / 'storage/license/key.bin').read_bytes(), 'Rehearsal has an independent local license encryption key')
    baseline = root / 'baseline'
    baseline.mkdir(mode=0o700)
    for entry in ['app', 'config', 'database', 'scripts', 'public']:
        shutil.copytree(production / entry, baseline / entry, symlinks=True)
    shutil.copy2(production / 'bootstrap.php', baseline / 'bootstrap.php')
    shutil.copytree(source / 'storage', baseline / 'storage', symlinks=True)
    for path in baseline.rglob('*'):
        if path.is_symlink():
            raise RuntimeError('Unexpected symbolic link in baseline source.')
    theme_bytes = (source / 'storage/theme.json').read_bytes()
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    log = (root / 'http.log').open('w')
    server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(source / 'public'), str(source / 'public/index.php')], env=dict(os.environ, SENSE_LOCAL_HTTP='1'), stdout=log, stderr=log)
    for attempt in range(40):
        try:
            request('/')
            break
        except urllib.error.URLError:
            time.sleep(.15)
    routes = ['/', '/platform', '/extensions', '/download', '/docs', '/docs/installation', '/docs/licensing', '/docs/packages', '/docs/server', '/contact', '/theme-assets/site.css', '/theme-assets/site.js', '/sitemap.xml', '/robots.txt']
    before_http = {}
    for path in routes:
        status, body, headers = request(path)
        check(status == 200, 'Existing signed theme renders before activation: ' + path)
        before_http[path] = (status, body)
    migrate = ['php', str(source / 'scripts/migrate-workspace.php')]
    status = json.loads(run(migrate + ['--status']))
    check(not status['ready'] and status['applied'] == 0, 'Real existing-site preflight reports pending Workspace migrations')
    check(sql(f'SHOW TABLES FROM `{name}`').splitlines() == initial, 'Preflight leaves clone schema unchanged')
    check(subprocess.run(migrate + ['--enable'], capture_output=True).returncode == 1, 'Incomplete clone cannot enable the panel')
    run(migrate)
    check(not (source / 'storage/workspace.json').exists(), 'Schema upgrade does not activate the panel')
    for table in initial:
        fields = ','.join(f'`{column}`' for column in columns[table])
        rows = sql(f'SELECT {fields} FROM `{name}`.`{table}` ORDER BY {fields}').splitlines()
        check(set(original_data[table].splitlines()).issubset(rows), 'Upgrade preserves original ' + table + ' rows')
    check(json.loads(run(migrate + ['--status']))['ready'], 'All clone migrations completed')
    run(migrate + ['--enable'])
    check(json.loads((source / 'storage/workspace.json').read_text())['enabled'] is True, 'Explicit activation opens the upgraded Workspace')
    check((source / 'storage/theme.json').read_bytes() == theme_bytes, 'Activation does not replace the production theme release')
    for path in routes:
        status, body, headers = request(path)
        check((status, body) == before_http[path], 'Upgrade preserves public response exactly: ' + path)
        check(headers.get('Set-Cookie') is None, 'Public response is independent of admin sessions: ' + path)
    # Only after proving password preservation, use a random QA password on the cloned owner.
    fixture_owner = r'''
    require $argv[1].'/bootstrap.php';
    $runtime=new App\Core\Runtime($argv[1]);$cfg=$runtime->read('installed')['database'];
    if(!preg_match('/^senseqa_[a-f0-9]{12}$/D',$cfg['name']))exit(2);
    $db=App\Core\Runtime::connect($cfg);
    $owner=$db->query("SELECT u.id,u.email FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.slug='owner' AND u.active=1 ORDER BY u.id LIMIT 1")->fetch();
    if(!$owner)exit(3);
    $db->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash(trim(stream_get_contents(STDIN)),PASSWORD_ARGON2ID),$owner['id']]);
    $db->exec("INSERT INTO settings (`key`,value) VALUES ('captcha_settings','{\"enabled\":false}') ON DUPLICATE KEY UPDATE value=VALUES(value)");
    echo json_encode($owner,JSON_THROW_ON_ERROR);
    '''
    owner = json.loads(run(['php', '-r', fixture_owner, str(source)], input=owner_password.encode()))
    _, page, _ = request('/login')
    status, body, _ = request('/login', dict(csrf=csrf(page), email=owner['email'], password=owner_password))
    check(status == 200 and json.loads(body).get('redirect') == '/dashboard', 'Migrated owner authenticates using clone-only test credentials')
    status, page, _ = request('/account')
    check(status == 200 and 'action="/settings/password"' in page, 'Old account bookmark opens migrated password settings')
    for path in ['/dashboard', '/settings', '/content/facilities', '/content/pages', '/content/posts', '/content/categories', '/content/navigation', '/content/media', '/system/access', '/system/email', '/appearance/themes', '/system/extensions', '/license']:
        status, page, headers = request(path)
        check(status == 200 and 'class="app-menu"' in page, 'Upgraded owner panel: ' + path)
        check('nonce-' in headers.get('Content-Security-Policy', ''), 'Upgraded panel CSP nonce: ' + path)
    check(request('/install')[0] == 404, 'Upgrade cannot reopen the installer')
    for path in ['/storage/installed.json', '/storage/workspace.json', '/storage/license/key.bin', '/config/workspace.php']:
        check(request(path)[0] == 404, 'Private upgrade state is not downloadable: ' + path)
    check(request('/settings', {'csrf': 'invalid'})[0] == 419, 'Upgraded settings reject invalid CSRF')
    server.terminate(); server.wait(timeout=10); server = None
    # Rehearse restoring the exact initial schema on the clone, never on production.
    guard_clone()
    sql(f'DROP DATABASE `{name}`; CREATE DATABASE `{name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
    run(['mariadb', name], input=dump.read_bytes())
    check(sql(f'SHOW TABLES FROM `{name}`').splitlines() == initial and snapshot(name) == original_data, 'Backup restores exact original schema, passwords and data on the clone')
    http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()))
    server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(baseline / 'public'), str(baseline / 'public/index.php')], env=dict(os.environ, SENSE_LOCAL_HTTP='1'), stdout=log, stderr=log)
    for attempt in range(40):
        try:
            request('/')
            break
        except urllib.error.URLError:
            time.sleep(.15)
    for path in routes:
        status, body, _ = request(path)
        check((status, body) == before_http[path], 'Restored original code serves the unchanged website: ' + path)
    check(not (baseline / 'storage/workspace.json').exists(), 'Restored private state does not enable an incompatible panel')
    check(request('/install')[0] == 404, 'Original Core rollback keeps the installer closed')
    check('name="email"' in request('/login')[1], 'Original Core login remains available after rollback')
    check(snapshot(origin) == original_data, 'Production Core rows remain unchanged throughout rehearsal')
    check(all(hashlib.sha256((production / path).read_bytes()).hexdigest() == digest for path, digest in original_private.items()), 'Production installation, license keys and theme state remain unchanged')
    print(f'{checks} existing-site upgrade checks passed.', flush=True)
finally:
    if server:
        server.terminate(); server.wait(timeout=10)
    if log:
        log.close()
    guard_clone()
    if created_user:
        sql(f"DROP USER '{name}'@'127.0.0.1'")
    if created_db:
        sql(f'DROP DATABASE `{name}`')
