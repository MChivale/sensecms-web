#!/usr/bin/env python3
"""Enable the exact signed Bluesky Publisher archive as a separately licensed offer."""
from __future__ import annotations

import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys

if os.name == 'nt' or os.geteuid() != 0 or len(sys.argv) != 2:
    raise SystemExit('Run as root on Linux: deploy-bluesky-distribution.py <private-stage>')

stage = Path(sys.argv[1]).resolve()
web = Path('/home/sensecms.com/web')
store = web / 'storage/distribution.json'
releases = web / 'storage/distribution/releases'
source = stage / 'artifacts/plugin-bluesky-publisher-0.1.0.zip'
target = releases / source.name
identity = 'plugin:bluesky-publisher'
stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
backup = Path('/root/sensecms-backups') / (stamp + '-bluesky-distribution-010')


def run(command):
    result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if result.returncode:
        raise RuntimeError(result.stdout.decode(errors='replace').strip() or 'Command failed.')
    return result.stdout


def atomic_bytes(path, payload, uid, gid, mode):
    temporary = path.with_name(path.name + '.bluesky-new')
    temporary.write_bytes(payload)
    os.chown(temporary, uid, gid)
    os.chmod(temporary, mode)
    os.replace(temporary, path)


if not stage.is_dir() or not str(stage).startswith('/root/sense-bluesky-publisher-') or not source.is_file():
    raise RuntimeError('Private scoped Bluesky release stage required.')
if not store.is_file() or not releases.is_dir() or target.exists():
    raise RuntimeError('Unexpected distribution baseline.')
config = json.loads(store.read_text())
if identity in config.get('products', {}):
    raise RuntimeError('Bluesky distribution offer already exists.')
verify = r'''$r=$argv[1];require$r.'/bootstrap.php';$cfg=json_decode(file_get_contents($r.'/storage/distribution.json'),true,32,JSON_THROW_ON_ERROR);$keys=array_map(static fn($key)=>base64_decode($key,true),$cfg['publishers']??[]);$m=App\Core\Packages\Archive::verify($argv[2],$keys);echo json_encode($m,JSON_THROW_ON_ERROR);'''
manifest = json.loads(run(['php8.5', '-r', verify, str(web), str(source)]))
if manifest.get('type') != 'plugin' or manifest.get('slug') != 'bluesky-publisher' or manifest.get('version') != '0.1.0':
    raise RuntimeError('Signed Bluesky archive identity mismatch.')

backup.mkdir(mode=0o700)
shutil.copy2(store, backup / 'distribution-before.json')
receipt = {'status': 'preflight', 'backup': str(backup)}
try:
    stat = store.stat()
    payload = source.read_bytes()
    digest = hashlib.sha256(payload).hexdigest()
    atomic_bytes(target, payload, stat.st_uid, stat.st_gid, 0o640)
    config['products'][identity] = {
        'type': 'plugin',
        'slug': 'bluesky-publisher',
        'version': '0.1.0',
        'pricing': 'paid',
        'license': {
            'product_name': 'Sense CMS Bluesky Publisher Plugin',
            'product_model': 'Bluesky Publisher Plugin',
        },
        'sha256': digest,
        'bytes': len(payload),
        'channel': 'development',
        'file': target.name,
        'enabled': True,
    }
    encoded = (json.dumps(config, separators=(',', ':')) + '\n').encode()
    atomic_bytes(store, encoded, stat.st_uid, stat.st_gid, stat.st_mode & 0o777)
    check = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);echo json_encode((new App\Core\Packages\Distribution($x))->entry('plugin:bluesky-publisher'),JSON_THROW_ON_ERROR);'''
    offer = json.loads(run(['sudo', '-u', 'sensecms', 'php8.5', '-r', check, str(web)]))
    if offer.get('sha256') != digest or offer.get('pricing') != 'paid' or offer.get('license', {}).get('product_name') != 'Sense CMS Bluesky Publisher Plugin':
        raise RuntimeError('Bluesky distribution verification failed.')
    receipt.update({'status': 'deployed', 'sha256': digest, 'bytes': len(payload)})
except BaseException:
    original = (backup / 'distribution-before.json').read_bytes()
    current = store.stat()
    atomic_bytes(store, original, current.st_uid, current.st_gid, current.st_mode & 0o777)
    if target.is_file() and target.parent == releases and target.name == 'plugin-bluesky-publisher-0.1.0.zip':
        target.unlink()
    receipt['status'] = 'rolled-back'
    raise
finally:
    (backup / 'receipt.json').write_text(json.dumps(receipt, indent=2, sort_keys=True) + '\n')
    os.chmod(backup / 'receipt.json', 0o600)

print(json.dumps(receipt, sort_keys=True))
