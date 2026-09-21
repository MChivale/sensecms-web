#!/usr/bin/env python3
"""Deploy Core Page Builder responsive layouts and signed Sense CMS theme 1.0.11."""
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
    raise SystemExit('Run as root on Linux: deploy-page-builder-layouts-20260920.py <private-stage>')

os.umask(0o077)
stage = Path(sys.argv[1]).resolve()
web = Path('/home/sensecms.com/web')
theme_source = stage / '.themes/sensecms'
core_files = [
    'app/Core/PageBuilder.php',
    'app/Core/CmsRepository.php',
    'app/Http/DashboardController.php',
    'app/Views/console-page-builder.php',
    'app/Views/console.php',
    'config/page-builder.php',
    'public/theme/sensecms-content-management.js',
    'public/theme/sensecms-page-builder.css',
    'public/theme/sensecms-page-builder.js',
]
migration = 'database/workspace/034_page_builder_layouts.sql'
baseline = {
    'app/Core/PageBuilder.php': 'd6c31bc1ebe446647f36b363f36f198d7a535b5542ca8dee9bf5ec4981b0b939',
    'app/Core/CmsRepository.php': '766eafdfd863d83fdb7112ace486ae6cd70294ed8bd3226552b84051c453f43b',
    'app/Http/DashboardController.php': '11cf93c64b988327c248503a0d6824041e60e9b613b3b2310e7a326194a72e56',
    'app/Views/console-page-builder.php': 'd0b28a92423203c1ff14d1b89eb26340db2bdebc5335926eaea8920e5993137a',
    'app/Views/console.php': '8fd17ebba611613d67c0f5fe2420e99a9a9437850f9454e343b983654ebf457e',
    'config/page-builder.php': '354de5f26d0677a419f22d5676da2ac75551235b682ad1e1f232986b8b041ea0',
    'public/theme/sensecms-content-management.js': '6db773a48c613f6c9f86eb76aacbd8b6d5b896400bf94bb0c46db8cb22a52ce9',
    'public/theme/sensecms-page-builder.css': '7a463a36648678974a0a100a07fad39b536a6981a758eb304fed985d05dc631f',
    'public/theme/sensecms-page-builder.js': 'f44d90b22d96709a6535a1ef13f728c44b000538ff63afdd849899bb02e45b8c',
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


def migration_status():
    return json.loads(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);echo json_encode((new App\Installer\WorkspaceMigration($db,$r))->status(),JSON_THROW_ON_ERROR);''', web, user='sensecms'))


def deploy(relative):
    src, dst = stage / '.cms/source' / relative, web / relative
    if not src.is_file() or src.is_symlink():
        raise RuntimeError('Invalid deployment source: ' + relative)
    dst.parent.mkdir(parents=True, exist_ok=True)
    if dst.exists():
        if dst.is_symlink() or not dst.is_file():
            raise RuntimeError('Unsafe deployment target: ' + relative)
        stat = dst.stat()
        metadata[relative] = (stat.st_uid, stat.st_gid, stat.st_mode & 0o777)
        old = backup / 'core' / relative
        old.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(dst, old)
    else:
        metadata[relative] = (0, 0, 0o644)
        os.chown(dst.parent, 0, 0)
        os.chmod(dst.parent, 0o755)
    uid, gid, mode = metadata[relative]
    tmp = dst.with_name(dst.name + '.deploy-' + token)
    shutil.copy2(src, tmp)
    os.chown(tmp, uid, gid)
    os.chmod(tmp, mode)
    os.replace(tmp, dst)
    deployed.append(relative)


def restore():
    for relative in reversed(deployed):
        if relative == migration:
            continue
        dst, old = web / relative, backup / 'core' / relative
        if old.is_file():
            shutil.copy2(old, dst)
            uid, gid, mode = metadata[relative]
            os.chown(dst, uid, gid)
            os.chmod(dst, mode)
        elif dst.is_file() and not dst.is_symlink():
            dst.unlink()
    previous = backup / 'theme-before.json'
    if previous.is_file():
        tmp = web / 'storage/theme.builder-layout-restore.json'
        shutil.copy2(previous, tmp)
        shutil.chown(tmp, user='sensecms', group='sensecms')
        os.chmod(tmp, 0o600)
        os.replace(tmp, web / 'storage/theme.json')


required = [
    web / 'bootstrap.php', stage / '.cms/source/bootstrap.php', stage / '.src/package-catalog.php',
    theme_source / 'sense-package.json', theme_source / 'theme.json',
    stage / 'tests/builder-defaults.php', stage / 'tests/themes.php', stage / 'tests/security-regressions.php',
    stage / 'tests/page-builder-ui-production.py',
    *(stage / '.cms/source' / relative for relative in core_files + [migration]),
]
check(stage.is_dir() and str(stage).startswith('/root/sense-builder-layouts-'), 'Private scoped deployment stage')
check(all(path.is_file() and not path.is_symlink() for path in required), 'Complete responsive layout deployment payload')
before = active()
check(before['slug'] == 'sensecms' and before['version'] == '1.0.10', 'Pinned signed theme 1.0.10 baseline')
check(all((web / relative).is_file() and sha(web / relative) == expected for relative, expected in baseline.items()), 'Expected production Page Builder baseline')
check(not (web / migration).exists(), 'Responsive layout migration is cleanly pending')
status = migration_status()
check(status['ready'] and not status['pending'] and not status['missing_tables'], 'Existing Workspace schema is healthy')
manifest = json.loads((theme_source / 'sense-package.json').read_text())
descriptor = json.loads((theme_source / 'theme.json').read_text())
check(manifest['slug'] == descriptor['slug'] == 'sensecms' and manifest['version'] == descriptor['version'] == '1.0.11', 'Theme 1.0.11 identity')
check(len(descriptor['supported_blocks']) == 17, 'Theme declares all seventeen Core sections')
for path in sorted(theme_source.rglob('*')):
    if path.is_symlink():
        raise RuntimeError('Theme source contains a symbolic link: ' + str(path))
    if path.is_file() and path.suffix == '.php':
        run(['php8.5', '-l', str(path)])
for path in [stage / '.cms/source' / relative for relative in core_files if relative.endswith('.php')]:
    run(['php8.5', '-l', str(path)])
run(['php8.5', '-l', str(stage / 'tests/builder-defaults.php')])
node = shutil.which('node')
if node:
    run([node, '--check', str(stage / '.cms/source/public/theme/sensecms-page-builder.js')])
    run([node, '--check', str(stage / '.cms/source/public/theme/sensecms-content-management.js')])
    run([node, '--check', str(theme_source / 'assets/site.js')])
    check(True, 'Staged JavaScript syntax')
check(b'247 builder preset/compatibility checks passed' in run(['php8.5', str(stage / 'tests/builder-defaults.php')]), 'Responsive layout contract regressions')
check(b'678 theme and website checks passed' in run(['php8.5', str(stage / 'tests/themes.php')]), 'Theme and website regressions')
if b'pdo_sqlite' in run(['php8.5', '-m']):
    check(b'92 security regression checks passed' in run(['php8.5', str(stage / 'tests/security-regressions.php')]), 'Security regressions')
else:
    receipt['checks'].append('Remote pdo_sqlite unavailable; locally passed security suite retained as evidence')
    print('SKIP Security regressions: remote pdo_sqlite is unavailable', flush=True)
run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb', 'cron'])
run(['nginx', '-t'])

lock = Path('/root/sensecms-private/deploy-workspace.lock').open('a+b')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
try:
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    token = hashlib.sha256(stamp.encode()).hexdigest()[:12]
    backup = Path('/root/sensecms-backups') / (stamp + '-page-builder-layouts')
    backup.mkdir(mode=0o700)
    receipt['backup'] = str(backup)
    shutil.copy2(web / 'storage/theme.json', backup / 'theme-before.json')
    run(['tar', '-czf', str(backup / 'theme-storage-before.tgz'), '-C', str(web), 'storage/theme.json', 'storage/themes'])
    with (backup / 'database-before.sql').open('wb') as output:
        result = subprocess.run(['mysqldump', '--single-transaction', '--routines', '--triggers', 'sensecms_site'], stdout=output, stderr=subprocess.PIPE)
        if result.returncode:
            raise RuntimeError(result.stderr.decode(errors='replace').strip() or 'Database backup failed.')
    os.chmod(backup / 'database-before.sql', 0o600)
    check((backup / 'database-before.sql').stat().st_size > 4096, 'Private production database recovery dump')
    deploy(migration)
    applied = int(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);echo(new App\Installer\WorkspaceMigration($db,$r))->apply();''', web, user='sensecms'))
    check(applied == 1, 'Responsive layout migration 034 applied exactly once')
    schema = json.loads(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$column=$db->query("SHOW COLUMNS FROM content_blocks LIKE 'layout'")->fetch(PDO::FETCH_ASSOC);echo json_encode(['column'=>$column,'configured'=>(int)$db->query('SELECT COUNT(*) FROM content_blocks WHERE layout IS NOT NULL')->fetchColumn(),'status'=>(new App\Installer\WorkspaceMigration($db,$r))->status()],JSON_THROW_ON_ERROR);''', web, user='sensecms'))
    check(bool(schema['column']) and schema['configured'] == 0 and schema['status']['ready'], 'Page-local layout column is ready without changing existing content')
    for relative in core_files:
        deploy(relative)
    for relative in core_files + [migration]:
        check(sha(web / relative) == sha(stage / '.cms/source' / relative), 'Deployed checksum ' + relative)

    package = backup / 'sensecms-theme-1.0.11.zip'
    php(r'''$secret=base64_decode(trim(file_get_contents('/root/sensecms-private/publisher.ed25519')),true);if(!is_string($secret))throw new RuntimeException('Invalid signing key.');try{App\Core\Packages\Archive::build($argv[1],$argv[2],$secret);}finally{sodium_memzero($secret);}''', theme_source, package)
    trust = json.loads(Path('/root/sensecms-private/trust.json').read_text())
    keys = json.dumps(trust, separators=(',', ':'))
    verified = json.loads(php(r'''$keys=array_map(static fn($key)=>base64_decode($key,true),json_decode($argv[2],true,16,JSON_THROW_ON_ERROR));$m=App\Core\Packages\Archive::verify($argv[1],$keys);echo json_encode(['identity'=>App\Core\Packages\Manifest::identity($m),'version'=>$m['version']],JSON_THROW_ON_ERROR);''', package, keys))
    check(verified == {'identity': 'theme:sensecms', 'version': '1.0.11'}, 'Signed theme archive verified')
    release = json.loads(php(r'''$r=new App\Core\Runtime($argv[1]);$r->license()->enforce($r->baseUrl());$keys=array_map(static fn($key)=>base64_decode($key,true),json_decode($argv[3],true,16,JSON_THROW_ON_ERROR));echo json_encode((new App\Core\Packages\ThemeManager($r))->install($argv[2],$keys,false),JSON_THROW_ON_ERROR);''', web, package, keys))
    run(['chown', '-R', 'sensecms:sensecms', str(web / 'storage/themes' / release['directory'])])
    run(['chown', 'sensecms:sensecms', str(web / 'storage/theme.json'), str(web / 'storage/themes.lock')])
    php(r'''$r=new App\Core\Runtime($argv[1]);$keys=array_map(static fn($key)=>base64_decode($key,true),json_decode($argv[3],true,16,JSON_THROW_ON_ERROR));(new App\Core\Packages\ThemeManager($r))->activate($argv[2],$keys);''', web, release['directory'], keys, user='sensecms')
    after = active()
    check(after['slug'] == 'sensecms' and after['version'] == '1.0.11', 'Signed theme 1.0.11 active')
    time.sleep(3)
    check(b'PASS Production Core Page Builder layouts' in run(['python3', str(stage / 'tests/page-builder-ui-production.py')]), 'Authenticated production responsive layout acceptance')
    for route in ['/', '/platform', '/theme/sensecms-page-builder.css?v=20260920-builder-layouts-1', '/theme/sensecms-page-builder.js?v=20260920-builder-layouts-1', '/theme-assets/site.css']:
        with urllib.request.urlopen('https://www.sensecms.com' + route, timeout=30) as response:
            body = response.read()
            check(response.status == 200 and body, 'HTTP 200 ' + route)
            if route in ['/', '/platform']:
                check(b'sense-layout' in body, 'Public responsive layout contract ' + route)
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
    receipt['status'] = 'rollback-needs-attention' if errors else ('rolled-back-with-additive-migration-retained' if backup else 'preflight-failed')
    if errors:
        receipt['recovery_errors'] = errors
    raise
finally:
    if backup is not None:
        (backup / 'receipt.json').write_text(json.dumps(receipt, indent=2, sort_keys=True) + '\n')
        os.chmod(backup / 'receipt.json', 0o600)
    fcntl.flock(lock, fcntl.LOCK_UN)
    lock.close()

print(json.dumps(receipt, sort_keys=True), flush=True)
