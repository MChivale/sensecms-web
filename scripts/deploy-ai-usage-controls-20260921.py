#!/usr/bin/env python3
"""Deploy Core AI usage budgets and metadata-only cost accounting."""
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
import urllib.error
import urllib.request

if os.name == 'nt' or os.geteuid() != 0 or len(os.sys.argv) != 2:
    raise SystemExit('Run as root on Linux: deploy-ai-usage-controls-20260921.py <private-stage>')

os.umask(0o077)
stage=Path(os.sys.argv[1]).resolve()
web=Path('/home/sensecms.com/web')
files=[
    'app/Core/AiChatService.php','app/Core/AiContentService.php','app/Core/AiRepository.php',
    'app/Views/console.php','app/Views/console-ai.php',
    'public/theme/sensecms-content-management.css',
]
migration='database/workspace/038_ai_usage_controls.sql'
baseline={
    'app/Core/AiChatService.php':'a8be78a9fea857f9dfd4d57fc42933798a1c2ca3043d14c796cacd5c54fdb4a4',
    'app/Core/AiContentService.php':'87bf4af83c05263741c9af64da0a55c84b92abc6fbe71173a5b37d2bd619458c',
    'app/Core/AiRepository.php':'c1d4d357781bc9df7798f0060afa67e051635f9371a6f954790f52f23d9ff73b',
    'app/Views/console.php':'94cfdb89b6c086437da1c9e2d2ffdfba92d3ef9a6c84c9e6c80cb57d914b1d5f',
    'public/theme/sensecms-content-management.css':'614183db9ee6a0d6d47ea4856c9b308f36a047374365c20da89abd786f2be6d0',
}
receipt={'status':'preflight','checks':[]}
backup=None
deployed=[]
metadata={}
started=int(datetime.datetime.now(datetime.timezone.utc).timestamp())

def run(command,*,user=None):
    if user:command=['sudo','-u',user,*command]
    result=subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
    if result.returncode:raise RuntimeError(result.stdout.decode(errors='replace').strip() or f'Command failed: {command[0]}')
    return result.stdout

def php(code,*args,user=None):
    return run(['php8.5','-r',f'require {json.dumps(str(web/"bootstrap.php"))};'+code,*map(str,args)],user=user)

def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()

def check(ok,label):
    if not ok:raise RuntimeError(label)
    receipt['checks'].append(label);print('PASS '+label,flush=True)

def state():
    return json.loads(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$providers=$db->query('SELECT id,slug,name,driver,base_url,default_model,api_key_encrypted,options,enabled,priority,verified_at,last_error,created_at,updated_at FROM ai_providers ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$usage=(bool)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ai_usage_events'")->fetchColumn();echo json_encode(['provider_count'=>count($providers),'provider_fingerprint'=>hash('sha256',json_encode($providers,JSON_UNESCAPED_SLASHES)),'usage_rows'=>$usage?(int)$db->query('SELECT COUNT(*) FROM ai_usage_events')->fetchColumn():null,'migrations'=>(new App\Installer\WorkspaceMigration($db,$r))->status()],JSON_THROW_ON_ERROR);''',web,user='sensecms'))

def active_theme():
    return json.loads(php(r'''$r=new App\Core\Runtime($argv[1]);echo json_encode((new App\Core\Packages\ThemeManager($r))->active(),JSON_THROW_ON_ERROR);''',web))

def deploy(relative):
    source,target=stage/'.cms/source'/relative,web/relative
    if not source.is_file() or source.is_symlink():raise RuntimeError('Invalid deployment source: '+relative)
    target.parent.mkdir(parents=True,exist_ok=True)
    if target.exists():
        if target.is_symlink() or not target.is_file():raise RuntimeError('Unsafe deployment target: '+relative)
        stat=target.stat();metadata[relative]=(stat.st_uid,stat.st_gid,stat.st_mode&0o777)
        previous=backup/'core'/relative;previous.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(target,previous)
    else:
        parent=target.parent.stat();metadata[relative]=(parent.st_uid,parent.st_gid,0o644)
    uid,gid,mode=metadata[relative];temporary=target.with_name(target.name+'.deploy-'+token);shutil.copy2(source,temporary);os.chown(temporary,uid,gid);os.chmod(temporary,mode);os.replace(temporary,target);deployed.append(relative)

def restore():
    for relative in reversed(deployed):
        if relative==migration:continue
        target,previous=web/relative,backup/'core'/relative
        if previous.is_file():
            shutil.copy2(previous,target);uid,gid,mode=metadata[relative];os.chown(target,uid,gid);os.chmod(target,mode)
        elif target.is_file() and not target.is_symlink():target.unlink()

required=[
    web/'bootstrap.php',stage/'.cms/source/bootstrap.php',
    stage/'tests/builder-defaults.php',stage/'tests/page-builder-ai.php',
    stage/'tests/security-regressions.php',stage/'tests/maintenance-regressions.php',
    stage/'tests/page-builder-ui-production.py',
    *(stage/'.cms/source'/relative for relative in files+[migration]),
]
check(stage.is_dir() and str(stage).startswith('/root/sense-ai-usage-'),'Private scoped deployment stage')
check(all(path.is_file() and not path.is_symlink() for path in required),'Complete Core AI usage deployment payload')
check(all((web/relative).is_file() and sha(web/relative)==expected for relative,expected in baseline.items()),'Expected production Core AI baseline')
check(not (web/'app/Views/console-ai.php').exists(),'New Core AI view is absent before deployment')
check(not (web/migration).exists(),'AI usage migration is cleanly pending')
theme_before=active_theme();check(theme_before['slug']=='sensecms','Pinned signed Sense CMS theme remains active')
before=state();check(before['migrations']['ready'] and not before['migrations']['pending'] and not before['migrations']['missing_tables'],'Existing Workspace schema is healthy')
for path in [stage/'.cms/source'/relative for relative in files if relative.endswith('.php')]:run(['php8.5','-l',str(path)])
check(b'22 Page Builder AI checks passed' in run(['php8.5',str(stage/'tests/page-builder-ai.php')]),'Provider-neutral AI and usage-control regressions')
check(b'282 builder preset/compatibility checks passed' in run(['php8.5',str(stage/'tests/builder-defaults.php')]),'Builder contract regressions')
if b'pdo_sqlite' in run(['php8.5','-m']):
    check(b'92 security regression checks passed' in run(['php8.5',str(stage/'tests/security-regressions.php')]),'Security regressions')
    check(b'16 maintenance checks passed' in run(['php8.5',str(stage/'tests/maintenance-regressions.php')]),'Maintenance regressions')
else:print('SKIP SQLite-dependent security and maintenance regressions (pdo_sqlite unavailable)',flush=True)
run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t'])

lock=Path('/root/sensecms-private/deploy-workspace.lock').open('a+b');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
try:
    stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');token=hashlib.sha256(stamp.encode()).hexdigest()[:12]
    backup=Path('/root/sensecms-backups')/(stamp+'-ai-usage-controls');backup.mkdir(mode=0o700);receipt['backup']=str(backup)
    with (backup/'database-before.sql').open('wb') as output:
        result=subprocess.run(['mysqldump','--single-transaction','--routines','--triggers','sensecms_site'],stdout=output,stderr=subprocess.PIPE)
        if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace').strip() or 'Database backup failed.')
    os.chmod(backup/'database-before.sql',0o600);check((backup/'database-before.sql').stat().st_size>4096,'Private production database recovery dump')
    deploy(migration)
    applied=int(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);echo(new App\Installer\WorkspaceMigration($db,$r))->apply();''',web,user='sensecms'))
    check(applied==1,'AI usage migration 038 applied exactly once')
    schema=json.loads(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$columns=[];foreach(['provider_id','provider_slug','purpose','reserved_input_tokens','reserved_output_tokens','input_cost_per_million','output_cost_per_million','estimated_cost_usd','actual_cost_usd']as$c){$q=$db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ai_usage_events' AND column_name=?");$q->execute([$c]);$columns[$c]=(bool)$q->fetchColumn();}echo json_encode(['columns'=>$columns,'rows'=>(int)$db->query('SELECT COUNT(*) FROM ai_usage_events')->fetchColumn(),'status'=>(new App\Installer\WorkspaceMigration($db,$r))->status()],JSON_THROW_ON_ERROR);''',web,user='sensecms'))
    check(all(schema['columns'].values()) and schema['rows']==0 and schema['status']['ready'],'AI usage schema is ready without stored prompts, output or usage')
    for relative in files:deploy(relative)
    for relative in files+[migration]:check(sha(web/relative)==sha(stage/'.cms/source'/relative),'Deployed checksum '+relative)
    policy=json.loads(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$slug='deployment-budget-check-'.bin2hex(random_bytes(4));$options=['purposes'=>['builder'],'limits'=>['daily_requests'=>1,'monthly_requests'=>1,'daily_tokens'=>10000,'monthly_tokens'=>10000,'daily_cost_usd'=>1,'monthly_cost_usd'=>1,'input_cost_per_million'=>1,'output_cost_per_million'=>2,'warning_percent'=>80]];try{$q=$db->prepare("INSERT INTO ai_providers (slug,name,driver,base_url,default_model,options,enabled,priority,created_at,updated_at) VALUES (?,?,'openai-responses','https://api.openai.com/v1','deployment-check',?,0,999,NOW(),NOW())");$q->execute([$slug,'Deployment budget check',json_encode($options,JSON_THROW_ON_ERROR)]);$provider=(new App\Core\AiRepository($db))->providerById((int)$db->lastInsertId());$repo=new App\Core\AiRepository($db);$id=$repo->beginUsage($provider,'builder',null,400,100);$blocked=false;try{$repo->beginUsage($provider,'builder',null,400,100);}catch(RuntimeException$e){$blocked=$e->getCode()===429;}$repo->finishUsage($id,true,['input'=>10,'output'=>5]);$event=$db->query("SELECT status,input_tokens,output_tokens,actual_cost_usd FROM ai_usage_events WHERE provider_id=".(int)$provider['id'])->fetch(PDO::FETCH_ASSOC);echo json_encode(['blocked'=>$blocked,'event'=>$event],JSON_THROW_ON_ERROR);}finally{if(isset($provider['id'])){$db->prepare('DELETE FROM ai_usage_events WHERE provider_id=?')->execute([$provider['id']]);$db->prepare('DELETE FROM ai_providers WHERE id=?')->execute([$provider['id']]);}}''',web,user='sensecms'))
    check(policy['blocked'] and policy['event']['status']=='completed' and int(policy['event']['input_tokens'])==10 and int(policy['event']['output_tokens'])==5 and abs(float(policy['event']['actual_cost_usd'])-0.00002)<0.000001,'Transactional request budget and token cost accounting')
    after_policy=state();check(after_policy['provider_count']==before['provider_count'] and after_policy['provider_fingerprint']==before['provider_fingerprint'] and after_policy['usage_rows']==0,'Acceptance removed temporary metadata and preserved every configured provider')
    check(b'PASS Production Core Page Builder AI' in run(['python3',str(stage/'tests/page-builder-ui-production.py')]),'Authenticated production AI usage and Builder acceptance')
    for route in ['/ai','/content/builder','/theme/sensecms-content-management.css?v=20260921-ai-budgets-1']:
        try:
            with urllib.request.urlopen('https://www.sensecms.com'+route,timeout=30) as response:check(response.status in (200,302) and response.read(),'HTTP response '+route)
        except urllib.error.HTTPError as error:check(error.code in (302,401,403),'Protected HTTP response '+route)
    check(state()['usage_rows']==0,'Acceptance made no external AI request')
    check(active_theme()==theme_before,'Active signed theme was not changed')
    run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t'])
    logs=run(['journalctl','--since','@'+str(started),'--no-pager','-u','nginx','-u','php8.5-fpm']).decode(errors='replace')
    check(not re.search(r'PHP Fatal|Uncaught|\bcrit\b|\bpanic\b',logs,re.I),'No fresh critical service errors')
    receipt['status']='deployed'
except BaseException:
    errors=[]
    if backup:
        try:restore()
        except BaseException as error:errors.append('recovery:'+type(error).__name__)
    receipt['status']='rollback-needs-attention' if errors else ('rolled-back-with-additive-migration-retained' if backup else 'preflight-failed')
    if errors:receipt['recovery_errors']=errors
    raise
finally:
    if backup:
        (backup/'receipt.json').write_text(json.dumps(receipt,indent=2,sort_keys=True)+'\n');os.chmod(backup/'receipt.json',0o600)
    fcntl.flock(lock,fcntl.LOCK_UN);lock.close()

print(json.dumps(receipt,sort_keys=True),flush=True)
