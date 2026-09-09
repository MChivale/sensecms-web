"""One-time, checksum-guarded licensing update from the established private QA fixture."""
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import subprocess
import urllib.request

web = Path('/home/sensecms.com/web')
qa = Path('/root/sense-workspace-test.SC495Gg0/.cms/source')
before = {
    'app/Core/LicenseClient.php': '5037492f913f8c13c5b24c56976ddb42aeb56f9cf58edf28a711d2b716b884f6',
    'app/Core/PackageManager.php': '9be675c521cb9967bb15b3e3551ccdceea6630c0e49cc5363880852fd96c8473',
    'app/Core/Packages/Entitlement.php': None,
}
os.umask(0o077)
lock = Path('/root/sensecms-private/deploy-workspace.lock').open('a')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)


def run(args):
    result = subprocess.run(args, capture_output=True)
    if result.returncode:
        raise RuntimeError('Deployment verification command failed: ' + args[0])


def atomic(path, data):
    if path.is_symlink():
        raise RuntimeError('Refusing symbolic link')
    tmp = path.with_name(path.name + '.license-deploy')
    created = False
    try:
        with tmp.open('xb') as handle:
            created = True
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(tmp, 0o644)
        os.replace(tmp, path)
    finally:
        if created and tmp.is_file():
            tmp.unlink()


def health():
    for path in ['/', '/platform', '/docs/licensing', '/download', '/login']:
        with urllib.request.urlopen('https://www.sensecms.com' + path, timeout=20) as res:
            if res.status != 200 or not res.read(200000):
                raise RuntimeError('HTTP health check failed')


original = {}
candidate = {}
for name, expected in before.items():
    target = web / name
    if target.is_symlink():
        raise RuntimeError('Invalid deployment target')
    original[name] = target.read_bytes() if target.exists() else None
    actual = hashlib.sha256(original[name]).hexdigest() if original[name] is not None else None
    if actual != expected:
        raise RuntimeError('Production baseline changed; re-review required: ' + name)
    candidate[name] = (qa / name).read_bytes()
    run(['php8.5', '-l', str(qa / name)])

health()
backup = Path('/root/sensecms-backups') / (datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-license-policy')
backup.mkdir(mode=0o700)
for name, data in original.items():
    if data is not None:
        target = backup / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
inventory = {name: hashlib.sha256(data).hexdigest() for name, data in candidate.items()}
(backup / 'deployment.json').write_text(json.dumps({'before': before, 'after': inventory}, indent=2))

try:
    # Publish dependency first; each file replacement is atomic. No DB/storage changes.
    for name in reversed(before):
        atomic(web / name, candidate[name])
    for name, expected in inventory.items():
        if hashlib.sha256((web / name).read_bytes()).hexdigest() != expected:
            raise RuntimeError('Deployed checksum differs')
        run(['php8.5', '-l', str(web / name)])
    # Graceful reload ensures FPM workers do not retain stale opcode cache.
    run(['systemctl', 'reload', 'php8.5-fpm'])
    run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb'])
    health()
except BaseException:
    for name, data in original.items():
        if data is not None:
            atomic(web / name, data)
    # A new, unused class is harmless; retain it for inspection after rollback.
    run(['systemctl', 'reload', 'php8.5-fpm'])
    raise
print(json.dumps({'backup': str(backup), 'sha256': inventory, 'health': 'passed'}))
