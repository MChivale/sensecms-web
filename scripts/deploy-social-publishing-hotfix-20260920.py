#!/usr/bin/env python3
"""Build, verify and deploy the 2026-09-20 Social Publishing hotfix."""
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
import urllib.request

if os.name=='nt' or os.geteuid()!=0 or len(sys.argv)!=2:raise SystemExit('Run as root on Linux: deploy-social-publishing-hotfix-20260920.py <private-stage>')
os.umask(0o077)
stage=Path(sys.argv[1]).resolve();web=Path('/home/sensecms.com/web');publisher=Path('/root/sensecms-private/publisher.ed25519')
releases=[('addon','social-publishing','0.4.1'),('plugin','bluesky-publisher','0.1.4'),('plugin','x-publisher','0.1.2'),('plugin','tiktok-publisher','0.1.2'),('plugin','youtube-publisher','0.1.1')]
receipt={'status':'preflight','checks':[]};backup=install_dir=None;installed=[];started=int(datetime.datetime.now(datetime.timezone.utc).timestamp())

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
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$packages=[];foreach([['addon','social-publishing'],['plugin','bluesky-publisher'],['plugin','x-publisher'],['plugin','tiktok-publisher'],['plugin','youtube-publisher']]as$p){$q=$db->prepare('SELECT version,active,signature_status FROM extension_packages WHERE type=? AND slug=?');$q->execute($p);$packages[$p[0].':'.$p[1]]=$q->fetch()?:null;}$social=[];foreach(['social_connections','social_post_targets','social_deliveries']as$t){$rows=$db->query("SELECT * FROM `$t` ORDER BY id")->fetchAll();$social[$t]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];}$due=(int)$db->query("SELECT COUNT(*) FROM social_post_targets t INNER JOIN posts p ON p.id=t.post_id WHERE t.enabled=1 AND t.last_enqueued_revision<t.revision AND (p.status='published' OR (p.status='scheduled' AND p.published_at<=UTC_TIMESTAMP()))")->fetchColumn();echo json_encode(['packages'=>$packages,'social'=>$social,'due'=>$due],JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web))
def install(archive):
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();$s=$m->stageLocalFile($argv[2],$owner);echo json_encode($m->install($s['token'],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web,archive,user='sensecms'))
def rollback(kind,slug):
    code=r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();echo json_encode($m->rollback($argv[2],$argv[3],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web,kind,slug,user='sensecms'))

required=[publisher,web/'bootstrap.php',*(stage/f'.{kind}s'/slug/'sense-package.json' for kind,slug,_ in releases),stage/'tests/social-publishing-regressions.php',stage/'tests/bluesky-publisher.php',stage/'tests/x-publisher.php',stage/'tests/youtube-publisher.php',stage/'tests/tiktok-publisher.php',stage/'tests/mastodon-publisher.php']
check(stage.is_dir()and str(stage).startswith('/root/sense-social-hotfix-'),'Private scoped deployment stage')
check(all(path.is_file()and not path.is_symlink()for path in required),'Complete Social Publishing hotfix payload')
before=state();expected={'addon:social-publishing':'0.4.0','plugin:bluesky-publisher':'0.1.3','plugin:x-publisher':'0.1.1','plugin:tiktok-publisher':'0.1.1','plugin:youtube-publisher':'0.1.0'}
for identity,version in expected.items():item=before['packages'][identity];check(item=={'version':version,'active':1,'signature_status':'verified'},'Pinned '+identity+' baseline')
check(before['due']==0,'No production social publication is waiting during deployment')
for path in [*stage.glob('.addons/social-publishing/**/*.php'),*stage.glob('.plugins/bluesky-publisher/**/*.php'),*stage.glob('.plugins/x-publisher/**/*.php'),*stage.glob('.plugins/youtube-publisher/**/*.php'),*stage.glob('tests/*.php')]:run(['php8.5','-l',str(path)])
run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t'])

lock=Path('/root/sensecms-private/deploy-workspace.lock').open('a+b');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
try:
    artifacts=stage/'artifacts';artifacts.mkdir(mode=0o700);build=r'''require$argv[1].'/bootstrap.php';$secret=base64_decode(trim(file_get_contents($argv[2])),true);if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid publisher key.');try{$a=App\Core\Packages\Archive::build($argv[3],$argv[4],$secret);echo json_encode(['sha256'=>$a['sha256'],'public'=>base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))],JSON_THROW_ON_ERROR);}finally{sodium_memzero($secret);}''';hashes={};public=''
    for kind,slug,version in releases:
        archive=artifacts/f'{kind}-{slug}-{version}.zip';result=json.loads(php(build,web,publisher,stage/f'.{kind}s'/slug,archive));hashes[f'{kind}:{slug}']=result['sha256'];public=result['public']
    trust=artifacts/'trust.json';trust.write_text(json.dumps({'sensecms-release':public},separators=(',',':')));os.chmod(trust,0o600);receipt['artifacts']=hashes
    verify=r'''require$argv[1].'/bootstrap.php';$keys=array_map(static fn(string$v):string=>base64_decode($v,true),json_decode(file_get_contents($argv[2]),true,16,JSON_THROW_ON_ERROR));for($i=3;$i<$argc;$i++){App\Core\Packages\Archive::verify($argv[$i],$keys);}echo'PASS';'''
    check(php(verify,web,trust,*(artifacts/f'{kind}-{slug}-{version}.zip' for kind,slug,version in releases))==b'PASS','Exact signed hotfix archives verified')
    testtree=stage/'runtime-tests';(testtree/'tests').mkdir(parents=True,mode=0o700);(testtree/'.cms').mkdir(mode=0o700);(testtree/'.cms/source').symlink_to(web,target_is_directory=True);(testtree/'.src').symlink_to(stage/'.src',target_is_directory=True);(testtree/'.plugins').symlink_to(stage/'.plugins',target_is_directory=True);(testtree/'.addons').symlink_to(stage/'.addons',target_is_directory=True)
    for name in ['social-publishing-regressions.php','bluesky-publisher.php','x-publisher.php','youtube-publisher.php','tiktok-publisher.php','mastodon-publisher.php']:shutil.copy2(stage/'tests'/name,testtree/'tests'/name)
    expected_tests={'social-publishing-regressions.php':'6 Social Publishing regression checks passed','bluesky-publisher.php':'Bluesky Publisher protocol checks passed','x-publisher.php':'18 X Publisher protocol checks passed','youtube-publisher.php':'12 YouTube Publisher protocol checks passed','tiktok-publisher.php':'17 TikTok Publisher protocol checks passed','mastodon-publisher.php':'26 Mastodon Publisher protocol checks passed'}
    for name,marker in expected_tests.items():check(marker in run(['php8.5',str(testtree/'tests'/name)]).decode(),'Linux '+name+' regression suite')
    stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');backup=Path('/root/sensecms-backups')/(stamp+'-social-publishing-hotfix');backup.mkdir(mode=0o700);receipt['backup']=str(backup)
    for kind,slug,_ in releases:shutil.copytree(web/f'{kind}s'/slug,backup/f'{kind}-{slug}')
    with(backup/'database-before.sql').open('wb')as output:
        result=subprocess.run(['mysqldump','--single-transaction','--routines','--triggers','sensecms_site'],stdout=output,stderr=subprocess.PIPE)
        if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace').strip()or'Database backup failed.')
    os.chmod(backup/'database-before.sql',0o600);check((backup/'database-before.sql').stat().st_size>4096,'Private production database recovery dump')
    uid=int(run(['id','-u','sensecms']).decode());gid=int(run(['id','-g','sensecms']).decode());install_dir=web/'storage/private'/('social-hotfix-'+stamp);install_dir.mkdir(mode=0o700);os.chown(install_dir,uid,gid)
    for kind,slug,version in releases:
        name=f'{kind}-{slug}-{version}.zip';target=install_dir/name;shutil.copy2(artifacts/name,target);os.chown(target,uid,gid);os.chmod(target,0o600);result=install(target);check(result.get('version')==version,'PackageManager installed '+kind+':'+slug+' '+version);installed.append((kind,slug))
    after=state();check(after['social']==before['social'],'Connections, targets and delivery history preserved exactly during upgrade');check(after['due']==0,'No content was queued by the hotfix deployment')
    for kind,slug,version in releases:check(after['packages'][f'{kind}:{slug}']=={'version':version,'active':1,'signature_status':'verified'},'Signed '+kind+':'+slug+' '+version+' active')
    changed=[('addon','social-publishing','src/SocialRepository.php'),('plugin','bluesky-publisher','src/provider.php'),('plugin','x-publisher','src/provider.php'),('plugin','tiktok-publisher','src/provider.php'),('plugin','youtube-publisher','src/provider.php')]
    for kind,slug,name in changed:check(sha(stage/f'.{kind}s'/slug/name)==sha(web/f'{kind}s'/slug/name),'Deployed checksum '+slug+'/'+name)
    worker=json.loads(run(['php8.5',str(web/'addons/social-publishing/scripts/social-worker.php'),str(web)],user='sensecms'));check(worker.get('ok')is True and worker.get('published')==0,'Production worker healthy without publishing content')
    editor=r'''$r=$argv[1];$slug=$argv[2];require$r.'/bootstrap.php';require$r.'/addons/social-publishing/src/SocialIntegrationManager.php';$runtime=new App\Core\Runtime($r);$installed=$runtime->read('installed');$root=$r;$baseUrl=$runtime->baseUrl();$cfg=require$r.'/config/workspace.php';$db=App\Core\Runtime::connect($installed['database']);$m=new SenseCMS\Social\SocialIntegrationManager($db,$r,(string)$cfg['secrets_key']);$q=$db->prepare('SELECT id FROM social_connections WHERE plugin_slug=? AND enabled=1 ORDER BY id LIMIT 1');$q->execute([$slug]);$id=(int)$q->fetchColumn();if(!$id)throw new RuntimeException('Missing '.$slug.' connection.');$v=$m->editor($slug,$id);echo json_encode(['fields'=>count($v['fields']??[])],JSON_THROW_ON_ERROR);'''
    choices=json.loads(php(editor,web,'youtube-publisher',user='sensecms'));check(choices.get('fields',0)>0,'Live YouTube publishing choices available')
    try:choices=json.loads(php(editor,web,'tiktok-publisher',user='sensecms'));check(choices.get('fields',0)>0,'Live TikTok publishing choices available')
    except RuntimeError as error:receipt.setdefault('warnings',[]).append('TikTok live creator-info endpoint remained transiently unavailable after the provider retry.');print('WARN TikTok live creator-info endpoint remained transiently unavailable',flush=True)
    for url in ['https://www.sensecms.com/social-publishing','https://www.sensecms.com/extension-assets/addon/social-publishing/editor.js?v=0.4.1']:
        with urllib.request.urlopen(url,timeout=30)as response:check(response.status==200,'HTTP 200 '+url)
    run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);logs=run(['journalctl','--since','@'+str(started),'--no-pager','-u','nginx','-u','php8.5-fpm']).decode(errors='replace');check(not re.search(r'PHP Fatal|\bcrit\b|\bpanic\b',logs,re.I),'No fresh critical service errors');receipt['status']='deployed'
    shutil.rmtree(install_dir);install_dir=None
except BaseException:
    receipt['status']='rolling-back' if installed else 'preflight-failed'
    for kind,slug in reversed(installed):
        try:rollback(kind,slug)
        except BaseException:receipt['status']='rollback-needs-attention'
    if receipt['status']!='rollback-needs-attention'and installed:receipt['status']='rolled-back'
    raise
finally:
    if install_dir is not None and install_dir.is_dir()and install_dir.name.startswith('social-hotfix-')and install_dir.parent==web/'storage/private':shutil.rmtree(install_dir)
    if backup is not None:(backup/'receipt.json').write_text(json.dumps(receipt,indent=2,sort_keys=True)+'\n');os.chmod(backup/'receipt.json',0o600)
    fcntl.flock(lock,fcntl.LOCK_UN);lock.close()
print(json.dumps(receipt,sort_keys=True),flush=True)
