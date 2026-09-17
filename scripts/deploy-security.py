"""Checksum-guarded Sense patches; run only on the production host after isolated QA."""
import datetime
import fcntl
import hashlib
from http.cookiejar import CookieJar
import json
import os
from pathlib import Path
import pwd
import re
import subprocess
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request

web = Path('/home/sensecms.com/web')
base = 'https://www.sensecms.com'
before = {
    'app/Core/AccessControl.php': '90dbbf2d8e72a1b64a5b0996b1cd5727a97e990b344ec6d0ef3e7f62f1e85c1e',
    'app/Core/HtmlSanitizer.php': '3a56eb4b6c276669e0d5b77f2763367a05324029c9277f3286ac5e8a8ce4acca',
}
after = {
    'app/Core/AccessControl.php': '6be51208efd68c72c899445cc1d5fb583f5695be59fd50bff3a41203e20c4ce9',
    'app/Core/HtmlSanitizer.php': '9f607aa85ca59da9d7f75500d82b05d085bc7104cc6fad0b3dc3cf3accc4ac8c',
}
maintenance = '--maintenance' in sys.argv
if maintenance:
    sys.argv.remove('--maintenance')
    before = {
        'app/Core/PackageManager.php': '8e6df8770a69f3d80b99bc6e44593619c65693bb075aeb7132ec65c01ed0ade7',
        'app/Core/SystemUpdate.php': 'b85869d13c473e4cd1a1d532e23b9c9f40cc54c5516e1504ee8d100c61808ec4',
        'app/Core/WorkflowRepository.php': 'e3d15b7fe6e74e3191d14ca410fcc90feb844c08315640d225dccbb2fc72f56c',
        'app/Http/DashboardController.php': '0ac2411c6522662e64528ac63fae61df071bfb8d5174441fe6e2cb6af1233cd5',
        'app/Http/SystemUpdateController.php': '1ebd1ede2ad3e5cc3e5f62b8c4ea8a5dd774d90b81fb5ea408d7b4f0364ad5c6',
        'app/Views/console-system-update.php': '686a9f9c6ecce2b75bcf23d24a99dbe4f3256ebbf385eb876add26bb31264722',
        'public/theme/sensecms-system-update.js': '84a556a7180f2a4880911780ccb51f1a84f4e573562b1f79ca4865d125dcc80f',
        'app/Views/console.php': 'c3f41a355fced148c1f8196e7fd22ca356f308f5b1eef28a42bba942252fa75b',
    }
    after = {
        'app/Core/PackageManager.php': 'e6d9514a4ea8b822b987063364483280b431d88cf7a23d5ad52036d90f40b58d',
        'app/Core/SystemUpdate.php': '3937a241f77ad677335ff2830a54e606eaa059285e4111a8a1d11f31f04f5b43',
        'app/Core/WorkflowRepository.php': 'd95ea5a43300bd85a113451d532e9baca8ab8135de60d70ca8d58e02fb6219db',
        'app/Http/DashboardController.php': '533cfc5fd494211680db5248e9cb5ca416a4c4758041ecb83dd48b3ef78d70bc',
        'app/Http/SystemUpdateController.php': 'e63efc0a75c7e3700f2370a8c1679cd0b2abf692e011c97c5d77084ce6f4091c',
        'app/Views/console-system-update.php': '8f5ff3c0a40a729970cbda325979a8a939fef2310c50366dfe2b3d3040ace55d',
        'public/theme/sensecms-system-update.js': '09bc4185212a6fcae7df9b0e74c6be07e2df26859f345becd6e5e8536a7d5ea6',
        'app/Views/console.php': '854a56385080877fcf3537fa0b959f1f0e7ecdb91351e1cbb1ac78c7b0bcc6c4',
    }
batch = 'maintenance' if maintenance else 'security'
if len(sys.argv) != 3 or sys.argv[1] not in ['--check', '--deploy'] or os.geteuid() != 0:
    raise SystemExit('Usage on Sense host: deploy-security.py [--maintenance] --check|--deploy /root/sense-<batch>-XXXXXXXX')
qa = Path(sys.argv[2])
if not re.fullmatch(r'/root/sense-' + batch + r'-[A-Za-z0-9]{8}', str(qa)) or qa.resolve() != qa or web.resolve() != web:
    raise SystemExit('Unexpected QA or production target')
os.umask(0o077)
lock = open('/root/sensecms-private/deploy-workspace.lock', 'a')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
jar = CookieJar()
http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def check(ok, label):
    if not ok:
        raise RuntimeError(label)
    print('PASS ' + label, flush=True)


def run(args, **kwargs):
    result = subprocess.run(args, capture_output=True, **kwargs)
    if result.returncode:
        raise RuntimeError('Command failed; private output withheld: ' + Path(args[0]).name)
    return result.stdout


def request(path, data=None):
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    headers = {'Accept': 'application/json'} if body is not None else {}
    try:
        response = http.open(urllib.request.Request(base + path, data=body, headers=headers), timeout=25)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.read(), response.headers


def health():
    for route in ['/', '/extensions', '/docs', '/contact', '/login', '/forgot-password', '/packages/download']:
        status, body, headers = request(route)
        check(status == 200 and body and headers.get('Content-Security-Policy'), 'HTTP and CSP ' + route)


def owner_ui():
    status, page, _ = request('/login')
    check(status == 200, 'Owner login screen')
    token = re.search(rb'name="csrf" value="([a-f0-9]{64})"', page)[1].decode()
    sid = next(cookie.value for cookie in jar if cookie.name == 'sensecms_session')
    check(re.fullmatch(r'[A-Za-z0-9,-]{16,128}', sid) is not None, 'Bound smoke-test session')
    # Read only this operator-owned session's challenge; do not disable CAPTCHA.
    challenge = run(['runuser', '-u', 'sensecms', '--', 'php8.5', '-r',
        'session_save_path($argv[1]);session_id($argv[2]);session_start(["read_and_close"=>true]);echo $_SESSION["sensecms_captcha_login_code"]??"";',
        str(web / 'storage/sessions'), sid]).decode()
    owner = json.loads(Path('/root/sensecms-private/owner.json').read_text())
    status, body, _ = request('/login', dict(csrf=token, email=owner['email'], password=owner['password'], captcha=challenge))
    del owner, challenge
    check(status == 200 and json.loads(body).get('ok') is True, 'Real owner credentials authenticate')
    try:
        for route in ['/dashboard', '/system/access', '/system/access?tab=roles', '/content/pages', '/appearance/themes']:
            status, body, _ = request(route)
            check(status == 200 and b'class="app-menu"' in body, 'Authenticated UI ' + route)
        token = re.search(rb'name="csrf" value="([a-f0-9]{64})"', body)[1].decode()
        status, _, _ = request('/system/access/users', dict(csrf='invalid'))
        check(status == 419, 'Access mutation still requires CSRF')
        if maintenance:
            status, body, _ = request('/system/update')
            check(status == 200 and b'Automatic Core updates are unavailable' in body
                and b'sensecms-system-update.js?v=20260909-maintenance-1' in body
                and body.count(b'disabled aria-describedby="sensecms-update-reason"') == 2,
                'Authenticated update page accurately disables unsupported actions')
            token = re.search(rb'data-csrf="([a-f0-9]{64})"', body)[1].decode()
            status, body, _ = request('/system/update?status=1')
            state = json.loads(body)['data']
            check(status == 200 and state['version'] == '0.1.0' and state['supported'] is False
                and state['available'] is False and state['verified'] is False and state['checked_at'] is None,
                'Live Core status is truthful and uses canonical build')
            for action in ['check', 'install']:
                status, body, _ = request('/system/update', dict(csrf=token, action=action, version='9.9.9'))
                check(status == 503 and json.loads(body)['ok'] is False, 'Unsupported update action refused: ' + action)
            status, body, _ = request('/theme/sensecms-system-update.js')
            check(status == 200 and hashlib.sha256(body).hexdigest() == after['public/theme/sensecms-system-update.js'],
                'Actual public update JavaScript matches candidate')
    finally:
        request('/logout', dict(csrf=token))


def atomic(path, data, metadata):
    check(not path.is_symlink(), 'Regular target ' + path.name)
    descriptor, name = tempfile.mkstemp(prefix='.security-', dir=path.parent)
    temp = Path(name)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(data); stream.flush(); os.fsync(stream.fileno())
        os.chown(temp, metadata.st_uid, metadata.st_gid)
        os.chmod(temp, metadata.st_mode & 0o777)
        os.replace(temp, path)
    finally:
        temp.unlink(missing_ok=True)


def fpm_probe(patched):
    # Private one-shot FastCGI probe; no public route and no database writes.
    descriptor, name = tempfile.mkstemp(prefix='security-probe-', suffix='.php', dir=web / 'storage')
    probe = Path(name)
    account = pwd.getpwnam('sensecms')
    try:
        code = '''<?php
        $root=dirname(__DIR__);
        foreach(['app/Core/AccessControl.php','app/Core/HtmlSanitizer.php'] as $file) {
            if(function_exists('opcache_invalidate') && ini_get('opcache.enable') && !opcache_invalidate($root.'/'.$file,true)) { http_response_code(500); exit('cache'); }
        }
        require $root.'/bootstrap.php';
        $result=App\\Core\\HtmlSanitizer::sanitize('<unknown><img src="/missing.png" onerror="alert(1)"></unknown>');
        header('Content-Type: application/json');
        echo json_encode(['sanitizer'=>!str_contains($result,'onerror'),'access'=>method_exists(App\\Core\\AccessControl::class,'assertRoleScope')]);
        '''
        expected = {'sanitizer': patched, 'access': patched}
        if maintenance:
            paths = json.dumps([p for p in before if p.endswith('.php')])
            code = '''<?php
            $root=dirname(__DIR__);
            foreach(json_decode('%s',true) as $file) {
                if(function_exists('opcache_invalidate') && ini_get('opcache.enable') && !opcache_invalidate($root.'/'.$file,true)) { http_response_code(500); exit('cache'); }
            }
            require $root.'/bootstrap.php';
            header('Content-Type: application/json');
            echo json_encode(['guard'=>!App\\Core\\SystemUpdate::allowed('app/Core/Test.php'),
                'sanitizer'=>!str_contains(App\\Core\\HtmlSanitizer::sanitize('<unknown><img src="/x" onerror="alert(1)"></unknown>'),'onerror'),
                'access'=>method_exists(App\\Core\\AccessControl::class,'assertRoleScope')]);
            ''' % paths
            expected = {'guard': patched, 'sanitizer': True, 'access': True}
        with os.fdopen(descriptor, 'w') as stream:
            stream.write(code)
        os.chown(probe, account.pw_uid, account.pw_gid)
        env = dict(os.environ, SCRIPT_FILENAME=str(probe), SCRIPT_NAME='/index.php', REQUEST_METHOD='GET',
            REQUEST_URI='/index.php', SERVER_PROTOCOL='HTTP/1.1', GATEWAY_INTERFACE='CGI/1.1',
            SERVER_NAME='www.sensecms.com', HTTP_HOST='www.sensecms.com', SERVER_PORT='443', HTTPS='on', REMOTE_ADDR='127.0.0.1')
        raw = run(['cgi-fcgi', '-bind', '-connect', '/run/php/php8.5-sensecms.sock'], env=env)
        headers, body = raw.split(b'\r\n\r\n', 1)
        check(b'Status: 500' not in headers and json.loads(body) == expected, 'Actual FPM code and shared opcode cache verified')
    finally:
        probe.unlink(missing_ok=True)


installed = json.loads((web / 'storage/installed.json').read_text())
check(installed['base_url'] == base and installed['database']['name'] == 'sensecms_site', 'Exact installation identity')
del installed
original, candidate, metadata = {}, {}, {}
for name in before:
    for path in [web / name, qa / '.cms/source' / name]:
        check(path.resolve() == path and path.is_file(), 'Verified source path ' + path.name)
    original[name] = (web / name).read_bytes()
    candidate[name] = (qa / '.cms/source' / name).read_bytes()
    metadata[name] = (web / name).stat()
    check(hashlib.sha256(original[name]).hexdigest() == before[name], 'Unchanged production baseline ' + name)
    check(hashlib.sha256(candidate[name]).hexdigest() == after[name], 'Exact tested candidate ' + name)
    if name.endswith('.php'):
        run(['php8.5', '-l', str(qa / '.cms/source' / name)])
tests = [('security-regressions.php', '--mysql'), ('workspace-migration.php',), ('themes.php',)]
if maintenance:
    tests.append(('maintenance-regressions.php', '--mysql'))
for test in tests:
    output = run(['php8.5', str(qa / 'tests' / test[0]), *test[1:]], cwd=qa)
    check(bool(output), 'Isolated QA ' + test[0])
run(['nginx', '-t']); run(['php-fpm8.5', '-t'])
run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb'])
health()
if sys.argv[1] == '--check':
    print('Preflight passed; no production source changed.')
    raise SystemExit(0)

stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
backup = Path('/root/sensecms-backups') / (stamp + '-' + batch)
backup.mkdir(mode=0o700)
run(['tar', '-czf', str(backup / 'web-before.tgz'), '-C', str(web.parent), 'web'])
contents = run(['tar', '-tzf', str(backup / 'web-before.tgz')])
check(b'web/storage/installed.json' in contents and b'web/app/Core/AccessControl.php' in contents, 'Full private application backup verified')
with (backup / 'database-before.sql').open('wb') as stream:
    result = subprocess.run(['mariadb-dump', '--single-transaction', '--skip-lock-tables', '--hex-blob', '--no-tablespaces', 'sensecms_site'], stdout=stream, stderr=subprocess.PIPE)
    check(result.returncode == 0, 'Consistent production database snapshot')
check((backup / 'database-before.sql').stat().st_size > 1000, 'Database recovery file retained')
for name, data in original.items():
    target = backup / name
    target.parent.mkdir(parents=True, exist_ok=True); target.write_bytes(data)
private_paths = ['installed.json', 'workspace.json', 'theme.json', 'license/key.bin', 'license/license.lic']
private_hashes = {p: hashlib.sha256((web / 'storage' / p).read_bytes()).hexdigest() for p in private_paths}
accounts_sql = 'SELECT id,password,email,active,is_demo,session_version FROM sensecms_site.users ORDER BY id; SELECT * FROM sensecms_site.user_roles ORDER BY user_id,role_id; SELECT * FROM sensecms_site.role_permissions ORDER BY role_id,permission_id;'
accounts_before = run(['mariadb', '--batch', '--skip-column-names'], input=accounts_sql.encode())
package_columns = []
if maintenance:
    package_columns = [line.split('\t')[0] for line in run(['mariadb', '--batch', '--skip-column-names', '-e', 'SHOW COLUMNS FROM sensecms_site.extension_packages']).decode().splitlines()]
    check(all(re.fullmatch(r'[a-z_]+', column) for column in package_columns), 'Verified package schema for state comparison')
# The existing themes GET synchronizes bundled metadata and refreshes updated_at.
# Compare every other package field, and all plugin/settings fields, without ignoring data.
package_select = ','.join('`' + column + '`' for column in package_columns if column != 'updated_at')
packages_sql = 'SELECT ' + package_select + ' FROM sensecms_site.extension_packages ORDER BY id; SELECT * FROM sensecms_site.installed_plugins ORDER BY slug; SELECT * FROM sensecms_site.settings ORDER BY `key`;'
packages_before = run(['mariadb', '--batch', '--skip-column-names'], input=packages_sql.encode()) if maintenance else None
updates = web / 'storage/system-updates'
def update_hashes():
    return {str(p.relative_to(updates)): hashlib.sha256(p.read_bytes()).hexdigest() for p in updates.rglob('*') if p.is_file()}
updates_before = update_hashes() if maintenance else None
logs = [Path('/var/log/nginx/sensecms.com.error.log'), web / 'storage/php-error.log']
positions = {p: p.stat().st_size if p.exists() else 0 for p in logs}
receipt = {'before': before, 'after': after, 'backup': str(backup), 'qa': str(qa), 'status': 'prepared'}
(backup / 'deployment.json').write_text(json.dumps(receipt, indent=2))
try:
    for name in before:
        check(hashlib.sha256((web / name).read_bytes()).hexdigest() == before[name], 'Baseline rechecked before publication ' + name)
        atomic(web / name, candidate[name], metadata[name])
    fpm_probe(True)
    for name, digest in after.items():
        check(hashlib.sha256((web / name).read_bytes()).hexdigest() == digest, 'Deployed SHA-256 ' + name)
        if name.endswith('.php'):
            run(['php8.5', '-l', str(web / name)])
    health(); owner_ui()
    check(accounts_before == run(['mariadb', '--batch', '--skip-column-names'], input=accounts_sql.encode()), 'Passwords, identities and permission assignments preserved')
    if maintenance:
        check(packages_before == run(['mariadb', '--batch', '--skip-column-names'], input=packages_sql.encode()), 'Live package registrations and settings unchanged')
        check(updates_before == update_hashes(), 'Update checks and rejected actions create or change no job or catalog files')
    check(all(hashlib.sha256((web / 'storage' / p).read_bytes()).hexdigest() == digest for p, digest in private_hashes.items()), 'Private configuration, encryption keys and theme preserved')
    run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb'])
    for path, position in positions.items():
        if not path.exists():
            continue
        with path.open('rb') as stream:
            stream.seek(position); fresh = stream.read()
        check(not re.search(rb'PHP (?:Warning|Fatal|Parse|Notice)|Uncaught|\[(?:crit|alert|emerg|error)\]', fresh, re.I), 'No fresh application errors: ' + path.name)
    receipt['status'] = 'verified'
except BaseException:
    for name in before:
        atomic(web / name, original[name], metadata[name])
    fpm_probe(False)
    receipt['status'] = 'rolled-back'
    raise
finally:
    (backup / 'deployment.json').write_text(json.dumps(receipt, indent=2))
print(json.dumps(receipt))
