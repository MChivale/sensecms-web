"""Pinned source-only homepage fix for official production and the user's installed demo."""
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
import urllib.error
import urllib.request

qa=Path('/root/sense-home-qb49jnGQ')
if os.geteuid()!=0 or sys.argv[1:] not in [['--check'],['--deploy']]:raise SystemExit('Use --check or --deploy on the verified host.')
os.umask(0o077)
lock=open('/root/sensecms-private/deploy-workspace.lock','a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
core=['app/Views/public-home.php','app/Http/PublicController.php','public/index.php']
baseline={'app/Http/PublicController.php':'cf644ec6d7e6021a0c949d01037d85147a1b0d23170294b7435f197e88689563','public/index.php':'fc744e038d422fd75ad0f8375d545b6596542714cfd39d5f432e087127e482f9'}
targets=[('sensecms.com','www.sensecms.com','sensecms_site'),('demo.sensecms.com','demo.sensecms.com','sensecms_demo')]
def run(args,**kwargs):
    result=subprocess.run(args,capture_output=True,**kwargs)
    if result.returncode:
        (qa/'failed-command.log').write_bytes(result.stdout+b'\n'+result.stderr)
        raise RuntimeError('Private command failed: '+Path(args[0]).name)
    return result.stdout
def check(ok,label):
    if not ok:raise RuntimeError(label)
    print('PASS '+label,flush=True)
def digest(path):return hashlib.sha256(path.read_bytes()).hexdigest()
candidate={p:digest(qa/'.cms/source'/p) for p in core}
for domain,host,db in targets:
    web=Path('/home')/domain/'web'
    installed=json.loads((web/'storage/installed.json').read_text())
    check(installed['base_url']=='https://'+host and installed['database']['name']==db,'Exact installed target '+host)
    for p in core:
        path=web/p
        check(path.resolve()==path,'Regular destination '+domain+'/'+p)
        check(digest(path)==baseline[p] if p in baseline else not path.exists(),'Pinned baseline '+domain+'/'+p)
for p in core:run(['php8.5','-l',str(qa/'.cms/source'/p)])
for test,args in [('workspace-migration.php',[]),('security-regressions.php',['--mysql']),('maintenance-regressions.php',['--mysql']),('themes.php',[]),('builder-defaults.php',[]),('popup-defaults.php',[])]:
    output=run(['php8.5',str(qa/'tests'/test),*args],cwd=qa);(qa/(test+'.log')).write_bytes(output);check(bool(output),'Isolated regression '+test)
output=run(['python3',str(qa/'tests/public-home-http.py')]);(qa/'routing.log').write_bytes(output);print(output.decode())
run(['nginx','-t']);run(['php-fpm8.5','-t'])
acceptance=qa/'home-preflight.json'
if sys.argv[1]=='--check':acceptance.write_text(json.dumps(candidate));print('Preflight accepted; no deployed files changed.');raise SystemExit(0)
check(json.loads(acceptance.read_text())==candidate,'Exact accepted source hashes')
stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
backup=Path('/root/sensecms-backups')/(stamp+'-public-home');backup.mkdir(mode=0o700)
original={};metadata={};private={};before={};log_positions={}
sql='SELECT id,password,email,active,is_demo,session_version FROM users ORDER BY id; SELECT * FROM user_roles ORDER BY user_id,role_id; SELECT * FROM role_permissions ORDER BY role_id,permission_id; SELECT * FROM settings ORDER BY `key`;'
sql+=''.join('SELECT * FROM '+table+' ORDER BY id;' for table in ['facilities','facility_translations','menus','menu_items','menu_item_translations','pages','page_translations','media','media_translations','content_blocks','content_block_translations','global_sections','global_section_translations','page_builder_revisions'])
for domain,host,db in targets:
    web=Path('/home')/domain/'web';folder=backup/domain;folder.mkdir()
    run(['tar','-czf',str(folder/'web-before.tgz'),'-C',str(web.parent),'web'])
    check(b'web/storage/installed.json' in run(['tar','-tzf',str(folder/'web-before.tgz')]),'Recovery archive '+domain)
    with (folder/'database-before.sql').open('wb') as stream:
        result=subprocess.run(['mariadb-dump','--single-transaction','--skip-lock-tables','--hex-blob','--no-tablespaces',db],stdout=stream,stderr=subprocess.PIPE)
        check(result.returncode==0 and stream.tell()>1000,'Database backup '+domain)
    before[db]=run(['mariadb',db,'--batch','--skip-column-names'],input=sql.encode())
    for p in ['installed.json','workspace.json','theme.json','license/key.bin','license/license.lic']:
        path=web/'storage'/p;private[path]=digest(path) if path.exists() else None
    for p in core:
        path=web/p;original[path]=path.read_bytes() if path.exists() else None
        info=path.stat() if path.exists() else (web/'app/Http/PublicController.php').stat()
        metadata[path]=(info.st_uid,info.st_gid,info.st_mode&0o777)
        if original[path] is not None:
            saved=folder/'files'/p;saved.parent.mkdir(parents=True,exist_ok=True);saved.write_bytes(original[path])
    log=Path('/var/log/nginx')/(domain+'.error.log')
    if log.exists():log_positions[log]=log.stat().st_size
(backup/'targets.json').write_text(json.dumps({str(p):{'uid':m[0],'gid':m[1],'mode':m[2],'existed':original[p] is not None} for p,m in metadata.items()},indent=2))
def atomic(path,data):
    fd,temp=tempfile.mkstemp(prefix='.release-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as stream:stream.write(data);stream.flush();os.fsync(stream.fileno())
        uid,gid,mode=metadata[path];os.chown(temp,uid,gid);os.chmod(temp,mode);os.replace(temp,path)
    finally:
        if os.path.exists(temp):os.unlink(temp)
def invalidate():
    web=Path('/home/sensecms.com/web');account=pwd.getpwnam('sensecms')
    fd,name=tempfile.mkstemp(prefix='home-probe-',suffix='.php',dir=web/'storage')
    try:
        paths=json.dumps([str(p) for p in original])
        code='<?php foreach(json_decode('+json.dumps(paths)+',true) as $p)if(function_exists("opcache_invalidate"))opcache_invalidate($p,true);echo "cache-refreshed";'
        with os.fdopen(fd,'w') as stream:stream.write(code)
        os.chown(name,account.pw_uid,account.pw_gid)
        env=dict(os.environ,SCRIPT_FILENAME=name,REQUEST_METHOD='GET',SCRIPT_NAME='/index.php',REQUEST_URI='/index.php',SERVER_PROTOCOL='HTTP/1.1',GATEWAY_INTERFACE='CGI/1.1',SERVER_NAME='www.sensecms.com',HTTP_HOST='www.sensecms.com',SERVER_PORT='443',HTTPS='on',REMOTE_ADDR='127.0.0.1')
        check(b'cache-refreshed' in run(['cgi-fcgi','-bind','-connect','/run/php/php8.5-sensecms.sock'],env=env),'Targeted opcode invalidation')
    finally:os.unlink(name)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):return None
http=urllib.request.build_opener(NoRedirect())
def request(host,path,method='GET'):
    try:response=http.open(urllib.request.Request('https://'+host+path,method=method),timeout=25)
    except urllib.error.HTTPError as error:response=error
    with response:return response.status,response.headers,response.read()
receipt={'backup':str(backup),'candidate':candidate,'status':'prepared'}
try:
    for p in core:
        source=qa/'.cms/source'/p;check(digest(source)==candidate[p],'Candidate unchanged '+p)
        for domain,host,db in targets:atomic(Path('/home')/domain/'web'/p,source.read_bytes())
    invalidate()
    for host in ['demo.sensecms.com','www.sensecms.com']:
        status,headers,body=request(host,'/')
        check(status==200 and headers.get('Content-Security-Policy') and b'Facility not found' not in body,'Live homepage '+host)
        check((b'data-public-home' in body)==(host=='demo.sensecms.com'),'Correct theme/default presentation '+host)
        check(request(host,'/login')[0]==200,'Login intact '+host)
        check(request(host,'/install')[0]==404,'Installer remains closed '+host)
    locales=run(['mariadb','sensecms_demo','--batch','--skip-column-names','-e','SELECT locale FROM languages WHERE enabled=1 ORDER BY id']).decode().splitlines()
    check(bool(locales) and all(re.fullmatch('[a-z]{2,5}',locale) for locale in locales),'Published demo locales')
    for path in [p for locale in locales for p in ['/'+locale,'/'+locale+'/home']]:
        status,headers,body=request('demo.sensecms.com',path)
        check(status==302 and headers.get('Location')=='/','Demo canonical redirect '+path)
    check(request('demo.sensecms.com','/missing-page')[0]==404,'Unknown page stays 404')
    status,headers,body=request('demo.sensecms.com','/','HEAD');check(status==200 and not body,'Demo HEAD')
    for path in ['/assets/workspace.css','/assets/logo.svg']:
        check(request('demo.sensecms.com',path)[0]==200,'Live homepage asset '+path)
    output=run(['python3',str(qa/'tests/update-http.py')]);(backup/'production-http.log').write_bytes(output);print(output.decode())
    for domain,host,db in targets:
        check(before[db]==run(['mariadb',db,'--batch','--skip-column-names'],input=sql.encode()),'Business data and settings unchanged '+domain)
        check(all(digest(Path('/home')/domain/'web'/p)==candidate[p] for p in core),'Exact deployed hashes '+domain)
    check(all((digest(p) if p.exists() else None)==sha for p,sha in private.items()),'Private identities, themes and licences preserved')
    for path,offset in log_positions.items():check(not re.search(rb'PHP (?:Warning|Fatal|Parse|Notice)|Uncaught|\[(?:crit|alert|emerg|error)\]',path.read_bytes()[offset:],re.I),'No fresh errors '+path.name)
    run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);receipt['status']='verified'
except BaseException:
    for path,data in original.items():
        if data is None:
            if path.exists():path.unlink()
        else:atomic(path,data)
    invalidate();receipt['status']='rolled-back';raise
finally:(backup/'deployment.json').write_text(json.dumps(receipt,indent=2))
print(json.dumps(receipt))
