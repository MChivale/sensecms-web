"""Reviewed, one-time product-site cutover. Preserve all runtime data and retain rollback backups."""
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import subprocess
import time
import urllib.request
import zipfile

web = Path('/home/sensecms.com/web')
qa = Path('/root/sense-workspace-test.SC495Gg0')
archive = qa / 'sensecms-install-0.1.0-workspace-dev.4.zip'
expected_hash = 'e33dcd105bb5b62f2370809d690a4e4cc86efee5f7a1d05ceaa25a810b726900'
changed = ['app/Core/CmsRepository.php','app/Core/PublicPagePath.php','app/Core/PublicTheme.php',
           'app/Http/DashboardController.php','app/Http/PublicController.php','app/Views/console-content-page-form.php',
           'app/Views/console-content-pages.php','app/Views/console-page-builder.php','app/workspace.php',
           'database/workspace/029_public_page_paths.sql','public/index.php']
os.umask(0o077)
lock = Path('/root/sensecms-private/deploy-workspace.lock').open('a')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

def run(args, **kwargs):
    result = subprocess.run(args, capture_output=True, **kwargs)
    if result.returncode:
        raise RuntimeError('Command failed: ' + args[0] + ' (private details retained by operator)')
    return result.stdout

def atomic(path, data):
    if path.is_symlink() or any(parent.is_symlink() for parent in path.parents):
        raise RuntimeError('Unexpected deployment symlink')
    temp = path.with_name(path.name + '.product-new')
    with temp.open('xb') as output:
        output.write(data)
    temp.chmod(0o644)
    os.replace(temp, path)

if web.resolve() != web or hashlib.sha256(archive.read_bytes()).hexdigest() != expected_hash:
    raise RuntimeError('Unexpected installation or candidate digest')
state = json.loads((web / 'storage/theme.json').read_text())
if state['active']['version'] != '0.1.0' or state['active']['slug'] != 'sensecms':
    raise RuntimeError('Already deployed or active theme changed; inspect before continuing')
with zipfile.ZipFile(archive) as package:
    candidate = {name: package.read(name) for name in changed}
for name, data in candidate.items():
    if name.endswith('.php'):
        run(['php', '-l'], input=data)
    if data != (qa / '.cms/source' / name).read_bytes():
        raise RuntimeError('QA source no longer matches the reviewed candidate: ' + name)
for file, marker in [('product-http-checks.log', 'Completed 312'), ('managed-product-checks.log', 'Completed 92'),
                     ('product-migration-checks.log', 'Completed 109')]:
    if marker not in (qa / file).read_text():
        raise RuntimeError('Missing acceptance evidence: ' + file)

stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
backup = Path('/root/sensecms-backups') / (stamp + '-product-pages')
backup.mkdir(mode=0o700)
run(['tar','-czf',str(backup / 'web-before.tgz'),'-C',str(web.parent),'web'])
if b'web/storage/installed.json' not in run(['tar','-tzf',str(backup / 'web-before.tgz')]):
    raise RuntimeError('Private storage backup missing')
original = {name: (web / name).read_bytes() if (web / name).exists() else None for name in changed}
front = web / 'public/index.php'
atomic(front, b'<?php http_response_code(503); header("Retry-After: 30"); header("Cache-Control: no-store"); echo "Sense CMS update. Please retry shortly.";')
time.sleep(3)
try:
    with (backup / 'database-before.sql').open('wb') as output:
        result = subprocess.run(['mariadb-dump','--single-transaction','--skip-lock-tables','--hex-blob','--no-tablespaces','sensecms_site'], stdout=output, stderr=subprocess.PIPE)
        if result.returncode or output.tell() < 1000:
            raise RuntimeError('Production database backup failed')
    migration = 'database/workspace/029_public_page_paths.sql'
    atomic(web / migration, candidate[migration])
    run(['php',str(web / 'scripts/migrate-workspace.php')])
    for name in changed:
        if name not in [migration, 'public/index.php']:
            atomic(web / name, candidate[name])
    # Only the protected operator process reads the production signing key.
    sign = r'''
    require $argv[1] . '/bootstrap.php';
    $secret = base64_decode(trim(file_get_contents('/root/sensecms-private/publisher.ed25519')), true);
    try { App\Core\Packages\Archive::build($argv[2], $argv[3], $secret); }
    finally { sodium_memzero($secret); }
    $keys = array_map(fn($value)=>base64_decode($value,true), json_decode(file_get_contents('/root/sensecms-private/trust.json'),true));
    App\Core\Packages\Archive::verify($argv[3], $keys);
    '''
    signed = backup / 'sensecms-theme-0.1.6.zip'
    run(['php','-r',sign,str(web),str(qa / '.themes/sensecms'),str(signed)])
    run(['php',str(web / 'scripts/theme.php'),'install',str(signed),'/root/sensecms-private/trust.json'])
    imported = run(['php',str(qa / 'scripts/publish-product-pages.php'),str(web),'--publish'])
    (backup / 'import.log').write_bytes(imported)
    # PHP-FPM must be able to read the new private release; preserve private mode.
    run(['chown','-R','sensecms:sensecms',str(web / 'storage/themes')])
    run(['chown','sensecms:sensecms',str(web / 'storage/theme.json'),str(web / 'storage/themes.lock')])
    atomic(front, candidate['public/index.php'])
    time.sleep(3)
    for route in ['/', '/platform', '/extensions/themes', '/docs/themes', '/contact', '/theme-assets/site.css']:
        with urllib.request.urlopen('https://www.sensecms.com' + route, timeout=20) as response:
            if response.status != 200:
                raise RuntimeError('Post-deployment route failed: ' + route)
    (backup / 'candidate.sha256').write_text(expected_hash + '\n')
    (backup / 'deployed-files.json').write_text(json.dumps({name:hashlib.sha256(data).hexdigest() for name,data in candidate.items()},indent=2))
    print('DEPLOYED product theme 0.1.6 and managed pages; backup: ' + str(backup))
except Exception:
    # Stop serving a partially changed theme. Keep additive schema/journal, private files and new pages for recovery.
    # The previous source ignores public_path; the old theme renders its original static pages.
    atomic(web / 'storage/theme.json', json.dumps(state).encode())
    os.chmod(web / 'storage/theme.json', 0o600)
    run(['chown','sensecms:sensecms',str(web / 'storage/theme.json')])
    for name, data in original.items():
        if data is not None and name != 'public/index.php':
            atomic(web / name, data)
    atomic(front, original['public/index.php'])
    print('Previous presentation restored; inspect retained backup: ' + str(backup))
    raise
