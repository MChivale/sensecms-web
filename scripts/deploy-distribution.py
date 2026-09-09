"""Guarded, opt-in deployment for the official distribution host only."""
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import urllib.request

qa = Path('/root/sense-workspace-test.SC495Gg0')
candidate = qa / 'candidate-038'
analytics = '--analytics' in sys.argv[1:]
calendar = '--calendar' in sys.argv[1:]
google_calendar = '--google-calendar' in sys.argv[1:]
microsoft_calendar = '--microsoft-calendar' in sys.argv[1:]
apple_calendar = '--apple-calendar' in sys.argv[1:]
telegram = '--telegram' in sys.argv[1:]
if sum([analytics, calendar, google_calendar, microsoft_calendar, apple_calendar, telegram]) > 1:
    raise SystemExit('Publish one package at a time')
args = [arg for arg in sys.argv[1:] if arg not in ['--calendar','--analytics','--google-calendar','--microsoft-calendar','--apple-calendar','--telegram']]
production = args == ['--production']
if not production and args != ['--qa']:
    raise SystemExit('Choose --qa or --production, optionally --calendar')
web = Path('/home/sensecms.com/web') if production else qa / '.cms/source'
base = 'https://www.sensecms.com' if production else 'http://127.0.0.1:8873'
if calendar or analytics or google_calendar or microsoft_calendar or apple_calendar or telegram:
    kind = 'microsoft-calendar' if microsoft_calendar else ('google-calendar' if google_calendar else ('analytics' if analytics else 'calendar'))
    identity = 'plugin:microsoft-365-calendar' if microsoft_calendar else ('plugin:google-calendar' if google_calendar else ('plugin:google-analytics' if analytics else 'addon:calendar'))
    if apple_calendar:
        kind, identity = 'apple-calendar', 'plugin:apple-calendar'
    if telegram:
        kind, identity = 'telegram', 'plugin:telegram-notifications'
    os.umask(0o077)
    lock = Path('/root/sensecms-private/deploy-workspace.lock').open('a')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    config = web / 'storage/distribution.json'
    before = config.read_bytes()
    cfg = json.loads(before)
    baseline = {'theme:sensecms', 'addon:calendar', 'plugin:google-analytics'} if analytics or google_calendar else {'theme:sensecms'}
    if microsoft_calendar:
        baseline = {'theme:sensecms', 'addon:calendar', 'plugin:google-analytics', 'plugin:google-calendar'}
    if apple_calendar:
        baseline = {'theme:sensecms', 'addon:calendar', 'plugin:google-analytics', 'plugin:google-calendar', 'plugin:microsoft-365-calendar'}
    if telegram:
        baseline = {'theme:sensecms', 'addon:calendar', 'plugin:google-analytics', 'plugin:google-calendar', 'plugin:microsoft-365-calendar', 'plugin:apple-calendar'}
    if cfg.get('enabled') is not True or set(cfg['products']) != baseline:
        raise RuntimeError('Unexpected distribution inventory')
    if analytics and (cfg['products'][identity]['version'] != '0.1.0' or cfg['products'][identity]['sha256'] != 'd181bfb752d30b08305e5fa047dea69ebc43ac4e9b039ea9eafdc02ed582eb36'):
        raise RuntimeError('Analytics update requires the exact 0.1.0 baseline')
    archive = Path('/root/sensecms-private/packages-20260907/addon-calendar-0.1.0.zip')
    entry = {'enabled': True, 'type': 'addon', 'slug': 'calendar', 'version': '0.1.0',
             'channel': 'development', 'file': archive.name, 'bytes': 69475,
             'sha256': '329c9fc0f98594e35537735865a3df1d8994406c20418d648a02815a8cdacbdd',
             'pricing': 'paid', 'license': {'product_name': 'Sense CMS Calendar',
                                          'product_model': 'Sense CMS Calendar Addon'}}
    if analytics:
        archive = candidate / 'plugin-google-analytics-0.1.1.zip'
        entry = {'enabled': True, 'type': 'plugin', 'slug': 'google-analytics', 'version': '0.1.1',
                 'channel': 'development', 'file': archive.name, 'bytes': archive.stat().st_size,
                 'sha256': hashlib.sha256(archive.read_bytes()).hexdigest(), 'pricing': 'free'}
    if google_calendar:
        archive = candidate / 'google-calendar-accepted-build/plugin-google-calendar-0.1.0.zip'
        entry = {'enabled': True, 'type': 'plugin', 'slug': 'google-calendar', 'version': '0.1.0',
                 'channel': 'development', 'file': archive.name, 'bytes': 3674,
                 'sha256': '1e207473613b24c85ca05be034ba3cefaf5bb475bf91b8df358ad83533703302',
                 'pricing': 'paid', 'license': {'product_name': 'Sense CMS Google Calendar',
                                              'product_model': 'Sense CMS Google Calendar Plugin'}}
    if microsoft_calendar:
        archive = candidate / 'plugin-microsoft-365-calendar-0.1.0.zip'
        entry = {'enabled': True, 'type': 'plugin', 'slug': 'microsoft-365-calendar', 'version': '0.1.0',
                 'channel': 'development', 'file': archive.name, 'bytes': 3850,
                 'sha256': '04b05f105c2b8b9e8b33b68f0542675d9a9daeb7898d816fefeb598e99be92fa',
                 'pricing': 'paid', 'license': {'product_name': 'Sense CMS Microsoft 365 Calendar',
                                              'product_model': 'Sense CMS Microsoft 365 Calendar Plugin'}}
    if apple_calendar:
        archive = candidate / 'plugin-apple-calendar-0.1.0.zip'
        entry = {'enabled': True, 'type': 'plugin', 'slug': 'apple-calendar', 'version': '0.1.0',
                 'channel': 'development', 'file': archive.name, 'bytes': 4907,
                 'sha256': 'f260b021bc3dd86cc94dcfdb9ec772363457db52f32fd20f28638691b2b5e69c',
                 'pricing': 'paid', 'license': {'product_name': 'Sense CMS Apple Calendar',
                                              'product_model': 'Sense CMS Apple Calendar Plugin'}}
    if telegram:
        archive = candidate / 'plugin-telegram-notifications-0.1.1.zip'
        entry = {'enabled': True, 'type': 'plugin', 'slug': 'telegram-notifications', 'version': '0.1.1',
                 'channel': 'development', 'file': archive.name, 'bytes': archive.stat().st_size,
                 'sha256': hashlib.sha256(archive.read_bytes()).hexdigest(), 'pricing': 'paid',
                 'license': {'product_name': 'Sense CMS Telegram Notifications', 'product_model': 'Sense CMS Telegram Notifications Plugin'}}
    if archive.stat().st_size != entry['bytes'] or hashlib.sha256(archive.read_bytes()).hexdigest() != entry['sha256']:
        raise RuntimeError('Calendar archive changed')
    def calendar_php(code, *values):
        command = ['php8.5', '-r', 'require $argv[1]."/bootstrap.php";' + code, str(web), *map(str, values)]
        result = subprocess.run(command, capture_output=True)
        if result.returncode:
            raise RuntimeError('Calendar verification failed; private output withheld')
        return result.stdout
    calendar_php('$keys=array_map(fn($k)=>base64_decode($k,true),json_decode(file_get_contents("/root/sensecms-private/trust.json"),true));$m=App\\Core\\Packages\\Archive::verify($argv[2],$keys);if(App\\Core\\Packages\\Manifest::identity($m)!==$argv[3]||$m["version"]!==$argv[4])throw new RuntimeException("Identity mismatch");', archive, identity, entry['version'])
    receipt = {'entry': entry, 'core': {name: hashlib.sha256((web / name).read_bytes()).hexdigest() for name in
               ['public/index.php', 'app/Core/Packages/Distribution.php', 'app/Http/DistributionController.php', 'app/Core/LicenseClient.php', 'app/Core/Packages/Entitlement.php']}}
    if production and json.loads((candidate / (kind + ('-011' if analytics else '') + '-accepted.json')).read_text()) != receipt:
        raise RuntimeError('Production calendar requires exact accepted QA configuration and Core')
    backup = (Path('/root/sensecms-backups') if production else qa) / (datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + kind + '-distribution')
    backup.mkdir(mode=0o700)
    (backup / 'distribution-before.json').write_bytes(before)
    (backup / 'candidate.json').write_text(json.dumps(receipt))
    destination = web / 'storage/distribution/releases' / archive.name
    with destination.open('xb') as handle:
        handle.write(archive.read_bytes())
    if production:
        shutil.chown(destination, user='sensecms', group='sensecms')
    cfg['products'][identity] = entry
    try:
        calendar_php('(new App\\Core\\Runtime($argv[1]))->write("distribution",json_decode($argv[2],true));', json.dumps(cfg))
        if production:
            shutil.chown(config, user='sensecms', group='sensecms')
        calendar_php('(new App\\Core\\Packages\\Distribution(new App\\Core\\Runtime($argv[1])))->archive($argv[2]);', identity)
        with urllib.request.urlopen(base + '/packages/download', timeout=20) as response:
            if set(json.load(response)['products']) != baseline | {identity}:
                raise RuntimeError('Calendar offer not visible')
    except BaseException:
        calendar_php('(new App\\Core\\Runtime($argv[1]))->write("distribution",json_decode($argv[2],true));', before.decode())
        if production:
            shutil.chown(config, user='sensecms', group='sensecms')
        raise
    print(json.dumps({'environment': 'production' if production else 'qa', 'backup': str(backup), kind: entry}))
    raise SystemExit(0)
names = ['public/index.php', 'app/Core/Packages/Distribution.php', 'app/Http/DistributionController.php']
inventory = {name: hashlib.sha256((candidate / '.cms/source' / name).read_bytes()).hexdigest() for name in names}
inventory['theme'] = hashlib.sha256(b''.join(p.read_bytes() for p in sorted((candidate / '.themes/sensecms').rglob('*')) if p.is_file())).hexdigest()
if production and json.loads((candidate / 'distribution-accepted.json').read_text()) != inventory:
    raise RuntimeError('Production requires the exact accepted QA candidate')
os.umask(0o077)
if (web / 'storage/distribution.json').exists():
    raise RuntimeError('Existing distribution configuration requires reviewed reconciliation')
if hashlib.sha256((web / names[0]).read_bytes()).hexdigest() != '03606265a097ebd6a16e0c75758ae102a8319fbd09cae954aff1661355cf1037':
    raise RuntimeError('Core baseline changed')
if any((web / name).exists() for name in names[1:]):
    raise RuntimeError('New controller or service already exists')

def run(args):
    result = subprocess.run(list(map(str, args)), capture_output=True)
    if result.returncode:
        raise RuntimeError('Private command failed: ' + str(args[0]))
    return result.stdout

def php(code, *args):
    return run(['php8.5', '-r', 'require $argv[1]."/bootstrap.php";' + code, web, *args])

for name in names:
    run(['php8.5', '-l', candidate / '.cms/source' / name])
backup = (Path('/root/sensecms-backups') if production else qa) / (datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-distribution')
backup.mkdir(mode=0o700)
shutil.copy2(web / names[0], backup / 'index-before.php')
(backup / 'inventory.json').write_text(json.dumps(inventory))
releases = web / 'storage/distribution/releases'
releases.mkdir(parents=True, mode=0o700)
archive = releases / 'theme-sensecms-0.3.8.zip'
php('$secret=base64_decode(trim(file_get_contents("/root/sensecms-private/publisher.ed25519")),true);try{App\\Core\\Packages\\Archive::build($argv[2],$argv[3],$secret);}finally{sodium_memzero($secret);}', candidate / '.themes/sensecms', archive)
trust = json.loads(Path('/root/sensecms-private/trust.json').read_text())
cfg = {'enabled': True, 'publishers': trust, 'products': {'theme:sensecms': {
    'enabled': True, 'type': 'theme', 'slug': 'sensecms', 'version': '0.3.8', 'channel': 'development',
    'file': archive.name, 'sha256': hashlib.sha256(archive.read_bytes()).hexdigest(), 'bytes': archive.stat().st_size, 'pricing': 'free',
}}}
changed = []
try:
    for name in names[1:] + names[:1]:
        dest = web / name
        temp = dest.with_name(dest.name + '.distribution-deploy')
        with temp.open('xb') as handle:
            handle.write((candidate / '.cms/source' / name).read_bytes())
        temp.chmod(0o644)
        os.replace(temp, dest)
        changed.append(name)
    php('$r=new App\\Core\\Runtime($argv[1]);$r->write("distribution",json_decode($argv[2],true));', json.dumps(cfg))
    if production:
        run(['chown', '-R', 'sensecms:sensecms', web / 'storage/distribution'])
        run(['chown', 'sensecms:sensecms', web / 'storage/distribution.json'])
    php('$r=new App\\Core\\Runtime($argv[1]);(new App\\Core\\Packages\\Distribution($r))->archive("theme:sensecms");')
    with urllib.request.urlopen(base + '/packages/download', timeout=20) as response:
        data = json.load(response)
        if not data.get('ok') or len(data['products']) != 1:
            raise RuntimeError('Distribution health check failed')
except BaseException:
    for name in changed:
        if name == names[0]:
            shutil.copy2(backup / 'index-before.php', web / name)
        else:
            (web / name).unlink()
    (web / 'storage/distribution.json').unlink(missing_ok=True)
    raise
print(json.dumps({'environment': 'production' if production else 'qa', 'backup': str(backup), 'offer': cfg['products']['theme:sensecms']}))
