#!/usr/bin/env python3
"""Build, verify and deploy the rich post editor and ordered Social Publishing UI."""
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

if os.name=='nt' or os.geteuid()!=0 or len(sys.argv)!=2:
    raise SystemExit('Run as root on Linux: deploy-post-editor-social-20260920.py <private-stage>')
os.umask(0o077)
stage=Path(sys.argv[1]).resolve();web=Path('/home/sensecms.com/web');publisher=Path('/root/sensecms-private/publisher.ed25519')
packages=[('addon','social-publishing','0.5.1'),('plugin','youtube-publisher','0.2.0'),('theme','sensecms','1.0.9')]
core_files=[
    'app/Core/CmsRepository.php','app/Core/HtmlSanitizer.php','app/Core/MediaLibrary.php','app/Core/PublicTheme.php','app/Core/SeoMeta.php',
    'app/Http/DashboardController.php','app/Http/PublicController.php','app/Views/console-content-post-form.php','app/Views/console.php',
    'public/theme/sensecms-content-management.css','public/theme/sensecms-editor-workflow.js','public/theme/sensecms-post-editor.js',
    'public/assets/lib/quill/LICENSE.txt','public/assets/lib/quill/quill.js','public/assets/lib/quill/quill.snow.css',
]
receipt={'status':'preflight','checks':[]};backup=install_dir=None;installed=[];theme_before=None;deployed_files=[];started=int(datetime.datetime.now(datetime.timezone.utc).timestamp())

def run(command,*,user=None):
    if user:command=['sudo','-u',user,*command]
    result=subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
    if result.returncode:raise RuntimeError(result.stdout.decode(errors='replace').strip()or f'Command failed: {command[0]}')
    return result.stdout
def php(code,*args,user=None,root=web):return run(['php8.5','-r',f'require {json.dumps(str(root/"bootstrap.php"))};'+code,*map(str,args)],user=user)
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def check(ok,label):
    if not ok:raise RuntimeError(label)
    receipt['checks'].append(label);print('PASS '+label,flush=True)
def state():
    code=r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$packages=[];foreach([['addon','social-publishing'],['plugin','youtube-publisher']]as$p){$q=$db->prepare('SELECT version,active,signature_status FROM extension_packages WHERE type=? AND slug=?');$q->execute($p);$packages[$p[0].':'.$p[1]]=$q->fetch()?:null;}$social=[];foreach(['social_connections','social_post_targets','social_deliveries']as$t){$rows=$db->query("SELECT * FROM `$t` ORDER BY id")->fetchAll();$social[$t]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];}$due=(int)$db->query("SELECT COUNT(*) FROM social_post_targets t INNER JOIN posts p ON p.id=t.post_id WHERE t.enabled=1 AND t.last_enqueued_revision<t.revision AND (p.status='published' OR (p.status='scheduled' AND p.published_at<=UTC_TIMESTAMP()))")->fetchColumn();$processing=(int)$db->query("SELECT COUNT(*) FROM social_deliveries WHERE status='processing'")->fetchColumn();$theme=(new App\Core\PackageManager($db,$r,'1.0.0'))->themeManager()->active();echo json_encode(['packages'=>$packages,'social'=>$social,'due'=>$due,'processing'=>$processing,'theme'=>$theme],JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web))
def install(archive):
    code=r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();$s=$m->stageLocalFile($argv[2],$owner);echo json_encode($m->install($s['token'],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web,archive,user='sensecms'))
def rollback(kind,slug):
    code=r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();echo json_encode($m->rollback($argv[2],$argv[3],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web,kind,slug,user='sensecms'))
def activate_theme(directory,trust):
    code=r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$keys=array_map(static fn(string$v):string=>base64_decode($v,true),json_decode(file_get_contents($argv[3]),true,16,JSON_THROW_ON_ERROR));echo json_encode($m->themeManager()->activate($argv[2],$keys),JSON_THROW_ON_ERROR);'''
    readable=web/'storage/private'/('.theme-trust-'+hashlib.sha256(str(trust).encode()).hexdigest()[:12]+'.json')
    shutil.copy2(trust,readable);shutil.chown(readable,user='sensecms',group='sensecms');os.chmod(readable,0o600)
    try:return json.loads(php(code,web,directory,readable,user='sensecms'))
    finally:readable.unlink(missing_ok=True)
def deploy_file(relative):
    src=stage/'.cms/source'/relative;dst=web/relative
    if not src.is_file() or src.is_symlink():raise RuntimeError('Invalid deployment source: '+relative)
    dst.parent.mkdir(parents=True,exist_ok=True)
    if relative.startswith('public/'):
        current=web/'public'
        for part in Path(relative).parts[1:-1]:
            current=current/part;os.chmod(current,0o755)
    if dst.exists():
        if dst.is_symlink() or not dst.is_file():raise RuntimeError('Unsafe deployment target: '+relative)
        stat=dst.stat();target_backup=backup/'core'/relative;target_backup.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(dst,target_backup);uid,gid,mode=stat.st_uid,stat.st_gid,stat.st_mode&0o777
    else:
        uid,gid=(sense_uid,sense_gid) if relative.startswith('public/') else (0,0);mode=0o644
    tmp=dst.with_name(dst.name+'.deploy-'+deploy_token);shutil.copy2(src,tmp);os.chown(tmp,uid,gid);os.chmod(tmp,mode);os.replace(tmp,dst);deployed_files.append(relative)
def restore_files():
    for relative in reversed(deployed_files):
        dst=web/relative;old=backup/'core'/relative
        if old.is_file():shutil.copy2(old,dst)
        elif dst.is_file() and not dst.is_symlink():dst.unlink()

required=[publisher,web/'bootstrap.php',stage/'.cms/source/bootstrap.php',stage/'.cms/source/database/workspace/031_post_editor_media.sql',stage/'scripts/deploy-post-editor-social-20260920.py',*(stage/f'.{kind}s'/slug/'sense-package.json' for kind,slug,_ in packages),*(stage/'tests'/name for name in ['post-editor-regressions.php','social-publishing-regressions.php','youtube-publisher.php','youtube-social-release.php','themes.php','media-limits.php','security-regressions.php','post-editor-ui-production.py','youtube-ui-production.py'])]
check(stage.is_dir()and str(stage).startswith('/root/sense-post-editor-'),'Private scoped deployment stage')
check(all(path.is_file()and not path.is_symlink()for path in required),'Complete post editor deployment payload')
before=state();check(before['packages']=={'addon:social-publishing':{'version':'0.4.1','active':1,'signature_status':'verified'},'plugin:youtube-publisher':{'version':'0.1.1','active':1,'signature_status':'verified'}},'Pinned Social Publishing and YouTube baseline')
theme_before=before['theme'];check(isinstance(theme_before,dict)and theme_before.get('slug')=='sensecms'and theme_before.get('version')=='1.0.8','Pinned active product theme 1.0.8 baseline')
check(before['due']==0,'No production social publication is waiting during deployment')
check(before['processing']==0,'No production social delivery is currently processing')
run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t'])
for path in [*(stage/'.cms/source'/item for item in core_files),*stage.glob('.addons/social-publishing/**/*.php'),*stage.glob('.plugins/youtube-publisher/**/*.php'),*stage.glob('.themes/sensecms/**/*.php'),*stage.glob('tests/*.php')]:run(['php8.5','-l',str(path)])
node=shutil.which('node')
if node:
    for path in [stage/'.cms/source/public/theme/sensecms-post-editor.js',stage/'.cms/source/public/theme/sensecms-editor-workflow.js',stage/'.addons/social-publishing/assets/editor.js',stage/'.themes/sensecms/assets/site.js']:run([node,'--check',str(path)])
    receipt['checks'].append('Staged JavaScript syntax verified with Node.js')

lock=Path('/root/sensecms-private/deploy-workspace.lock').open('a+b');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
try:
    artifacts=stage/'artifacts'
    if artifacts.is_dir()and artifacts.parent==stage:shutil.rmtree(artifacts)
    artifacts.mkdir(mode=0o700);build=r'''$secret=base64_decode(trim(file_get_contents($argv[1])),true);if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid publisher key.');try{$a=App\Core\Packages\Archive::build($argv[2],$argv[3],$secret);echo json_encode(['sha256'=>$a['sha256'],'public'=>base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))],JSON_THROW_ON_ERROR);}finally{sodium_memzero($secret);}''';hashes={};public=''
    for kind,slug,version in packages:
        archive=artifacts/f'{kind}-{slug}-{version}.zip';result=json.loads(php(build,publisher,stage/f'.{kind}s'/slug,archive,root=stage/'.cms/source'));hashes[f'{kind}:{slug}']=result['sha256'];public=result['public']
    trust=artifacts/'trust.json';trust.write_text(json.dumps({'sensecms-release':public},separators=(',',':')));os.chmod(trust,0o600);(artifacts/'youtube-social-release.json').write_text(json.dumps({key:value for key,value in hashes.items() if key!='theme:sensecms'},separators=(',',':')));receipt['artifacts']=hashes
    verify=r'''$keys=array_map(static fn(string$v):string=>base64_decode($v,true),json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR));for($i=2;$i<$argc;$i++)App\Core\Packages\Archive::verify($argv[$i],$keys);echo'PASS';'''
    check(php(verify,trust,*(artifacts/f'{kind}-{slug}-{version}.zip' for kind,slug,version in packages),root=stage/'.cms/source')==b'PASS','Exact signed release archives verified')
    test_markers={'post-editor-regressions.php':'11 post editor regression checks passed','social-publishing-regressions.php':'8 Social Publishing regression checks passed','youtube-publisher.php':'13 YouTube Publisher protocol checks passed','media-limits.php':'18 media/default checks passed'}
    for name,marker in test_markers.items():check(marker in run(['php8.5',str(stage/'tests'/name)]).decode(),'Linux '+name+' suite')
    if 'pdo_sqlite' in run(['php8.5','-m']).decode().lower():
        check('92 security regression checks passed' in run(['php8.5',str(stage/'tests/security-regressions.php')]).decode(),'Linux security-regressions.php suite')
    check('signed YouTube social package checks passed' in run(['php8.5',str(stage/'tests/youtube-social-release.php'),str(artifacts),str(trust)]).decode(),'Exact signed package lifecycle and Workspace migration on isolated MariaDB')
    stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');deploy_token=hashlib.sha256(stamp.encode()).hexdigest()[:12];backup=Path('/root/sensecms-backups')/(stamp+'-post-editor-social');backup.mkdir(mode=0o700);receipt['backup']=str(backup)
    shutil.copytree(web/'addons/social-publishing',backup/'addon-social-publishing');shutil.copytree(web/'plugins/youtube-publisher',backup/'plugin-youtube-publisher')
    with(backup/'database-before.sql').open('wb')as output:
        result=subprocess.run(['mysqldump','--single-transaction','--routines','--triggers','sensecms_site'],stdout=output,stderr=subprocess.PIPE)
        if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace').strip()or'Database backup failed.')
    os.chmod(backup/'database-before.sql',0o600);check((backup/'database-before.sql').stat().st_size>4096,'Private production database recovery dump')
    sense_uid=int(run(['id','-u','sensecms']).decode());sense_gid=int(run(['id','-g','sensecms']).decode())
    migration_src=stage/'.cms/source/database/workspace/031_post_editor_media.sql';migration_dst=web/'database/workspace/031_post_editor_media.sql';shutil.copy2(migration_src,backup/'031_post_editor_media.sql');tmp=migration_dst.with_name(migration_dst.name+'.deploy-'+deploy_token);shutil.copy2(migration_src,tmp);os.chown(tmp,0,0);os.chmod(tmp,0o644);os.replace(tmp,migration_dst)
    applied=int(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);echo(new App\Installer\WorkspaceMigration($db,$r))->apply();''',web,user='sensecms').decode());check(applied==1,'Workspace migration 031 applied exactly once')
    for relative in core_files:deploy_file(relative)
    for relative in core_files:check(sha(stage/'.cms/source'/relative)==sha(web/relative),'Deployed checksum '+relative)
    uid=sense_uid;gid=sense_gid;install_dir=web/'storage/private'/('post-editor-social-'+stamp);install_dir.mkdir(mode=0o700);os.chown(install_dir,uid,gid)
    for kind,slug,version in packages:
        name=f'{kind}-{slug}-{version}.zip';target=install_dir/name;shutil.copy2(artifacts/name,target);os.chown(target,uid,gid);os.chmod(target,0o600);result=install(target);check(result.get('version')==version,'PackageManager installed '+kind+':'+slug+' '+version);installed.append((kind,slug,result))
    theme_release=installed[-1][2];activated=activate_theme(theme_release['directory'],trust);check(activated.get('version')=='1.0.9','Signed product theme 1.0.9 activated')
    after=state();check(after['social']==before['social'],'Connections, targets and delivery history preserved exactly');check(after['due']==0,'No content was queued by deployment');check(after['processing']==0,'No social delivery entered processing during deployment')
    check(after['packages']=={'addon:social-publishing':{'version':'0.5.1','active':1,'signature_status':'verified'},'plugin:youtube-publisher':{'version':'0.2.0','active':1,'signature_status':'verified'}},'Signed Social Publishing 0.5.1 and YouTube 0.2.0 active')
    check(after['theme'].get('version')=='1.0.9','Product theme pointer reports 1.0.9')
    schema=json.loads(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$columns=$db->query("SHOW COLUMNS FROM posts WHERE Field IN ('audio_media_id','video_media_id')")->fetchAll();echo json_encode(['columns'=>array_column($columns,'Field'),'tags'=>(bool)$db->query("SHOW TABLES LIKE 'post_tags'")->fetchColumn(),'map'=>(bool)$db->query("SHOW TABLES LIKE 'post_tag_map'")->fetchColumn()],JSON_THROW_ON_ERROR);''',web,user='sensecms').decode());check(set(schema['columns'])=={'audio_media_id','video_media_id'}and schema['tags']and schema['map'],'Production rich post schema ready')
    worker=json.loads(run(['php8.5',str(web/'addons/social-publishing/scripts/social-worker.php'),str(web)],user='sensecms'));check(worker.get('ok')is True and worker.get('published')==0,'Production social worker healthy without publishing content')
    check('PASS Production rich post editor' in run(['python3',str(stage/'tests/post-editor-ui-production.py')]).decode(),'Authenticated production post editor acceptance')
    check('PASS Production YouTube workspace' in run(['python3',str(stage/'tests/youtube-ui-production.py')]).decode(),'Authenticated production YouTube acceptance')
    for url in ['https://www.sensecms.com/','https://www.sensecms.com/theme/sensecms-post-editor.js?v=20260920-1','https://www.sensecms.com/assets/lib/quill/quill.js?v=2.0.3','https://www.sensecms.com/extension-assets/addon/social-publishing/editor.js?v=0.5.1']:
        with urllib.request.urlopen(url,timeout=30)as response:check(response.status==200,'HTTP 200 '+url)
    run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t']);logs=run(['journalctl','--since','@'+str(started),'--no-pager','-u','nginx','-u','php8.5-fpm']).decode(errors='replace');check(not re.search(r'PHP Fatal|Uncaught|\bcrit\b|\bpanic\b',logs,re.I),'No fresh critical service errors')
    receipt['status']='deployed';shutil.rmtree(install_dir);install_dir=None
except BaseException:
    receipt['status']='rolling-back' if backup else 'preflight-failed'
    recovery_errors=[]
    if theme_before and theme_before.get('directory') and 'trust' in locals():
        try:activate_theme(theme_before['directory'],trust)
        except BaseException as error:recovery_errors.append('theme:'+type(error).__name__)
    for kind,slug,_ in reversed(installed):
        if kind=='theme':continue
        try:rollback(kind,slug)
        except BaseException as error:recovery_errors.append(kind+':'+slug+':'+type(error).__name__)
    if backup:
        try:restore_files()
        except BaseException as error:recovery_errors.append('core:'+type(error).__name__)
    if receipt['status']=='rolling-back':receipt['status']='rollback-needs-attention' if recovery_errors else 'rolled-back'
    if recovery_errors:receipt['recovery_errors']=recovery_errors
    raise
finally:
    if install_dir is not None and install_dir.is_dir()and install_dir.name.startswith('post-editor-social-')and install_dir.parent==web/'storage/private':shutil.rmtree(install_dir)
    if backup is not None:(backup/'receipt.json').write_text(json.dumps(receipt,indent=2,sort_keys=True)+'\n');os.chmod(backup/'receipt.json',0o600)
    fcntl.flock(lock,fcntl.LOCK_UN);lock.close()
print(json.dumps(receipt,sort_keys=True),flush=True)
