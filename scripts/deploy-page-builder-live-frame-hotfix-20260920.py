#!/usr/bin/env python3
"""Allow same-origin framing only for the authenticated Core Live Canvas route."""
from __future__ import annotations

import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import time

if os.name == 'nt' or os.geteuid() != 0 or len(sys.argv) != 2:
    raise SystemExit('Run as root on Linux: deploy-page-builder-live-frame-hotfix-20260920.py <private-stage>')

os.umask(0o077)
stage = Path(sys.argv[1]).resolve()
web = Path('/home/sensecms.com/web')
source = stage / '.cms/source/public/index.php'
target = web / 'public/index.php'
baseline = 'b4a4e5d704db02973cbcbd04198e98021cb59c395f8885f36e78afb57f643cf7'
live_canvas = {
    'app/Core/PageBuilder.php': '10f44c834cd87a64abf229afdc2a09e77c617823ef1b48895e6a1669b0f6bfd5',
    'app/Http/PublicController.php': 'd338d8d879815d19b6c01ba1d5bdee46bf0d7fc2811019d388907da24da07013',
    'app/workspace.php': 'c2f97c5d42c3da0506844daee0dd55be5b3a3d2403eefb3045485a26503bb7df',
    'app/Views/console-page-builder.php': 'bb9da1e064694a29338574c3d7ac0f2a34a9d8a18b5c760aa94a1ede4b5ac467',
    'app/Views/console.php': '33f8f0851ff4d3439d5e448230d4c04c8fd55765cab79b952c098a1043c5d862',
    'public/theme/sensecms-content-management.js': '5e31ef6abb93d698cb89de1350e46012bd0ce18b0d32b6f9b9edf9b40c580b69',
    'public/theme/sensecms-page-builder.css': 'bc71fd9cb3d39d7c46f1e3c4f0d96f12a1f6064764d112476f80db4702ba2072',
    'public/theme/sensecms-page-builder.js': 'd1d6c446ec29ea6ca7ab250388a2006dc48794609cef02f8b798d765d74a4095',
    'public/theme/sensecms-builder-preview.css': 'c169a60d3b1a353ec39acdc26e8902ca1adaadaa2897d7d2fe8e3a345184d3ea',
    'public/theme/sensecms-builder-preview.js': '6d03a06f5034750e30d23b7f36c3b85b59797d69c5b9e8fcd4c606df99964805',
}
checks: list[str] = []


def run(command):
    result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if result.returncode:
        raise RuntimeError(result.stdout.decode(errors='replace').strip() or f'Command failed: {command[0]}')
    return result.stdout


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def check(ok: bool, label: str) -> None:
    if not ok:
        raise RuntimeError(label)
    checks.append(label)
    print('PASS ' + label, flush=True)


check(stage.is_dir() and str(stage).startswith('/root/sense-builder-live-'), 'Private scoped deployment stage')
check(source.is_file() and not source.is_symlink(), 'Complete frame-policy hotfix payload')
check(target.is_file() and not target.is_symlink() and sha(target) == baseline, 'Pinned production front-controller baseline')
check(all((web / relative).is_file() and sha(web / relative) == expected for relative, expected in live_canvas.items()), 'Live Canvas deployment remains exact')
active = json.loads(run(['php8.5', '-r', f'require {json.dumps(str(web / "bootstrap.php"))};$r=new App\\Core\\Runtime($argv[1]);echo json_encode((new App\\Core\\Packages\\ThemeManager($r))->active(),JSON_THROW_ON_ERROR);', str(web)]))
check(active['slug'] == 'sensecms' and active['version'] == '1.0.12', 'Signed theme 1.0.12 remains active')
run(['php8.5', '-l', str(source)])
check(b'255 builder preset/compatibility checks passed' in run(['php8.5', str(stage / 'tests/builder-defaults.php')]), 'Frame policy and Live Canvas regressions')
run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb', 'cron'])
run(['nginx', '-t'])

lock = Path('/root/sensecms-private/deploy-workspace.lock').open('a+b')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
backup = Path('/root/sensecms-backups') / (datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-page-builder-live-frame')
backup.mkdir(mode=0o700)
shutil.copy2(target, backup / 'public-index.php')
stat = target.stat()
deployed = False
try:
    tmp = target.with_name(target.name + '.deploy-live-frame')
    shutil.copy2(source, tmp)
    os.chown(tmp, stat.st_uid, stat.st_gid)
    os.chmod(tmp, stat.st_mode & 0o777)
    os.replace(tmp, target)
    deployed = True
    check(sha(target) == sha(source), 'Deployed front-controller checksum')
    time.sleep(3)
    check(b'PASS Production Core Page Builder Live Canvas' in run(['python3', str(stage / 'tests/page-builder-ui-production.py')]), 'Authenticated production Live Canvas frame acceptance')
    run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb', 'cron'])
    run(['nginx', '-t'])
    errors = run(['journalctl', '--since', '@' + str(int(datetime.datetime.now(datetime.timezone.utc).timestamp()) - 120), '-u', 'nginx', '-u', 'php8.5-fpm', '--no-pager', '-p', 'err'])
    check(b'No entries' in errors or not errors.strip(), 'No fresh critical service errors')
    print(json.dumps({'status': 'deployed', 'backup': str(backup), 'sha256': sha(target), 'checks': checks}, sort_keys=True))
except Exception:
    if deployed:
        shutil.copy2(backup / 'public-index.php', target)
        os.chown(target, stat.st_uid, stat.st_gid)
        os.chmod(target, stat.st_mode & 0o777)
    raise
finally:
    fcntl.flock(lock, fcntl.LOCK_UN)
    lock.close()
