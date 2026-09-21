#!/usr/bin/env python3
"""Deploy Core Page Builder Live Canvas and signed Sense CMS theme 1.0.12."""
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
    raise SystemExit('Run as root on Linux: deploy-page-builder-live-canvas-20260920.py <private-stage>')

os.umask(0o077)
stage = Path(sys.argv[1]).resolve()
web = Path('/home/sensecms.com/web')
theme_source = stage / '.themes/sensecms'
core_files = [
    'app/Core/PageBuilder.php',
    'app/Http/PublicController.php',
    'app/workspace.php',
    'app/Views/console-page-builder.php',
    'app/Views/console.php',
    'public/theme/sensecms-content-management.js',
    'public/theme/sensecms-page-builder.css',
    'public/theme/sensecms-page-builder.js',
    'public/theme/sensecms-builder-preview.css',
    'public/theme/sensecms-builder-preview.js',
]
baseline = {
    'app/Core/PageBuilder.php': '993b4b89d045e774bdf98bf51e610ab0e358ead613279577bc679081cef3f0ff',
    'app/Http/PublicController.php': '7ee84b4b4c0b6cf95fa4a177a2ebc14e33e37fc73dd78c0fa833329c9c972309',
    'app/workspace.php': 'b487dd3cffb326cc8f6cc2b03b54245d5b9d7996fb9b2a7585a5b993271b5755',
    'app/Views/console-page-builder.php': 'ec65481ab6cb6d243e758f7b4ede4f997bc3ee36db2d9f00f73c778e754791ae',
    'app/Views/console.php': '0801a272db86eebe5d8ae60de13bc57c3165b791b9ed31b6249ae0fb66e99331',
    'public/theme/sensecms-content-management.js': '877effd2d6f96e02e4b38d36929f5ea712773b217338b29f5d04a3c46ef42554',
    'public/theme/sensecms-page-builder.css': '3184b774d12ae7e708cd55d31bd749d47375e3bd56767abdd2ae015ec0e0514e',
    'public/theme/sensecms-page-builder.js': 'c497a559eefcbce74d6be21a390b863e4ba0da40f08b3a4f6c1b2b4e39490e79',
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
    src, dst = stage / '.cms/source' / relative, web / relative
    if not src.is_file() or src.is_symlink():
        raise RuntimeError('Invalid deployment source: ' + relative)
    dst.parent.mkdir(parents=True, exist_ok=True)
    if dst.exists():
        if dst.is_symlink() or not dst.is_file():
            raise RuntimeError('Unsafe deployment target: ' + relative)
        stat = dst.stat(); metadata[relative] = (stat.st_uid, stat.st_gid, stat.st_mode & 0o777)
        old = backup / 'core' / relative; old.parent.mkdir(parents=True, exist_ok=True); shutil.copy2(dst, old)
    else:
        parent = dst.parent.stat(); metadata[relative] = (parent.st_uid, parent.st_gid, 0o644)
    uid, gid, mode = metadata[relative]
    tmp = dst.with_name(dst.name + '.deploy-' + token)
    shutil.copy2(src, tmp); os.chown(tmp, uid, gid); os.chmod(tmp, mode); os.replace(tmp, dst); deployed.append(relative)


def restore():
    for relative in reversed(deployed):
        dst, old = web / relative, backup / 'core' / relative
        if old.is_file():
            shutil.copy2(old, dst); uid, gid, mode = metadata[relative]; os.chown(dst, uid, gid); os.chmod(dst, mode)
        elif dst.is_file() and not dst.is_symlink():
            dst.unlink()
    for name in ['theme.json', 'themes.lock']:
        old = backup / ('storage-' + name)
        if old.is_file():
            shutil.copy2(old, web / 'storage' / name); shutil.chown(web / 'storage' / name, user='sensecms', group='sensecms'); os.chmod(web / 'storage' / name, 0o600)


required = [
    web / 'bootstrap.php', stage / '.cms/source/bootstrap.php', theme_source / 'sense-package.json', theme_source / 'theme.json',
    stage / 'tests/builder-defaults.php', stage / 'tests/themes.php', stage / 'tests/security-regressions.php',
    stage / 'tests/maintenance-regressions.php', stage / 'tests/page-builder-ui-production.py',
    *(stage / '.cms/source' / relative for relative in core_files),
]
check(stage.is_dir() and str(stage).startswith('/root/sense-builder-live-'), 'Private scoped deployment stage')
check(all(path.is_file() and not path.is_symlink() for path in required), 'Complete Live Canvas deployment payload')
before = active()
check(before['slug'] == 'sensecms' and before['version'] == '1.0.11', 'Pinned signed theme 1.0.11 baseline')
check(all((web / relative).is_file() and sha(web / relative) == expected for relative, expected in baseline.items()), 'Expected production Page Builder baseline')
check(all(not (web / relative).exists() for relative in core_files[-2:]), 'Core preview bridge is cleanly pending')
manifest = json.loads((theme_source / 'sense-package.json').read_text())
descriptor = json.loads((theme_source / 'theme.json').read_text())
check(manifest['slug'] == descriptor['slug'] == 'sensecms' and manifest['version'] == descriptor['version'] == '1.0.12', 'Theme 1.0.12 identity')
for path in sorted(theme_source.rglob('*')):
    if path.is_symlink(): raise RuntimeError('Theme source contains a symbolic link: ' + str(path))
    if path.is_file() and path.suffix == '.php': run(['php8.5', '-l', str(path)])
for path in [stage / '.cms/source' / relative for relative in core_files if relative.endswith('.php')]: run(['php8.5', '-l', str(path)])
node = shutil.which('node')
if node:
    for relative in ['public/theme/sensecms-page-builder.js','public/theme/sensecms-builder-preview.js','public/theme/sensecms-content-management.js']:
        run([node, '--check', str(stage / '.cms/source' / relative)])
    check(True, 'Staged JavaScript syntax')
check(b'254 builder preset/compatibility checks passed' in run(['php8.5', str(stage / 'tests/builder-defaults.php')]), 'Live Canvas contract regressions')
check(b'678 theme and website checks passed' in run(['php8.5', str(stage / 'tests/themes.php')]), 'Theme and website regressions')
if b'pdo_sqlite' in run(['php8.5', '-m']):
    check(b'92 security regression checks passed' in run(['php8.5', str(stage / 'tests/security-regressions.php')]), 'Security regressions')
    check(b'16 maintenance checks passed' in run(['php8.5', str(stage / 'tests/maintenance-regressions.php')]), 'Maintenance regressions')
else:
    print('SKIP SQLite-dependent security and maintenance regressions (pdo_sqlite is unavailable on production)')
run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb', 'cron']); run(['nginx', '-t'])

lock = Path('/root/sensecms-private/deploy-workspace.lock').open('a+b')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
try:
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ'); token = hashlib.sha256(stamp.encode()).hexdigest()[:12]
    backup = Path('/root/sensecms-backups') / (stamp + '-page-builder-live-canvas'); backup.mkdir(mode=0o700); receipt['backup'] = str(backup)
    for name in ['theme.json', 'themes.lock']:
        source = web / 'storage' / name
        if source.is_file(): shutil.copy2(source, backup / ('storage-' + name))
    run(['tar', '-czf', str(backup / 'theme-storage-before.tgz'), '-C', str(web), 'storage/theme.json', 'storage/themes.lock', 'storage/themes'])
    for relative in core_files: deploy(relative)
    for relative in core_files: check(sha(web / relative) == sha(stage / '.cms/source' / relative), 'Deployed checksum ' + relative)

    package = backup / 'sensecms-theme-1.0.12.zip'
    php(r'''$secret=base64_decode(trim(file_get_contents('/root/sensecms-private/publisher.ed25519')),true);if(!is_string($secret))throw new RuntimeException('Invalid signing key.');try{App\Core\Packages\Archive::build($argv[1],$argv[2],$secret);}finally{sodium_memzero($secret);}''', theme_source, package)
    trust = json.loads(Path('/root/sensecms-private/trust.json').read_text()); keys = json.dumps(trust, separators=(',', ':'))
    verified = json.loads(php(r'''$keys=array_map(static fn($key)=>base64_decode($key,true),json_decode($argv[2],true,16,JSON_THROW_ON_ERROR));$m=App\Core\Packages\Archive::verify($argv[1],$keys);echo json_encode(['identity'=>App\Core\Packages\Manifest::identity($m),'version'=>$m['version']],JSON_THROW_ON_ERROR);''', package, keys))
    check(verified == {'identity': 'theme:sensecms', 'version': '1.0.12'}, 'Signed theme archive verified')
    release = json.loads(php(r'''$r=new App\Core\Runtime($argv[1]);$r->license()->enforce($r->baseUrl());$keys=array_map(static fn($key)=>base64_decode($key,true),json_decode($argv[3],true,16,JSON_THROW_ON_ERROR));echo json_encode((new App\Core\Packages\ThemeManager($r))->install($argv[2],$keys,false),JSON_THROW_ON_ERROR);''', web, package, keys))
    run(['chown', '-R', 'sensecms:sensecms', str(web / 'storage/themes' / release['directory'])]); run(['chown', 'sensecms:sensecms', str(web / 'storage/theme.json'), str(web / 'storage/themes.lock')])
    php(r'''$r=new App\Core\Runtime($argv[1]);$keys=array_map(static fn($key)=>base64_decode($key,true),json_decode($argv[3],true,16,JSON_THROW_ON_ERROR));(new App\Core\Packages\ThemeManager($r))->activate($argv[2],$keys);''', web, release['directory'], keys, user='sensecms')
    after = active(); check(after['slug'] == 'sensecms' and after['version'] == '1.0.12', 'Signed theme 1.0.12 active')
    time.sleep(3)
    check(b'PASS Production Core Page Builder Live Canvas' in run(['python3', str(stage / 'tests/page-builder-ui-production.py')]), 'Authenticated production Live Canvas acceptance')
    for route in ['/', '/platform', '/theme/sensecms-page-builder.css?v=20260920-live-canvas-1', '/theme/sensecms-page-builder.js?v=20260920-live-canvas-1', '/theme/sensecms-builder-preview.css?v=20260920-live-1', '/theme/sensecms-builder-preview.js?v=20260920-live-1']:
        with urllib.request.urlopen('https://www.sensecms.com' + route, timeout=30) as response:
            check(response.status == 200 and response.read(), 'HTTP 200 ' + route)
    run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb', 'cron']); run(['nginx', '-t'])
    logs = run(['journalctl', '--since', '@' + str(started), '--no-pager', '-u', 'nginx', '-u', 'php8.5-fpm']).decode(errors='replace')
    check(not re.search(r'PHP Fatal|Uncaught|\bcrit\b|\bpanic\b', logs, re.I), 'No fresh critical service errors')
    receipt.update(status='deployed', release=release)
except BaseException:
    errors = []
    if backup:
        try: restore()
        except BaseException as error: errors.append('recovery:' + type(error).__name__)
    receipt['status'] = 'rollback-needs-attention' if errors else ('rolled-back' if backup else 'preflight-failed')
    if errors: receipt['recovery_errors'] = errors
    raise
finally:
    if backup:
        (backup / 'receipt.json').write_text(json.dumps(receipt, indent=2, sort_keys=True) + '\n'); os.chmod(backup / 'receipt.json', 0o600)
    fcntl.flock(lock, fcntl.LOCK_UN); lock.close()

print(json.dumps(receipt, sort_keys=True), flush=True)
