#!/usr/bin/env python3
"""Build, verify and deploy TikTok Publisher 0.1.0 with Sandbox credentials."""
from __future__ import annotations

import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import urllib.error
import urllib.request

if os.name == 'nt' or os.geteuid() != 0 or len(sys.argv) != 2:
    raise SystemExit('Run as root on Linux: deploy-tiktok-publisher-0.1.0.py <private-stage>')
os.umask(0o077)

stage=Path(sys.argv[1]).resolve();web=Path('/home/sensecms.com/web');nginx=Path('/etc/nginx/sites-available/sensecms.com');publisher=Path('/root/sensecms-private/publisher.ed25519');private=web/'storage/private/tiktok'
core_files=['app/Core/LicenseService.php','config/workspace.php'];website_files=['TikTokBrokerService.php','TikTokOAuthEndpoint.php','tiktok-oauth.php']
expected={web/core_files[0]:'314e16a9801cc124c70dec38022cbf81f8c95df4d868d0f207a3630af0dd89ea',web/core_files[1]:'3c71a0146e60aa792b4a36bc5ec7370f312ae2a5a735601c5f228fecfdf63714',nginx:'cd59c03ae183e28f522b2625f2520d9be7f51f69e7be655e03cae812c0894348'}
receipt={'status':'preflight','checks':[]};backup=install_dir=None;mutated=installed=upgraded=published=False;created=[];started=int(datetime.datetime.now(datetime.timezone.utc).timestamp())

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
def atomic(source,target):
    temporary=target.with_name(target.name+'.tiktok-new');shutil.copy2(source,temporary)
    if target.exists():stat=target.stat();os.chown(temporary,stat.st_uid,stat.st_gid);os.chmod(temporary,stat.st_mode&0o777)
    else:os.chown(temporary,0,0);os.chmod(temporary,0o644)
    os.replace(temporary,target)
def config():
    values={}
    for line in (stage/'.cfg/TikTok.txt').read_text(encoding='utf-8-sig').splitlines():
        if '=' in line:
            key,value=line.split('=',1);values[key.strip()]=value.strip()
    required={'TIKTOK_ENVIRONMENT':'sandbox','TIKTOK_OAUTH_CALLBACK':'https://www.sensecms.com/api/social/tiktok/v1/callback','TIKTOK_OAUTH_SCOPES':'user.info.basic,video.publish','TIKTOK_TARGET_USER':'chivale.group','TIKTOK_ACCESS_STATUS':'target-user-connected'}
    if any(values.get(key)!=value for key,value in required.items())or not re.fullmatch(r'[A-Za-z0-9_-]{5,200}',values.get('TIKTOK_CLIENT_KEY',''))or not re.fullmatch(r'[^\x00-\x20]{8,512}',values.get('TIKTOK_CLIENT_SECRET','')):raise RuntimeError('Private TikTok Sandbox configuration is incomplete.')
    return{'client_key':values['TIKTOK_CLIENT_KEY'],'client_secret':values['TIKTOK_CLIENT_SECRET']}
def state():
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$packages=[];foreach([['addon','social-publishing'],['plugin','facebook-publisher'],['plugin','x-publisher'],['plugin','linkedin-publisher'],['plugin','bluesky-publisher'],['plugin','mastodon-publisher'],['plugin','telegram-channels-publisher'],['plugin','pinterest-publisher'],['plugin','tiktok-publisher']]as$p){$q=$db->prepare("SELECT version,active,signature_status FROM extension_packages WHERE type=? AND slug=?");$q->execute($p);$packages[$p[0].':'.$p[1]]=$q->fetch()?:null;}$social=[];foreach(['social_connections'=>'SELECT * FROM social_connections ORDER BY id','social_post_targets'=>'SELECT id,post_id,plugin_slug,connection_id,enabled,message,revision,last_enqueued_revision,updated_at FROM social_post_targets ORDER BY id','social_deliveries'=>'SELECT * FROM social_deliveries ORDER BY id']as$t=>$sql){$rows=$db->query($sql)->fetchAll();$social[$t]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];}$due=(int)$db->query("SELECT COUNT(*) FROM social_post_targets t INNER JOIN posts p ON p.id=t.post_id WHERE t.enabled=1 AND t.last_enqueued_revision<t.revision AND (p.status='published' OR (p.status='scheduled' AND p.published_at<=UTC_TIMESTAMP()))")->fetchColumn();$options=$db->query("SHOW COLUMNS FROM social_post_targets LIKE 'options_json'")->fetch()? (int)$db->query("SELECT COUNT(*) FROM social_post_targets WHERE options_json IS NOT NULL")->fetchColumn():null;echo json_encode(['packages'=>$packages,'social'=>$social,'due'=>$due,'options'=>$options],JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web))
def install(archive):
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();$s=$m->stageLocalFile($argv[2],$owner);echo json_encode($m->install($s['token'],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web,archive,user='sensecms'))
def rollback_packages():
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();if($m->package('plugin','tiktok-publisher'))$m->uninstall('plugin','tiktok-publisher',$owner);if($m->package('addon','social-publishing')['version']==='0.3.0'){$count=(int)$db->query("SELECT COUNT(*) FROM social_post_targets WHERE options_json IS NOT NULL")->fetchColumn();if($count)throw new RuntimeException('TikTok publishing options exist; automatic downgrade refused.');$db->exec("ALTER TABLE social_post_targets DROP COLUMN options_json");$q=$db->prepare("UPDATE extension_migrations SET rolled_back_at=NOW() WHERE package_type='addon' AND package_slug='social-publishing' AND migration_id='social-publishing-0.3.0-provider-options' AND rolled_back_at IS NULL");$q->execute();$m->rollback('addon','social-publishing',$owner);}'''
    php(code,web,user='sensecms')

required=[stage/'.cfg/TikTok.txt',*(stage/'.src'/name for name in website_files),stage/'.src/package-catalog.php',*(stage/'.cms/source'/name for name in core_files),stage/'.addons/social-publishing/sense-package.json',stage/'.addons/social-publishing/addon.json',stage/'.plugins/tiktok-publisher/sense-package.json',stage/'.plugins/tiktok-publisher/plugin.json',stage/'deploy/nginx/sensecms.com.conf',stage/'scripts/publish-tiktok-publisher-extension.php',stage/'tests/tiktok-broker.php',stage/'tests/tiktok-publisher.php',stage/'tests/tiktok-social-release.php',stage/'tests/tiktok-ui-production.py',stage/'tests/package-catalog-http.py']
check(stage.is_dir()and str(stage).startswith('/root/sense-tiktok-publisher-'),'Private scoped TikTok deployment stage');check(all(path.is_file()and not path.is_symlink()for path in required),'Complete TikTok candidate payload');cfg=config();check(web.is_dir()and nginx.is_file()and publisher.is_file(),'Production installation and signing runtime');check(all(path.is_file()and sha(path)==digest for path,digest in expected.items()),'Pinned production Core and Nginx baseline');check(all(not(web/'website'/name).exists()for name in website_files)and not private.exists(),'TikTok broker and private runtime absent before first deployment')
before=state();baseline={'addon:social-publishing':('0.2.2',1,'verified'),'plugin:facebook-publisher':('0.2.1',1,'verified'),'plugin:x-publisher':('0.1.1',1,'verified'),'plugin:linkedin-publisher':('0.1.1',1,'verified'),'plugin:bluesky-publisher':('0.1.3',1,'verified'),'plugin:mastodon-publisher':('0.1.1',1,'verified'),'plugin:telegram-channels-publisher':('0.1.1',1,'verified'),'plugin:pinterest-publisher':('0.1.0',1,'verified')}
for key,value in baseline.items():item=before['packages'][key];check(item is not None and tuple(item.values())==value,'Installed '+key+' baseline')
check(before['packages']['plugin:tiktok-publisher']is None and not(web/'plugins/tiktok-publisher').exists(),'TikTok Publisher absent before first installation');check(before['options']is None,'Provider options migration is new');check(before['due']==0,'No production social publication is waiting during deployment')
for path in [*stage.glob('.src/TikTok*.php'),stage/'.src/tiktok-oauth.php',*stage.glob('.plugins/tiktok-publisher/**/*.php'),*stage.glob('.addons/social-publishing/**/*.php'),stage/'scripts/publish-tiktok-publisher-extension.php',stage/'tests/tiktok-broker.php',stage/'tests/tiktok-publisher.php',stage/'tests/tiktok-social-release.php']:run(['php8.5','-l',str(path)])
run(['python3','-m','py_compile',str(stage/'tests/tiktok-ui-production.py'),str(stage/'tests/package-catalog-http.py'),str(stage/'scripts/deploy-tiktok-publisher-0.1.0.py')]);run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t'])

lock=Path('/root/sensecms-private/deploy-workspace.lock').open('a+b');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
try:
    artifacts=stage/'artifacts';artifacts.mkdir(mode=0o700);build=r'''require$argv[1].'/bootstrap.php';$secret=base64_decode(trim(file_get_contents($argv[2])),true);if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid key');try{$a=App\Core\Packages\Archive::build($argv[3],$argv[4],$secret);echo json_encode(['sha256'=>$a['sha256'],'public'=>base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))],JSON_THROW_ON_ERROR);}finally{sodium_memzero($secret);}''';hashes={};public=''
    for kind,slug,version in [('addon','social-publishing','0.3.0'),('plugin','tiktok-publisher','0.1.0')]:
        archive=artifacts/f'{kind}-{slug}-{version}.zip';result=json.loads(php(build,web,publisher,stage/f'.{kind}s'/slug,archive));hashes[f'{kind}:{slug}']=result['sha256'];public=result['public']
    (artifacts/'tiktok-social-release.json').write_text(json.dumps(hashes,separators=(',',':')));(artifacts/'trust.json').write_text(json.dumps({'sensecms-release':public},separators=(',',':')));os.chmod(artifacts/'tiktok-social-release.json',0o600);os.chmod(artifacts/'trust.json',0o600);receipt['artifacts']=hashes
    testtree=stage/'runtime-tests';(testtree/'tests').mkdir(parents=True,mode=0o700);(testtree/'.cms').mkdir(mode=0o700);(testtree/'.cms/source').symlink_to(web,target_is_directory=True);(testtree/'.src').symlink_to(stage/'.src',target_is_directory=True);(testtree/'.plugins').symlink_to(stage/'.plugins',target_is_directory=True);(testtree/'.addons').symlink_to(stage/'.addons',target_is_directory=True)
    for name in ['tiktok-broker.php','tiktok-publisher.php','tiktok-social-release.php']:shutil.copy2(stage/'tests'/name,testtree/'tests'/name)
    check('25 TikTok broker checks passed' in run(['php8.5',str(testtree/'tests/tiktok-broker.php')]).decode(),'Isolated TikTok OAuth broker protocol');check('15 TikTok Publisher protocol checks passed' in run(['php8.5',str(testtree/'tests/tiktok-publisher.php')]).decode(),'Isolated TikTok publishing protocol');check('signed TikTok social package checks passed' in run(['php8.5',str(testtree/'tests/tiktok-social-release.php'),str(artifacts),str(artifacts/'trust.json')]).decode(),'Exact signed TikTok package lifecycle on isolated MariaDB')
    stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');backup=Path('/root/sensecms-backups')/(stamp+'-tiktok-publisher-010');backup.mkdir(mode=0o700);receipt['backup']=str(backup);(backup/'core').mkdir(mode=0o700);(backup/'website').mkdir(mode=0o700)
    for name in core_files:destination=backup/'core'/name;destination.parent.mkdir(parents=True,exist_ok=True,mode=0o700);shutil.copy2(web/name,destination)
    shutil.copy2(nginx,backup/'nginx-before.conf');shutil.copy2(artifacts/'addon-social-publishing-0.3.0.zip',backup/'addon-social-publishing-0.3.0.zip');shutil.copy2(artifacts/'plugin-tiktok-publisher-0.1.0.zip',backup/'plugin-tiktok-publisher-0.1.0.zip')
    with(backup/'database-before.sql').open('wb')as output:
        result=subprocess.run(['mysqldump','--single-transaction','--routines','--triggers','sensecms_site'],stdout=output,stderr=subprocess.PIPE)
        if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace').strip()or'Database backup failed.')
    os.chmod(backup/'database-before.sql',0o600);check((backup/'database-before.sql').stat().st_size>4096,'Private production database recovery dump')
    uid=int(run(['id','-u','sensecms']).decode());gid=int(run(['id','-g','sensecms']).decode());private.mkdir(mode=0o700);os.chown(private,uid,gid);created.append(private);key=private/'key.bin';key.write_bytes(os.urandom(32));os.chown(key,uid,gid);os.chmod(key,0o600);config_file=private/'config.json';config_file.write_text(json.dumps(cfg,separators=(',',':')));os.chown(config_file,uid,gid);os.chmod(config_file,0o600);del cfg
    for name in core_files:atomic(stage/'.cms/source'/name,web/name)
    for name in website_files:target=web/'website'/name;atomic(stage/'.src'/name,target);created.append(target)
    atomic(stage/'deploy/nginx/sensecms.com.conf',nginx);mutated=True;run(['nginx','-t']);run(['systemctl','reload','nginx']);run(['systemctl','reload','php8.5-fpm'])
    install_dir=web/'storage/private'/('tiktok-publisher-deploy-'+stamp);install_dir.mkdir(mode=0o700);os.chown(install_dir,uid,gid)
    for name in ['addon-social-publishing-0.3.0.zip','plugin-tiktok-publisher-0.1.0.zip']:
        target=install_dir/name;shutil.copy2(artifacts/name,target);os.chown(target,uid,gid);os.chmod(target,0o600);result=install(target);check(result.get('version')==name.rsplit('-',1)[1][:-4],'PackageManager installed '+name)
        if name.startswith('addon-'):upgraded=True
        else:installed=True
    run(['php8.5',str(stage/'scripts/publish-tiktok-publisher-extension.php'),str(web),'--apply',str(backup)]);published=True
    after=state();check(after['packages']['addon:social-publishing']=={'version':'0.3.0','active':1,'signature_status':'verified'},'Signed Social Publishing 0.3.0 active');check(after['packages']['plugin:tiktok-publisher']=={'version':'0.1.0','active':1,'signature_status':'verified'},'Signed TikTok Publisher 0.1.0 active');check(after['social']==before['social']and after['options']==0,'Existing social data preserved exactly with empty provider options')
    for source,target in [*((stage/'.cms/source'/name,web/name)for name in core_files),*((stage/'.src'/name,web/'website'/name)for name in website_files)]:check(sha(source)==sha(target),'Deployed checksum '+target.name)
    worker=json.loads(run(['php8.5',str(web/'addons/social-publishing/scripts/social-worker.php'),str(web)],user='sensecms'));check(worker.get('ok')is True and worker.get('published')==0,'Production worker healthy without publishing content')
    check('PASS Production TikTok workspace' in run(['python3',str(stage/'tests/tiktok-ui-production.py')]).decode(),'Authenticated TikTok workspace and assets');check('21 product prices/licence policies' in run(['python3',str(stage/'tests/package-catalog-http.py'),'https://www.sensecms.com']).decode(),'Public TikTok marketplace and catalogue')
    start=r'''$r=$argv[1];require$r.'/bootstrap.php';require$r.'/plugins/tiktok-publisher/src/TikTokOnboardingClient.php';$runtime=new App\Core\Runtime($r);$installed=$runtime->read('installed');$root=$r;$baseUrl=$runtime->baseUrl();$cfg=require$r.'/config/workspace.php';$c=new SenseCMS\TikTok\TikTokOnboardingClient($runtime->license(),$cfg['integrations']['tiktok_social_broker_url'],$baseUrl);$v=$c->start();if(!str_starts_with((string)$v,'https://www.sensecms.com/api/social/tiktok/v1/authorize?request='))throw new RuntimeException('Invalid broker result');echo'PASS';''';check(php(start,web,user='sensecms')==b'PASS','Licensed installation reaches configured TikTok broker')
    request=urllib.request.Request('https://www.sensecms.com/api/social/tiktok/v1/start',data=b'{}',headers={'Content-Type':'application/json'},method='POST')
    try:urllib.request.urlopen(request,timeout=30);status=200
    except urllib.error.HTTPError as error:status=error.code
    check(status==401,'TikTok broker rejects unlicensed public requests');run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t']);logs=run(['journalctl','--since','@'+str(started),'--no-pager','-u','nginx','-u','php8.5-fpm']).decode(errors='replace');check(not re.search(r'PHP Fatal|\bcrit\b|\bpanic\b',logs,re.I),'No fresh critical service errors');receipt['status']='deployed-sandbox-ready';shutil.rmtree(install_dir);install_dir=None
except BaseException:
    receipt['status']='rolling-back' if mutated else 'preflight-failed'
    if mutated and backup is not None:
        if published:
            try:run(['php8.5',str(stage/'scripts/publish-tiktok-publisher-extension.php'),str(web),'--rollback',str(backup)])
            except BaseException:pass
        if installed or upgraded:
            try:rollback_packages()
            except BaseException:pass
        for name in core_files:atomic(backup/'core'/name,web/name)
        for target in reversed(created):
            if target.is_dir()and target==private:shutil.rmtree(target)
            elif target.exists():target.unlink()
        atomic(backup/'nginx-before.conf',nginx);run(['nginx','-t']);run(['systemctl','reload','nginx']);run(['systemctl','reload','php8.5-fpm']);receipt['status']='rolled-back'
    raise
finally:
    if install_dir is not None and install_dir.is_dir()and install_dir.name.startswith('tiktok-publisher-deploy-')and install_dir.parent==web/'storage/private':shutil.rmtree(install_dir)
    if backup is not None:(backup/'receipt.json').write_text(json.dumps(receipt,indent=2,sort_keys=True)+'\n');os.chmod(backup/'receipt.json',0o600)
    fcntl.flock(lock,fcntl.LOCK_UN);lock.close()
print(json.dumps(receipt,sort_keys=True),flush=True)
