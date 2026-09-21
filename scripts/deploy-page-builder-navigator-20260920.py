#!/usr/bin/env python3
"""Deploy Core Page Builder recovery, navigator, clipboard and Core-owned Quill assets."""
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
import time
import urllib.request

if os.name=='nt' or os.geteuid()!=0 or len(sys.argv)!=2:
    raise SystemExit('Run as root on Linux: deploy-page-builder-navigator-20260920.py <private-stage>')
os.umask(0o077)
stage=Path(sys.argv[1]).resolve();web=Path('/home/sensecms.com/web')
core_files=[
    'app/Core/CmsRepository.php','app/Http/DashboardController.php','app/Views/console-page-builder.php','app/Views/console.php','app/workspace.php',
    'public/theme/sensecms-content-management.js','public/theme/sensecms-page-builder.css','public/theme/sensecms-page-builder.js',
    'public/assets/lib/quill/LICENSE.txt','public/assets/lib/quill/quill.js','public/assets/lib/quill/quill.snow.css',
]
migrations=['database/workspace/032_page_builder_drafts.sql','database/workspace/033_page_builder_clipboard.sql']
baseline={
    'app/Core/CmsRepository.php':'0c4b1feeeef220b20e604cf36a19d78a3a265ae3d2d35a26b2a791b6247e47d9',
    'app/Http/DashboardController.php':'ff30ee754006cc4ac850e3761e05b5c7e92901e5a61ed2769f549f0e95911f86',
    'app/Views/console-page-builder.php':'1922986d8fe7abeac63d8a39ae0a89a338b900e45a886f050d447ad57199c587',
    'app/Views/console.php':'362b3e23403055b90583c157363131cb7f6273e4dfd66d316dbed5df9510e4c2',
    'app/workspace.php':'ca6382a9706ee44447af6d84c9623e8979b57b3268e591afdcc9d5b3a5f4e7af',
    'public/theme/sensecms-content-management.js':'a29071cefe284b3e94604c0b736e115da93806adc8f19fbe327a4d55740a4cd8',
    'public/theme/sensecms-page-builder.css':'6993d51656db9e959078249171791ceb974ead3cf0a575c9fc140b27c95f3fc1',
    'public/theme/sensecms-page-builder.js':'2e9d2a5bf5b221b5c5fa0fa7aac53f11ffded6670f5194d4bc6e7eb9e83c918a',
}
quill_baseline={'LICENSE':'395c12b616d6f58238b4be39284d4d9221b58dd6e1f1e34d9ab537e34abbb022','quill.js':'f6157c72ac9b3f51cdead426335688a027b12405d9d6a4daadd38a676b2d7ff2','quill.snow.css':'1c7948cd13aa92fac6390319bc1e5e461823da171519d3a768db56164f871636'}
receipt={'status':'preflight','checks':[]};backup=None;deployed=[];metadata={};started=int(datetime.datetime.now(datetime.timezone.utc).timestamp())

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
def migration_status():
    code=r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);echo json_encode((new App\Installer\WorkspaceMigration($db,$r))->status(),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code,web,user='sensecms'))
def deploy(relative,*,uid=None,gid=None,mode=None):
    src=stage/'.cms/source'/relative;dst=web/relative
    if not src.is_file()or src.is_symlink():raise RuntimeError('Invalid deployment source: '+relative)
    dst.parent.mkdir(parents=True,exist_ok=True)
    if relative.startswith('public/'):
        current=web/'public'
        for part in Path(relative).parts[1:-1]:
            current=current/part
            if current.is_symlink()or not current.is_dir():raise RuntimeError('Unsafe public deployment directory: '+str(current))
            os.chmod(current,0o755)
    else:os.chown(dst.parent,0,0);os.chmod(dst.parent,0o755)
    if dst.exists():
        if dst.is_symlink()or not dst.is_file():raise RuntimeError('Unsafe deployment target: '+relative)
        stat=dst.stat();metadata[relative]=(stat.st_uid,stat.st_gid,stat.st_mode&0o777);old=backup/'core'/relative;old.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(dst,old);uid,gid,mode=metadata[relative]
    else:uid,gid,mode=uid if uid is not None else 0,gid if gid is not None else 0,mode if mode is not None else 0o644
    tmp=dst.with_name(dst.name+'.deploy-'+token);shutil.copy2(src,tmp);os.chown(tmp,uid,gid);os.chmod(tmp,mode);os.replace(tmp,dst);deployed.append(relative)
def restore():
    for relative in reversed(deployed):
        if relative in migrations:continue # Additive DDL and its verified journal entry remain recoverable together.
        dst=web/relative;old=backup/'core'/relative
        if old.is_file():
            shutil.copy2(old,dst);uid,gid,mode=metadata[relative];os.chown(dst,uid,gid);os.chmod(dst,mode)
        elif dst.is_file()and not dst.is_symlink():dst.unlink()

required=[web/'bootstrap.php',stage/'.cms/source/bootstrap.php',stage/'tests/builder-defaults.php',stage/'tests/page-builder-ui-production.py',stage/'tests/post-editor-ui-production.py',stage/'.themes/sensecms/theme.json',*(stage/'.cms/source'/path for path in core_files+migrations),stage/'.cms/source/app/Core/PageBuilder.php',stage/'.cms/source/app/Core/ThemeContract.php',stage/'.cms/source/app/Core/HtmlSanitizer.php',stage/'.cms/source/config/page-builder.php',stage/'.cms/source/config/page-builder-localized-defaults.php',stage/'.cms/source/config/page-builder-layouts.json']
check(stage.is_dir()and str(stage).startswith('/root/sense-builder-navigator-'),'Private scoped deployment stage')
check(all(path.is_file()and not path.is_symlink()for path in required),'Complete Page Builder deployment payload')
check(all((web/path).is_file()and sha(web/path)==expected for path,expected in baseline.items()),'Expected production Page Builder baseline')
old_quill=web/'public/theme/vendor/quill';check(old_quill.is_dir()and all(sha(old_quill/name)==expected for name,expected in quill_baseline.items()),'Expected legacy Quill baseline')
provisioned=all((web/path).is_file()and sha(web/path)==sha(stage/'.cms/source'/path)for path in migrations)
check(provisioned or all(not(web/path).exists()for path in migrations),'Builder migrations are either cleanly pending or already verified')
check(all(not(web/path).exists()for path in core_files[-3:]),'Core-owned Quill destination is initially clean')
status=migration_status();check(status['ready']and not status['pending']and not status['missing_tables'],'Existing Workspace schema is healthy')
run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t'])
for path in [stage/'.cms/source/app/Core/CmsRepository.php',stage/'.cms/source/app/Http/DashboardController.php',stage/'.cms/source/app/Views/console-page-builder.php',stage/'.cms/source/app/Views/console.php',stage/'.cms/source/app/workspace.php',stage/'tests/builder-defaults.php']:
    run(['php8.5','-l',str(path)])
node=shutil.which('node')
if node:
    run([node,'--check',str(stage/'.cms/source/public/theme/sensecms-page-builder.js')]);run([node,'--check',str(stage/'.cms/source/public/theme/sensecms-content-management.js')]);check(True,'Staged JavaScript syntax')
check(b'190 builder preset/compatibility checks passed' in run(['php8.5',str(stage/'tests/builder-defaults.php')]),'Builder contract regressions')

lock=Path('/root/sensecms-private/deploy-workspace.lock').open('a+b');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
try:
    stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');token=hashlib.sha256(stamp.encode()).hexdigest()[:12];backup=Path('/root/sensecms-backups')/(stamp+'-page-builder-navigator');backup.mkdir(mode=0o700);receipt['backup']=str(backup)
    shutil.copytree(old_quill,backup/'legacy-quill')
    with(backup/'database-before.sql').open('wb')as output:
        result=subprocess.run(['mysqldump','--single-transaction','--routines','--triggers','sensecms_site'],stdout=output,stderr=subprocess.PIPE)
        if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace').strip()or'Database backup failed.')
    os.chmod(backup/'database-before.sql',0o600);check((backup/'database-before.sql').stat().st_size>4096,'Private production database recovery dump')
    for relative in migrations:deploy(relative,uid=0,gid=0,mode=0o644)
    applied=int(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);echo(new App\Installer\WorkspaceMigration($db,$r))->apply();''',web,user='sensecms'))
    check(applied==(0 if provisioned else 2),'Workspace migrations 032 and 033 are verified and complete')
    for relative in core_files[:-3]:deploy(relative)
    old_meta=(old_quill/'quill.js').stat()
    for relative in core_files[-3:]:deploy(relative,uid=old_meta.st_uid,gid=old_meta.st_gid,mode=0o644)
    for relative in core_files+migrations:check(sha(web/relative)==sha(stage/'.cms/source'/relative),'Deployed checksum '+relative)
    schema=json.loads(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$tables=[];foreach(['page_builder_drafts','page_builder_clipboards']as$t)$tables[$t]=(bool)$db->query("SHOW TABLES LIKE '$t'")->fetchColumn();$clipboard=$db->query("SHOW COLUMNS FROM page_builder_clipboards")->fetchAll(PDO::FETCH_ASSOC);echo json_encode(['tables'=>$tables,'clipboard'=>$clipboard,'status'=>(new App\Installer\WorkspaceMigration($db,$r))->status()],JSON_THROW_ON_ERROR);''',web,user='sensecms'))
    check(all(schema['tables'].values())and schema['status']['ready'],'Production Page Builder recovery and clipboard schema ready')
    time.sleep(3) # FPM OPcache revalidates timestamps every two seconds; avoid mixed old/new class generations.
    check(b'PASS Production Core Page Builder navigator' in run(['python3',str(stage/'tests/page-builder-ui-production.py')]),'Authenticated production Page Builder acceptance')
    check(b'PASS Production create/edit post editor' in run(['python3',str(stage/'tests/post-editor-ui-production.py')]),'Authenticated production post editor and Core Quill acceptance')
    for route in ['/','/theme/sensecms-page-builder.css?v=20260920-builder-navigator-1','/theme/sensecms-page-builder.js?v=20260920-builder-navigator-1','/assets/lib/quill/quill.js?v=2.0.3','/assets/lib/quill/quill.snow.css?v=2.0.3']:
        with urllib.request.urlopen('https://www.sensecms.com'+route,timeout=30)as response:check(response.status==200 and response.read(),'HTTP 200 '+route)
    run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t']);logs=run(['journalctl','--since','@'+str(started),'--no-pager','-u','nginx','-u','php8.5-fpm']).decode(errors='replace');check(not re.search(r'PHP Fatal|Uncaught|\bcrit\b|\bpanic\b',logs,re.I),'No fresh critical service errors')
    shutil.rmtree(old_quill);check(not old_quill.exists(),'Legacy theme-scoped Quill copy removed after verified cutover')
    receipt['status']='deployed'
except BaseException:
    receipt['status']='rolling-back' if backup else 'preflight-failed';errors=[]
    if backup:
        try:restore()
        except BaseException as error:errors.append('core:'+type(error).__name__)
    if receipt['status']=='rolling-back':receipt['status']='rollback-needs-attention' if errors else 'rolled-back-with-additive-migrations-retained'
    if errors:receipt['recovery_errors']=errors
    raise
finally:
    if backup is not None:
        (backup/'receipt.json').write_text(json.dumps(receipt,indent=2,sort_keys=True)+'\n');os.chmod(backup/'receipt.json',0o600)
    fcntl.flock(lock,fcntl.LOCK_UN);lock.close()
print(json.dumps(receipt,sort_keys=True),flush=True)
