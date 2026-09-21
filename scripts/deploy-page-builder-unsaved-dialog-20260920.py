#!/usr/bin/env python3
"""Deploy the Core styled unsaved-changes navigation guard."""
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
    raise SystemExit('Run as root on Linux: deploy-page-builder-unsaved-dialog-20260920.py <private-stage>')

os.umask(0o077)
stage = Path(sys.argv[1]).resolve()
web = Path('/home/sensecms.com/web')
files = [
    'public/theme/sensecms-unsaved-guard.js',
    'public/theme/sensecms-page-builder.js',
    'public/theme/sensecms-content-management.js',
    'app/Views/console.php',
]
baseline = {
    'public/theme/sensecms-unsaved-guard.js': '94154196d72030a3ccd5b9074d66445a7f5d90d5c4eeadb0010bfd4ef0543000',
    'public/theme/sensecms-page-builder.js': '8eb57e624e921d7ddba1f39e6796e22668fc5c5be619d1edf34766ccf486f86c',
    'public/theme/sensecms-content-management.js': 'f6f17dedb0d38164b192d0f5815aa74595dcfd855f4e5f2fe528651a528731a2',
    'app/Views/console.php': 'd14a34ef60d6244e135c5d03fb0a41aafd982e0f0d12a4cccdb1f421b5b9d462',
}
receipt = {'status': 'preflight', 'checks': []}
backup = None
deployed: list[str] = []
started = int(datetime.datetime.now(datetime.timezone.utc).timestamp())


def run(command):
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


required = [stage / '.cms/source' / path for path in files] + [stage / 'tests/page-builder-ui-production.py']
check(stage.is_dir() and str(stage).startswith('/root/sense-builder-unsaved-'), 'Private scoped deployment stage')
check(all(path.is_file() and not path.is_symlink() for path in required), 'Complete unsaved-dialog payload')
check(all((web / path).is_file() and sha(web / path) == expected for path, expected in baseline.items()), 'Expected production Core baseline')
run(['php8.5', '-l', str(stage / '.cms/source/app/Views/console.php')])
node = shutil.which('node')
if node:
    for path in files[:3]:
        run([node, '--check', str(stage / '.cms/source' / path)])
    check(True, 'Staged JavaScript syntax')
guard = (stage / '.cms/source/public/theme/sensecms-unsaved-guard.js').read_text()
builder = (stage / '.cms/source/public/theme/sensecms-page-builder.js').read_text()
check('confirmLeave:open' in guard and 'if(url.href===location.href)return' not in guard and 'guard?.confirmLeave' in builder, 'Styled internal navigation guard contract')
run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm'])
run(['nginx', '-t'])

lock = Path('/root/sensecms-private/deploy-workspace.lock').open('a+b')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
try:
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    backup = Path('/root/sensecms-backups') / (stamp + '-page-builder-unsaved-dialog')
    backup.mkdir(mode=0o700)
    receipt['backup'] = str(backup)
    for relative in files:
        source, target, previous = stage / '.cms/source' / relative, web / relative, backup / relative
        previous.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(target, previous)
        stat = target.stat()
        temporary = target.with_name(target.name + '.deploy')
        shutil.copy2(source, temporary)
        os.chown(temporary, stat.st_uid, stat.st_gid)
        os.chmod(temporary, stat.st_mode & 0o777)
        os.replace(temporary, target)
        deployed.append(relative)
        check(sha(target) == sha(source), 'Deployed checksum ' + relative)
    time.sleep(3)
    check(b'PASS Production Core Page Builder layouts' in run(['python3', str(stage / 'tests/page-builder-ui-production.py')]), 'Authenticated production styled-dialog acceptance')
    for route in ['/theme/sensecms-unsaved-guard.js?v=20260920-2', '/theme/sensecms-page-builder.js?v=20260920-unsaved-2', '/theme/sensecms-content-management.js?v=20260920-unsaved-2']:
        with urllib.request.urlopen('https://www.sensecms.com' + route, timeout=30) as response:
            check(response.status == 200 and response.read(), 'HTTP 200 ' + route)
    run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm'])
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
