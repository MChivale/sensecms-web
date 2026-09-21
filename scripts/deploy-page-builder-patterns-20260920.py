#!/usr/bin/env python3
"""Deploy the Core Page Builder pattern library with guarded rollback."""
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
    raise SystemExit('Run as root on Linux: deploy-page-builder-patterns-20260920.py <private-stage>')
os.umask(0o077)
stage = Path(sys.argv[1]).resolve()
web = Path('/home/sensecms.com/web')
files = [
    'app/Core/PageBuilder.php',
    'app/Http/DashboardController.php',
    'app/Views/console-page-builder.php',
    'app/Views/console.php',
    'config/page-builder-patterns.php',
    'public/theme/sensecms-content-management.js',
    'public/theme/sensecms-page-builder.css',
    'public/theme/sensecms-page-builder.js',
]
baseline = {
    'app/Core/PageBuilder.php': '0978f7f0f6a8bae7573a14c0d9ae21b7c40ac239317633d0738b86fd6ba0386e',
    'app/Http/DashboardController.php': 'b7fb1f81b423e389c71bc2ecc5a3593c24ab6ecab7cd101017e1e053208d75d7',
    'app/Views/console-page-builder.php': 'b81c505084d8fa1af0016ea19d1a21fda75dc77d4987c1bab8f180488bd9bd6d',
    'app/Views/console.php': '06181947f664c64995a0746d428a1112bc6260d491ac575baea54206ec798072',
    'public/theme/sensecms-content-management.js': 'a31a4ae75fcea41139625b728aa3010cb1d8b79ea517ea806772fbc0429f2ba2',
    'public/theme/sensecms-page-builder.css': '17b546d63a27d08c85efb7945ff2fd99ad96c57e559ce408a96bb0b189f00c67',
    'public/theme/sensecms-page-builder.js': '32b4005bd86a062dd03ea2783aedbfd80095ed71b3165f2ee892607bfad9814d',
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

def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

def check(ok, label):
    if not ok:
        raise RuntimeError(label)
    receipt['checks'].append(label)
    print('PASS ' + label, flush=True)

def deploy(relative):
    src, dst = stage / '.cms/source' / relative, web / relative
    if not src.is_file() or src.is_symlink():
        raise RuntimeError('Invalid deployment source: ' + relative)
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
        dst.parent.mkdir(parents=True, exist_ok=True)
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
        dst, old = web / relative, backup / 'core' / relative
        if old.is_file():
            shutil.copy2(old, dst)
            uid, gid, mode = metadata[relative]
            os.chown(dst, uid, gid)
            os.chmod(dst, mode)
        elif dst.is_file() and not dst.is_symlink():
            dst.unlink()

required = [stage / '.cms/source' / path for path in files]
required += [stage / 'tests/builder-defaults.php', stage / 'tests/page-builder-ui-production.py']
check(stage.is_dir() and str(stage).startswith('/root/sense-builder-patterns-'), 'Private scoped deployment stage')
check(all(path.is_file() and not path.is_symlink() for path in required), 'Complete Page Builder pattern deployment payload')
check(all((web / path).is_file() and sha(web / path) == expected for path, expected in baseline.items()), 'Expected production Page Builder baseline')
check(not (web / 'config/page-builder-patterns.php').exists(), 'Core pattern configuration is new')
run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb', 'cron'])
run(['nginx', '-t'])
for path in required[:5] + [stage / 'tests/builder-defaults.php']:
    run(['php8.5', '-l', str(path)])
check(b'216 builder preset/compatibility checks passed' in run(['php8.5', str(stage / 'tests/builder-defaults.php')]), 'Builder pattern contract regressions')

lock = Path('/root/sensecms-private/deploy-workspace.lock').open('a+b')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
try:
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    token = hashlib.sha256(stamp.encode()).hexdigest()[:12]
    backup = Path('/root/sensecms-backups') / (stamp + '-page-builder-patterns')
    backup.mkdir(mode=0o700)
    receipt['backup'] = str(backup)
    for relative in files:
        deploy(relative)
    for relative in files:
        check(sha(web / relative) == sha(stage / '.cms/source' / relative), 'Deployed checksum ' + relative)
    time.sleep(3)
    check(b'PASS Production Core Page Builder navigator, patterns' in run(['python3', str(stage / 'tests/page-builder-ui-production.py')]), 'Authenticated production Page Builder pattern acceptance')
    for route in ['/theme/sensecms-page-builder.css?v=20260920-builder-patterns-1', '/theme/sensecms-page-builder.js?v=20260920-builder-patterns-1']:
        with urllib.request.urlopen('https://www.sensecms.com' + route, timeout=30) as response:
            check(response.status == 200 and response.read(), 'HTTP 200 ' + route)
    run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb', 'cron'])
    run(['nginx', '-t'])
    logs = run(['journalctl', '--since', '@' + str(started), '--no-pager', '-u', 'nginx', '-u', 'php8.5-fpm']).decode(errors='replace')
    check(not re.search(r'PHP Fatal|Uncaught|\bcrit\b|\bpanic\b', logs, re.I), 'No fresh critical service errors')
    receipt['status'] = 'deployed'
except BaseException:
    receipt['status'] = 'rolling-back' if backup else 'preflight-failed'
    errors = []
    if backup:
        try:
            restore()
        except BaseException as error:
            errors.append(type(error).__name__)
    if receipt['status'] == 'rolling-back':
        receipt['status'] = 'rollback-needs-attention' if errors else 'rolled-back'
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
