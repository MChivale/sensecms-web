#!/usr/bin/env python3
"""Build, verify, install and distribute Mastodon Publisher 0.1.0."""
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

if os.name == 'nt' or os.geteuid() != 0 or len(sys.argv) != 2:
    raise SystemExit('Run as root on Linux: deploy-mastodon-publisher-0.1.0.py <private-stage>')

stage = Path(sys.argv[1]).resolve()
web = Path('/home/sensecms.com/web')
distribution = web/'storage/distribution.json'
releases = web/'storage/distribution/releases'
publisher = Path('/root/sensecms-private/publisher.ed25519')
identity = 'plugin:mastodon-publisher'
expected_distribution = '376991eae128e8f479a89f13300fa074480bfdfe2ceb85d25979c3d3ea5a2604'
receipt = {'status':'preflight','checks':[]}
backup = install_dir = target = None
installed = distribution_changed = False


def run(command, *, user=None):
    if user:
        command = ['sudo','-u',user,*command]
    result = subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
    if result.returncode:
        raise RuntimeError(result.stdout.decode(errors='replace').strip() or f'Command failed: {command[0]}')
    return result.stdout


def php(code, *args, user=None):
    return run(['php8.5','-r',code,*map(str,args)],user=user)


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def check(ok,label):
    if not ok:
        raise RuntimeError(label)
    receipt['checks'].append(label)
    print('PASS '+label,flush=True)


def atomic_bytes(destination,payload):
    temporary=destination.with_name(destination.name+'.mastodon-new')
    temporary.write_bytes(payload)
    stat=destination.stat()
    os.chown(temporary,stat.st_uid,stat.st_gid)
    os.chmod(temporary,stat.st_mode&0o777)
    os.replace(temporary,destination)


def state():
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$packages=[];foreach([['addon','social-publishing'],['plugin','facebook-publisher'],['plugin','x-publisher'],['plugin','linkedin-publisher'],['plugin','bluesky-publisher'],['plugin','mastodon-publisher']]as$p){$q=$db->prepare("SELECT version,active,signature_status FROM extension_packages WHERE type=? AND slug=?");$q->execute($p);$packages[$p[0].':'.$p[1]]=$q->fetch()?:null;}$tables=['social_connections','social_post_targets','social_deliveries'];$rows=[];$fingerprints=[];foreach($tables as$t){$data=$db->query("SELECT * FROM `$t` ORDER BY 1")->fetchAll();$rows[$t]=count($data);$fingerprints[$t]=hash('sha256',json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}$due=(int)$db->query("SELECT COUNT(*) FROM social_post_targets t INNER JOIN posts p ON p.id=t.post_id WHERE t.enabled=1 AND t.last_enqueued_revision<t.revision AND (p.status='published' OR (p.status='scheduled' AND p.published_at<=UTC_TIMESTAMP()))")->fetchColumn();echo json_encode(['packages'=>$packages,'rows'=>$rows,'fingerprints'=>$fingerprints,'due'=>$due],JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web))


def install(archive):
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();$s=$m->stageLocalFile($argv[2],$owner);echo json_encode($m->install($s['token'],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web,archive,user='sensecms'))


def uninstall():
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();if($m->package('plugin','mastodon-publisher'))$m->uninstall('plugin','mastodon-publisher',$owner);'''
    php(code,web,user='sensecms')


required=[stage/'.addons/social-publishing/sense-package.json',stage/'.addons/social-publishing/addon.json',stage/'.plugins/mastodon-publisher/sense-package.json',stage/'.plugins/mastodon-publisher/plugin.json',stage/'.plugins/mastodon-publisher/runtime.php',stage/'.plugins/mastodon-publisher/src/MastodonClient.php',stage/'tests/mastodon-publisher.php',stage/'tests/mastodon-social-release.php',stage/'tests/mastodon-ui-production.py']
check(stage.is_dir() and str(stage).startswith('/root/sense-mastodon-publisher-'),'Private scoped Mastodon deployment stage')
check(all(path.is_file() and not path.is_symlink() for path in required),'Complete Mastodon candidate payload')
check(web.is_dir() and distribution.is_file() and releases.is_dir() and publisher.is_file(),'Production package and distribution runtime')
check(sha(distribution)==expected_distribution,'Pinned production distribution baseline')
before=state();expected={'addon:social-publishing':('0.2.1',1,'verified'),'plugin:facebook-publisher':('0.2.1',1,'verified'),'plugin:x-publisher':('0.1.1',1,'verified'),'plugin:linkedin-publisher':('0.1.1',1,'verified'),'plugin:bluesky-publisher':('0.1.3',1,'verified')}
for key,value in expected.items():
    item=before['packages'][key]
    check(item is not None and tuple(item.values())==value,'Installed '+key+' baseline')
check(before['packages'][identity] is None and not (web/'plugins/mastodon-publisher').exists(),'Mastodon Publisher absent before first installation')
config=json.loads(distribution.read_text())
check(identity not in config.get('products',{}),'Mastodon distribution offer absent before publication')
for path in [*stage.glob('.plugins/mastodon-publisher/**/*.php'),stage/'tests/mastodon-publisher.php',stage/'tests/mastodon-social-release.php']:
    run(['php8.5','-l',str(path)])
run(['python3','-m','py_compile',str(stage/'scripts/deploy-mastodon-publisher-0.1.0.py'),str(stage/'tests/mastodon-ui-production.py')])
run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t'])
logs={path:path.stat().st_size for path in [Path('/var/log/nginx/sensecms.com.error.log'),web/'storage/php-error.log'] if path.exists()}

lock=Path('/root/sensecms-private/deploy-workspace.lock').open('a+b');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
try:
    artifacts=stage/'artifacts';artifacts.mkdir(mode=0o700)
    build=r'''require$argv[1].'/bootstrap.php';$secret=base64_decode(trim(file_get_contents($argv[2])),true);if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid key');try{$a=App\Core\Packages\Archive::build($argv[3],$argv[4],$secret);echo json_encode(['sha256'=>$a['sha256'],'public'=>base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))],JSON_THROW_ON_ERROR);}finally{sodium_memzero($secret);}'''
    hashes={};public=''
    for kind,slug,version in [('addon','social-publishing','0.2.1'),('plugin','mastodon-publisher','0.1.0')]:
        archive=artifacts/f'{kind}-{slug}-{version}.zip';result=json.loads(php(build,web,publisher,stage/f'.{kind}s'/slug,archive));hashes[f'{kind}:{slug}']=result['sha256'];public=result['public']
    (artifacts/'mastodon-social-release.json').write_text(json.dumps(hashes,separators=(',',':')));(artifacts/'trust.json').write_text(json.dumps({'sensecms-release':public},separators=(',',':')))
    os.chmod(artifacts/'mastodon-social-release.json',0o600);os.chmod(artifacts/'trust.json',0o600);receipt['artifacts']=hashes
    testtree=stage/'runtime-tests';(testtree/'tests').mkdir(parents=True,mode=0o700);(testtree/'.cms').mkdir(mode=0o700);(testtree/'.cms/source').symlink_to(web,target_is_directory=True);(testtree/'.plugins').symlink_to(stage/'.plugins',target_is_directory=True);(testtree/'.addons').symlink_to(stage/'.addons',target_is_directory=True)
    for name in ['mastodon-publisher.php','mastodon-social-release.php']:
        shutil.copy2(stage/'tests'/name,testtree/'tests'/name)
    check('26 Mastodon Publisher protocol checks passed' in run(['php8.5',str(testtree/'tests/mastodon-publisher.php')]).decode(),'Isolated Mastodon OAuth and publishing protocol')
    check('signed Mastodon social package checks passed' in run(['php8.5',str(testtree/'tests/mastodon-social-release.php'),str(artifacts),str(artifacts/'trust.json')]).decode(),'Exact signed Mastodon package lifecycle on isolated MariaDB')

    stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');backup=Path('/root/sensecms-backups')/(stamp+'-mastodon-publisher-010');backup.mkdir(mode=0o700);receipt['backup']=str(backup);shutil.copy2(distribution,backup/'distribution-before.json');shutil.copy2(artifacts/'plugin-mastodon-publisher-0.1.0.zip',backup/'plugin-mastodon-publisher-0.1.0.zip')
    with (backup/'database-before.sql').open('wb') as output:
        result=subprocess.run(['mysqldump','--single-transaction','--routines','--triggers','sensecms_site'],stdout=output,stderr=subprocess.PIPE)
        if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace').strip() or 'Database backup failed.')
    os.chmod(backup/'database-before.sql',0o600);check((backup/'database-before.sql').stat().st_size>4096,'Private production database recovery dump')

    uid=int(run(['id','-u','sensecms']).decode());gid=int(run(['id','-g','sensecms']).decode());install_dir=web/'storage/private'/('mastodon-publisher-deploy-'+stamp);install_dir.mkdir(mode=0o700);os.chown(install_dir,uid,gid);candidate=install_dir/'plugin-mastodon-publisher-0.1.0.zip';shutil.copy2(artifacts/candidate.name,candidate);os.chown(candidate,uid,gid);os.chmod(candidate,0o600)
    result=install(candidate);installed=True;check(result.get('version')=='0.1.0','PackageManager installed Mastodon Publisher 0.1.0')
    target=releases/candidate.name;check(not target.exists(),'Immutable Mastodon distribution target is new');payload=(artifacts/candidate.name).read_bytes();temporary=target.with_name(target.name+'.new');temporary.write_bytes(payload);stat=distribution.stat();os.chown(temporary,stat.st_uid,stat.st_gid);os.chmod(temporary,0o640);os.replace(temporary,target)
    current=json.loads(distribution.read_text());current['products'][identity]={'type':'plugin','slug':'mastodon-publisher','version':'0.1.0','pricing':'paid','license':{'product_name':'Sense CMS Mastodon Publisher Plugin','product_model':'Mastodon Publisher Plugin'},'sha256':hashlib.sha256(payload).hexdigest(),'bytes':len(payload),'channel':'development','file':target.name,'enabled':True};atomic_bytes(distribution,(json.dumps(current,separators=(',',':'))+'\n').encode());distribution_changed=True

    after=state();plugin=after['packages'][identity];check(plugin=={'version':'0.1.0','active':1,'signature_status':'verified'},'Signed Mastodon Publisher 0.1.0 active');check(after['rows']==before['rows'] and after['fingerprints']==before['fingerprints'],'Existing social connections, targets and deliveries preserved exactly')
    live=json.loads(distribution.read_text())['products'][identity];check(live['sha256']==sha(target) and live['pricing']=='paid' and live['license']['product_name']=='Sense CMS Mastodon Publisher Plugin','Paid Mastodon distribution offer active')
    for source in stage.glob('.plugins/mastodon-publisher/**/*'):
        if source.is_file() and source.name!='sense-package.json':check(sha(source)==sha(web/'plugins/mastodon-publisher'/source.relative_to(stage/'.plugins/mastodon-publisher')),'Deployed checksum '+source.name)
    if after['due']==0:
        worker=json.loads(run(['php8.5',str(web/'addons/social-publishing/scripts/social-worker.php'),str(web)],user='sensecms'));check(worker.get('ok') is True and worker.get('published')==0,'Production worker healthy without publishing content')
    check('PASS Production Mastodon workspace' in run(['python3',str(stage/'tests/mastodon-ui-production.py')]).decode(),'Authenticated Mastodon workspace and SSRF acceptance')
    run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t'])
    check(all(not re.search(rb'PHP (?:Warning|Fatal|Parse|Notice)|Uncaught|\[(?:crit|alert|emerg|error)\]',path.read_bytes()[position:],re.I) for path,position in logs.items()),'No fresh PHP/Nginx errors')
    receipt['status']='deployed';shutil.rmtree(install_dir);install_dir=None
except BaseException:
    receipt['status']='rolling-back'
    if distribution_changed and backup is not None:atomic_bytes(distribution,(backup/'distribution-before.json').read_bytes())
    if target is not None and target.is_file() and target.parent==releases and target.name=='plugin-mastodon-publisher-0.1.0.zip':target.unlink()
    if installed:uninstall()
    receipt['status']='rolled-back';raise
finally:
    if install_dir is not None and install_dir.is_dir() and install_dir.name.startswith('mastodon-publisher-deploy-') and install_dir.parent==web/'storage/private':shutil.rmtree(install_dir)
    if backup is not None:(backup/'receipt.json').write_text(json.dumps(receipt,indent=2,sort_keys=True)+'\n');os.chmod(backup/'receipt.json',0o600)
    fcntl.flock(lock,fcntl.LOCK_UN);lock.close()

print(json.dumps(receipt,sort_keys=True),flush=True)
