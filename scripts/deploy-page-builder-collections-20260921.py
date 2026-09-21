#!/usr/bin/env python3
"""Deploy Core Page Builder dynamic collections and signed Sense CMS theme 1.0.14."""
from __future__ import annotations

import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time
import urllib.request

if os.name == 'nt' or os.geteuid() != 0 or len(sys.argv) != 2:
    raise SystemExit('Run as root on Linux: deploy-page-builder-collections-20260921.py <private-stage>')

os.umask(0o077)
stage = Path(sys.argv[1]).resolve()
web = Path('/home/sensecms.com/web')
theme_source = stage / '.themes/sensecms'
files = [
    'app/Core/PageBuilder.php', 'app/Core/CmsRepository.php', 'app/Http/DashboardController.php',
    'app/Http/PublicController.php', 'app/Views/console-page-builder.php', 'app/Views/console.php',
    'config/page-builder-layouts.json', 'public/theme/sensecms-content-management.js',
    'public/theme/sensecms-page-builder.css', 'public/theme/sensecms-page-builder.js',
]
baseline = {
    'app/Core/PageBuilder.php': 'b59bf38c613ebde0f4ebe8b49fed90c507fdc8fd5865ce88bacab1f707a605db',
    'app/Core/CmsRepository.php': 'a582341966c3120d7672bee1d47652d5a1445f06b1d2e4caf4b3a4b09d9538b6',
    'app/Http/DashboardController.php': '8faea63d26354e9e605faafe1ef284b9b750f34bbd3555f4a3d102d98ffaccfd',
    'app/Http/PublicController.php': 'd338d8d879815d19b6c01ba1d5bdee46bf0d7fc2811019d388907da24da07013',
    'app/Views/console-page-builder.php': '4890abff0850bb6612d65f1f1d0c75e52113117e6c90cd26a2dda0565a330ac1',
    'app/Views/console.php': '73d84f2650f80d8e4908a537d8e6819be656778aa8d62b71ebd64a9e361477eb',
    'config/page-builder-layouts.json': '7443d4faa5057cfc7744693576b6e7497464d6b3569ae3be11a1d34ed2e09a56',
    'public/theme/sensecms-content-management.js': '593d173c5cae6e82de3df87b66dc7649e579271999aedaeeeca41760d312149d',
    'public/theme/sensecms-page-builder.css': '562c64260242409f0b128b4767b6d66e2cca16b6f795c6e91997b7dbd93f8ee5',
    'public/theme/sensecms-page-builder.js': '851984386920c31111239e2297470521a646f0a18fba10a821c9e3e8f73f6980',
}
receipt = {'status': 'preflight', 'checks': []}
backup = None
deployed: list[str] = []
metadata: dict[str, tuple[int, int, int]] = {}
started = int(datetime.datetime.now(datetime.timezone.utc).timestamp())


def run(command, *, user=None):
    if user:
        command = ['sudo', '-u', user, *command]
    result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if result.returncode:
        raise RuntimeError(result.stdout.decode(errors='replace').strip() or f'Command failed: {command[0]}')
    return result.stdout


def php(code, *args, user=None):
    return run(['php8.5', '-r', f'require {json.dumps(str(web / "bootstrap.php"))};' + code, *map(str, args)], user=user)


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def check(ok, label):
    if not ok:
        raise RuntimeError(label)
    receipt['checks'].append(label)
    print('PASS ' + label, flush=True)


def active():
    return json.loads(php(r'''$r=new App\Core\Runtime($argv[1]);echo json_encode((new App\Core\Packages\ThemeManager($r))->active(),JSON_THROW_ON_ERROR);''', web))


def deploy(relative):
    source, target = stage / '.cms/source' / relative, web / relative
    if not source.is_file() or source.is_symlink():
        raise RuntimeError('Invalid deployment source: ' + relative)
    if not target.is_file() or target.is_symlink():
        raise RuntimeError('Unsafe deployment target: ' + relative)
    stat = target.stat()
    metadata[relative] = (stat.st_uid, stat.st_gid, stat.st_mode & 0o777)
    previous = backup / 'core' / relative
    previous.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(target, previous)
    temporary = target.with_name(target.name + '.deploy-' + token)
    shutil.copy2(source, temporary)
    os.chown(temporary, stat.st_uid, stat.st_gid)
    os.chmod(temporary, stat.st_mode & 0o777)
    os.replace(temporary, target)
    deployed.append(relative)


def restore():
    for relative in reversed(deployed):
        target, previous = web / relative, backup / 'core' / relative
        if previous.is_file():
            shutil.copy2(previous, target)
            uid, gid, mode = metadata[relative]
            os.chown(target, uid, gid)
            os.chmod(target, mode)
    for name in ['theme.json', 'themes.lock']:
        previous = backup / ('storage-' + name)
        if previous.is_file():
            shutil.copy2(previous, web / 'storage' / name)
            shutil.chown(web / 'storage' / name, user='sensecms', group='sensecms')
            os.chmod(web / 'storage' / name, 0o600)


required = [stage / '.cms/source' / path for path in files] + [
    theme_source / 'sense-package.json', theme_source / 'theme.json',
    stage / 'tests/builder-defaults.php', stage / 'tests/page-builder-collections.php',
    stage / 'tests/themes.php', stage / 'tests/security-regressions.php',
    stage / 'tests/maintenance-regressions.php', stage / 'tests/page-builder-ui-production.py',
]
check(stage.is_dir() and str(stage).startswith('/root/sense-builder-collections-'), 'Private scoped deployment stage')
check(all(path.is_file() and not path.is_symlink() for path in required), 'Complete dynamic collections deployment payload')
before = active()
check(before['slug'] == 'sensecms' and before['version'] == '1.0.13', 'Pinned signed theme 1.0.13 baseline')
check(all((web / path).is_file() and sha(web / path) == expected for path, expected in baseline.items()), 'Expected production Page Builder baseline')
manifest = json.loads((theme_source / 'sense-package.json').read_text())
descriptor = json.loads((theme_source / 'theme.json').read_text())
check(manifest['slug'] == descriptor['slug'] == 'sensecms' and manifest['version'] == descriptor['version'] == '1.0.14', 'Theme 1.0.14 identity')
for path in sorted(theme_source.rglob('*')):
    if path.is_symlink():
        raise RuntimeError('Theme source contains a symbolic link: ' + str(path))
    if path.is_file() and path.suffix == '.php':
        run(['php8.5', '-l', str(path)])
for path in [stage / '.cms/source' / relative for relative in files if relative.endswith('.php')]:
    run(['php8.5', '-l', str(path)])
node = shutil.which('node')
if node:
    for relative in [path for path in files if path.endswith('.js')]:
        run([node, '--check', str(stage / '.cms/source' / relative)])
    check(True, 'Staged JavaScript syntax')
check(b'270 builder preset/compatibility checks passed' in run(['php8.5', str(stage / 'tests/builder-defaults.php')]), 'Dynamic collection contract regressions')
if b'pdo_sqlite' in run(['php8.5', '-m']):
    check(b'7 dynamic collection database checks passed' in run(['php8.5', str(stage / 'tests/page-builder-collections.php')]), 'Dynamic collection database regressions')
    check(b'92 security regression checks passed' in run(['php8.5', str(stage / 'tests/security-regressions.php')]), 'Security regressions')
    check(b'16 maintenance checks passed' in run(['php8.5', str(stage / 'tests/maintenance-regressions.php')]), 'Maintenance regressions')
else:
    print('SKIP SQLite-dependent dynamic collection, security and maintenance regressions (pdo_sqlite unavailable)')
check(b'678 theme and website checks passed' in run(['php8.5', str(stage / 'tests/themes.php')]), 'Theme and website regressions')
run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb', 'cron'])
run(['nginx', '-t'])

lock = Path('/root/sensecms-private/deploy-workspace.lock').open('a+b')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
try:
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    token = hashlib.sha256(stamp.encode()).hexdigest()[:12]
    backup = Path('/root/sensecms-backups') / (stamp + '-page-builder-collections')
    backup.mkdir(mode=0o700)
    receipt['backup'] = str(backup)
    for name in ['theme.json', 'themes.lock']:
        source = web / 'storage' / name
        if source.is_file():
            shutil.copy2(source, backup / ('storage-' + name))
    run(['tar', '-czf', str(backup / 'theme-storage-before.tgz'), '-C', str(web), 'storage/theme.json', 'storage/themes.lock', 'storage/themes'])
    for relative in files:
        deploy(relative)
        check(sha(web / relative) == sha(stage / '.cms/source' / relative), 'Deployed checksum ' + relative)
    package = backup / 'sensecms-theme-1.0.14.zip'
    php(r'''$secret=base64_decode(trim(file_get_contents('/root/sensecms-private/publisher.ed25519')),true);if(!is_string($secret))throw new RuntimeException('Invalid signing key.');try{App\Core\Packages\Archive::build($argv[1],$argv[2],$secret);}finally{sodium_memzero($secret);}''', theme_source, package)
    trust = json.loads(Path('/root/sensecms-private/trust.json').read_text())
    keys = json.dumps(trust, separators=(',', ':'))
    verified = json.loads(php(r'''$keys=array_map(static fn($key)=>base64_decode($key,true),json_decode($argv[2],true,16,JSON_THROW_ON_ERROR));$m=App\Core\Packages\Archive::verify($argv[1],$keys);echo json_encode(['identity'=>App\Core\Packages\Manifest::identity($m),'version'=>$m['version']],JSON_THROW_ON_ERROR);''', package, keys))
    check(verified == {'identity': 'theme:sensecms', 'version': '1.0.14'}, 'Signed theme archive verified')
    release = json.loads(php(r'''$r=new App\Core\Runtime($argv[1]);$r->license()->enforce($r->baseUrl());$keys=array_map(static fn($key)=>base64_decode($key,true),json_decode($argv[3],true,16,JSON_THROW_ON_ERROR));echo json_encode((new App\Core\Packages\ThemeManager($r))->install($argv[2],$keys,false),JSON_THROW_ON_ERROR);''', web, package, keys))
    run(['chown', '-R', 'sensecms:sensecms', str(web / 'storage/themes' / release['directory'])])
    run(['chown', 'sensecms:sensecms', str(web / 'storage/theme.json'), str(web / 'storage/themes.lock')])
    php(r'''$r=new App\Core\Runtime($argv[1]);$keys=array_map(static fn($key)=>base64_decode($key,true),json_decode($argv[3],true,16,JSON_THROW_ON_ERROR));(new App\Core\Packages\ThemeManager($r))->activate($argv[2],$keys);''', web, release['directory'], keys, user='sensecms')
    after = active()
    check(after['slug'] == 'sensecms' and after['version'] == '1.0.14', 'Signed theme 1.0.14 active')
    time.sleep(3)
    check(b'PASS Production Core Page Builder dynamic collections' in run(['python3', str(stage / 'tests/page-builder-ui-production.py')]), 'Authenticated production dynamic collection acceptance')
    for route in ['/', '/platform', '/theme/sensecms-page-builder.css?v=20260921-collections-1', '/theme/sensecms-page-builder.js?v=20260921-collections-1', '/theme-assets/site.css']:
        with urllib.request.urlopen('https://www.sensecms.com' + route, timeout=30) as response:
            check(response.status == 200 and response.read(), 'HTTP 200 ' + route)
    run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb', 'cron'])
    run(['nginx', '-t'])
    logs = run(['journalctl', '--since', '@' + str(started), '--no-pager', '-u', 'nginx', '-u', 'php8.5-fpm']).decode(errors='replace')
    check(not re.search(r'PHP Fatal|Uncaught|\bcrit\b|\bpanic\b', logs, re.I), 'No fresh critical service errors')
    receipt.update(status='deployed', release=release)
except BaseException:
    errors = []
    if backup:
        try:
            restore()
        except BaseException as error:
            errors.append('recovery:' + type(error).__name__)
    receipt['status'] = 'rollback-needs-attention' if errors else ('rolled-back' if backup else 'preflight-failed')
    if errors:
        receipt['recovery_errors'] = errors
    raise
finally:
    if backup:
        (backup / 'receipt.json').write_text(json.dumps(receipt, indent=2, sort_keys=True) + '\n')
        os.chmod(backup / 'receipt.json', 0o600)
    fcntl.flock(lock, fcntl.LOCK_UN)
    lock.close()

print(json.dumps(receipt, sort_keys=True), flush=True)
