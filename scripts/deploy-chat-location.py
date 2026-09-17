"""Pinned official-site deployment of operator-only visitor location."""
import datetime,fcntl,hashlib,json,os,pwd,socket,subprocess,tempfile,urllib.request
from pathlib import Path
os.umask(0o077)
stage=Path('/root/sense-chat-location-taAYjoyZ');src=stage/'source/.cms/source';root=Path('/home/sensecms.com/web')
migration='database/workspace/030_chat_visitor_location.sql'
files=['app/Core/IpCountry.php','storage/geoip/country.bin',migration,'app/Core/AiRepository.php','app/Core/AiChatService.php','app/Http/AiChatController.php','public/theme/sensecms-live-chat.css','public/theme/sensecms-live-chat.js','app/Views/console-live-chat.php','app/Views/console.php']
assert socket.gethostname()=='ind' and json.loads((root/'storage/installed.json').read_text())['base_url']=='https://www.sensecms.com'
assert '24 isolated public chat HTTP checks passed.' in (stage/'qa.log').read_text()
lock=Path('/root/sensecms-private/deploy-workspace.lock').open('a+b');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
account=pwd.getpwnam('sensecms')
def run(args,**kwargs):
    res=subprocess.run(list(map(str,args)),capture_output=True,**kwargs)
    if res.returncode:raise RuntimeError('Private command failed: '+str(args[0]))
    return res.stdout
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def php(code):
    return run(['runuser','-u','sensecms','--','php8.5','-r','require $argv[1]."/bootstrap.php";$r=new App\\Core\\Runtime($argv[1]);$db=App\\Core\\Runtime::connect($r->read("installed")["database"]);'+code,root])
def put(path,data,mode):
    assert not path.is_symlink() and path.parent.resolve()==path.parent
    fd,tmp=tempfile.mkstemp(prefix='.location-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(data);out.flush();os.fchown(out.fileno(),account.pw_uid,account.pw_gid);os.fchmod(out.fileno(),mode);os.fsync(out.fileno())
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def invalidate():
    fd,name=tempfile.mkstemp(prefix='location-cache-',suffix='.php',dir=root/'storage')
    try:
        with os.fdopen(fd,'w') as out:out.write('<?php foreach(json_decode('+repr(json.dumps(files))+',true) as $f)if(str_ends_with($f,".php"))opcache_invalidate(dirname(__DIR__)."/".$f,true);echo "location-cache-ok";')
        os.chown(name,account.pw_uid,account.pw_gid);os.chmod(name,0o600)
        env=dict(os.environ,SCRIPT_FILENAME=name,SCRIPT_NAME='/index.php',REQUEST_METHOD='GET',REQUEST_URI='/index.php',SERVER_PROTOCOL='HTTP/1.1',GATEWAY_INTERFACE='CGI/1.1',SERVER_NAME='www.sensecms.com',HTTP_HOST='www.sensecms.com',SERVER_PORT='443',HTTPS='on',REMOTE_ADDR='127.0.0.1')
        assert b'location-cache-ok' in run(['cgi-fcgi','-bind','-connect','/run/php/php8.5-sensecms.sock'],env=env)
    finally:os.unlink(name)
def snapshot():return hashlib.sha256(run(['mariadb','sensecms_site','-BN'],input=b'SELECT * FROM settings ORDER BY settings.key; SELECT id,email,password,active,is_demo,session_version FROM users ORDER BY id; SELECT * FROM user_roles ORDER BY user_id,role_id;')).hexdigest()
original={};modes={}
for name in files:
    path=root/name;assert not path.is_symlink() and (src/name).is_file()
    original[name]=path.read_bytes() if path.exists() else None;modes[name]=path.stat().st_mode&0o777 if path.exists() else (0o600 if name.startswith('storage/') else 0o644)
    if name.endswith('.php'):run(['php8.5','-l',src/name])
assert all(original[name] is None for name in files[:2])
assert original[migration] in (None,(src/migration).read_bytes())
assert json.loads(php('echo json_encode((new App\\Installer\\WorkspaceMigration($db,$argv[1]))->status());'))['ready']
assert b'23 offline IP-country checks passed.' in run(['php8.5',stage/'source/tests/ip-country.php'])
backup=Path('/root/sensecms-backups')/(datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'-chat-location');backup.mkdir(mode=0o700)
for name,data in original.items():
    if data is not None:
        dest=backup/name;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(data)
with (backup/'database.sql').open('wb') as out:
    res=subprocess.run(['mariadb-dump','--single-transaction','--routines','--triggers','sensecms_site'],stdout=out,stderr=subprocess.PIPE);assert res.returncode==0
before=snapshot();logs={str(p):p.stat().st_size for p in [Path('/var/log/nginx/sensecms.com.error.log'),root/'storage/php-error.log'] if p.exists()}
receipt={'backup':str(backup),'status':'prepared','hashes':{name:sha(src/name) for name in files},'modes':modes,'new_files':[name for name in files if original[name] is None],'logs_before':logs,'dataset_source':'https://download.db-ip.com/free/dbip-country-lite-2026-09.csv.gz','dataset_sha256':sha(stage/'dbip-country-lite-2026-09.csv.gz'),'dataset_license':'CC BY 4.0; IP Geolocation by DB-IP; September 2026; converted to sorted fixed-width binary records'}
(backup/'receipt.json').write_text(json.dumps(receipt,indent=2))
applied=False
try:
    (root/'storage/geoip').mkdir(mode=0o700,exist_ok=True);os.chown(root/'storage/geoip',account.pw_uid,account.pw_gid)
    for name in files[:3]:put(root/name,(src/name).read_bytes(),modes[name])
    status=json.loads(php('echo json_encode((new App\\Installer\\WorkspaceMigration($db,$argv[1]))->status());'))
    assert status['pending'] in ([],[Path(migration).name])
    assert php('$db->exec("SET SESSION lock_wait_timeout=5");echo (new App\\Installer\\WorkspaceMigration($db,$argv[1]))->apply();')==str(len(status['pending'])).encode();applied=True
    for name in files[3:]:
        assert (root/name).read_bytes()==original[name];put(root/name,(src/name).read_bytes(),modes[name])
    invalidate()
    assert all(sha(root/name)==sha(src/name) for name in files)
    assert php('echo App\\Core\\IpCountry::lookup("8.8.8.8");')==b'US'
    assert php('echo App\\Core\\IpCountry::lookup("2001:4860:4860::8888");')==b'CA'  # Exact September 2026 source range, not an assumed anycast location.
    with urllib.request.urlopen('https://www.sensecms.com/',timeout=30) as response:assert response.status==200 and b'data-public-chat ' in response.read()
    assert snapshot()==before
    assert run(['systemctl','is-active','nginx','php8.5-fpm','mariadb']).decode().split()==['active']*3
    assert all(Path(p).stat().st_size==size for p,size in logs.items())
    receipt['status']='deployed-http-verified';(backup/'receipt.json').write_text(json.dumps(receipt,indent=2));print(json.dumps(receipt,indent=2))
except BaseException:
    for name,data in original.items():
        if data is not None:put(root/name,data,modes[name])
        elif name!=migration and (root/name).exists():(root/name).unlink()
    # Additive columns/journal are retained for compatibility; never drop visitor data.
    if not applied and (root/migration).exists():(root/migration).unlink()
    invalidate();receipt['status']='code-rolled-back';receipt['migration_retained']=applied;(backup/'receipt.json').write_text(json.dumps(receipt,indent=2));raise
