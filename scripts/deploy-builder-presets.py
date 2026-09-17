"""Exact, backed-up builder preset deployment. No theme, configuration or database migration."""
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

web=Path('/home/sensecms.com/web');qa=Path('/root/sense-builder-I06xQGCK');baseline=Path('/root/sense-media-C1YgZvc9/.cms/source')
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
core=['app/Core/PageBuilder.php','config/page-builder.php','config/page-builder-layouts.json','config/page-builder-localized-defaults.php']
core+=['app/Views/'+p+'.php' for p in ['console-content-categories','console-content-navigation-item','console-content-navigation','console-content-posts','console-live-chat','console-facilities','console-media-library','console','console-page-builder','console-extensions']]
sources={web/p:qa/'.cms/source'/p for p in core}
check(json.loads((web/'storage/installed.json').read_text())['base_url']=='https://www.sensecms.com','Exact official installation')
for p in core:check(digest(web/p)==digest(baseline/p),'Unchanged baseline '+p)
for target,source in sources.items():
    check(target.resolve()==target and source.resolve()==source and source.is_file(),'Safe candidate '+source.name)
    if source.suffix=='.php':run(['php8.5','-l',str(source)])
for test,args in [('builder-defaults.php',[]),('media-limits.php',[]),('workspace-migration.php',[]),('security-regressions.php',['--mysql']),('maintenance-regressions.php',['--mysql']),('themes.php',[])]:
    output=run(['php8.5',str(qa/'tests'/test),*args],cwd=qa);(qa/(test+'.log')).write_bytes(output);check(bool(output),'Isolated QA '+test)
run(['nginx','-t']);run(['php-fpm8.5','-t'])
candidate={str(p):digest(s) for p,s in sources.items()};acceptance=qa/'builder-preflight.json'
if sys.argv[1]=='--check':acceptance.write_text(json.dumps(candidate));print('Preflight accepted; production unchanged.');raise SystemExit(0)
check(json.loads(acceptance.read_text())==candidate,'Exact accepted candidate')
stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
backup=Path('/root/sensecms-backups')/(stamp+'-builder-presets');backup.mkdir(mode=0o700)
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
import pwd
account=pwd.getpwnam('sensecms')
def invalidate():
    fd,name=tempfile.mkstemp(prefix='builder-probe-',suffix='.php',dir=web/'storage')
    try:
        paths=json.dumps([p for p in core if p.endswith('.php')])
        code='<?php $root=dirname(__DIR__);foreach(json_decode('+json.dumps(paths)+',true) as $p)if(function_exists("opcache_invalidate"))opcache_invalidate($root."/".$p,true);echo "cache-refreshed";'
        with os.fdopen(fd,'w') as stream:stream.write(code)
        os.chown(name,account.pw_uid,account.pw_gid)
        env=dict(os.environ,SCRIPT_FILENAME=name,SCRIPT_NAME='/index.php',REQUEST_METHOD='GET',REQUEST_URI='/index.php',SERVER_PROTOCOL='HTTP/1.1',GATEWAY_INTERFACE='CGI/1.1',SERVER_NAME='www.sensecms.com',HTTP_HOST='www.sensecms.com',SERVER_PORT='443',HTTPS='on',REMOTE_ADDR='127.0.0.1')
        check(b'cache-refreshed' in run(['cgi-fcgi','-bind','-connect','/run/php/php8.5-sensecms.sock'],env=env),'FPM opcode cache refreshed')
    finally:os.unlink(name)
sql='SELECT id,password,email,active,is_demo,session_version FROM users ORDER BY id; SELECT * FROM user_roles ORDER BY user_id,role_id; SELECT * FROM role_permissions ORDER BY role_id,permission_id; SELECT * FROM settings ORDER BY `key`;'
sql+=''.join('SELECT * FROM '+table+' ORDER BY id;' for table in ['menus','menu_items','menu_item_translations','pages','page_translations','media','media_translations','content_blocks','content_block_translations','global_sections','global_section_translations','page_builder_revisions'])
before=run(['mariadb','sensecms_site','--batch','--skip-column-names'],input=sql.encode())
private={p:digest(web/'storage'/p) for p in ['installed.json','workspace.json','theme.json','license/key.bin','license/license.lic']}
errorlog=Path('/var/log/nginx/sensecms.com.error.log');position=errorlog.stat().st_size
receipt={'backup':str(backup),'candidate':candidate,'status':'prepared'}
try:
    for target,source in sources.items():
        check(digest(source)==candidate[str(target)],'Candidate unchanged '+source.name);atomic(target,source.read_bytes())
    run(['nginx','-t']);run(['php-fpm8.5','-t'])
    invalidate()
    output=run(['python3',str(qa/'tests/update-http.py')]);(backup/'http.log').write_bytes(output);print(output.decode(),flush=True)
    check(before==run(['mariadb','sensecms_site','--batch','--skip-column-names'],input=sql.encode()),'Users, settings, menus, pages, blocks, translations, shared sections and revisions preserved')
    check(all(digest(web/'storage'/p)==sha for p,sha in private.items()),'Private installation, theme and licensing unchanged')
    check(all(digest(p)==candidate[str(p)] for p in sources),'Exact deployed hashes')
    run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron'])
    import re
    fresh=errorlog.read_bytes()[position:]
    check(not re.search(rb'PHP (?:Warning|Fatal|Parse|Notice)|Uncaught|\[(?:crit|alert|emerg|error)\]',fresh,re.I),'No fresh PHP/Nginx errors')
    receipt['status']='verified'
except BaseException:
    for target,data in original.items():atomic(target,data)
    invalidate();receipt['status']='rolled-back';raise
finally:(backup/'deployment.json').write_text(json.dumps(receipt,indent=2))
print(json.dumps(receipt))
