"""Pinned, official-site-only live chat deployment; no installer/package rebuild."""
import datetime, fcntl, hashlib, json, os, pwd, socket, subprocess, tempfile, urllib.request
from pathlib import Path

os.umask(0o077)
stage=Path('/root/sense-live-chat-i1jwYjZ7')
src=stage/'source/.cms/source'
root=Path('/home/sensecms.com/web')
files=['public/assets/public-chat.css','public/assets/public-chat.js','app/Views/public-chat.php','app/Core/PublicChat.php',
       'app/Http/AiChatController.php','app/workspace.php','app/Http/PublicController.php','public/index.php']
assert socket.gethostname()=='ind'
assert root.resolve()==root and json.loads((root/'storage/installed.json').read_text())['base_url']=='https://www.sensecms.com'
assert '21 isolated public chat HTTP checks passed.' in (stage/'qa.log').read_text()
lock=Path('/root/sensecms-private/deploy-workspace.lock').open('a+b')
fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
account=pwd.getpwnam('sensecms')
def run(args,**kwargs):
    res=subprocess.run(list(map(str,args)),capture_output=True,**kwargs)
    if res.returncode:raise RuntimeError('Private command failed: '+str(args[0]))
    return res.stdout
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def put(path,data,mode=0o644):
    assert not path.is_symlink() and path.parent.resolve()==path.parent
    fd,tmp=tempfile.mkstemp(prefix='.chat-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(data);out.flush();os.fchown(out.fileno(),account.pw_uid,account.pw_gid);os.fchmod(out.fileno(),mode);os.fsync(out.fileno())
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def invalidate():
    fd,name=tempfile.mkstemp(prefix='chat-cache-',suffix='.php',dir=root/'storage')
    try:
        with os.fdopen(fd,'w') as out:
            out.write('<?php foreach(json_decode('+repr(json.dumps(files))+',true) as $f)if(str_ends_with($f,".php"))opcache_invalidate(dirname(__DIR__)."/".$f,true);echo "chat-cache-ok";')
        os.chown(name,account.pw_uid,account.pw_gid);os.chmod(name,0o600)
        env=dict(os.environ,SCRIPT_FILENAME=name,SCRIPT_NAME='/index.php',REQUEST_METHOD='GET',REQUEST_URI='/index.php',SERVER_PROTOCOL='HTTP/1.1',GATEWAY_INTERFACE='CGI/1.1',SERVER_NAME='www.sensecms.com',HTTP_HOST='www.sensecms.com',SERVER_PORT='443',HTTPS='on',REMOTE_ADDR='127.0.0.1')
        assert b'chat-cache-ok' in run(['cgi-fcgi','-bind','-connect','/run/php/php8.5-sensecms.sock'],env=env)
    finally:os.unlink(name)
def snapshot():return hashlib.sha256(run(['mariadb','sensecms_site','-BN'],input=b'SELECT * FROM settings ORDER BY settings.key; SELECT id,email,password,active,is_demo,session_version FROM users ORDER BY id; SELECT * FROM user_roles ORDER BY user_id,role_id;')).hexdigest()

original={};modes={}
for name in files:
    path=root/name
    assert not path.is_symlink() and src.joinpath(name).is_file()
    original[name]=path.read_bytes() if path.exists() else None
    modes[name]=path.stat().st_mode&0o777 if path.exists() else 0o644
    if name.endswith('.php'):run(['php8.5','-l',src/name])
if any(original[name] is not None for name in files[:4]):
    previous=json.loads(Path('/root/sensecms-backups/20260913T070824Z-public-chat/receipt.json').read_text())
    assert previous['status']=='deployed-http-verified' and all(sha(root/name)==previous['hashes'][name] for name in files),'Only the verified initial chat deployment may be refined'
backup=Path('/root/sensecms-backups')/(datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'-public-chat')
backup.mkdir(mode=0o700)
for name,data in original.items():
    if data is not None:
        dest=backup/name;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(data)
before=snapshot()
logs={str(p):p.stat().st_size for p in [Path('/var/log/nginx/sensecms.com.error.log'),root/'storage/php-error.log'] if p.exists()}
receipt={'backup':str(backup),'status':'prepared','hashes':{name:sha(src/name) for name in files},'new_files':[name for name in files if original[name] is None],'modes':modes,'logs_before':logs}
(backup/'receipt.json').write_text(json.dumps(receipt,indent=2))
try:
    for name in files:
        path=root/name
        assert (path.read_bytes() if path.exists() else None)==original[name]
        put(path,(src/name).read_bytes(),modes[name])
    invalidate()
    assert all(sha(root/name)==sha(src/name) for name in files)
    with urllib.request.urlopen('https://www.sensecms.com/',timeout=30) as response:
        assert response.status==200 and response.read().count(b'data-public-chat ')==1
    for name in files[:2]:
        with urllib.request.urlopen('https://www.sensecms.com/'+name.removeprefix('public/'),timeout=30) as response:
            assert response.status==200 and hashlib.sha256(response.read()).hexdigest()==sha(src/name)
    assert snapshot()==before,'Accounts and configuration must remain unchanged'
    assert run(['systemctl','is-active','nginx','php8.5-fpm','mariadb']).decode().split()==['active']*3
    assert all(Path(p).stat().st_size==size for p,size in logs.items()),'Inspect fresh error logs'
    receipt['status']='deployed-http-verified';(backup/'receipt.json').write_text(json.dumps(receipt,indent=2))
    print(json.dumps(receipt,indent=2))
except BaseException:
    for name,data in original.items():
        if data is None:
            if (root/name).exists():(root/name).unlink()
        else:put(root/name,data,modes[name])
    invalidate();receipt['status']='rolled-back';(backup/'receipt.json').write_text(json.dumps(receipt,indent=2));raise
