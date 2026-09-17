"""One-shot, pinned migration to an independent demo PHP identity/pool.

Run from a root-only staged checkout on ind with --check, then --deploy.
Backs up configuration, complete demo files/DB and ownership; rolls back on failure.
No application source deployment, database migration or installer/package build.
"""
import datetime,fcntl,grp,hashlib,json,os,pwd,re,stat,subprocess,sys,tempfile,time
from pathlib import Path
import urllib.error,urllib.request

stage=Path(__file__).resolve().parent.parent
web=Path('/home/demo.sensecms.com/web');official=Path('/home/sensecms.com/web')
nginx=Path('/etc/nginx/sites-available/demo.sensecms.com')
pool=Path('/etc/php/8.5/fpm/pool.d/demo-sensecms.conf')
socket=Path('/run/php/php8.5-demo-sensecms.sock')
account='demo-sensecms'
os.umask(0o077)
if os.geteuid()!=0 or not str(stage).startswith('/root/') or sys.argv[1:] not in [['--check'],['--deploy']]:raise SystemExit('Root-only staged --check / --deploy required')
lock=open('/root/sensecms-private/deploy-workspace.lock','a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
def run(args,**kwargs):
    result=subprocess.run([str(a) for a in args],capture_output=True,**kwargs)
    if result.returncode:
        (stage/'isolation-command-error.log').write_bytes(result.stdout+b'\n'+result.stderr)
        raise RuntimeError('Private diagnostic: '+Path(str(args[0])).name+' failed')
    return result.stdout
def check(ok,label):
    if not ok:raise RuntimeError(label)
    print('PASS '+label,flush=True)
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def paths():return [web,*web.rglob('*')]
def request(host,path='/',method='GET'):
    try:res=urllib.request.urlopen(urllib.request.Request('https://'+host+path,method=method),timeout=25)
    except urllib.error.HTTPError as error:res=error
    with res:return res.status,res.read(),res.headers
def wait_for(test,label):
    for _ in range(30):
        if test():check(True,label);return
        time.sleep(1)
    raise RuntimeError(label+' timed out')

check(run(['hostname']).strip()==b'ind','Deployment host')
for root,url,db in [(web,'https://demo.sensecms.com','sensecms_demo'),(official,'https://www.sensecms.com','sensecms_site')]:
    installed=json.loads((root/'storage/installed.json').read_text())
    check(root.resolve()==root and installed['base_url']==url and installed['database']['name']==db,'Exact independent installation '+db)
check(sha(nginx)=='6318826a54ccbfec7cc4f4700a45a889c7d72f2153c54af101910b60a95813df','Pinned demo Nginx baseline')
check(sha(Path('/etc/php/8.5/fpm/pool.d/sensecms.conf'))=='74f34379abbcb7a770e2b922929d96ca8faebfac671a4831a0910a41cce00121','Official pool baseline')
try:pwd.getpwnam(account);exists=True
except KeyError:exists=False
check(not exists and not pool.exists() and not socket.exists(),'New identity/pool/socket are unclaimed')
old=pwd.getpwnam('sensecms');device=web.stat().st_dev
tree=paths()
check(all(not p.is_symlink() and p.stat().st_dev==device and (p.is_dir() or (p.is_file() and p.stat().st_nlink==1)) for p in tree),'No symlinks, mounts, special files or hard links')
check(all(p.stat().st_uid in [0,old.pw_uid] and p.stat().st_gid in [0,old.pw_gid] for p in tree),'Known ownership only')
check(not (web/'storage/tmp').exists(),'Private temporary directory is new')
public_uploads=[web/'public/media',web/'public/sensecms/audio/custom']
check(all(not p.exists() for p in public_uploads),'Fresh demo public-upload roots are unclaimed')
source={nginx:stage/'deploy/nginx/demo.sensecms.com.conf',pool:stage/'deploy/php/demo-sensecms.conf'}
candidate={str(p):sha(src) for p,src in source.items()}
check(b'php8.5-demo-sensecms.sock' in source[nginx].read_bytes(),'Dedicated upstream in candidate')
run(['nginx','-t']);run(['php-fpm8.5','-t'])
if sys.argv[1]=='--check':
    (stage/'isolation-preflight.json').write_text(json.dumps(candidate));print('Read-only preflight passed');raise SystemExit(0)
check(json.loads((stage/'isolation-preflight.json').read_text())==candidate,'Accepted candidate hashes')
stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
backup=Path('/root/sensecms-backups')/(stamp+'-demo-isolation');backup.mkdir(mode=0o700)
run(['tar','-czf',backup/'demo-web-before.tgz','-C',web.parent,'web'])
check(b'web/storage/installed.json' in run(['tar','-tzf',backup/'demo-web-before.tgz']),'Complete demo recovery archive')
with (backup/'demo-database-before.sql').open('wb') as stream:
    result=subprocess.run(['mariadb-dump','--single-transaction','--skip-lock-tables','--hex-blob','--no-tablespaces','sensecms_demo'],stdout=stream,stderr=subprocess.PIPE)
    check(result.returncode==0 and stream.tell()>1000,'Private demo database backup')
original=nginx.read_bytes();(backup/'nginx-before.conf').write_bytes(original)
metadata={str(p):(p.stat().st_uid,p.stat().st_gid,stat.S_IMODE(p.stat().st_mode)) for p in tree}
(backup/'ownership-before.json').write_text(json.dumps(metadata))
protected=[Path('/etc/php/8.5/fpm/pool.d/sensecms.conf'),Path('/etc/nginx/sites-available/sensecms.com')]
protected += [root/'storage'/p for root in [web,official] for p in ['installed.json','workspace.json','theme.json','license/key.bin','license/license.lic'] if (root/'storage'/p).exists()]
baseline={p:sha(p) for p in protected}
code={p:sha(p) for p in tree if p.is_file() and not p.is_relative_to(web/'storage')}
sql="SELECT id,email,password,active,is_demo,session_version FROM users ORDER BY id; SELECT * FROM user_roles ORDER BY user_id,role_id; SELECT * FROM role_permissions ORDER BY role_id,permission_id; SELECT * FROM settings ORDER BY `key`;"
sql+=''.join('SELECT * FROM '+t+' ORDER BY id;' for t in ['facilities','pages','page_translations','posts','post_translations','media','content_blocks','global_sections'])
snapshots={db:run(['mariadb',db,'-BN'],input=sql.encode()) for db in ['sensecms_site','sensecms_demo']}
logs={p:p.stat().st_size if p.exists() else 0 for p in [web/'storage/php-error.log',official/'storage/php-error.log',Path('/var/log/nginx/demo.sensecms.com.error.log'),Path('/var/log/nginx/sensecms.com.error.log')]}
def atomic(path,data):
    fd,tmp=tempfile.mkstemp(prefix='.sense-isolation-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as f:f.write(data);f.flush();os.fsync(f.fileno())
        os.chown(tmp,0,0);os.chmod(tmp,0o644);os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def reload_nginx(data):
    atomic(nginx,data);run(['nginx','-t']);run(['systemctl','reload','nginx'])
def probe():
    fd,name=tempfile.mkstemp(prefix='isolation-probe-',suffix='.php',dir=web/'storage')
    code='''<?php
    require '/home/demo.sensecms.com/web/bootstrap.php';
    $runtime=new App\\Core\\Runtime('/home/demo.sensecms.com/web');
    $db=App\\Core\\Runtime::connect($runtime->read('installed')['database']);
    $denied=false;try{$db->query('SELECT id FROM sensecms_site.users LIMIT 1');}catch(PDOException $e){$denied=in_array((int)($e->errorInfo[1]??0),[1044,1142],true);}
    $temp=tempnam(sys_get_temp_dir(),'isolation-');$ok=$temp!==false&&str_starts_with($temp,'/home/demo.sensecms.com/web/storage/tmp/');if($temp!==false)unlink($temp);
    header('Content-Type: application/json');echo json_encode(['uid'=>posix_geteuid(),'db'=>$db->query('SELECT DATABASE()')->fetchColumn(),'other_db_denied'=>$denied,'other_runtime_denied'=>!is_readable('/home/sensecms.com/web/storage/installed.json'),'own_runtime'=>is_readable('/home/demo.sensecms.com/web/storage/installed.json'),'private_temp'=>$ok,'session_path'=>ini_get('session.save_path'),'error_log'=>ini_get('error_log'),'opcache_permission'=>(bool)ini_get('opcache.validate_permission')]);'''
    try:
        with os.fdopen(fd,'w') as f:f.write(code)
        os.chown(name,user.pw_uid,user.pw_gid)
        env=dict(os.environ,SCRIPT_FILENAME=name,REQUEST_METHOD='GET',SCRIPT_NAME='/index.php',REQUEST_URI='/index.php',SERVER_PROTOCOL='HTTP/1.1',GATEWAY_INTERFACE='CGI/1.1',SERVER_NAME='demo.sensecms.com',HTTP_HOST='demo.sensecms.com',SERVER_PORT='443',HTTPS='on',REMOTE_ADDR='127.0.0.1')
        raw=run(['cgi-fcgi','-bind','-connect',socket],env=env);data=json.loads(raw.split(b'\r\n\r\n',1)[1])
        check(data['uid']==user.pw_uid and data['db']=='sensecms_demo' and all(data[k] for k in ['other_db_denied','other_runtime_denied','own_runtime','private_temp','opcache_permission']),'Actual FPM identity, database isolation and private temporary files')
        check(data['session_path']==str(web/'storage/sessions') and data['error_log']==str(web/'storage/php-error.log'),'Installation-specific FPM sessions and errors')
        (backup/'fpm-probe.json').write_text(json.dumps(data))
    finally:os.unlink(name)
receipt={'backup':str(backup),'hashes':candidate,'status':'prepared'};maintenance=False;created=False
try:
    run(['useradd','--system','--user-group','--no-create-home','--home-dir','/home/demo.sensecms.com','--shell','/usr/sbin/nologin',account]);created=True;user=pwd.getpwnam(account)
    atomic(pool,source[pool].read_bytes());run(['php-fpm8.5','-t']);run(['systemctl','reload','php8.5-fpm'])
    wait_for(socket.exists,'New pool socket available')
    maintenance=True;started=time.monotonic()
    gated=original.replace(b'    index index.php index.html;',b'    index index.php index.html;\n    add_header Retry-After 30 always;\n    return 503;')
    check(gated!=original,'Exact demo maintenance gate')
    reload_nginx(gated);wait_for(lambda:request('demo.sensecms.com')[0]==503,'Demo maintenance active')
    check(request('www.sensecms.com')[0]==200,'Official site available during demo maintenance')
    time.sleep(3)
    for p in paths():
        if p.resolve()!=p or p.stat().st_dev!=device:raise RuntimeError('Unsafe ownership target')
        info=p.stat()
        if info.st_uid==old.pw_uid:os.chown(p,user.pw_uid,user.pw_gid)
    temp=web/'storage/tmp';temp.mkdir(mode=0o700);os.chown(temp,user.pw_uid,user.pw_gid)
    # Core stores public uploads as 0640, in 0750 directories. Inherit only the
    # Nginx read group for these public roots; never share private runtime groups.
    public_gid=grp.getgrnam('www-data').gr_gid
    for p in public_uploads:p.mkdir(mode=0o750);os.chown(p,user.pw_uid,public_gid);os.chmod(p,0o2750)
    probe()
    reverse="require '/home/sensecms.com/web/bootstrap.php';$r=new App\\Core\\Runtime('/home/sensecms.com/web');$db=App\\Core\\Runtime::connect($r->read('installed')['database']);try{$db->query('SELECT id FROM sensecms_demo.users LIMIT 1');exit(1);}catch(PDOException $e){exit(in_array((int)($e->errorInfo[1]??0),[1044,1142],true)?0:1);}"
    run(['runuser','-u','sensecms','--','php8.5','-r',reverse]);check(True,'Official database identity cannot read demo database')
    for who,target in [(account,official/'storage/installed.json'),('sensecms',web/'storage/installed.json')]:
        check(subprocess.run(['runuser','-u',who,'--','test','-r',str(target)]).returncode==1,'Other installation runtime unreadable to '+who)
        check(subprocess.run(['runuser','-u',who,'--','test','-w',str(target.parent)]).returncode==1,'Other installation runtime not writable to '+who)
    reload_nginx(source[nginx].read_bytes());wait_for(lambda:request('demo.sensecms.com')[0]==200,'Demo restored on dedicated pool');maintenance=False
    receipt['maintenance_seconds']=round(time.monotonic()-started,1)
    for upload_root in public_uploads:
        folder=upload_root/('isolation-'+os.urandom(8).hex());sample=folder/'probe.txt'
        try:
            run(['runuser','-u',account,'--','php8.5','-r','umask(0022);mkdir($argv[1],0750);file_put_contents($argv[1]."/probe.txt","public-upload-permission-check");chmod($argv[1]."/probe.txt",0640);',folder])
            check(sample.stat().st_uid==user.pw_uid and sample.stat().st_gid==public_gid,'New uploads inherit correct public read group')
            route='/'+str(sample.relative_to(web/'public'))
            check(request('demo.sensecms.com',route)[:2]==(200,b'public-upload-permission-check') and request('demo.sensecms.com',route,'HEAD')[0]==200,'Nginx serves runtime-created upload GET/HEAD')
            check(subprocess.run(['runuser','-u','www-data','--','test','-w',str(upload_root)]).returncode==1,'Nginx cannot write public uploads')
        finally:
            if sample.exists():sample.unlink()
            if folder.exists():folder.rmdir()
    output=run(['python3',stage/'tests/demo-access-http.py']);(backup/'https-acceptance.log').write_bytes(output);print(output.decode(),flush=True)
    for host in ['www.sensecms.com','demo.sensecms.com']:
        for route in ['/','/login','/assets/logo.svg','/assets/workspace.css']:
            check(request(host,route)[0]==200,'Public/static route '+host+route)
        for route in ['/storage/installed.json','/.sense-bootstrap/manifest.json','/install']:
            check(request(host,route)[0]==404,'Private/installer route closed '+host+route)
    check(all(sha(p)==value for p,value in baseline.items()),'Official configuration and both private identities unchanged')
    check(all(sha(p)==value for p,value in code.items()),'Demo application source unchanged')
    check(all(snapshots[db]==run(['mariadb',db,'-BN'],input=sql.encode()) for db in snapshots),'Accounts, roles, settings and content preserved on both databases')
    check(all(sha(p)==candidate[str(p)] for p in source),'Deployed configuration hashes')
    check(all(p.stat().st_uid!=old.pw_uid for p in paths()),'No demo files left owned by official runtime')
    for p,offset in logs.items():
        check(not p.exists() or not re.search(rb'PHP (?:Warning|Fatal|Parse|Notice)|Uncaught|\[(?:crit|alert|emerg|error)\]',p.read_bytes()[offset:],re.I),'No fresh application errors '+str(p))
    run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);receipt['status']='verified'
except BaseException:
    if created:
        for p in paths():
            if str(p) in metadata:
                uid,gid,mode=metadata[str(p)];os.chown(p,uid,gid);os.chmod(p,mode)
            elif p.stat().st_uid==user.pw_uid:os.chown(p,old.pw_uid,old.pw_gid)
    reload_nginx(original)
    if pool.exists():pool.unlink();run(['php-fpm8.5','-t']);run(['systemctl','reload','php8.5-fpm'])
    receipt['status']='rolled-back; unused locked system account retained';raise
finally:(backup/'deployment.json').write_text(json.dumps(receipt,indent=2))
print(json.dumps(receipt))
