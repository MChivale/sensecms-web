#!/usr/bin/env python3
"""Deploy the Core Page Builder Quality Inspector with guarded rollback."""
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
import urllib.error
import urllib.request

if os.name == 'nt' or os.geteuid() != 0 or len(sys.argv) != 2:
    raise SystemExit('Run as root on Linux: deploy-page-builder-quality-20260921.py <private-stage>')

os.umask(0o077)
stage = Path(sys.argv[1]).resolve()
web = Path('/home/sensecms.com/web')
files = [
    'app/Core/PageBuilder.php', 'app/Core/MediaLibrary.php', 'app/Http/DashboardController.php',
    'app/Views/console-page-builder.php', 'app/Views/console.php', 'app/workspace.php',
    'public/theme/sensecms-content-management.js', 'public/theme/sensecms-page-builder.css',
    'public/theme/sensecms-page-builder.js',
]
baseline = {
    'app/Core/PageBuilder.php': '2771fa2bc3af520eaf215e61a28d727d5893bb986836379afb2ce8072adac76e',
    'app/Core/MediaLibrary.php': '3b4b7c50eb568e185270e6edb97ecff064ad58d873736e8e32a8da5c7852324f',
    'app/Http/DashboardController.php': '9e5607e05f2d57e9b480c7ef931b11291a3b32d199b13af242cf08a2949f9421',
    'app/Views/console-page-builder.php': '8bf789a9b054f4a107c8858acb838673f22d9122d8233245acd80ae60957d879',
    'app/Views/console.php': '19b0b14c7db578a682fdb7cfe24a8ecdcccf5380b55e8190d8a35b70223e58b6',
    'app/workspace.php': 'c2f97c5d42c3da0506844daee0dd55be5b3a3d2403eefb3045485a26503bb7df',
    'public/theme/sensecms-content-management.js': 'b71a1ee06f622176cd676e0887e7b48644f0394a0de64bfd5461409e6b31464a',
    'public/theme/sensecms-page-builder.css': '266ce90b03c9033b709f7a8de785b086ddca42f16f92b855682ad167e26a1462',
    'public/theme/sensecms-page-builder.js': '932bcea2a6ccaef9198bde0c885a35faabaf76e9cd82171049d3453e9450d2d2',
}
receipt = {'status': 'preflight', 'checks': []}
backup = None
deployed: list[str] = []
started = int(datetime.datetime.now(datetime.timezone.utc).timestamp())


def run(command, *, user=None):
    if user:
        command = ['sudo', '-u', user, *command]
    result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if result.returncode:
        raise RuntimeError(result.stdout.decode(errors='replace').strip() or f'Command failed: {command[0]}')
    return result.stdout


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def check(ok, label):
    if not ok:
        raise RuntimeError(label)
    receipt['checks'].append(label)
    print('PASS ' + label, flush=True)


required = [stage / '.cms/source' / path for path in files] + [
    stage / 'tests/builder-defaults.php', stage / 'tests/security-regressions.php',
    stage / 'tests/maintenance-regressions.php', stage / 'tests/page-builder-ui-production.py',
]
check(stage.is_dir() and str(stage).startswith('/root/sense-builder-quality-'), 'Private scoped deployment stage')
check(all(path.is_file() and not path.is_symlink() for path in required), 'Complete Quality Inspector deployment payload')
check(all((web / path).is_file() and sha(web / path) == expected for path, expected in baseline.items()), 'Expected production Page Builder baseline')
for path in [stage / '.cms/source' / relative for relative in files if relative.endswith('.php')]:
    run(['php8.5', '-l', str(path)])
node = shutil.which('node')
if node:
    for relative in [path for path in files if path.endswith('.js')]:
        run([node, '--check', str(stage / '.cms/source' / relative)])
    check(True, 'Staged JavaScript syntax')
check(b'266 builder preset/compatibility checks passed' in run(['php8.5', str(stage / 'tests/builder-defaults.php')]), 'Quality Inspector contract regressions')
if b'pdo_sqlite' in run(['php8.5', '-m']):
    check(b'92 security regression checks passed' in run(['php8.5', str(stage / 'tests/security-regressions.php')]), 'Security regressions')
    check(b'16 maintenance checks passed' in run(['php8.5', str(stage / 'tests/maintenance-regressions.php')]), 'Maintenance regressions')
else:
    print('SKIP SQLite-dependent security and maintenance regressions (pdo_sqlite unavailable)')
run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb', 'cron'])
run(['nginx', '-t'])

lock = Path('/root/sensecms-private/deploy-workspace.lock').open('a+b')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
try:
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    backup = Path('/root/sensecms-backups') / (stamp + '-page-builder-quality')
    backup.mkdir(mode=0o700)
    receipt['backup'] = str(backup)
    for relative in files:
        source, target, previous = stage / '.cms/source' / relative, web / relative, backup / relative
        previous.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(target, previous)
        stat = target.stat()
        temporary = target.with_name(target.name + '.deploy-quality')
        shutil.copy2(source, temporary)
        os.chown(temporary, stat.st_uid, stat.st_gid)
        os.chmod(temporary, stat.st_mode & 0o777)
        os.replace(temporary, target)
        deployed.append(relative)
        check(sha(target) == sha(source), 'Deployed checksum ' + relative)
    time.sleep(3)
    check(b'PASS Production Core Page Builder Quality Inspector' in run(['python3', str(stage / 'tests/page-builder-ui-production.py')]), 'Authenticated production Quality Inspector acceptance')
    for route in ['/content/builder', '/theme/sensecms-page-builder.css?v=20260921-quality-1', '/theme/sensecms-page-builder.js?v=20260921-quality-1']:
        try:
            with urllib.request.urlopen('https://www.sensecms.com' + route, timeout=30) as response:
                check(response.status in (200, 302) and response.read(), 'HTTP response ' + route)
        except urllib.error.HTTPError as error:
            check(error.code in (302, 401, 403), 'Protected HTTP response ' + route)
    run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb', 'cron'])
    run(['nginx', '-t'])
    logs = run(['journalctl', '--since', '@' + str(started), '--no-pager', '-u', 'nginx', '-u', 'php8.5-fpm']).decode(errors='replace')
    check(not re.search(r'PHP Fatal|Uncaught|\bcrit\b|\bpanic\b', logs, re.I), 'No fresh critical service errors')
    receipt['status'] = 'deployed'
except BaseException:
    errors = []
    if backup:
        for relative in reversed(deployed):
            try:
                source, target = backup / relative, web / relative
                stat = target.stat()
                shutil.copy2(source, target)
                os.chown(target, stat.st_uid, stat.st_gid)
                os.chmod(target, stat.st_mode & 0o777)
            except BaseException as error:
                errors.append(relative + ':' + type(error).__name__)
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
