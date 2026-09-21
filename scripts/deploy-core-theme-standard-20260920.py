#!/usr/bin/env python3
"""Deploy the portable Core builder contract and signed Sense CMS theme 1.0.10."""
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
import urllib.request

if os.name == 'nt' or os.geteuid() != 0 or len(sys.argv) != 2:
    raise SystemExit('Run as root on Linux: deploy-core-theme-standard-20260920.py <private-stage>')

os.umask(0o077)
stage = Path(sys.argv[1]).resolve()
web = Path('/home/sensecms.com/web')
theme_source = stage / '.themes/sensecms'
core_files = ['app/Core/PageBuilder.php', 'app/Core/ThemeContract.php']
baseline = {
    'app/Core/PageBuilder.php': '06cd32b7e1395d968e0a0b32fef166c7c13ad8afd668fb03b73fbcc2dc02ac96',
    'app/Core/ThemeContract.php': 'ecefa2367a79f2c2b575cebd2f52e3f13c848efca4208e6bc799aa3951b8472b',
}
receipt = {'status': 'preflight', 'checks': []}
backup = None
deployed = []
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
    code = r'''$r=new App\Core\Runtime($argv[1]);echo json_encode((new App\Core\Packages\ThemeManager($r))->active(),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web))


def deploy_file(relative):
    src = stage / '.cms/source' / relative
    dst = web / relative
    old = backup / 'core' / relative
    old.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(dst, old)
    stat = dst.stat()
    tmp = dst.with_name(dst.name + '.builder-standard-' + token)
    shutil.copy2(src, tmp)
    os.chown(tmp, stat.st_uid, stat.st_gid)
    os.chmod(tmp, stat.st_mode & 0o777)
    os.replace(tmp, dst)
    deployed.append(relative)


def restore():
    for relative in reversed(deployed):
        shutil.copy2(backup / 'core' / relative, web / relative)
    previous = backup / 'theme-before.json'
    if previous.is_file():
        tmp = web / 'storage/theme.builder-standard-restore.json'
        shutil.copy2(previous, tmp)
        shutil.chown(tmp, user='sensecms', group='sensecms')
        os.chmod(tmp, 0o600)
        os.replace(tmp, web / 'storage/theme.json')


required = [web / 'bootstrap.php', theme_source / 'sense-package.json', theme_source / 'theme.json',
            stage / 'tests/builder-defaults.php', stage / 'tests/themes.php', stage / 'tests/security-regressions.php',
            *(stage / '.cms/source' / relative for relative in core_files)]
check(stage.is_dir() and str(stage).startswith('/root/sense-core-theme-standard-'), 'Private scoped deployment stage')
check(all(path.is_file() and not path.is_symlink() for path in required), 'Complete Core and theme payload')
before = active()
check(before['slug'] == 'sensecms' and before['version'] == '1.0.9', 'Pinned signed theme 1.0.9 baseline')
check(all(sha(web / relative) == expected for relative, expected in baseline.items()), 'Expected production Core baseline')
manifest = json.loads((theme_source / 'sense-package.json').read_text())
descriptor = json.loads((theme_source / 'theme.json').read_text())
check(manifest['slug'] == descriptor['slug'] == 'sensecms' and manifest['version'] == descriptor['version'] == '1.0.10', 'Theme 1.0.10 identity')
check(len(descriptor['supported_blocks']) == 15, 'Theme declares all fifteen Core sections')
for path in sorted(theme_source.rglob('*')):
    check(not path.is_symlink(), 'Theme source contains no symbolic links') if path.is_symlink() else None
    if path.is_file() and path.suffix == '.php':
        run(['php8.5', '-l', str(path)])
for path in [stage / '.cms/source/app/Core/PageBuilder.php', stage / '.cms/source/app/Core/ThemeContract.php']:
    run(['php8.5', '-l', str(path)])
node = shutil.which('node')
if node:
    run([node, '--check', str(theme_source / 'assets/site.js')])
    check(True, 'Theme JavaScript syntax')
check(b'177 builder preset/compatibility checks passed' in run(['php8.5', str(stage / 'tests/builder-defaults.php')]), 'Builder contract regressions')
check(b'675 theme and website checks passed' in run(['php8.5', str(stage / 'tests/themes.php')]), 'Theme and website regressions')
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
    backup = Path('/root/sensecms-backups') / (stamp + '-core-theme-standard')
    backup.mkdir(mode=0o700)
    receipt['backup'] = str(backup)
    shutil.copy2(web / 'storage/theme.json', backup / 'theme-before.json')
    run(['tar', '-czf', str(backup / 'theme-storage-before.tgz'), '-C', str(web), 'storage/theme.json', 'storage/themes'])
    with (backup / 'database-before.sql').open('wb') as output:
        result = subprocess.run(['mysqldump', '--single-transaction', '--routines', '--triggers', 'sensecms_site'], stdout=output, stderr=subprocess.PIPE)
        if result.returncode:
            raise RuntimeError(result.stderr.decode(errors='replace').strip() or 'Database backup failed.')
    os.chmod(backup / 'database-before.sql', 0o600)
    check((backup / 'database-before.sql').stat().st_size > 4096, 'Private production recovery dump')
    for relative in core_files:
        deploy_file(relative)
    for relative in core_files:
        check(sha(web / relative) == sha(stage / '.cms/source' / relative), 'Deployed checksum ' + relative)

    package = backup / 'sensecms-theme-1.0.10.zip'
    php(r'''$secret=base64_decode(trim(file_get_contents('/root/sensecms-private/publisher.ed25519')),true);if(!is_string($secret))throw new RuntimeException('Invalid signing key.');try{App\Core\Packages\Archive::build($argv[1],$argv[2],$secret);}finally{sodium_memzero($secret);}''', theme_source, package)
    trust = json.loads(Path('/root/sensecms-private/trust.json').read_text())
    keys = json.dumps(trust, separators=(',', ':'))
    verified = json.loads(php(r'''$keys=array_map(static fn($key)=>base64_decode($key,true),json_decode($argv[2],true,16,JSON_THROW_ON_ERROR));$m=App\Core\Packages\Archive::verify($argv[1],$keys);echo json_encode(['identity'=>App\Core\Packages\Manifest::identity($m),'version'=>$m['version']],JSON_THROW_ON_ERROR);''', package, keys))
    check(verified == {'identity': 'theme:sensecms', 'version': '1.0.10'}, 'Signed theme archive verified')
    release = json.loads(php(r'''$r=new App\Core\Runtime($argv[1]);$r->license()->enforce($r->baseUrl());$keys=array_map(static fn($key)=>base64_decode($key,true),json_decode($argv[3],true,16,JSON_THROW_ON_ERROR));echo json_encode((new App\Core\Packages\ThemeManager($r))->install($argv[2],$keys,false),JSON_THROW_ON_ERROR);''', web, package, keys))
    run(['chown', '-R', 'sensecms:sensecms', str(web / 'storage/themes' / release['directory'])])
    run(['chown', 'sensecms:sensecms', str(web / 'storage/theme.json'), str(web / 'storage/themes.lock')])
    php(r'''$r=new App\Core\Runtime($argv[1]);$keys=array_map(static fn($key)=>base64_decode($key,true),json_decode($argv[3],true,16,JSON_THROW_ON_ERROR));(new App\Core\Packages\ThemeManager($r))->activate($argv[2],$keys);''', web, release['directory'], keys, user='sensecms')
    after = active()
    check(after['slug'] == 'sensecms' and after['version'] == '1.0.10', 'Signed theme 1.0.10 active')
    contract = json.loads(php(r'''$r=new App\Core\Runtime($argv[1]);$p=(new App\Core\Packages\ThemeManager($r))->activePath();$t=json_decode(file_get_contents($p.'/theme.json'),true,64,JSON_THROW_ON_ERROR);$t['_path']=$p;$resolved=App\Core\ThemeContract::resolve($t['slug'],[$t['slug']=>$t]);$catalog=App\Core\PageBuilder::catalog($resolved);echo json_encode(['types'=>array_keys($catalog),'supported'=>$resolved['supported_blocks']],JSON_THROW_ON_ERROR);''', web))
    check(len(contract['types']) >= 15 and len(set(descriptor['supported_blocks']) - set(contract['types'])) == 0, 'Production builder exposes the complete Core standard')
    check(len(set(descriptor['supported_blocks']) - set(contract['supported'])) == 0, 'Active theme contract includes all Core sections')
    for route in ['/', '/platform', '/docs/themes', '/theme-assets/site.css', '/theme-assets/site.js']:
        with urllib.request.urlopen('https://www.sensecms.com' + route, timeout=30) as response:
            body = response.read()
            check(response.status == 200 and body, 'HTTP 200 ' + route)
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
    if backup is not None:
        (backup / 'receipt.json').write_text(json.dumps(receipt, indent=2, sort_keys=True) + '\n')
        os.chmod(backup / 'receipt.json', 0o600)
    fcntl.flock(lock, fcntl.LOCK_UN)
    lock.close()

print(json.dumps(receipt, sort_keys=True), flush=True)
