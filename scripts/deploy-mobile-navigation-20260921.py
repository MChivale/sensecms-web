#!/usr/bin/env python3
"""Deploy the Core responsive administration navigation fix."""
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
import urllib.request

if os.name == 'nt' or os.geteuid() != 0 or len(os.sys.argv) != 2:
    raise SystemExit('Run as root on Linux: deploy-mobile-navigation-20260921.py <private-stage>')

os.umask(0o077)
stage = Path(os.sys.argv[1]).resolve()
web = Path('/home/sensecms.com/web')
files = [
    'app/Views/console.php',
    'public/theme/sensecms-shell.css',
    'public/theme/sensecms-shell.js',
]
baseline = {
    'app/Views/console.php': '2d7112e53640d03bd170905080f562cc1b8072c78761ed59caa8c0dc13944c85',
    'public/theme/sensecms-shell.css': 'b97cbd4054e63f0f8b463060fb74304ef5954447c0e129213001c037f3abc649',
    'public/theme/sensecms-shell.js': '2152bf61becb5366fb96a24c6f208e94f8e304678ab7d67dc685f37ebffadc29',
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


def request(path):
    with urllib.request.urlopen('https://www.sensecms.com' + path, timeout=30) as response:
        return response.status, response.read(), response.headers


required = [stage / '.cms/source' / path for path in files]
check(stage.is_dir() and str(stage).startswith('/root/sense-mobile-navigation-'), 'Private scoped deployment stage')
check(all(path.is_file() and not path.is_symlink() for path in required), 'Complete responsive-navigation payload')
check(all((web / path).is_file() and sha(web / path) == expected for path, expected in baseline.items()), 'Expected production Core baseline')
run(['php8.5', '-l', str(stage / '.cms/source/app/Views/console.php')])
node = shutil.which('node')
if node:
    run([node, '--check', str(stage / '.cms/source/public/theme/sensecms-shell.js')])
css = (stage / '.cms/source/public/theme/sensecms-shell.css').read_text()
script = (stage / '.cms/source/public/theme/sensecms-shell.js').read_text()
view = (stage / '.cms/source/app/Views/console.php').read_text()
check('z-index:105!important' in css and '.sensecms-shell-backdrop{position:fixed;z-index:95' in css, 'Navigation renders above its dimming layer')
check('height:100dvh!important' in css and 'touch-action:pan-y' in css and 'html.sidenav-enable body{overflow:hidden}' in css, 'Independent menu scrolling and background scroll lock')
check('html.sidenav-enable [data-menu-backdrop]{inset-inline-start:' in css and '.sensecms-menu-header-action button{display:grid!important' in css, 'Backdrop excludes the visible menu and close control remains available')
check('menu.inert = mobile && !open' in script and "event.key === 'Escape'" in script and 'menuReturnFocus.focus()' in script, 'Keyboard and focus accessibility contract')
check('sensecms-shell.css?v=20260921-mobile-menu-2' in view and 'sensecms-shell.js?v=20260921-mobile-menu-2' in view, 'Versioned responsive-navigation assets')
run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb'])
run(['nginx', '-t'])

lock = Path('/root/sensecms-private/deploy-workspace.lock').open('a+b')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
try:
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    token = hashlib.sha256(stamp.encode()).hexdigest()[:12]
    backup = Path('/root/sensecms-backups') / (stamp + '-mobile-navigation')
    backup.mkdir(mode=0o700)
    receipt['backup'] = str(backup)
    for relative in files:
        source, target, previous = stage / '.cms/source' / relative, web / relative, backup / relative
        previous.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(target, previous)
        stat = target.stat()
        temporary = target.with_name(target.name + '.deploy-' + token)
        shutil.copy2(source, temporary)
        os.chown(temporary, stat.st_uid, stat.st_gid)
        os.chmod(temporary, stat.st_mode & 0o777)
        os.replace(temporary, target)
        deployed.append(relative)
        check(sha(target) == sha(source), 'Deployed checksum ' + relative)

    for route, relative in [
        ('/theme/sensecms-shell.css?v=20260921-mobile-menu-2', files[1]),
        ('/theme/sensecms-shell.js?v=20260921-mobile-menu-2', files[2]),
    ]:
        status, body, _ = request(route)
        check(status == 200 and hashlib.sha256(body).hexdigest() == sha(web / relative), 'HTTP asset checksum ' + relative)
    run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb'])
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
