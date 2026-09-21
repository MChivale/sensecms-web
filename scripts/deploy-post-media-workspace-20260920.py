#!/usr/bin/env python3
"""Deploy the professional post tag and media workspace hotfix."""
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
    raise SystemExit('Run as root on Linux: deploy-post-media-workspace-20260920.py <private-stage>')
os.umask(0o077)
stage=Path(sys.argv[1]).resolve();web=Path('/home/sensecms.com/web')
core_files=['app/Http/DashboardController.php','app/Views/console-content-post-form.php','app/Views/console.php','public/theme/sensecms-content-management.css','public/theme/sensecms-content-management.js','public/theme/sensecms-post-editor.js']
receipt={'status':'preflight','checks':[]};backup=None;deployed=[];started=int(datetime.datetime.now(datetime.timezone.utc).timestamp())

def run(command,*,user=None):
    if user:command=['sudo','-u',user,*command]
    result=subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
    if result.returncode:raise RuntimeError(result.stdout.decode(errors='replace').strip()or f'Command failed: {command[0]}')
    return result.stdout
def php(code,*args,user=None):return run(['php8.5','-r',f'require {json.dumps(str(web/"bootstrap.php"))};'+code,*map(str,args)],user=user)
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def check(ok,label):
    if not ok:raise RuntimeError(label)
    receipt['checks'].append(label);print('PASS '+label,flush=True)
def state():
    code=r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$q=$db->prepare('SELECT version,active,signature_status FROM extension_packages WHERE type=? AND slug=?');$q->execute(['addon','social-publishing']);$social=[];foreach(['social_connections','social_post_targets','social_deliveries']as$t){$rows=$db->query("SELECT * FROM `$t` ORDER BY id")->fetchAll();$social[$t]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];}$due=(int)$db->query("SELECT COUNT(*) FROM social_post_targets t INNER JOIN posts p ON p.id=t.post_id WHERE t.enabled=1 AND t.last_enqueued_revision<t.revision AND (p.status='published' OR (p.status='scheduled' AND p.published_at<=UTC_TIMESTAMP()))")->fetchColumn();$processing=(int)$db->query("SELECT COUNT(*) FROM social_deliveries WHERE status='processing'")->fetchColumn();echo json_encode(['package'=>$q->fetch()?:null,'social'=>$social,'due'=>$due,'processing'=>$processing],JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web))
def deploy_file(relative):
    src=stage/'.cms/source'/relative;dst=web/relative
    if not src.is_file() or src.is_symlink():raise RuntimeError('Invalid source: '+relative)
    old=backup/'core'/relative;old.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(dst,old);stat=dst.stat();tmp=dst.with_name(dst.name+'.deploy-'+token);shutil.copy2(src,tmp);os.chown(tmp,stat.st_uid,stat.st_gid);os.chmod(tmp,stat.st_mode&0o777);os.replace(tmp,dst);deployed.append(relative)
def restore_files():
    for relative in reversed(deployed):shutil.copy2(backup/'core'/relative,web/relative)

required=[web/'bootstrap.php',stage/'tests/post-editor-regressions.php',stage/'tests/post-editor-ui-production.py',*(stage/'.cms/source'/item for item in core_files)]
check(stage.is_dir()and str(stage).startswith('/root/sense-post-media-workspace-'),'Private scoped deployment stage')
check(all(path.is_file()and not path.is_symlink()for path in required),'Complete post media workspace payload')
before=state();check(before['package']=={'version':'0.5.2','active':1,'signature_status':'verified'},'Pinned Social Publishing 0.5.2 baseline');check(before['due']==0 and before['processing']==0,'No social publication is due or processing')
run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t'])
for path in [stage/'.cms/source/app/Http/DashboardController.php',stage/'.cms/source/app/Views/console-content-post-form.php',stage/'.cms/source/app/Views/console.php',stage/'tests/post-editor-regressions.php']:run(['php8.5','-l',str(path)])
node=shutil.which('node')
if node:
    for path in [stage/'.cms/source/public/theme/sensecms-content-management.js',stage/'.cms/source/public/theme/sensecms-post-editor.js']:run([node,'--check',str(path)])
    check(True,'Staged JavaScript syntax verified')
check('21 post editor regression checks passed' in run(['php8.5',str(stage/'tests/post-editor-regressions.php')]).decode(),'Post editor regression suite')

lock=Path('/root/sensecms-private/deploy-workspace.lock').open('a+b');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
try:
    stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');token=hashlib.sha256(stamp.encode()).hexdigest()[:12];backup=Path('/root/sensecms-backups')/(stamp+'-post-media-workspace');backup.mkdir(mode=0o700);receipt['backup']=str(backup)
    with(backup/'database-before.sql').open('wb')as output:
        result=subprocess.run(['mysqldump','--single-transaction','--routines','--triggers','sensecms_site'],stdout=output,stderr=subprocess.PIPE)
        if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace').strip()or'Database backup failed.')
    os.chmod(backup/'database-before.sql',0o600);check((backup/'database-before.sql').stat().st_size>4096,'Private production database recovery dump')
    for relative in core_files:deploy_file(relative)
    for relative in core_files:check(sha(stage/'.cms/source'/relative)==sha(web/relative),'Deployed checksum '+relative)
    after=state();check(after['package']==before['package'],'Signed Social Publishing package unchanged');check(after['social']==before['social'],'Connections, targets and delivery history preserved exactly');check(after['due']==0 and after['processing']==0,'Deployment queued and published no content')
    worker=json.loads(run(['php8.5',str(web/'addons/social-publishing/scripts/social-worker.php'),str(web)],user='sensecms'));check(worker.get('ok')is True and worker.get('published')==0,'Social worker healthy without publishing content')
    check('PASS Production create/edit post editor' in run(['python3',str(stage/'tests/post-editor-ui-production.py')]).decode(),'Authenticated production create/edit acceptance')
    for url in ['https://www.sensecms.com/theme/sensecms-content-management.css?v=20260920-post-editor-3','https://www.sensecms.com/theme/sensecms-content-management.js?v=20260920-post-editor-3','https://www.sensecms.com/theme/sensecms-post-editor.js?v=20260920-3']:
        with urllib.request.urlopen(url,timeout=30)as response:check(response.status==200,'HTTP 200 '+url)
    run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t']);logs=run(['journalctl','--since','@'+str(started),'--no-pager','-u','nginx','-u','php8.5-fpm']).decode(errors='replace');check(not re.search(r'PHP Fatal|Uncaught|\bcrit\b|\bpanic\b',logs,re.I),'No fresh critical service errors');receipt['status']='deployed'
except BaseException:
    errors=[]
    if backup:
        try:restore_files()
        except BaseException as error:errors.append('core:'+type(error).__name__)
    receipt['status']='rollback-needs-attention' if errors else ('rolled-back' if backup else 'preflight-failed')
    if errors:receipt['recovery_errors']=errors
    raise
finally:
    if backup is not None:(backup/'receipt.json').write_text(json.dumps(receipt,indent=2,sort_keys=True)+'\n');os.chmod(backup/'receipt.json',0o600)
    fcntl.flock(lock,fcntl.LOCK_UN);lock.close()
print(json.dumps(receipt,sort_keys=True),flush=True)
