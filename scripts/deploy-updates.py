"""Exact candidate deployment of release checks and official Update page; root operator only."""
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import subprocess
import sys
import tempfile

web=Path('/home/sensecms.com/web')
qa=Path('/root/sense-updates-4c0rnb7y')
baseline=Path('/root/sense-maintenance-HvrDfbrm/.cms/source')
if sys.argv[1:] not in [['--check'],['--deploy']] or os.geteuid()!=0 or web.resolve()!=web or qa.resolve()!=qa:
    raise SystemExit('Use --check or --deploy on the verified Sense host.')
os.umask(0o077)
lock=open('/root/sensecms-private/deploy-workspace.lock','a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)

def run(args, **kwargs):
    result=subprocess.run(args,capture_output=True,**kwargs)
    if result.returncode:
        (qa/'failed-command.log').write_bytes(result.stdout+b'\n'+result.stderr)
        raise RuntimeError('Private command failed: '+Path(args[0]).name)
    return result.stdout

def check(ok,label):
    if not ok: raise RuntimeError(label)
    print('PASS '+label,flush=True)

def php(code,*args):
    return run(['php8.5','-r','require $argv[1]."/bootstrap.php";'+code,str(web),*map(str,args)])

def digest(path): return hashlib.sha256(path.read_bytes()).hexdigest()

core=['app/Core/CoreReleases.php','app/Core/SystemUpdate.php','app/Http/SystemUpdateController.php',
    'app/Http/DashboardController.php','app/Http/AuthController.php','app/Views/console-system-update.php',
    'app/Views/console.php','app/Views/update-connect.php','public/index.php',
    'public/theme/sensecms-system-update.js','scripts/check-updates.php']
new={'app/Core/CoreReleases.php','app/Views/update-connect.php','scripts/check-updates.php'}
sources={web/name:qa/'.cms/source'/name for name in core}
sources[web/'website/core-releases.php']=qa/'.src/core-releases.php'
nginx=Path('/etc/nginx/sites-available/sensecms.com')
sources[nginx]=qa/'deploy/nginx/sensecms.com.conf'
sources[Path('/etc/cron.d/sensecms-updates')]=qa/'deploy/cron/sensecms-updates'
private=Path('/root/sensecms-private')
sources[private/'publish-core-releases.php']=qa/'scripts/publish-core-releases.php'
sources[private/'core-releases.json']=qa/'.src/core-releases.json'
check(json.loads((web/'storage/installed.json').read_text())['base_url']=='https://www.sensecms.com','Exact official installation')
check(json.loads((web/'storage/theme.json').read_text())['active']['version']=='0.3.8','Theme 0.3.8 baseline')
check(digest(nginx)=='b65c9211f72ddb812539f04f08b859717e1014be26c61416594bdcd35a5fdfa5','Unchanged Nginx baseline')
for name in core:
    check(not (web/name).exists() if name in new else digest(web/name)==digest(baseline/name),'Core baseline '+name)
for target,source in sources.items():
    check(target.resolve()==target and source.resolve()==source and source.is_file(),'Safe target '+target.name)
    if target not in [web/name for name in core] and target!=nginx: check(not target.exists(),'New operational target '+target.name)
    if source.suffix=='.php': run(['php8.5','-l',str(source)])
check(json.loads((qa/'.src/core-releases.json').read_text())=={'releases':[]},'No unaccepted Stable promotion')
for test,args in [('core-releases.php',[]),('maintenance-regressions.php',['--mysql']),('security-regressions.php',['--mysql']),('workspace-migration.php',[]),('themes.php',[])]:
    output=run(['php8.5',str(qa/'tests'/test),*args],cwd=qa)
    (qa/(test+'.log')).write_bytes(output);check(bool(output),'Isolated QA '+test)
run(['nginx','-t']);run(['php-fpm8.5','-t']);run(['systemctl','is-active','nginx','php8.5-fpm','mariadb'])
candidate={str(target):digest(source) for target,source in sources.items()}
candidate['theme']=hashlib.sha256(b''.join(p.read_bytes() for p in sorted((qa/'.themes/sensecms').rglob('*')) if p.is_file())).hexdigest()
acceptance=qa/'update-preflight.json'
if sys.argv[1]=='--check':
    acceptance.write_text(json.dumps(candidate));print('Preflight accepted; production unchanged.');raise SystemExit(0)
check(json.loads(acceptance.read_text())==candidate,'Exact preflight-accepted candidate')
stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
backup=Path('/root/sensecms-backups')/(stamp+'-updates');backup.mkdir(mode=0o700)
run(['tar','-czf',str(backup/'web-before.tgz'),'-C',str(web.parent),'web'])
check(b'web/storage/installed.json' in run(['tar','-tzf',str(backup/'web-before.tgz')]),'Full source/private-runtime backup')
with (backup/'database-before.sql').open('wb') as stream:
    result=subprocess.run(['mariadb-dump','--single-transaction','--skip-lock-tables','--hex-blob','--no-tablespaces','sensecms_site'],stdout=stream,stderr=subprocess.PIPE)
    check(result.returncode==0 and stream.tell()>1000,'Consistent database recovery snapshot')
cron=Path('/etc/cron.d/sensecms-release-catalog')
extra=[web/'storage/theme.json',web/'storage/update-trust.json',web/'storage/release-feed.json',web/'storage/core-update-state.json',web/'storage/core-update-catalog.json',cron]
original={};metadata={}
for target in [*sources,*extra]:
    check(not target.is_symlink(),'Regular recovery target '+target.name)
    original[target]=target.read_bytes() if target.exists() else None
    metadata[target]=target.stat() if target.exists() else None
    if original[target] is not None:
        saved=backup/'files'/str(target).lstrip('/');saved.parent.mkdir(parents=True,exist_ok=True);saved.write_bytes(original[target])
(backup/'targets.json').write_text(json.dumps({str(p):{'existed':data is not None,'uid':metadata[p].st_uid if metadata[p] else 0,'gid':metadata[p].st_gid if metadata[p] else 0,'mode':metadata[p].st_mode&0o777 if metadata[p] else 0o644} for p,data in original.items()},indent=2))
account=pwd.getpwnam('sensecms')
def atomic(target,data,uid=0,gid=0,mode=0o644):
    check(target.parent.is_dir() and target.resolve()==target,'Atomic target '+target.name)
    fd,name=tempfile.mkstemp(prefix='.release-',dir=target.parent)
    try:
        with os.fdopen(fd,'wb') as stream: stream.write(data);stream.flush();os.fsync(stream.fileno())
        os.chown(name,uid,gid);os.chmod(name,mode);os.replace(name,target)
    finally:
        if os.path.exists(name):os.unlink(name)

def invalidate():
    fd,name=tempfile.mkstemp(prefix='update-probe-',suffix='.php',dir=web/'storage')
    try:
        code='<?php $root=dirname(__DIR__);foreach(json_decode('+json.dumps(json.dumps([p for p in core if p.endswith('.php')]))+',true) as $p)if(function_exists("opcache_invalidate"))opcache_invalidate($root."/".$p,true);echo "cache-refreshed";'
        with os.fdopen(fd,'w') as stream:stream.write(code)
        os.chown(name,account.pw_uid,account.pw_gid)
        env=dict(os.environ,SCRIPT_FILENAME=name,SCRIPT_NAME='/index.php',REQUEST_METHOD='GET',REQUEST_URI='/index.php',SERVER_PROTOCOL='HTTP/1.1',GATEWAY_INTERFACE='CGI/1.1',SERVER_NAME='www.sensecms.com',HTTP_HOST='www.sensecms.com',SERVER_PORT='443',HTTPS='on',REMOTE_ADDR='127.0.0.1')
        check(b'cache-refreshed' in run(['cgi-fcgi','-bind','-connect','/run/php/php8.5-sensecms.sock'],env=env),'Shared FPM opcode cache refreshed')
    finally:os.unlink(name)

sql='SELECT id,password,email,active,is_demo,session_version FROM users ORDER BY id; SELECT * FROM user_roles ORDER BY user_id,role_id; SELECT * FROM role_permissions ORDER BY role_id,permission_id;'
sql+=''.join('SELECT * FROM '+table+' ORDER BY id;' for table in ['menus','menu_items','menu_item_translations','pages','page_translations'])
state_before=run(['mariadb','sensecms_site','--batch','--skip-column-names'],input=sql.encode())
private_paths=['installed.json','workspace.json','license/key.bin','license/license.lic']
private_hash={p:digest(web/'storage'/p) for p in private_paths}
errorlog=Path('/var/log/nginx/sensecms.com.error.log');position=errorlog.stat().st_size
receipt={'backup':str(backup),'candidate':candidate,'status':'prepared'}
try:
    for target,source in sources.items():
        check(digest(source)==candidate[str(target)],'Candidate unchanged '+source.name)
        info=metadata[target];atomic(target,source.read_bytes(),info.st_uid if info else 0,info.st_gid if info else 0,info.st_mode&0o777 if info else 0o644)
    public=php('$secret=base64_decode(trim(file_get_contents("/root/sensecms-private/publisher.ed25519")),true);try{$pub=base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));$trust=json_decode(file_get_contents("/root/sensecms-private/trust.json"),true);if(!in_array($pub,$trust,true))throw new RuntimeException("Independent trust mismatch");echo $pub;}finally{sodium_memzero($secret);}')
    atomic(web/'storage/update-trust.json',json.dumps({'public_key':public.decode()}).encode(),account.pw_uid,account.pw_gid,0o600)
    run(['php8.5',str(private/'publish-core-releases.php'),str(web),str(private/'core-releases.json'),str(private/'publisher.ed25519')])
    archive=backup/'theme-sensecms-0.3.9.zip'
    php('$secret=base64_decode(trim(file_get_contents("/root/sensecms-private/publisher.ed25519")),true);try{App\\Core\\Packages\\Archive::build($argv[2],$argv[3],$secret);}finally{sodium_memzero($secret);}',qa/'.themes/sensecms',archive)
    orphan=web/'storage/themes'/('sensecms-0.3.9-'+digest(archive)[:16])
    if orphan.exists():
        state=json.loads((web/'storage/theme.json').read_text())
        check(orphan.resolve().parent==web/'storage/themes' and not orphan.is_symlink()
            and orphan.name not in state.get('releases',{})
            and all(state.get(part,{}).get('directory')!=orphan.name for part in ['active','previous'])
            and digest(orphan/'archive.zip')==digest(archive),'Verified inactive orphan from rolled-back attempt')
        os.rename(orphan,backup/'retained-orphan-theme')
    release=json.loads(php('$r=new App\\Core\\Runtime($argv[1]);$keys=array_map(fn($k)=>base64_decode($k,true),json_decode(file_get_contents("/root/sensecms-private/trust.json"),true));echo json_encode((new App\\Core\\Packages\\ThemeManager($r))->install($argv[2],$keys,false));',archive))
    directory=web/'storage/themes'/release['directory']
    check(directory.resolve().parent==web/'storage/themes' and re.fullmatch(r'sensecms-0\.3\.9-[a-f0-9]{16}',directory.name),'Exact new immutable theme directory')
    run(['chown','-R','sensecms:sensecms',str(directory)])
    php('$r=new App\\Core\\Runtime($argv[1]);$keys=array_map(fn($k)=>base64_decode($k,true),json_decode(file_get_contents("/root/sensecms-private/trust.json"),true));(new App\\Core\\Packages\\ThemeManager($r))->activate($argv[2],$keys);',release['directory'])
    run(['chown','sensecms:sensecms',str(web/'storage/theme.json'),str(web/'storage/themes.lock')])
    invalidate();run(['nginx','-t']);run(['systemctl','reload','nginx'])
    log=run(['python3',str(qa/'tests/update-http.py')]);(backup/'http-checks.log').write_bytes(log);print(log.decode(),flush=True)
    run(['runuser','-u','sensecms','--','php8.5',str(web/'scripts/check-updates.php')])
    check(state_before==run(['mariadb','sensecms_site','--batch','--skip-column-names'],input=sql.encode()),'User identities, permissions, navigation and page content preserved')
    check(all(digest(web/'storage'/p)==sha for p,sha in private_hash.items()),'Private installation and licensing unchanged')
    check(all(digest(target)==sha for target,sha in ((p,candidate[str(p)]) for p in sources)),'All deployed source/configuration hashes match')
    crondata='7 */6 * * * root /usr/bin/php8.5 /root/sensecms-private/publish-core-releases.php /home/sensecms.com/web /root/sensecms-private/core-releases.json /root/sensecms-private/publisher.ed25519 >> /root/sensecms-private/release-catalog.log 2>&1\n'
    atomic(cron,crondata.encode())
    run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron'])
    fresh=errorlog.read_bytes()[position:]
    check(not re.search(rb'PHP (?:Warning|Fatal|Parse|Notice)|Uncaught|\[(?:crit|alert|emerg|error)\]',fresh,re.I),'No fresh Nginx/PHP errors')
    receipt.update(status='verified',theme=release,archive_sha256=digest(archive))
except BaseException:
    for target,data in original.items():
        if data is None: target.unlink(missing_ok=True)
        else:
            info=metadata[target];atomic(target,data,info.st_uid,info.st_gid,info.st_mode&0o777)
    invalidate();run(['nginx','-t']);run(['systemctl','reload','nginx'])
    receipt['status']='rolled-back'
    raise
finally:(backup/'deployment.json').write_text(json.dumps(receipt,indent=2))
print(json.dumps(receipt))
