#!/usr/bin/env python3
"""Build, verify and deploy YouTube Publisher 0.1.0 with private-only uploads."""
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

if os.name=='nt' or os.geteuid()!=0 or len(sys.argv)!=2:raise SystemExit('Run as root on Linux: deploy-youtube-publisher-0.1.0.py <private-stage>')
os.umask(0o077)
stage=Path(sys.argv[1]).resolve();web=Path('/home/sensecms.com/web');nginx=Path('/etc/nginx/sites-available/sensecms.com');publisher=Path('/root/sensecms-private/publisher.ed25519');private=web/'storage/private/youtube';core_files=['app/Core/LicenseService.php','config/workspace.php'];website_files=['YouTubeBrokerService.php','YouTubeOAuthEndpoint.php','youtube-oauth.php'];expected={web/core_files[0]:'7f8d826d7d5a8689c96779024fe5f9e7ced3e11a66e2d39564ed1721c962239d',web/core_files[1]:'dfef946debbc4c77d730c26bce1fe17033204deea654acb156cecb6bc640180c',nginx:'df3c57e24cfc07c3c64f096267a0bd79aa27771b47bb84db1ddfc48c4429748d'}
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
    temporary=target.with_name(target.name+'.youtube-new');shutil.copy2(source,temporary)
    if target.exists():stat=target.stat();os.chown(temporary,stat.st_uid,stat.st_gid);os.chmod(temporary,stat.st_mode&0o777)
    else:os.chown(temporary,0,0);os.chmod(temporary,0o644)
    os.replace(temporary,target)
def config():
    raw=json.loads((stage/'.cfg/youtube.json').read_text(encoding='utf-8-sig'));values=raw.get('web')or raw.get('installed')or{};client=str(values.get('client_id',''));secret=str(values.get('client_secret',''));redirects=values.get('redirect_uris')or[]
    if not re.fullmatch(r'[0-9]+-[A-Za-z0-9_-]+\.apps\.googleusercontent\.com',client)or not re.fullmatch(r'[^\x00-\x20]{8,512}',secret)or'https://www.sensecms.com/api/social/youtube/v1/callback'not in redirects:raise RuntimeError('Private YouTube OAuth configuration is incomplete.')
    return{'client_id':client,'client_secret':secret,'public_uploads':False}
def state():
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$packages=[];foreach([['addon','social-publishing'],['plugin','facebook-publisher'],['plugin','x-publisher'],['plugin','linkedin-publisher'],['plugin','bluesky-publisher'],['plugin','mastodon-publisher'],['plugin','telegram-channels-publisher'],['plugin','pinterest-publisher'],['plugin','tiktok-publisher'],['plugin','youtube-publisher']]as$p){$q=$db->prepare("SELECT version,active,signature_status FROM extension_packages WHERE type=? AND slug=?");$q->execute($p);$packages[$p[0].':'.$p[1]]=$q->fetch()?:null;}$social=[];foreach(['social_connections'=>'SELECT * FROM social_connections ORDER BY id','social_post_targets'=>'SELECT * FROM social_post_targets ORDER BY id','social_deliveries'=>'SELECT * FROM social_deliveries ORDER BY id']as$t=>$sql){$rows=$db->query($sql)->fetchAll();$social[$t]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];}$due=(int)$db->query("SELECT COUNT(*) FROM social_post_targets t INNER JOIN posts p ON p.id=t.post_id WHERE t.enabled=1 AND t.last_enqueued_revision<t.revision AND (p.status='published' OR (p.status='scheduled' AND p.published_at<=UTC_TIMESTAMP()))")->fetchColumn();echo json_encode(['packages'=>$packages,'social'=>$social,'due'=>$due],JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web))
def install(archive):
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();$s=$m->stageLocalFile($argv[2],$owner);echo json_encode($m->install($s['token'],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web,archive,user='sensecms'))
def rollback_packages():
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();if($m->package('plugin','youtube-publisher'))$m->uninstall('plugin','youtube-publisher',$owner);if(($m->package('addon','social-publishing')['version']??'')==='0.4.0')$m->rollback('addon','social-publishing',$owner);'''
    php(code,web,user='sensecms')

required=[stage/'.cfg/youtube.json',*(stage/'.src'/name for name in website_files),stage/'.src/package-catalog.php',*(stage/'.cms/source'/name for name in core_files),stage/'.addons/social-publishing/sense-package.json',stage/'.addons/social-publishing/addon.json',stage/'.plugins/youtube-publisher/sense-package.json',stage/'.plugins/youtube-publisher/plugin.json',stage/'deploy/nginx/sensecms.com.conf',stage/'scripts/publish-youtube-publisher-extension.php',stage/'tests/youtube-broker.php',stage/'tests/youtube-publisher.php',stage/'tests/youtube-social-release.php',stage/'tests/youtube-ui-production.py',stage/'tests/package-catalog-http.py']
check(stage.is_dir()and str(stage).startswith('/root/sense-youtube-publisher-'),'Private scoped YouTube deployment stage');check(all(path.is_file()and not path.is_symlink()for path in required),'Complete YouTube candidate payload');cfg=config();check(web.is_dir()and nginx.is_file()and publisher.is_file(),'Production installation and signing runtime');check(all(path.is_file()and sha(path)==digest for path,digest in expected.items()),'Pinned production Core and Nginx baseline');check(all(not(web/'website'/name).exists()for name in website_files)and not private.exists(),'YouTube broker and private runtime absent before first deployment')
before=state();baseline={'addon:social-publishing':('0.3.0',1,'verified'),'plugin:facebook-publisher':('0.2.1',1,'verified'),'plugin:x-publisher':('0.1.1',1,'verified'),'plugin:linkedin-publisher':('0.1.2',1,'verified'),'plugin:bluesky-publisher':('0.1.3',1,'verified'),'plugin:mastodon-publisher':('0.1.1',1,'verified'),'plugin:telegram-channels-publisher':('0.1.1',1,'verified'),'plugin:pinterest-publisher':('0.1.1',1,'verified'),'plugin:tiktok-publisher':('0.1.1',1,'verified')}
for key,value in baseline.items():item=before['packages'][key];check(item is not None and tuple(item.values())==value,'Installed '+key+' baseline')
check(before['packages']['plugin:youtube-publisher']is None and not(web/'plugins/youtube-publisher').exists(),'YouTube Publisher absent before first installation');check(before['due']==0,'No production social publication is waiting during deployment')
for path in [*stage.glob('.src/YouTube*.php'),stage/'.src/youtube-oauth.php',*stage.glob('.plugins/youtube-publisher/**/*.php'),*stage.glob('.addons/social-publishing/**/*.php'),stage/'scripts/publish-youtube-publisher-extension.php',stage/'tests/youtube-broker.php',stage/'tests/youtube-publisher.php',stage/'tests/youtube-social-release.php']:run(['php8.5','-l',str(path)])
run(['python3','-m','py_compile',str(stage/'tests/youtube-ui-production.py'),str(stage/'tests/package-catalog-http.py'),str(stage/'scripts/deploy-youtube-publisher-0.1.0.py')]);run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t'])

lock=Path('/root/sensecms-private/deploy-workspace.lock').open('a+b');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
try:
    artifacts=stage/'artifacts';artifacts.mkdir(mode=0o700);build=r'''require$argv[1].'/bootstrap.php';$secret=base64_decode(trim(file_get_contents($argv[2])),true);if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid key');try{$a=App\Core\Packages\Archive::build($argv[3],$argv[4],$secret);echo json_encode(['sha256'=>$a['sha256'],'public'=>base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))],JSON_THROW_ON_ERROR);}finally{sodium_memzero($secret);}''';hashes={};public=''
    for kind,slug,version in [('addon','social-publishing','0.4.0'),('plugin','youtube-publisher','0.1.0')]:
        archive=artifacts/f'{kind}-{slug}-{version}.zip';result=json.loads(php(build,web,publisher,stage/f'.{kind}s'/slug,archive));hashes[f'{kind}:{slug}']=result['sha256'];public=result['public']
    (artifacts/'youtube-social-release.json').write_text(json.dumps(hashes,separators=(',',':')));(artifacts/'trust.json').write_text(json.dumps({'sensecms-release':public},separators=(',',':')));os.chmod(artifacts/'youtube-social-release.json',0o600);os.chmod(artifacts/'trust.json',0o600);receipt['artifacts']=hashes
    testtree=stage/'runtime-tests';(testtree/'tests').mkdir(parents=True,mode=0o700);(testtree/'.cms').mkdir(mode=0o700);(testtree/'.cms/source').symlink_to(web,target_is_directory=True);(testtree/'.src').symlink_to(stage/'.src',target_is_directory=True);(testtree/'.plugins').symlink_to(stage/'.plugins',target_is_directory=True);(testtree/'.addons').symlink_to(stage/'.addons',target_is_directory=True)
    for name in ['youtube-broker.php','youtube-publisher.php','youtube-social-release.php']:shutil.copy2(stage/'tests'/name,testtree/'tests'/name)
    check('21 YouTube broker checks passed' in run(['php8.5',str(testtree/'tests/youtube-broker.php')]).decode(),'Isolated YouTube OAuth broker protocol');check('11 YouTube Publisher protocol checks passed' in run(['php8.5',str(testtree/'tests/youtube-publisher.php')]).decode(),'Isolated YouTube publishing protocol');check('signed YouTube social package checks passed' in run(['php8.5',str(testtree/'tests/youtube-social-release.php'),str(artifacts),str(artifacts/'trust.json')]).decode(),'Exact signed YouTube package lifecycle on isolated MariaDB')
    stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');backup=Path('/root/sensecms-backups')/(stamp+'-youtube-publisher-010');backup.mkdir(mode=0o700);receipt['backup']=str(backup);(backup/'core').mkdir(mode=0o700)
    for name in core_files:destination=backup/'core'/name;destination.parent.mkdir(parents=True,exist_ok=True,mode=0o700);shutil.copy2(web/name,destination)
    shutil.copy2(nginx,backup/'nginx-before.conf');shutil.copy2(artifacts/'addon-social-publishing-0.4.0.zip',backup/'addon-social-publishing-0.4.0.zip');shutil.copy2(artifacts/'plugin-youtube-publisher-0.1.0.zip',backup/'plugin-youtube-publisher-0.1.0.zip')
    with(backup/'database-before.sql').open('wb')as output:
        result=subprocess.run(['mysqldump','--single-transaction','--routines','--triggers','sensecms_site'],stdout=output,stderr=subprocess.PIPE)
        if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace').strip()or'Database backup failed.')
    os.chmod(backup/'database-before.sql',0o600);check((backup/'database-before.sql').stat().st_size>4096,'Private production database recovery dump')
    uid=int(run(['id','-u','sensecms']).decode());gid=int(run(['id','-g','sensecms']).decode());private.mkdir(mode=0o700);os.chown(private,uid,gid);created.append(private);key=private/'key.bin';key.write_bytes(os.urandom(32));os.chown(key,uid,gid);os.chmod(key,0o600);config_file=private/'config.json';config_file.write_text(json.dumps(cfg,separators=(',',':')));os.chown(config_file,uid,gid);os.chmod(config_file,0o600);del cfg
    for name in core_files:atomic(stage/'.cms/source'/name,web/name)
    for name in website_files:target=web/'website'/name;atomic(stage/'.src'/name,target);created.append(target)
    atomic(stage/'deploy/nginx/sensecms.com.conf',nginx);mutated=True;run(['nginx','-t']);run(['systemctl','reload','nginx']);run(['systemctl','reload','php8.5-fpm'])
    install_dir=web/'storage/private'/('youtube-publisher-deploy-'+stamp);install_dir.mkdir(mode=0o700);os.chown(install_dir,uid,gid)
    for name in ['addon-social-publishing-0.4.0.zip','plugin-youtube-publisher-0.1.0.zip']:
        target=install_dir/name;shutil.copy2(artifacts/name,target);os.chown(target,uid,gid);os.chmod(target,0o600);result=install(target);check(result.get('version')==name.rsplit('-',1)[1][:-4],'PackageManager installed '+name)
        if name.startswith('addon-'):upgraded=True
        else:installed=True
    run(['php8.5',str(stage/'scripts/publish-youtube-publisher-extension.php'),str(web),'--apply',str(backup)]);published=True
    after=state();check(after['packages']['addon:social-publishing']=={'version':'0.4.0','active':1,'signature_status':'verified'},'Signed Social Publishing 0.4.0 active');check(after['packages']['plugin:youtube-publisher']=={'version':'0.1.0','active':1,'signature_status':'verified'},'Signed YouTube Publisher 0.1.0 active');check(after['social']==before['social'],'Existing social connections, targets and deliveries preserved exactly')
    for source,target in [*((stage/'.cms/source'/name,web/name)for name in core_files),*((stage/'.src'/name,web/'website'/name)for name in website_files)]:check(sha(source)==sha(target),'Deployed checksum '+target.name)
    worker=json.loads(run(['php8.5',str(web/'addons/social-publishing/scripts/social-worker.php'),str(web)],user='sensecms'));check(worker.get('ok')is True and worker.get('published')==0,'Production worker healthy without publishing content')
    check('PASS Production YouTube workspace' in run(['python3',str(stage/'tests/youtube-ui-production.py')]).decode(),'Authenticated YouTube workspace, assets and media picker');check('22 product prices/licence policies' in run(['python3',str(stage/'tests/package-catalog-http.py'),'https://www.sensecms.com']).decode(),'Public YouTube marketplace and catalogue')
    start=r'''$r=$argv[1];require$r.'/bootstrap.php';require$r.'/plugins/youtube-publisher/src/YouTubeOnboardingClient.php';$runtime=new App\Core\Runtime($r);$installed=$runtime->read('installed');$root=$r;$baseUrl=$runtime->baseUrl();$cfg=require$r.'/config/workspace.php';$c=new SenseCMS\YouTube\YouTubeOnboardingClient($runtime->license(),$cfg['integrations']['youtube_social_broker_url'],$baseUrl);$v=$c->start();if(!str_starts_with((string)$v,'https://www.sensecms.com/api/social/youtube/v1/authorize?request='))throw new RuntimeException('Invalid broker result');echo'PASS';''';check(php(start,web,user='sensecms')==b'PASS','Licensed installation reaches configured YouTube broker')
    request=urllib.request.Request('https://www.sensecms.com/api/social/youtube/v1/start',data=b'{}',headers={'Content-Type':'application/json'},method='POST')
    try:urllib.request.urlopen(request,timeout=30);status=200
    except urllib.error.HTTPError as error:status=error.code
    check(status==401,'YouTube broker rejects unlicensed public requests');run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t']);logs=run(['journalctl','--since','@'+str(started),'--no-pager','-u','nginx','-u','php8.5-fpm']).decode(errors='replace');check(not re.search(r'PHP Fatal|\bcrit\b|\bpanic\b',logs,re.I),'No fresh critical service errors');receipt['status']='deployed-private-upload-ready';shutil.rmtree(install_dir);install_dir=None
except BaseException:
    receipt['status']='rolling-back' if mutated else 'preflight-failed'
    if mutated and backup is not None:
        if published:
            try:run(['php8.5',str(stage/'scripts/publish-youtube-publisher-extension.php'),str(web),'--rollback',str(backup)])
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
    if install_dir is not None and install_dir.is_dir()and install_dir.name.startswith('youtube-publisher-deploy-')and install_dir.parent==web/'storage/private':shutil.rmtree(install_dir)
    if backup is not None:(backup/'receipt.json').write_text(json.dumps(receipt,indent=2,sort_keys=True)+'\n');os.chmod(backup/'receipt.json',0o600)
    fcntl.flock(lock,fcntl.LOCK_UN);lock.close()
print(json.dumps(receipt,sort_keys=True),flush=True)
