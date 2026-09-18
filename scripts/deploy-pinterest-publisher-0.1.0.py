#!/usr/bin/env python3
"""Build, verify and deploy Pinterest Publisher 0.1.0 pending Pinterest access."""
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
    raise SystemExit('Run as root on Linux: deploy-pinterest-publisher-0.1.0.py <private-stage>')

stage=Path(sys.argv[1]).resolve();web=Path('/home/sensecms.com/web');nginx=Path('/etc/nginx/sites-available/sensecms.com');publisher=Path('/root/sensecms-private/publisher.ed25519')
core_files=['app/Core/LicenseService.php','config/workspace.php'];website_files=['PinterestBrokerService.php','PinterestOAuthEndpoint.php','pinterest-oauth.php']
expected={web/core_files[0]:'2e2621f541184e65037235321fef3271acb69fa40f1b826dd8474233bc5e1bd4',web/core_files[1]:'ee1984201465da30589a6cf013e1d4b230bfeb6cda625f6dfd2f55df0939c21a',nginx:'c004502c37c8ce419c640e634a1dbdd0d89bd81e62a41b249aba6960e3f42523'}
receipt={'status':'preflight','checks':[]};backup=install_dir=None;installed=mutated=published=False;created=[]

def run(command,*,user=None):
    if user:command=['sudo','-u',user,*command]
    result=subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
    if result.returncode:raise RuntimeError(result.stdout.decode(errors='replace').strip() or f'Command failed: {command[0]}')
    return result.stdout
def php(code,*args,user=None):return run(['php8.5','-r',code,*map(str,args)],user=user)
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def check(ok,label):
    if not ok:raise RuntimeError(label)
    receipt['checks'].append(label);print('PASS '+label,flush=True)
def atomic(source,target):
    temporary=target.with_name(target.name+'.pinterest-new');shutil.copy2(source,temporary)
    if target.exists():stat=target.stat();os.chown(temporary,stat.st_uid,stat.st_gid);os.chmod(temporary,stat.st_mode&0o777)
    else:os.chown(temporary,0,0);os.chmod(temporary,0o644)
    os.replace(temporary,target)
def state():
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$packages=[];foreach([['addon','social-publishing'],['plugin','facebook-publisher'],['plugin','x-publisher'],['plugin','linkedin-publisher'],['plugin','bluesky-publisher'],['plugin','mastodon-publisher'],['plugin','telegram-channels-publisher'],['plugin','pinterest-publisher']]as$p){$q=$db->prepare("SELECT version,active,signature_status FROM extension_packages WHERE type=? AND slug=?");$q->execute($p);$packages[$p[0].':'.$p[1]]=$q->fetch()?:null;}$tables=['social_connections','social_post_targets','social_deliveries'];$rows=[];$fingerprints=[];foreach($tables as$t){$data=$db->query("SELECT * FROM `$t` ORDER BY 1")->fetchAll();$rows[$t]=count($data);$fingerprints[$t]=hash('sha256',json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}$due=(int)$db->query("SELECT COUNT(*) FROM social_post_targets t INNER JOIN posts p ON p.id=t.post_id WHERE t.enabled=1 AND t.last_enqueued_revision<t.revision AND (p.status='published' OR (p.status='scheduled' AND p.published_at<=UTC_TIMESTAMP()))")->fetchColumn();echo json_encode(['packages'=>$packages,'rows'=>$rows,'fingerprints'=>$fingerprints,'due'=>$due],JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web))
def install(archive):
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();$s=$m->stageLocalFile($argv[2],$owner);echo json_encode($m->install($s['token'],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web,archive,user='sensecms'))
def uninstall():
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();if($m->package('plugin','pinterest-publisher'))$m->uninstall('plugin','pinterest-publisher',$owner);'''
    php(code,web,user='sensecms')

required=[*(stage/'.src'/name for name in website_files),stage/'.src/package-catalog.php',*(stage/'.cms/source'/name for name in core_files),stage/'.addons/social-publishing/sense-package.json',stage/'.addons/social-publishing/addon.json',stage/'.plugins/pinterest-publisher/sense-package.json',stage/'.plugins/pinterest-publisher/plugin.json',stage/'deploy/nginx/sensecms.com.conf',stage/'scripts/publish-pinterest-publisher-extension.php',stage/'tests/pinterest-broker.php',stage/'tests/pinterest-publisher.php',stage/'tests/pinterest-social-release.php',stage/'tests/pinterest-ui-production.py',stage/'tests/package-catalog-http.py']
check(stage.is_dir()and str(stage).startswith('/root/sense-pinterest-publisher-'),'Private scoped Pinterest deployment stage');check(all(path.is_file()and not path.is_symlink()for path in required),'Complete Pinterest candidate payload');check(web.is_dir()and nginx.is_file()and publisher.is_file(),'Production installation and signing runtime');check(all(path.is_file()and sha(path)==digest for path,digest in expected.items()),'Pinned production Core and Nginx baseline');check(all(not(web/'website'/name).exists()for name in website_files),'Pinterest broker files absent before first deployment')
before=state();baseline={'addon:social-publishing':('0.2.2',1,'verified'),'plugin:facebook-publisher':('0.2.1',1,'verified'),'plugin:x-publisher':('0.1.1',1,'verified'),'plugin:linkedin-publisher':('0.1.1',1,'verified'),'plugin:bluesky-publisher':('0.1.3',1,'verified'),'plugin:mastodon-publisher':('0.1.1',1,'verified'),'plugin:telegram-channels-publisher':('0.1.1',1,'verified')}
for key,value in baseline.items():item=before['packages'][key];check(item is not None and tuple(item.values())==value,'Installed '+key+' baseline')
check(before['packages']['plugin:pinterest-publisher']is None and not(web/'plugins/pinterest-publisher').exists(),'Pinterest Publisher absent before first installation')
for path in [*stage.glob('.src/Pinterest*.php'),stage/'.src/pinterest-oauth.php',*stage.glob('.plugins/pinterest-publisher/**/*.php'),stage/'scripts/publish-pinterest-publisher-extension.php',stage/'tests/pinterest-broker.php',stage/'tests/pinterest-publisher.php',stage/'tests/pinterest-social-release.php']:run(['php8.5','-l',str(path)])
run(['python3','-m','py_compile',str(stage/'tests/pinterest-ui-production.py'),str(stage/'tests/package-catalog-http.py'),str(stage/'scripts/deploy-pinterest-publisher-0.1.0.py')]);run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t'])

lock=Path('/root/sensecms-private/deploy-workspace.lock').open('a+b');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
try:
    artifacts=stage/'artifacts';artifacts.mkdir(mode=0o700);build=r'''require$argv[1].'/bootstrap.php';$secret=base64_decode(trim(file_get_contents($argv[2])),true);if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid key');try{$a=App\Core\Packages\Archive::build($argv[3],$argv[4],$secret);echo json_encode(['sha256'=>$a['sha256'],'public'=>base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))],JSON_THROW_ON_ERROR);}finally{sodium_memzero($secret);}''';hashes={};public=''
    for kind,slug,version in [('addon','social-publishing','0.2.2'),('plugin','pinterest-publisher','0.1.0')]:
        archive=artifacts/f'{kind}-{slug}-{version}.zip';result=json.loads(php(build,web,publisher,stage/f'.{kind}s'/slug,archive));hashes[f'{kind}:{slug}']=result['sha256'];public=result['public']
    (artifacts/'pinterest-social-release.json').write_text(json.dumps(hashes,separators=(',',':')));(artifacts/'trust.json').write_text(json.dumps({'sensecms-release':public},separators=(',',':')));os.chmod(artifacts/'pinterest-social-release.json',0o600);os.chmod(artifacts/'trust.json',0o600);receipt['artifacts']=hashes
    testtree=stage/'runtime-tests';(testtree/'tests').mkdir(parents=True,mode=0o700);(testtree/'.cms').mkdir(mode=0o700);(testtree/'.cms/source').symlink_to(web,target_is_directory=True);(testtree/'.src').symlink_to(stage/'.src',target_is_directory=True);(testtree/'.plugins').symlink_to(stage/'.plugins',target_is_directory=True);(testtree/'.addons').symlink_to(stage/'.addons',target_is_directory=True)
    for name in ['pinterest-broker.php','pinterest-publisher.php','pinterest-social-release.php']:shutil.copy2(stage/'tests'/name,testtree/'tests'/name)
    check('25 Pinterest broker checks passed' in run(['php8.5',str(testtree/'tests/pinterest-broker.php')]).decode(),'Isolated Pinterest OAuth broker protocol');check('17 Pinterest Publisher protocol checks passed' in run(['php8.5',str(testtree/'tests/pinterest-publisher.php')]).decode(),'Isolated Pinterest publishing protocol');check('signed Pinterest social package checks passed' in run(['php8.5',str(testtree/'tests/pinterest-social-release.php'),str(artifacts),str(artifacts/'trust.json')]).decode(),'Exact signed Pinterest package lifecycle on isolated MariaDB')
    stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');backup=Path('/root/sensecms-backups')/(stamp+'-pinterest-publisher-010');backup.mkdir(mode=0o700);receipt['backup']=str(backup);(backup/'core').mkdir(mode=0o700);(backup/'website').mkdir(mode=0o700)
    for name in core_files:destination=backup/'core'/name;destination.parent.mkdir(parents=True,exist_ok=True,mode=0o700);shutil.copy2(web/name,destination)
    shutil.copy2(nginx,backup/'nginx-before.conf');shutil.copy2(artifacts/'plugin-pinterest-publisher-0.1.0.zip',backup/'plugin-pinterest-publisher-0.1.0.zip')
    with(backup/'database-before.sql').open('wb')as output:
        result=subprocess.run(['mysqldump','--single-transaction','--routines','--triggers','sensecms_site'],stdout=output,stderr=subprocess.PIPE)
        if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace').strip()or'Database backup failed.')
    os.chmod(backup/'database-before.sql',0o600);check((backup/'database-before.sql').stat().st_size>4096,'Private production database recovery dump')
    for name in core_files:atomic(stage/'.cms/source'/name,web/name)
    for name in website_files:target=web/'website'/name;atomic(stage/'.src'/name,target);created.append(target)
    atomic(stage/'deploy/nginx/sensecms.com.conf',nginx);mutated=True;run(['nginx','-t']);run(['systemctl','reload','nginx']);run(['systemctl','reload','php8.5-fpm'])
    uid=int(run(['id','-u','sensecms']).decode());gid=int(run(['id','-g','sensecms']).decode());install_dir=web/'storage/private'/('pinterest-publisher-deploy-'+stamp);install_dir.mkdir(mode=0o700);os.chown(install_dir,uid,gid);candidate=install_dir/'plugin-pinterest-publisher-0.1.0.zip';shutil.copy2(artifacts/candidate.name,candidate);os.chown(candidate,uid,gid);os.chmod(candidate,0o600);result=install(candidate);installed=True;check(result.get('version')=='0.1.0','PackageManager installed Pinterest Publisher 0.1.0')
    run(['php8.5',str(stage/'scripts/publish-pinterest-publisher-extension.php'),str(web),'--apply',str(backup)]);published=True
    after=state();check(after['packages']['plugin:pinterest-publisher']=={'version':'0.1.0','active':1,'signature_status':'verified'},'Signed Pinterest Publisher 0.1.0 active');check(after['rows']==before['rows']and after['fingerprints']==before['fingerprints'],'Existing social connections, targets and deliveries preserved exactly')
    for source,target in [*((stage/'.cms/source'/name,web/name)for name in core_files),*((stage/'.src'/name,web/'website'/name)for name in website_files)]:check(sha(source)==sha(target),'Deployed checksum '+target.name)
    if after['due']==0:worker=json.loads(run(['php8.5',str(web/'addons/social-publishing/scripts/social-worker.php'),str(web)],user='sensecms'));check(worker.get('ok')is True and worker.get('published')==0,'Production worker healthy without publishing content')
    check('PASS Production Pinterest workspace' in run(['python3',str(stage/'tests/pinterest-ui-production.py')]).decode(),'Authenticated Pinterest workspace and assets');check('20 product prices/licence policies' in run(['python3',str(stage/'tests/package-catalog-http.py'),'https://www.sensecms.com']).decode(),'Public Pinterest marketplace and catalogue')
    request=urllib.request.Request('https://www.sensecms.com/api/social/pinterest/v1/start',data=b'{}',headers={'Content-Type':'application/json'},method='POST')
    try:urllib.request.urlopen(request,timeout=30);status=200
    except urllib.error.HTTPError as error:status=error.code
    check(status==503,'Pinterest broker route fails closed before approved app secret');run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t']);receipt['status']='deployed-awaiting-pinterest-access';shutil.rmtree(install_dir);install_dir=None
except BaseException:
    receipt['status']='rolling-back' if mutated else 'preflight-failed'
    if mutated and backup is not None:
        if published:
            try:run(['php8.5',str(stage/'scripts/publish-pinterest-publisher-extension.php'),str(web),'--rollback',str(backup)])
            except BaseException:pass
        if installed:
            try:uninstall()
            except BaseException:pass
        for name in core_files:atomic(backup/'core'/name,web/name)
        for target in created:
            if target.exists():target.unlink()
        atomic(backup/'nginx-before.conf',nginx);run(['nginx','-t']);run(['systemctl','reload','nginx']);run(['systemctl','reload','php8.5-fpm']);receipt['status']='rolled-back'
    raise
finally:
    if install_dir is not None and install_dir.is_dir()and install_dir.name.startswith('pinterest-publisher-deploy-')and install_dir.parent==web/'storage/private':shutil.rmtree(install_dir)
    if backup is not None:(backup/'receipt.json').write_text(json.dumps(receipt,indent=2,sort_keys=True)+'\n');os.chmod(backup/'receipt.json',0o600)
    fcntl.flock(lock,fcntl.LOCK_UN);lock.close()
print(json.dumps(receipt,sort_keys=True),flush=True)
