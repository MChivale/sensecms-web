"""Pinned 1.0 transition of two independent installations; operator-only, not an updater."""
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import urllib.request

stage = Path('/root/sense-release-1.0-eDoxsJCH')
candidate = stage / 'source/.cms/source'
private = Path('/root/sensecms-private')
sites = [('sensecms', 'sensecms_site', Path('/home/sensecms.com/web'), 'www.sensecms.com'),
         ('demo-sensecms', 'sensecms_demo', Path('/home/demo.sensecms.com/web'), 'demo.sensecms.com')]
core = ['app/Installer/Installer.php', 'app/Views/workspace.php', 'config/workspace.php', 'config/product.php']
order = ['plugin:google-analytics', 'plugin:google-calendar', 'plugin:microsoft-365-calendar',
         'plugin:apple-calendar', 'plugin:telegram-notifications', 'addon:calendar']
os.umask(0o077)
assert socket.gethostname() == 'ind' and sys.argv[1:] in (['--preflight'], ['--apply'])

def run(args, **kwargs):
    result = subprocess.run(list(map(str, args)), capture_output=True, **kwargs)
    if result.returncode:
        raise RuntimeError('Command failed; private output withheld: ' + str(args[0]))
    return result.stdout

def check(ok, message):
    if not ok:
        raise RuntimeError(message)
    print('PASS ' + message, flush=True)

def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

def atomic(path, data, stat):
    assert path.parent.resolve() == path.parent and not path.is_symlink()
    fd, name = tempfile.mkstemp(prefix='.' + path.name + '-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as handle:
            handle.write(data); handle.flush()
            os.fchown(handle.fileno(), stat.st_uid, stat.st_gid)
            os.fchmod(handle.fileno(), stat.st_mode & 0o777)
            os.fsync(handle.fileno())
        os.replace(name, path)
    finally:
        if os.path.exists(name): os.unlink(name)

def php(site, code, *args, data=None):
    user, _, root, _ = site
    return run(['runuser', '-u', user, '--', 'php8.5', '-r',
                'require $argv[1]."/bootstrap.php"; $r=new App\\Core\\Runtime($argv[1]); ' + code,
                root, *args], input=data)

def manager(site, code, *args):
    return php(site, '$db=App\\Core\\Runtime::connect($r->read("installed")["database"]); '
               '$p=require $argv[1]."/config/product.php"; '
               '$m=new App\\Core\\PackageManager($db,$argv[1],$p["core_version"]); ' + code, *args)

def invalidate(site):
    user, _, root, host = site
    account = pwd.getpwnam(user)
    files = core + [str(p.relative_to(root)) for branch in ['addons', 'plugins'] for p in (root / branch).rglob('*.php')]
    fd, name = tempfile.mkstemp(prefix='release-probe-', suffix='.php', dir=root / 'storage')
    try:
        code = '<?php $root=dirname(__DIR__);foreach(json_decode(' + repr(json.dumps(files)) + ',true) as $file){if(function_exists("opcache_invalidate"))opcache_invalidate($root."/".$file,true);} echo "release-cache-ok:".(require $root."/config/product.php")["core_version"];'
        with os.fdopen(fd, 'w') as handle: handle.write(code)
        os.chown(name, account.pw_uid, account.pw_gid); os.chmod(name, 0o600)
        env = dict(os.environ, SCRIPT_FILENAME=name, SCRIPT_NAME='/index.php', REQUEST_METHOD='GET',
                   REQUEST_URI='/index.php', SERVER_PROTOCOL='HTTP/1.1', GATEWAY_INTERFACE='CGI/1.1',
                   SERVER_NAME=host, HTTP_HOST=host, SERVER_PORT='443', HTTPS='on', REMOTE_ADDR='127.0.0.1')
        result = run(['cgi-fcgi', '-bind', '-connect', '/run/php/php8.5-' + user + '.sock'], env=env)
        check(b'release-cache-ok:' in result, 'Installation opcode cache refreshed: ' + host)
    finally: os.unlink(name)

def sql(db, query):
    return run(['mariadb', db, '--batch', '--skip-column-names'], input=query.encode())

snapshot_sql = 'SELECT id,email,password,active,is_demo,session_version FROM users ORDER BY id; SELECT * FROM user_roles ORDER BY user_id,role_id; SELECT * FROM role_permissions ORDER BY role_id,permission_id; SELECT * FROM settings ORDER BY `key`;'
snapshot_sql += ''.join('SELECT * FROM ' + table + ' ORDER BY id;' for table in ['pages','page_translations','menus','menu_items','menu_item_translations'])
inventory = json.loads((stage / 'packages/release-set.json').read_text())
products = {item['identity']: item for item in inventory['products']}
theme = products['theme:sensecms']
lock = (private / 'deploy-workspace.lock').open('a+b')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
check('39 signed release-transition checks passed.' in (stage / 'transition-tests.log').read_text(), 'Signed upgrade/rollback acceptance completed')
check('Completed 120 Workspace' in (stage / 'workspace-tests.log').read_text(), 'Workspace functional acceptance completed')
check('96 security regression checks passed' in (stage / 'security-tests.log').read_text(), 'MariaDB security acceptance completed')
check('29 maintenance checks passed' in (stage / 'maintenance-tests.log').read_text(), 'Concurrent lifecycle acceptance completed')
original = {}; metadata = {}; snapshots = {}; identities = {}; old_packages = {}
for site in sites:
    user, db, root, host = site
    check(root.resolve() == root and json.loads((root / 'storage/installed.json').read_text())['base_url'] == 'https://' + host, 'Exact isolated target: ' + host)
    for name in core:
        path = root / name
        check(path.is_file() and not path.is_symlink(), 'Existing Core target ' + host + '/' + name)
        original[str(path)] = path.read_bytes(); metadata[str(path)] = path.stat()
        run(['php8.5', '-l', candidate / name])
    version = php(site, 'echo (require $argv[1]."/config/product.php")["core_version"];').decode()
    check(version == '0.1.0', 'Expected Core 0.1.0 baseline: ' + host)
    snapshots[db] = sql(db, snapshot_sql)
    for name in ['installed.json','workspace.json','license/key.bin','license/license.lic']:
        path = root / 'storage' / name
        identities[str(path)] = sha(path)
    old_packages[db] = json.loads(manager(site, 'echo json_encode(array_values(array_filter($m->packages(),fn($p)=>$p["source"]==="package"&&$p["type"]!=="theme")));'))
check({p['type'] + ':' + p['slug'] for p in old_packages['sensecms_site']} == set(order), 'Official six optional packages explicitly scoped')
check(not old_packages['sensecms_demo'], 'Demo will not inherit official optional packages')
www = sites[0][2]; store = www / 'storage'; dist = store / 'distribution/releases'
state = json.loads((store / 'theme.json').read_text())
check(state['active']['version'] == '0.3.11' and len(state['releases']) == 1, 'Only reviewed previous theme installed')
config = json.loads((store / 'distribution.json').read_text())
check(set(config['products']) == set(products), 'All existing offers accounted for without adding products')
for identity, item in products.items():
    check(sha(stage / 'packages' / item['file']) == item['sha256'], 'Pinned package bytes: ' + identity)
    check(not (dist / item['file']).exists(), 'New immutable distribution path: ' + identity)
for entry in old_packages['sensecms_site']:
    check(entry['version'] == config['products'][entry['type'] + ':' + entry['slug']]['version'], 'Installed and offered baseline agrees: ' + entry['slug'])
if sys.argv[1:] == ['--preflight']:
    print('Preflight passed; no production changes.'); sys.exit(0)

backup = Path('/root/sensecms-backups') / (datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-release-1.0')
backup.mkdir(mode=0o700)
for site in sites:
    user, db, root, host = site
    folder = backup / host; folder.mkdir(mode=0o700)
    with (folder / 'database.sql').open('wb') as handle:
        proc = subprocess.run(['mariadb-dump', '--single-transaction', '--routines', '--triggers', db], stdout=handle, stderr=subprocess.PIPE)
        check(proc.returncode == 0, 'Private database backup: ' + host)
    for name in core:
        target = folder / name; target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        shutil.copy2(root / name, target)
    paths = [p for p in ['storage/theme.json','storage/themes','storage/distribution.json','storage/packages','addons','plugins'] if (root / p).exists()]
    if paths: run(['tar','-czf',folder / 'extensions-before.tgz','-C',root,*paths])
for name in ['theme.json','distribution.json']: shutil.copy2(store / name, backup / name)
notice_rows = sql('sensecms_site', "SELECT id,HEX(data) FROM content_block_translations WHERE data LIKE '%Available version:%' ORDER BY id;")
(backup / 'notices-before.tsv').write_bytes(notice_rows)
updated = []; new_files = []; changed_rows = []; new_theme = None; retired = []
logs = {p:p.stat().st_size for p in [Path('/var/log/nginx/sensecms.com.error.log'),Path('/var/log/nginx/demo.sensecms.com.error.log'),store/'php-error.log'] if p.exists()}
receipt = {'backup':str(backup),'status':'prepared','versions':{key:p['version'] for key,p in products.items()},'source':{name:sha(candidate/name) for name in core}}
(backup / 'receipt.json').write_text(json.dumps(receipt, indent=2))
try:
    for item in products.values():
        target = dist / item['file']; atomic(target, (stage/'packages'/item['file']).read_bytes(), (store/'distribution.json').stat()); new_files.append(target)
    for identity in order:
        item = products[identity]
        result = json.loads(manager(sites[0], '$s=$m->stageLocalFile($argv[2],1);echo json_encode($m->install($s["token"],1));', dist / item['file']))
        updated.append(identity)
        check(result['version'] == item['version'], 'Production signed package upgraded: ' + identity)
    new_theme = json.loads(manager(sites[0], '$s=$m->stageLocalFile($argv[2],1);$v=$m->install($s["token"],1);echo json_encode($m->themeManager()->activate($v["directory"],$m->trustedKeys()));', dist/theme['file']))
    for site in sites:
        for name in core:
            path = site[2] / name
            check(path.read_bytes() == original[str(path)], 'Unchanged deployment baseline: ' + site[3] + '/' + name)
            atomic(path, (candidate / name).read_bytes(), metadata[str(path)])
        invalidate(site)
        # installed.json records installation provenance; current version comes from product.php.
        check(php(site, 'echo (require $argv[1]."/config/product.php")["core_version"];') == b'1.0.0', 'Core 1.0.0 active: ' + site[3])
    new_config = json.loads(json.dumps(config))
    for identity, item in products.items():
        new_config['products'][identity].update({key:item[key] for key in ['version','file','sha256','bytes']})
    atomic(store/'distribution.json', json.dumps(new_config,separators=(',',':')).encode(), (store/'distribution.json').stat())
    for line in notice_rows.decode().splitlines():
        row_id, encoded = line.split('\t'); before = bytes.fromhex(encoded); after = before
        # Resolve the product's owning public page, not a global version-string replacement.
        path = sql('sensecms_site','SELECT p.public_path FROM content_block_translations t JOIN content_blocks b ON b.id=t.block_id JOIN pages p ON p.id=b.page_id WHERE t.id='+str(int(row_id))).decode().strip()
        for identity, item in products.items():
            kind, slug = identity.split(':')
            if path == '/extensions/catalog/' + kind + '/' + slug:
                old = config['products'][identity]['version']
                after = before.replace(('Available version: '+old+'.').encode(), ('Available version: '+item['version']+'.').encode())
        if before != after:
            result=sql('sensecms_site','UPDATE content_block_translations SET data=CONVERT(0x'+after.hex()+' USING utf8mb4) WHERE id='+str(int(row_id))+' AND BINARY data=0x'+before.hex()+'; SELECT ROW_COUNT();')
            check(result.strip()==b'1','Scoped public version notice updated: '+row_id)
            changed_rows.append((row_id,before,after))
    check(all(sql(site[1], snapshot_sql)==snapshots[site[1]] for site in sites), 'Both installations retain accounts, permissions, settings, pages and navigation')
    check(all(sha(Path(path))==digest for path,digest in identities.items()), 'Installation and encryption identities remain unchanged')
    for site in sites:
        for route in ['/','/login','/sensecms/images/sensecms-logo-email.png']:
            with urllib.request.urlopen('https://'+site[3]+route,timeout=30) as response: check(response.status==200,'Live health: '+site[3]+route)
        check(all(sha(site[2]/name)==sha(candidate/name) for name in core),'Deployed Core checksums: '+site[3])
    # Only one theme remains visible. The previous exact archive is recoverable outside runtime.
    theme_lock=(store/'themes.lock').open('r+b');fcntl.flock(theme_lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    try:
        live=json.loads((store/'theme.json').read_text());check(live['active']==new_theme,'Verified active theme before retirement')
        live.update(releases={new_theme['directory']:new_theme},previous=None)
        atomic(store/'theme.json',json.dumps(live,separators=(',',':')).encode(),(store/'theme.json').stat())
        old_dir=store/'themes'/state['active']['directory']
        check(old_dir.resolve().parent==store/'themes' and not old_dir.is_symlink(),'Scoped old theme retirement')
        old_dir.rename(backup/old_dir.name);retired.append((old_dir,backup/old_dir.name))
        old_file=dist/config['products']['theme:sensecms']['file']
        old_file.rename(backup/old_file.name);retired.append((old_file,backup/old_file.name))
    finally:fcntl.flock(theme_lock,fcntl.LOCK_UN);theme_lock.close()
    check(all(not re.search(rb'PHP (?:Warning|Fatal|Parse|Notice)|Uncaught|\[(?:crit|alert|emerg|error)\]',p.read_bytes()[pos:],re.I) for p,pos in logs.items()),'No fresh PHP/Nginx errors')
    run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron'])
    receipt.update(status='deployed-awaiting-final-https-acceptance',theme=new_theme,public_notice_ids=[int(row[0]) for row in changed_rows])
except BaseException:
    # Source-only coordinated rollback; never overwrite production business data with a DB dump.
    for source,target in reversed(retired): target.rename(source)
    for site in sites:
        for name in core:
            path=site[2]/name;atomic(path,original[str(path)],metadata[str(path)])
        invalidate(site)
    for identity in ['addon:calendar']+list(reversed(order[:-1])):
        if identity in updated:
            kind,slug=identity.split(':');manager(sites[0],'$m->rollback($argv[2],$argv[3],1);',kind,slug)
    for name in ['theme.json','distribution.json']:atomic(store/name,(backup/name).read_bytes(),(store/name).stat())
    for row_id,before,after in changed_rows:
        sql('sensecms_site','UPDATE content_block_translations SET data=CONVERT(0x'+before.hex()+' USING utf8mb4) WHERE id='+str(int(row_id))+' AND BINARY data=0x'+after.hex())
    for site in sites:invalidate(site)
    receipt['status']='rolled-back; candidate archives retained privately'
    raise
finally:(backup/'receipt.json').write_text(json.dumps(receipt,indent=2))
print(json.dumps(receipt),flush=True)
