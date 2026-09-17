"""Exact, backed-up Sense CMS upload/defaults deployment. No theme or database migration."""
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

web=Path('/home/sensecms.com/web');qa=Path('/root/sense-media-C1YgZvc9');baseline=Path('/root/sense-simplify-LVpGVHhi/.cms/source')
if os.geteuid()!=0 or sys.argv[1:] not in [['--check'],['--deploy']]:raise SystemExit('Use --check or --deploy on the verified Sense host.')
os.umask(0o077)
lock=open('/root/sensecms-private/deploy-workspace.lock','a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
def run(args,**kwargs):
    r=subprocess.run(args,capture_output=True,**kwargs)
    if r.returncode:
        (qa/'failed-command.log').write_bytes(r.stdout+b'\n'+r.stderr);raise RuntimeError('Private command failed: '+Path(args[0]).name)
    return r.stdout
def check(ok,label):
    if not ok:raise RuntimeError(label)
    print('PASS '+label,flush=True)
def digest(path):return hashlib.sha256(path.read_bytes()).hexdigest()
core=['app/Core/'+name+'.php' for name in ['MediaLibrary','AiChatService','EmailSystem','MailService','PackageManager','SeoMeta','SiteChrome']]
core+=['app/Http/DashboardController.php','app/Views/account-recovery.php','app/Views/console-email.php','app/Views/console-media-library.php','app/Views/console.php','app/Views/license-required.php','public/index.php','public/theme/sensecms-media-library.js']
sources={web/p:qa/'.cms/source'/p for p in core}
nginx=Path('/etc/nginx/sites-available/sensecms.com');pool=Path('/etc/php/8.5/fpm/pool.d/sensecms.conf')
sources[nginx]=qa/'deploy/nginx/sensecms.com.conf';sources[pool]=qa/'deploy/php/sensecms.conf'
check(json.loads((web/'storage/installed.json').read_text())['base_url']=='https://www.sensecms.com','Exact official installation')
for p in core:check(digest(web/p)==digest(baseline/p),'Unchanged baseline '+p)
check(digest(nginx)=='c13f5d0e71fba75e0d8221f8c99ea64bf564f97a4a29ca2abec387b03a53c14a','Unchanged Nginx baseline')
check(digest(pool)=='4df999b6ff91c8be3fab246302487338806ff0c8cfdb54a294244da9870518b1','Unchanged private FPM pool baseline')
for target,source in sources.items():
    check(target.resolve()==target and source.resolve()==source and source.is_file(),'Safe candidate '+source.name)
    if source.suffix=='.php':run(['php8.5','-l',str(source)])
for test,args in [('media-limits.php',[]),('workspace-migration.php',[]),('security-regressions.php',['--mysql']),('maintenance-regressions.php',['--mysql']),('themes.php',[])]:
    output=run(['php8.5',str(qa/'tests'/test),*args],cwd=qa);(qa/(test+'.log')).write_bytes(output);check(bool(output),'Isolated QA '+test)
output=run(['python3',str(qa/'tests/media-http.py'),'--nginx']);(qa/'media-http.log').write_bytes(output);print(output.decode(),flush=True)
run(['nginx','-t']);run(['php-fpm8.5','-t'])
candidate={str(p):digest(s) for p,s in sources.items()};acceptance=qa/'media-preflight.json'
if sys.argv[1]=='--check':acceptance.write_text(json.dumps(candidate));print('Preflight accepted; production unchanged.');raise SystemExit(0)
check(json.loads(acceptance.read_text())==candidate,'Exact accepted candidate')
stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
backup=Path('/root/sensecms-backups')/(stamp+'-media-defaults');backup.mkdir(mode=0o700)
run(['tar','-czf',str(backup/'web-before.tgz'),'-C',str(web.parent),'web'])
check(b'web/storage/installed.json' in run(['tar','-tzf',str(backup/'web-before.tgz')]),'Private runtime and source recovery archive')
with (backup/'database-before.sql').open('wb')as stream:
    r=subprocess.run(['mariadb-dump','--single-transaction','--skip-lock-tables','--hex-blob','--no-tablespaces','sensecms_site'],stdout=stream,stderr=subprocess.PIPE)
    check(r.returncode==0 and stream.tell()>1000,'Database recovery snapshot')
original={};metadata={}
for target in sources:
    check(target.resolve()==target and target.is_file(),'Regular recovery target '+target.name)
    original[target]=target.read_bytes();metadata[target]=target.stat()
    dest=backup/'files'/str(target).lstrip('/');dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(original[target])
(backup/'targets.json').write_text(json.dumps({str(p):{'uid':s.st_uid,'gid':s.st_gid,'mode':s.st_mode&0o777} for p,s in metadata.items()},indent=2))
def atomic(target,data):
    info=metadata[target];fd,name=tempfile.mkstemp(prefix='.release-',dir=target.parent)
    try:
        with os.fdopen(fd,'wb')as stream:stream.write(data);stream.flush();os.fsync(stream.fileno())
        os.chown(name,info.st_uid,info.st_gid);os.chmod(name,info.st_mode&0o777);os.replace(name,target)
    finally:
        if os.path.exists(name):os.unlink(name)
sql='SELECT id,password,email,active,is_demo,session_version FROM users ORDER BY id; SELECT * FROM user_roles ORDER BY user_id,role_id; SELECT * FROM role_permissions ORDER BY role_id,permission_id; SELECT * FROM settings ORDER BY `key`;'
sql+=''.join('SELECT * FROM '+table+' ORDER BY id;' for table in ['menus','menu_items','menu_item_translations','pages','page_translations','media','media_translations'])
before=run(['mariadb','sensecms_site','--batch','--skip-column-names'],input=sql.encode())
private={p:digest(web/'storage'/p) for p in ['installed.json','workspace.json','theme.json','license/key.bin','license/license.lic']}
errorlog=Path('/var/log/nginx/sensecms.com.error.log');position=errorlog.stat().st_size
receipt={'backup':str(backup),'candidate':candidate,'status':'prepared'}
try:
    for target,source in sources.items():
        check(digest(source)==candidate[str(target)],'Candidate unchanged '+source.name);atomic(target,source.read_bytes())
    run(['nginx','-t']);run(['php-fpm8.5','-t'])
    run(['systemctl','reload','php8.5-fpm']);run(['systemctl','reload','nginx'])
    output=run(['python3',str(qa/'media-inspect.py')]);check(b'"upload_max_filesize":"80M"' in output and b'"post_max_size":"96M"' in output,'Effective private FPM limits 80M/96M')
    output=run(['python3',str(qa/'tests/update-http.py')]);(backup/'http.log').write_bytes(output);print(output.decode(),flush=True)
    output=run(['python3',str(qa/'tests/media-production-http.py')]);(backup/'media-http.log').write_bytes(output);print(output.decode(),flush=True)
    check(before==run(['mariadb','sensecms_site','--batch','--skip-column-names'],input=sql.encode()),'Users, permissions, settings, menus, pages and media preserved')
    check(all(digest(web/'storage'/p)==sha for p,sha in private.items()),'Private installation, theme and licensing unchanged')
    check(all(digest(p)==candidate[str(p)] for p in sources),'Exact deployed hashes')
    run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron'])
    import re
    fresh=errorlog.read_bytes()[position:]
    check(not re.search(rb'PHP (?:Warning|Fatal|Parse|Notice)|Uncaught|\[(?:crit|alert|emerg)\]',fresh,re.I),'No fresh PHP/critical errors (expected oversize request rejections excluded)')
    receipt['status']='verified'
except BaseException:
    for target,data in original.items():atomic(target,data)
    run(['nginx','-t']);run(['php-fpm8.5','-t']);run(['systemctl','reload','php8.5-fpm']);run(['systemctl','reload','nginx']);receipt['status']='rolled-back';raise
finally:(backup/'deployment.json').write_text(json.dumps(receipt,indent=2))
print(json.dumps(receipt))
