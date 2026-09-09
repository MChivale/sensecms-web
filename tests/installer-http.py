"""Isolated Linux/MySQL end-to-end test. Never target an existing database."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import socket
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

root = Path(__file__).resolve().parents[1]
source = root / '.cms/source'
if not re.fullmatch(r'sensecms-install-test\.[A-Za-z0-9]{8}', root.name):
    raise SystemExit('This destructive test is restricted to an isolated deployment test directory.')
cfg = {}
for line in (root / '.cfg/License.txt').read_text().splitlines():
    match = re.match(r'^\s*License\s*Key\s*[:=]\s*(\S+)\s*$', line, re.I)
    if match:
        cfg['LicenseKey'] = match[1]
key = cfg['LicenseKey']
name = 'senseqa_' + secrets.token_hex(6)
password = secrets.token_urlsafe(24)
owner_password = secrets.token_urlsafe(24)
assert re.fullmatch(r'senseqa_[0-9a-f]{12}', name)

def sql(statement):
    result = subprocess.run(['mariadb', '--batch', '--skip-column-names'], input=statement, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError('Isolated SQL operation failed (details suppressed).')
    return result.stdout.strip()

checks = 0
def check(condition, label):
    global checks
    if not condition:
        raise RuntimeError('FAIL: ' + label)
    checks += 1
    print('PASS ' + label, flush=True)

jar = http.cookiejar.CookieJar()
http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def request(path, data=None, headers=None):
    req = urllib.request.Request(base + path, data=urllib.parse.urlencode(data).encode() if data is not None else None, headers=headers or {})
    try:
        with http.open(req, timeout=40) as response:
            return response.status, response.read().decode(), response.headers
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode(), error.headers

def csrf(page):
    match = re.search(r'name="csrf" value="([a-f0-9]{64})"', page)
    if not match:
        raise RuntimeError('CSRF field missing')
    return match[1]

def post(path, data):
    status, body, headers = request(path, data, {'Accept': 'application/json'})
    return status, json.loads(body)

created_db = False
created_user = False
server = None
log = None
try:
    sql(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
    created_db = True
    sql(f"CREATE USER '{name}'@'127.0.0.1' IDENTIFIED BY '{password}'; GRANT ALL ON `{name}`.* TO '{name}'@'127.0.0.1';")
    created_user = True
    subprocess.run(['php', str(source / 'scripts/prepare.php'), 'https://www.sensecms.com'], check=True, capture_output=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    log = (root / 'php-test.log').open('w')
    server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(source / 'public'), str(source / 'public/index.php')],
                              env=dict(os.environ, SENSE_LOCAL_HTTP='1'), stdout=log, stderr=log)
    for attempt in range(40):
        try:
            status, page, headers = request('/install')
            break
        except urllib.error.URLError:
            time.sleep(.15)
    check(status == 200 and 'Activate your license' in page and 'name="license_key"' in page, 'first screen is license entry')
    check('name="db_host"' not in page and 'name="admin_email"' not in page, 'database and owner hidden before licensing')
    check('no-store' in headers['Cache-Control'] and "frame-ancestors 'none'" in headers['Content-Security-Policy'], 'installer security headers')
    token = csrf(page)
    check(post('/install/license', {'license_key': key})[0] == 419, 'CSRF required before licensing')
    check(post('/install/complete', {'csrf': token})[0] == 409, 'installation cannot bypass license step')
    check(post('/install/license', {'csrf': token, 'license_key': 'invalid'})[0] == 422, 'invalid license rejected')
    status, result = post('/install/license', {'csrf': token, 'license_key': key})
    check(status == 200 and result.get('redirect') == '/install', 'real Chivale license accepted')
    status, page, _ = request('/install')
    check(status == 200 and 'Check your server' in page and key not in page, 'requirements follow license with no key echo')
    token = csrf(page)
    check(post('/install/requirements', {'csrf': token})[0] == 200, 'server requirements pass')
    _, page, _ = request('/install'); token = csrf(page)
    details = dict(csrf=token, db_host='127.0.0.1', db_port='3306', db_name=name, db_user=name, db_password=password,
                   admin_name='Isolated QA Owner', admin_email='owner@example.test', admin_password=owner_password,
                   admin_confirm=owner_password, site_name='Isolated Sense CMS QA')
    sql(f'CREATE TABLE `{name}`.existing_data (id INT);')
    check(post('/install/complete', details)[0] == 422, 'nonempty database rejected without data loss')
    check(sql(f'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema="{name}" AND table_name="existing_data"') == '1', 'existing table preserved')
    sql(f'DROP TABLE `{name}`.existing_data;')
    sql(f"REVOKE INSERT ON `{name}`.* FROM '{name}'@'127.0.0.1';")
    check(post('/install/complete', details)[0] == 503, 'failed seed does not mark installation complete')
    check(not (source / 'storage/installed.json').exists() and (source / 'storage/installing.json').exists(), 'interrupted installation retains private recovery state')
    check(sql(f'SELECT COUNT(*) FROM `{name}`.users') == '0', 'failed seed leaves no partial owner')
    sql(f"GRANT INSERT ON `{name}`.* TO '{name}'@'127.0.0.1';")
    check(post('/install/complete', dict(details, admin_email='different@example.test'))[0] == 422, 'resume rejects a different owner')
    migration_file = source / 'database/workspace/027_languages.sql'
    migration_bytes = migration_file.read_bytes()
    try:
        migration_file.write_bytes(migration_bytes + b'\n-- changed after interruption\n')
        check(post('/install/complete', details)[0] == 422, 'resume rejects changed Workspace migration files')
    finally:
        migration_file.write_bytes(migration_bytes)
    sql(f"REVOKE ALTER ON `{name}`.* FROM '{name}'@'127.0.0.1';")
    for table in ['users', 'settings', 'activity_log']:
        sql(f"GRANT ALTER ON `{name}`.`{table}` TO '{name}'@'127.0.0.1';")
    check(post('/install/complete', details)[0] == 503, 'interrupted Workspace DDL does not complete installation')
    check(sql(f'SELECT COUNT(*) FROM `{name}`.workspace_migrations') != '0', 'Workspace failure occurs after real partial migration progress')
    owner_before = sql(f'SELECT id,password FROM `{name}`.users')
    check(not (source / 'storage/installed.json').exists() and not (source / 'storage/workspace.json').exists(), 'incomplete schema cannot enable the panel')
    check(post('/install/complete', dict(details, admin_password='incorrect-resume-password', admin_confirm='incorrect-resume-password'))[0] == 422, 'resume cannot replace an existing owner password')
    sql(f'CREATE TABLE `{name}`.unexpected (id INT);')
    check(post('/install/complete', details)[0] == 422, 'Workspace resume rejects an unrelated table')
    sql(f'DROP TABLE `{name}`.unexpected;')
    sql(f"GRANT ALTER ON `{name}`.* TO '{name}'@'127.0.0.1';")
    workspace_file = source / 'storage/workspace.json'
    workspace_file.write_text(json.dumps({'secret': 'invalid-identity'}))
    check(post('/install/complete', details)[0] == 422, 'invalid private Workspace identity prevents final completion')
    check(not (source / 'storage/installed.json').exists(), 'finished schema alone is not a completed installation')
    workspace_secret = secrets.token_hex(32)
    workspace_file.write_text(json.dumps({'enabled': False, 'secret': workspace_secret}))
    status, result = post('/install/complete', details)
    check(status == 200 and result.get('redirect') == '/login', 'complete browser-driven installation')
    check(sql(f'SELECT id,password FROM `{name}`.users') == owner_before, 'resume preserves owner identity and password hash')
    workspace = json.loads((source / 'storage/workspace.json').read_text())
    check(workspace.get('enabled') is True and re.fullmatch('[a-f0-9]{64}', workspace.get('secret', '')), 'fresh installation provisions a private Workspace identity')
    check(workspace['secret'] == workspace_secret, 'finalization preserves an existing valid private identity')
    migrate = ['php', str(source / 'scripts/migrate-workspace.php')]
    cli_status = subprocess.run(migrate + ['--status'], capture_output=True, text=True)
    check(cli_status.returncode == 0 and json.loads(cli_status.stdout)['ready'], 'licensed CLI reports completed schema readiness')
    check(subprocess.run(migrate + ['--unknown'], capture_output=True).returncode == 1, 'CLI rejects unknown flags')
    workspace['enabled'] = False
    workspace_file.write_text(json.dumps(workspace))
    state_before = workspace_file.read_bytes()
    check(subprocess.run(migrate + ['--status'], capture_output=True).returncode == 0 and workspace_file.read_bytes() == state_before, 'CLI status does not enable the panel or change its identity')
    check(subprocess.run(migrate, capture_output=True).returncode == 0 and workspace_file.read_bytes() == state_before, 'default CLI migration leaves the panel disabled')
    license_file = source / 'storage/license/license.lic'
    license_saved = license_file.with_suffix('.qa-held')
    license_file.rename(license_saved)
    try:
        check(subprocess.run(migrate + ['--enable'], capture_output=True).returncode == 1 and workspace_file.read_bytes() == state_before, 'CLI activation requires the installed license and leaves disabled state unchanged')
    finally:
        license_saved.rename(license_file)
    check(subprocess.run(migrate + ['--enable'], capture_output=True).returncode == 0, 'explicit licensed CLI activation succeeds')
    enabled = json.loads(workspace_file.read_text())
    check(enabled['enabled'] is True and enabled['secret'] == workspace_secret, 'CLI activation preserves existing encryption identity')
    enabled_bytes = workspace_file.read_bytes()
    check(subprocess.run(migrate + ['--enable'], capture_output=True).returncode == 0 and workspace_file.read_bytes() == enabled_bytes, 'CLI activation is repeat-safe')
    check(sql(f'SELECT COUNT(*) FROM `{name}`.workspace_migrations') == str(len(list((source / 'database/workspace').glob('*.sql')))), 'all Workspace migrations finish before installation completes')
    check(not (source / 'storage/installing.json').exists(), 'successful completion removes recovery marker')
    check(sql(f'SELECT COUNT(*) FROM `{name}`.facilities') == '0' and sql(f'SELECT COUNT(*) FROM `{name}`.extension_packages') == '0', 'no demo facilities or optional packages are seeded')
    check(request('/install')[0] == 404, 'installer closed after completion')
    check(request('/storage/installed.json')[0] != 200, 'private configuration not served')
    check('captcha' in request('/login')[1], 'fresh login retains CAPTCHA protection')
    # Disable CAPTCHA only in this uniquely named disposable test database; never solve it.
    sql(f'''INSERT INTO `{name}`.settings (`key`,value) VALUES ('captcha_settings','{{"enabled":false}}') ON DUPLICATE KEY UPDATE value=VALUES(value);''')
    _, page, _ = request('/login'); token = csrf(page)
    check(post('/login', {'csrf': token, 'email': details['admin_email'], 'password': 'wrong'})[0] == 422, 'wrong password rejected')
    status, result = post('/login', {'csrf': token, 'email': details['admin_email'], 'password': owner_password})
    check(status == 200 and result.get('redirect') == '/dashboard', 'owner login succeeds')
    status, page, _ = request('/dashboard')
    check(status == 200 and 'class="app-menu"' in page, 'fresh installation opens the full Sense CMS Workspace')
    for path in ['/content/facilities', '/content/pages', '/content/media', '/system/access', '/profile', '/settings', '/marketplace', '/system/extensions', '/appearance/themes']:
        screen_status, screen, _ = request(path)
        check(screen_status == 200 and 'class="app-menu"' in screen, 'fresh empty Workspace screen: ' + path)
    token = csrf(page)
    check(post('/settings', {'csrf': 'bad', 'site_name': 'Changed'})[0] == 419, 'settings protected by CSRF')
    check(post('/settings', {'csrf': token, 'first_name': 'Updated QA owner'})[0] == 200, 'AJAX settings save')
    check('Updated QA owner' in request('/settings')[1], 'settings persisted')
    _, account_page, _ = request('/settings')
    check('Current password' in account_page, 'account password form available')
    new_password = secrets.token_urlsafe(24)
    change = dict(csrf=token, current_password=owner_password, new_password=new_password, confirm_password=new_password)
    check(post('/settings/password', dict(change, csrf='bad'))[0] == 419, 'password change requires CSRF')
    check(post('/settings/password', dict(change, current_password='wrong'))[0] == 422, 'password change requires current password')
    time.sleep(3.1)
    old_password = owner_password
    check(post('/settings/password', change)[0] == 200, 'password change succeeds')
    owner_password = new_password
    check(sql(f'SELECT session_version FROM `{name}`.users LIMIT 1') == '2', 'password change revokes old session versions')
    check(post('/logout', {'csrf': token})[0] == 200, 'logout succeeds')
    check('name="email"' in request('/dashboard')[1], 'dashboard requires authentication after logout')
    _, page, _ = request('/login')
    check(post('/login', dict(csrf=csrf(page), email=details['admin_email'], password=old_password))[0] == 422, 'previous password no longer works')
    check(post('/login', dict(csrf=csrf(page), email=details['admin_email'], password=owner_password))[0] == 200, 'new password works')
    _, page, _ = request('/settings')
    check(post('/logout', {'csrf': csrf(page)})[0] == 200, 'logout after changed password')
    stored = (source / 'storage/installed.json').read_text()
    check(key not in stored and owner_password not in stored, 'configuration excludes license and owner passwords')
    check(sql(f"SELECT COUNT(*) FROM `{name}`.user_roles ur JOIN `{name}`.roles r ON r.id=ur.role_id WHERE r.slug='owner'") == '1', 'exactly one owner role assigned')
    if (root / '.themes/sensecms').is_dir():
        build_theme = r'''
        require $argv[1] . '/.cms/source/bootstrap.php';
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        App\Core\Packages\Archive::build($argv[1] . '/.themes/sensecms', $argv[1] . '/theme.zip', $secret);
        file_put_contents($argv[1] . '/trust.json', json_encode(['sensecms-release' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))]));
        sodium_memzero($secret);
        '''
        subprocess.run(['php', '-r', build_theme, str(root)], check=True, capture_output=True)
        subprocess.run(['php', str(source / 'scripts/theme.php'), 'install', str(root / 'theme.zip'), str(root / 'trust.json')], check=True, capture_output=True)
        status, page, headers = request('/')
        check(status == 200 and 'Make room' in page, 'installed Core serves activated product theme')
        check(headers.get('Set-Cookie') is None, 'public theme does not open admin sessions')
        check(request('/docs/installation')[0] == 200, 'installed Core serves public documentation')
        check(request('/theme-assets/site.css')[0] == 200, 'installed theme assets served through Core')
        check(request('/theme-assets/../pages.php')[0] == 404, 'theme PHP cannot be downloaded')
        check(request('/unknown-public-page')[0] == 404, 'public unknown route returns 404 instead of login')
        check('name="email"' in request('/dashboard')[1], 'theme does not bypass admin authentication')
        check(request('/install')[0] == 404, 'theme activation does not reopen installer')
    for number in range(11):
        _, page, _ = request('/login')
        post('/login', {'csrf': csrf(page), 'email': details['admin_email'], 'password': 'wrong'})
    _, page, _ = request('/login')
    check(post('/login', {'csrf': csrf(page), 'email': details['admin_email'], 'password': owner_password})[0] == 422, 'server-side login rate limit')
    print(f'{checks} HTTP integration checks passed.', flush=True)
finally:
    if server:
        server.terminate(); server.wait(timeout=10)
    if log:
        log.close()
    if created_user:
        sql(f"DROP USER '{name}'@'127.0.0.1';")
    if created_db:
        sql(f'DROP DATABASE `{name}`;')
