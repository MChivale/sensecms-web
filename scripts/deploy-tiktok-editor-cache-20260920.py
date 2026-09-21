#!/usr/bin/env python3
"""Deploy TikTok Publisher 0.1.3 creator-choice cache hotfix."""
from __future__ import annotations
import datetime,fcntl,hashlib,json,os,re,shutil,subprocess,sys,time,urllib.request
from pathlib import Path
if os.name=='nt'or os.geteuid()!=0 or len(sys.argv)!=2:raise SystemExit('Run as root on Linux: deploy-tiktok-editor-cache-20260920.py <private-stage>')
os.umask(0o077);stage=Path(sys.argv[1]).resolve();web=Path('/home/sensecms.com/web');publisher=Path('/root/sensecms-private/publisher.ed25519');receipt={'status':'preflight','checks':[]};backup=install_dir=None;installed=False;started=int(datetime.datetime.now(datetime.timezone.utc).timestamp())
def run(command,*,user=None):
    if user:command=['sudo','-u',user,*command]
    result=subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
    if result.returncode:raise RuntimeError(result.stdout.decode(errors='replace').strip()or f'Command failed: {command[0]}')
    return result.stdout
def php(code,*args,user=None):return run(['php8.5','-r',code,*map(str,args)],user=user)
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def check(ok,label):
    if not ok:raise RuntimeError(label)
    receipt['checks'].append(label);print('PASS '+label,flush=True)
def state():
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$q=$db->prepare("SELECT version,active,signature_status FROM extension_packages WHERE type='plugin' AND slug='tiktok-publisher'");$q->execute();$social=[];foreach(['social_connections','social_post_targets','social_deliveries']as$t){$rows=$db->query("SELECT * FROM `$t` ORDER BY id")->fetchAll();$social[$t]=hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}echo json_encode(['package'=>$q->fetch()?:null,'social'=>$social],JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web))
def install(archive):
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();$s=$m->stageLocalFile($argv[2],$owner);echo json_encode($m->install($s['token'],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web,archive,user='sensecms'))
def rollback():
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();echo json_encode($m->rollback('plugin','tiktok-publisher',$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web,user='sensecms'))
required=[publisher,web/'bootstrap.php',stage/'.plugins/tiktok-publisher/sense-package.json',stage/'.plugins/tiktok-publisher/plugin.json',stage/'tests/tiktok-publisher.php']
check(stage.is_dir()and str(stage).startswith('/root/sense-tiktok-cache-'),'Private scoped TikTok deployment stage');check(all(p.is_file()and not p.is_symlink()for p in required),'Complete TikTok cache hotfix payload');before=state();check(before['package']=={'version':'0.1.2','active':1,'signature_status':'verified'},'Pinned TikTok Publisher 0.1.2 baseline')
for path in stage.glob('.plugins/tiktok-publisher/**/*.php'):run(['php8.5','-l',str(path)])
run(['php8.5','-l',str(stage/'tests/tiktok-publisher.php')]);run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron'])
lock=Path('/root/sensecms-private/deploy-workspace.lock').open('a+b');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
try:
    artifacts=stage/'artifacts';artifacts.mkdir(mode=0o700);archive=artifacts/'plugin-tiktok-publisher-0.1.3.zip';build=r'''require$argv[1].'/bootstrap.php';$secret=base64_decode(trim(file_get_contents($argv[2])),true);if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid publisher key.');try{$a=App\Core\Packages\Archive::build($argv[3],$argv[4],$secret);echo json_encode(['sha256'=>$a['sha256'],'public'=>base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))],JSON_THROW_ON_ERROR);}finally{sodium_memzero($secret);}''';built=json.loads(php(build,web,publisher,stage/'.plugins/tiktok-publisher',archive));receipt['artifact']=built['sha256'];trust=artifacts/'trust.json';trust.write_text(json.dumps({'sensecms-release':built['public']},separators=(',',':')));verify=r'''require$argv[1].'/bootstrap.php';$keys=array_map(static fn(string$v):string=>base64_decode($v,true),json_decode(file_get_contents($argv[2]),true,16,JSON_THROW_ON_ERROR));App\Core\Packages\Archive::verify($argv[3],$keys);echo'PASS';''';check(php(verify,web,trust,archive)==b'PASS','Exact signed TikTok 0.1.3 archive verified')
    testtree=stage/'runtime-tests';(testtree/'tests').mkdir(parents=True,mode=0o700);(testtree/'.cms').mkdir(mode=0o700);(testtree/'.cms/source').symlink_to(web,target_is_directory=True);(testtree/'.plugins').symlink_to(stage/'.plugins',target_is_directory=True);shutil.copy2(stage/'tests/tiktok-publisher.php',testtree/'tests/tiktok-publisher.php');check('19 TikTok Publisher protocol checks passed'in run(['php8.5',str(testtree/'tests/tiktok-publisher.php')]).decode(),'TikTok payload, cache and transient-retry regression suite')
    stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');backup=Path('/root/sensecms-backups')/(stamp+'-tiktok-editor-cache');backup.mkdir(mode=0o700);receipt['backup']=str(backup);shutil.copytree(web/'plugins/tiktok-publisher',backup/'plugin-tiktok-publisher')
    with(backup/'database-before.sql').open('wb')as output:
        result=subprocess.run(['mysqldump','--single-transaction','--routines','--triggers','sensecms_site'],stdout=output,stderr=subprocess.PIPE)
        if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace').strip()or'Database backup failed.')
    check((backup/'database-before.sql').stat().st_size>4096,'Private production database recovery dump')
    uid=int(run(['id','-u','sensecms']).decode());gid=int(run(['id','-g','sensecms']).decode());install_dir=web/'storage/private'/('tiktok-cache-'+stamp);install_dir.mkdir(mode=0o700);os.chown(install_dir,uid,gid);target=install_dir/archive.name;shutil.copy2(archive,target);os.chown(target,uid,gid);os.chmod(target,0o600);result=install(target);check(result.get('version')=='0.1.3','PackageManager installed TikTok Publisher 0.1.3');installed=True
    after=state();check(after['package']=={'version':'0.1.3','active':1,'signature_status':'verified'},'Signed TikTok Publisher 0.1.3 active');check(after['social']==before['social'],'Social data preserved exactly during package upgrade');check(sha(stage/'.plugins/tiktok-publisher/src/provider.php')==sha(web/'plugins/tiktok-publisher/src/provider.php'),'Deployed TikTok provider checksum')
    editor=r'''$r=$argv[1];require$r.'/bootstrap.php';require$r.'/addons/social-publishing/src/SocialIntegrationManager.php';$runtime=new App\Core\Runtime($r);$installed=$runtime->read('installed');$root=$r;$baseUrl=$runtime->baseUrl();$cfg=require$r.'/config/workspace.php';$db=App\Core\Runtime::connect($installed['database']);$m=new SenseCMS\Social\SocialIntegrationManager($db,$r,(string)$cfg['secrets_key']);$id=(int)$db->query("SELECT id FROM social_connections WHERE plugin_slug='tiktok-publisher' AND enabled=1 ORDER BY id LIMIT 1")->fetchColumn();$v=$m->editor('tiktok-publisher',$id);echo json_encode(['fields'=>count($v['fields']??[])],JSON_THROW_ON_ERROR);'''
    live=None
    for attempt in range(3):
        try:live=json.loads(php(editor,web,user='sensecms'));break
        except RuntimeError:
            if attempt==2:raise
            time.sleep(10)
    check((live or{}).get('fields',0)>0,'Live TikTok publishing choices fetched and cached');cached=json.loads(php(editor,web,user='sensecms'));check(cached.get('fields',0)>0,'Live TikTok publishing choices reused from encrypted cache')
    with urllib.request.urlopen('https://www.sensecms.com/extension-assets/plugin/tiktok-publisher/tiktok.js?v=0.1.3',timeout=30)as response:check(response.status==200,'HTTP 200 TikTok 0.1.3 asset')
    logs=run(['journalctl','--since','@'+str(started),'--no-pager','-u','nginx','-u','php8.5-fpm']).decode(errors='replace');check(not re.search(r'PHP Fatal|\bcrit\b|\bpanic\b',logs,re.I),'No fresh critical service errors');receipt['status']='deployed';shutil.rmtree(install_dir);install_dir=None
except BaseException:
    receipt['status']='rolling-back'if installed else'preflight-failed'
    if installed:
        try:rollback();receipt['status']='rolled-back'
        except BaseException:receipt['status']='rollback-needs-attention'
    raise
finally:
    if install_dir is not None and install_dir.is_dir()and install_dir.name.startswith('tiktok-cache-')and install_dir.parent==web/'storage/private':shutil.rmtree(install_dir)
    if backup is not None:(backup/'receipt.json').write_text(json.dumps(receipt,indent=2,sort_keys=True)+'\n');os.chmod(backup/'receipt.json',0o600)
    fcntl.flock(lock,fcntl.LOCK_UN);lock.close()
print(json.dumps(receipt,sort_keys=True),flush=True)
