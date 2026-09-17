"""One-shot removal of the website/CMS bridge on the verified official installation."""
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
qa=Path('/root/sense-simplify-LVpGVHhi')
previous=Path('/root/sensecms-backups/20260909T133533Z-updates/deployment.json')
if os.geteuid()!=0 or sys.argv[1:] not in [['--check'],['--deploy']]:
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
def digest(path): return hashlib.sha256(path.read_bytes()).hexdigest()
def php(code,*args):
    return run(['php8.5','-r','require $argv[1]."/bootstrap.php";'+code,str(web),*map(str,args)])
core=['app/Http/AuthController.php','app/Http/SystemUpdateController.php','app/Views/console-system-update.php',
      'app/Views/console.php','public/index.php','public/theme/sensecms-system-update.js']
removed='app/Views/update-connect.php'
sources={web/name:qa/'.cms/source'/name for name in core}
old=json.loads(previous.read_text())
check(old['status']=='verified','Verified preceding deployment')
check(json.loads((web/'storage/installed.json').read_text())['base_url']=='https://www.sensecms.com','Exact official installation')
check(json.loads((web/'storage/theme.json').read_text())['active']==old['theme'],'Unchanged active theme baseline')
for name in [*core,removed]:
    path=web/name
    check(path.resolve()==path and digest(path)==old['candidate'][str(path)],'Unchanged baseline '+name)
for source in sources.values():
    check(source.resolve()==source and source.is_file(),'Safe candidate '+source.name)
    if source.suffix=='.php':run(['php8.5','-l',str(source)])
check(not (qa/'.cms/source'/removed).exists(),'Removed landing absent from candidate')
for test in ['core-releases.php','themes.php','security-regressions.php','maintenance-regressions.php']:
    args=['--mysql'] if test in ['security-regressions.php','maintenance-regressions.php'] else []
    output=run(['php8.5',str(qa/'tests'/test),*args],cwd=qa)
    (qa/(test+'.log')).write_bytes(output);check(bool(output),'Isolated QA '+test)
run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron'])
candidate={str(p):digest(s) for p,s in sources.items()}
candidate['theme']=hashlib.sha256(b''.join(p.read_bytes() for p in sorted((qa/'.themes/sensecms').rglob('*')) if p.is_file())).hexdigest()
acceptance=qa/'preflight.json'
if sys.argv[1]=='--check':
    acceptance.write_text(json.dumps(candidate));print('Preflight accepted; production unchanged.');raise SystemExit(0)
check(json.loads(acceptance.read_text())==candidate,'Exact accepted candidate')
backup=Path('/root/sensecms-backups')/(datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'-update-simplification')
backup.mkdir(mode=0o700)
run(['tar','-czf',str(backup/'web-before.tgz'),'-C',str(web.parent),'web'])
check(b'web/storage/installed.json' in run(['tar','-tzf',str(backup/'web-before.tgz')]),'Source/private-runtime recovery archive')
with (backup/'database-before.sql').open('wb') as stream:
    result=subprocess.run(['mariadb-dump','--single-transaction','--skip-lock-tables','--hex-blob','--no-tablespaces','sensecms_site'],stdout=stream,stderr=subprocess.PIPE)
    check(result.returncode==0 and stream.tell()>1000,'Database recovery snapshot')
original={};metadata={}
for target in [*sources,web/removed,web/'storage/theme.json']:
    check(target.resolve()==target and target.is_file(),'Regular recovery target '+target.name)
    original[target]=target.read_bytes();metadata[target]=target.stat()
    saved=backup/'files'/str(target).lstrip('/');saved.parent.mkdir(parents=True,exist_ok=True);saved.write_bytes(original[target])
(backup/'targets.json').write_text(json.dumps({str(p):{'uid':s.st_uid,'gid':s.st_gid,'mode':s.st_mode&0o777} for p,s in metadata.items()},indent=2))
def atomic(target,data):
    info=metadata[target];fd,name=tempfile.mkstemp(prefix='.release-',dir=target.parent)
    try:
        with os.fdopen(fd,'wb') as stream:stream.write(data);stream.flush();os.fsync(stream.fileno())
        os.chown(name,info.st_uid,info.st_gid);os.chmod(name,info.st_mode&0o777);os.replace(name,target)
    finally:
        if os.path.exists(name):os.unlink(name)
account=pwd.getpwnam('sensecms')
def invalidate():
    fd,name=tempfile.mkstemp(prefix='update-probe-',suffix='.php',dir=web/'storage')
    try:
        paths=json.dumps([*core,removed]);code='<?php $root=dirname(__DIR__);foreach(json_decode('+json.dumps(paths)+',true) as $p)if(function_exists("opcache_invalidate"))opcache_invalidate($root."/".$p,true);echo "cache-refreshed";'
        with os.fdopen(fd,'w') as stream:stream.write(code)
        os.chown(name,account.pw_uid,account.pw_gid)
        env=dict(os.environ,SCRIPT_FILENAME=name,SCRIPT_NAME='/index.php',REQUEST_METHOD='GET',REQUEST_URI='/index.php',SERVER_PROTOCOL='HTTP/1.1',GATEWAY_INTERFACE='CGI/1.1',SERVER_NAME='www.sensecms.com',HTTP_HOST='www.sensecms.com',SERVER_PORT='443',HTTPS='on',REMOTE_ADDR='127.0.0.1')
        check(b'cache-refreshed' in run(['cgi-fcgi','-bind','-connect','/run/php/php8.5-sensecms.sock'],env=env),'FPM opcode cache refreshed')
    finally:os.unlink(name)
sql='SELECT id,password,email,active,is_demo,session_version FROM users ORDER BY id; SELECT * FROM user_roles ORDER BY user_id,role_id; SELECT * FROM role_permissions ORDER BY role_id,permission_id;'
sql+=''.join('SELECT * FROM '+table+' ORDER BY id;' for table in ['menus','menu_items','menu_item_translations','pages','page_translations'])
before=run(['mariadb','sensecms_site','--batch','--skip-column-names'],input=sql.encode())
private_hash={p:digest(web/'storage'/p) for p in ['installed.json','workspace.json','license/key.bin','license/license.lic']}
errorlog=Path('/var/log/nginx/sensecms.com.error.log');position=errorlog.stat().st_size
receipt={'backup':str(backup),'candidate':candidate,'status':'prepared'}
try:
    for target,source in sources.items():
        check(digest(source)==candidate[str(target)],'Candidate unchanged '+source.name)
        atomic(target,source.read_bytes())
    (web/removed).unlink()
    archive=backup/'theme-sensecms-0.3.10.zip'
    php('$secret=base64_decode(trim(file_get_contents("/root/sensecms-private/publisher.ed25519")),true);try{App\\Core\\Packages\\Archive::build($argv[2],$argv[3],$secret);}finally{sodium_memzero($secret);}',qa/'.themes/sensecms',archive)
    release=json.loads(php('$r=new App\\Core\\Runtime($argv[1]);$keys=array_map(fn($k)=>base64_decode($k,true),json_decode(file_get_contents("/root/sensecms-private/trust.json"),true));echo json_encode((new App\\Core\\Packages\\ThemeManager($r))->install($argv[2],$keys,false));',archive))
    directory=web/'storage/themes'/release['directory']
    check(directory.resolve().parent==web/'storage/themes' and re.fullmatch(r'sensecms-0\.3\.10-[a-f0-9]{16}',directory.name),'Exact immutable theme directory')
    run(['chown','-R','sensecms:sensecms',str(directory)])
    php('$r=new App\\Core\\Runtime($argv[1]);$keys=array_map(fn($k)=>base64_decode($k,true),json_decode(file_get_contents("/root/sensecms-private/trust.json"),true));(new App\\Core\\Packages\\ThemeManager($r))->activate($argv[2],$keys);',release['directory'])
    run(['chown','sensecms:sensecms',str(web/'storage/theme.json'),str(web/'storage/themes.lock')])
    invalidate()
    output=run(['python3',str(qa/'tests/update-http.py')]);(backup/'http-checks.log').write_bytes(output);print(output.decode(),flush=True)
    run(['runuser','-u','sensecms','--','php8.5',str(web/'scripts/check-updates.php')])
    check(before==run(['mariadb','sensecms_site','--batch','--skip-column-names'],input=sql.encode()),'Identity, permissions, menus and content preserved')
    check(all(digest(web/'storage'/p)==sha for p,sha in private_hash.items()),'Private installation and licensing unchanged')
    check(all(digest(p)==candidate[str(p)] for p in sources) and not (web/removed).exists(),'Deployed source hashes and removal verified')
    run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron'])
    check(not re.search(rb'PHP (?:Warning|Fatal|Parse|Notice)|Uncaught|\[(?:crit|alert|emerg|error)\]',errorlog.read_bytes()[position:],re.I),'No fresh Nginx/PHP errors')
    receipt.update(status='verified',theme=release,archive_sha256=digest(archive))
except BaseException:
    for target,data in original.items():atomic(target,data)
    invalidate();receipt['status']='rolled-back';raise
finally:(backup/'deployment.json').write_text(json.dumps(receipt,indent=2))
print(json.dumps(receipt))
