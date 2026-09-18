#!/usr/bin/env python3
"""Build, verify, deploy and publish Telegram Channels Publisher 0.1.0."""
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

if os.name=='nt' or os.geteuid()!=0 or len(sys.argv)!=2:raise SystemExit('Run as root on Linux: deploy-telegram-channels-publisher-0.1.0.py <private-stage>')
stage=Path(sys.argv[1]).resolve();web=Path('/home/sensecms.com/web');distribution=web/'storage/distribution.json';releases=web/'storage/distribution/releases';publisher=Path('/root/sensecms-private/publisher.ed25519');broker=web/'website/TelegramBrokerService.php';endpoint=web/'website/TelegramWebhook.php';identity='plugin:telegram-channels-publisher';receipt={'status':'preflight','checks':[]};backup=install_dir=target=None;addon_updated=plugin_installed=distribution_changed=broker_changed=endpoint_changed=marketplace_changed=False
expected={broker:'2e8016897192f7b5eaf1d95270b2e82412badc4bfd6c8666ec202b12ab8f1700',endpoint:'a23cf51f804b25ccbf119669b56e53f60abdb7d776bd5c68126491ff90a72c49'}

def run(cmd,*,user=None):
    if user:cmd=['sudo','-u',user,*cmd]
    result=subprocess.run(cmd,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
    if result.returncode:raise RuntimeError(result.stdout.decode(errors='replace').strip() or f'Command failed: {cmd[0]}')
    return result.stdout
def php(code,*args,user=None):return run(['php8.5','-r',code,*map(str,args)],user=user)
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def check(ok,label):
    if not ok:raise RuntimeError(label)
    receipt['checks'].append(label);print('PASS '+label,flush=True)
def atomic_file(source,destination):
    temporary=destination.with_name(destination.name+'.telegram-channels-new');shutil.copy2(source,temporary);stat=destination.stat();os.chown(temporary,stat.st_uid,stat.st_gid);os.chmod(temporary,stat.st_mode&0o777);os.replace(temporary,destination)
def atomic_bytes(destination,payload):
    temporary=destination.with_name(destination.name+'.telegram-channels-new');temporary.write_bytes(payload);stat=destination.stat();os.chown(temporary,stat.st_uid,stat.st_gid);os.chmod(temporary,stat.st_mode&0o777);os.replace(temporary,destination)
def state():
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$packages=[];foreach([['addon','social-publishing'],['plugin','telegram-channels-publisher']]as$p){$q=$db->prepare('SELECT version,active,signature_status FROM extension_packages WHERE type=? AND slug=?');$q->execute($p);$packages[implode(':',$p)]=$q->fetch()?:null;}$social=[];foreach(['social_connections','social_post_targets','social_deliveries']as$t){$rows=$db->query("SELECT * FROM `$t` ORDER BY 1")->fetchAll();$social[$t]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];}$due=(int)$db->query("SELECT COUNT(*) FROM social_post_targets t INNER JOIN posts p ON p.id=t.post_id WHERE t.enabled=1 AND t.last_enqueued_revision<t.revision AND (p.status='published' OR (p.status='scheduled' AND p.published_at<=UTC_TIMESTAMP()))")->fetchColumn();echo json_encode(['packages'=>$packages,'social'=>$social,'due'=>$due],JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web))
def install(archive):
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();$s=$m->stageLocalFile($argv[2],$owner);echo json_encode($m->install($s['token'],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web,archive,user='sensecms'))
def package_action(action,kind,slug):
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();echo json_encode($argv[2]==='uninstall'?$m->uninstall($argv[3],$argv[4],$owner):$m->rollback($argv[3],$argv[4],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web,action,kind,slug,user='sensecms'))

required=[stage/'.src/TelegramBrokerService.php',stage/'.src/TelegramWebhook.php',stage/'.addons/social-publishing/sense-package.json',stage/'.plugins/telegram-channels-publisher/sense-package.json',stage/'.plugins/telegram-channels-publisher/plugin.json',stage/'scripts/publish-telegram-channels-extension.php',stage/'tests/telegram-channels-broker.php',stage/'tests/telegram-channels-publisher.php',stage/'tests/telegram-channels-social-release.php',stage/'tests/telegram-channels-ui-production.py',stage/'tests/package-catalog-http.py']
check(stage.is_dir() and str(stage).startswith('/root/sense-telegram-channels-'),'Private scoped Telegram Channels deployment stage');check(all(path.is_file() and not path.is_symlink() for path in required),'Complete Telegram Channels 0.1.0 candidate');check(web.is_dir() and distribution.is_file() and releases.is_dir() and publisher.is_file(),'Production package and distribution runtime');check(all(path.is_file() and sha(path)==digest for path,digest in expected.items()),'Pinned Telegram broker baseline')
before=state();check(before['packages']['addon:social-publishing']=={'version':'0.2.1','active':1,'signature_status':'verified'},'Signed Social Publishing 0.2.1 baseline');check(before['packages'][identity] is None and not (web/'plugins/telegram-channels-publisher').exists(),'Telegram Channels Publisher absent before first installation');config=json.loads(distribution.read_text());check(identity not in config.get('products',{}),'Telegram Channels distribution identity is new');check(before['due']==0,'No production social publication is waiting during deployment')
for path in [stage/'.src/TelegramBrokerService.php',stage/'.src/TelegramWebhook.php',stage/'scripts/publish-telegram-channels-extension.php',*stage.glob('.plugins/telegram-channels-publisher/**/*.php'),*stage.glob('.addons/social-publishing/**/*.php'),stage/'tests/telegram-channels-broker.php',stage/'tests/telegram-channels-publisher.php',stage/'tests/telegram-channels-social-release.php']:run(['php8.5','-l',str(path)])
run(['python3','-m','py_compile',str(stage/'scripts/deploy-telegram-channels-publisher-0.1.0.py'),str(stage/'tests/telegram-channels-ui-production.py'),str(stage/'tests/package-catalog-http.py')]);run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t']);logs={path:path.stat().st_size for path in [Path('/var/log/nginx/sensecms.com.error.log'),web/'storage/php-error.log'] if path.exists()}

lock=Path('/root/sensecms-private/deploy-workspace.lock').open('a+b');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
try:
    artifacts=stage/'artifacts';artifacts.mkdir(mode=0o700);build=r'''require$argv[1].'/bootstrap.php';$secret=base64_decode(trim(file_get_contents($argv[2])),true);if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid key');try{$a=App\Core\Packages\Archive::build($argv[3],$argv[4],$secret);echo json_encode(['sha256'=>$a['sha256'],'public'=>base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))],JSON_THROW_ON_ERROR);}finally{sodium_memzero($secret);}''';hashes={};public=''
    for kind,slug,version in [('addon','social-publishing','0.2.2'),('plugin','telegram-channels-publisher','0.1.0')]:
        archive=artifacts/f'{kind}-{slug}-{version}.zip';result=json.loads(php(build,web,publisher,stage/f'.{kind}s'/slug,archive));hashes[f'{kind}:{slug}']=result['sha256'];public=result['public']
    (artifacts/'telegram-channels-social-release.json').write_text(json.dumps(hashes,separators=(',',':')));(artifacts/'trust.json').write_text(json.dumps({'sensecms-release':public},separators=(',',':')));os.chmod(artifacts/'telegram-channels-social-release.json',0o600);os.chmod(artifacts/'trust.json',0o600);receipt['artifacts']=hashes
    testtree=stage/'runtime-tests';(testtree/'tests').mkdir(parents=True,mode=0o700);(testtree/'.cms').mkdir(mode=0o700);(testtree/'.cms/source').symlink_to(web,target_is_directory=True);(testtree/'.src').symlink_to(stage/'.src',target_is_directory=True);(testtree/'.plugins').symlink_to(stage/'.plugins',target_is_directory=True);(testtree/'.addons').symlink_to(stage/'.addons',target_is_directory=True)
    for name in ['telegram-channels-broker.php','telegram-channels-publisher.php','telegram-channels-social-release.php']:shutil.copy2(stage/'tests'/name,testtree/'tests'/name)
    check('15 Telegram Channels broker checks passed' in run(['php8.5',str(testtree/'tests/telegram-channels-broker.php')]).decode(),'Isolated Telegram Channels broker protocol');check('13 Telegram Channels publisher checks passed' in run(['php8.5',str(testtree/'tests/telegram-channels-publisher.php')]).decode(),'Isolated Telegram Channels provider protocol');check('signed Telegram Channels social package checks passed' in run(['php8.5',str(testtree/'tests/telegram-channels-social-release.php'),str(artifacts),str(artifacts/'trust.json')]).decode(),'Exact signed Telegram Channels package lifecycle on isolated MariaDB')
    stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');backup=Path('/root/sensecms-backups')/(stamp+'-telegram-channels-publisher-010');backup.mkdir(mode=0o700);receipt['backup']=str(backup);shutil.copy2(broker,backup/'TelegramBrokerService.php');shutil.copy2(endpoint,backup/'TelegramWebhook.php');shutil.copy2(distribution,backup/'distribution-before.json');shutil.copytree(web/'addons/social-publishing',backup/'social-publishing-0.2.1');shutil.copy2(artifacts/'addon-social-publishing-0.2.2.zip',backup/'addon-social-publishing-0.2.2.zip');shutil.copy2(artifacts/'plugin-telegram-channels-publisher-0.1.0.zip',backup/'plugin-telegram-channels-publisher-0.1.0.zip')
    with(backup/'database-before.sql').open('wb')as output:
        result=subprocess.run(['mariadb-dump','--single-transaction','--skip-lock-tables','--hex-blob','--no-tablespaces','sensecms_site'],stdout=output,stderr=subprocess.PIPE)
        if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace').strip() or 'Database backup failed.')
    os.chmod(backup/'database-before.sql',0o600);check((backup/'database-before.sql').stat().st_size>4096,'Private production database recovery dump')
    atomic_file(stage/'.src/TelegramBrokerService.php',broker);broker_changed=True;atomic_file(stage/'.src/TelegramWebhook.php',endpoint);endpoint_changed=True
    uid=int(run(['id','-u','sensecms']).decode());gid=int(run(['id','-g','sensecms']).decode());install_dir=web/'storage/private'/('telegram-channels-deploy-'+stamp);install_dir.mkdir(mode=0o700);os.chown(install_dir,uid,gid)
    for name in ['addon-social-publishing-0.2.2.zip','plugin-telegram-channels-publisher-0.1.0.zip']:
        candidate=install_dir/name;shutil.copy2(artifacts/name,candidate);os.chown(candidate,uid,gid);os.chmod(candidate,0o600);result=install(candidate)
        if name.startswith('addon-'):addon_updated=True;check(result.get('version')=='0.2.2' and result.get('updated') is True,'PackageManager upgraded Social Publishing to 0.2.2')
        else:plugin_installed=True;check(result.get('version')=='0.1.0','PackageManager installed Telegram Channels Publisher 0.1.0')
    target=releases/'plugin-telegram-channels-publisher-0.1.0.zip';check(not target.exists(),'Immutable Telegram Channels distribution target is new');payload=(artifacts/target.name).read_bytes();temporary=target.with_name(target.name+'.new');temporary.write_bytes(payload);store_stat=distribution.stat();os.chown(temporary,store_stat.st_uid,store_stat.st_gid);os.chmod(temporary,0o640);os.replace(temporary,target);current=json.loads(distribution.read_text());current['products'][identity]={'type':'plugin','slug':'telegram-channels-publisher','version':'0.1.0','pricing':'paid','license':{'product_name':'Sense CMS Telegram Channels Plugin','product_model':'Telegram Channels Plugin'},'sha256':hashlib.sha256(payload).hexdigest(),'bytes':len(payload),'channel':'development','file':target.name,'enabled':True};atomic_bytes(distribution,(json.dumps(current,separators=(',',':'))+'\n').encode());distribution_changed=True
    run(['php8.5',str(stage/'scripts/publish-telegram-channels-extension.php'),str(web),'--apply',str(backup)]);marketplace_changed=True
    after=state();check(after['packages']['addon:social-publishing']=={'version':'0.2.2','active':1,'signature_status':'verified'},'Signed Social Publishing 0.2.2 active');check(after['packages'][identity]=={'version':'0.1.0','active':1,'signature_status':'verified'},'Signed Telegram Channels Publisher 0.1.0 active');check(after['social']==before['social'],'Existing social connections, targets and deliveries preserved exactly');check(sha(stage/'.src/TelegramBrokerService.php')==sha(broker) and sha(stage/'.src/TelegramWebhook.php')==sha(endpoint),'Deployed Telegram broker checksums');
    for source in stage.glob('.plugins/telegram-channels-publisher/**/*'):
        if source.is_file() and source.name!='sense-package.json':check(sha(source)==sha(web/'plugins/telegram-channels-publisher'/source.relative_to(stage/'.plugins/telegram-channels-publisher')),'Deployed checksum '+source.name)
    live=json.loads(distribution.read_text())['products'][identity];check(live['sha256']==sha(target) and live['pricing']=='paid' and live['license']['product_name']=='Sense CMS Telegram Channels Plugin','Paid Telegram Channels distribution offer active')
    worker=json.loads(run(['php8.5',str(web/'addons/social-publishing/scripts/social-worker.php'),str(web)],user='sensecms'));check(worker.get('ok')is True and worker.get('published')==0,'Production worker healthy without publishing content')
    request=urllib.request.Request('https://www.sensecms.com/api/telegram/v1/channels/verify',data=b'{}',headers={'Content-Type':'application/json'},method='POST')
    try:urllib.request.urlopen(request,timeout=30);api_status=200
    except urllib.error.HTTPError as error:api_status=error.code
    check(api_status==401,'Telegram Channels broker rejects unauthenticated verification');run(['systemctl','reload','php8.5-fpm']);check('PASS Production Telegram Channels workspace' in run(['python3',str(stage/'tests/telegram-channels-ui-production.py')]).decode(),'Authenticated Telegram Channels workspace and assets');check('managed marketplace routes' in run(['python3',str(stage/'tests/package-catalog-http.py'),'https://www.sensecms.com']).decode(),'Public marketplace route and Telegram Channels detail');run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t']);check(all(not re.search(rb'PHP (?:Warning|Fatal|Parse|Notice)|Uncaught|\[(?:crit|alert|emerg|error)\]',path.read_bytes()[position:],re.I)for path,position in logs.items()),'No fresh PHP/Nginx errors');receipt['status']='deployed'
except BaseException:
    receipt['status']='rolling-back'
    if marketplace_changed and backup is not None:
        try:run(['php8.5',str(stage/'scripts/publish-telegram-channels-extension.php'),str(web),'--rollback',str(backup)])
        except Exception:pass
    if distribution_changed and backup is not None:atomic_bytes(distribution,(backup/'distribution-before.json').read_bytes())
    if target is not None and target.is_file() and target.parent==releases and target.name=='plugin-telegram-channels-publisher-0.1.0.zip':target.unlink()
    if plugin_installed:
        try:package_action('uninstall','plugin','telegram-channels-publisher')
        except Exception:pass
    if addon_updated:
        try:package_action('rollback','addon','social-publishing')
        except Exception:pass
    if broker_changed and backup is not None:atomic_file(backup/'TelegramBrokerService.php',broker)
    if endpoint_changed and backup is not None:atomic_file(backup/'TelegramWebhook.php',endpoint)
    run(['systemctl','reload','php8.5-fpm']);receipt['status']='rolled-back';raise
finally:
    if install_dir is not None and install_dir.is_dir() and install_dir.name.startswith('telegram-channels-deploy-') and install_dir.parent==web/'storage/private':shutil.rmtree(install_dir)
    if backup is not None:(backup/'receipt.json').write_text(json.dumps(receipt,indent=2,sort_keys=True)+'\n');os.chmod(backup/'receipt.json',0o600)
    fcntl.flock(lock,fcntl.LOCK_UN);lock.close()
print(json.dumps(receipt,sort_keys=True),flush=True)
