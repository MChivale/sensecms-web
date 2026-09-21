#!/usr/bin/env python3
"""Deploy the Core Knowledge Base, RAG and cost-locked training workspace."""
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
    raise SystemExit('Run as root on Linux: deploy-ai-knowledge-20260921.py <private-stage>')

os.umask(0o077)
stage=Path(os.sys.argv[1]).resolve();web=Path('/home/sensecms.com/web')
files=[
    'app/Core/AccessControl.php','app/Core/AiKnowledgeBase.php','app/Core/AiRepository.php',
    'app/Core/CmsRepository.php','app/Core/ConsoleSearchIndex.php','app/Http/DashboardController.php',
    'app/Views/console-ai-knowledge.php','app/Views/console-ai-navigation.php','app/Views/console-ai.php',
    'app/Views/console-content-post-form.php','app/Views/console.php','app/workspace.php',
    'public/theme/sensecms-ai-knowledge.js','public/theme/sensecms-content-management.css',
]
migration='database/workspace/040_ai_knowledge_base.sql'
baseline={
    'app/Core/AccessControl.php':'9c3bc6bd769bbbe2857f7b2f2354b39b54f86df27ca9cb72bb95f1d2e77c66f5',
    'app/Core/AiRepository.php':'5108af66698f113a639982dd8de7f40cceaee63a9c82d59a5e874eb8fd4b205e',
    'app/Core/CmsRepository.php':'5c19d53ea72dc436431a1fec525b19c2c8639f1c8f3b0edef34228eaa765164f',
    'app/Core/ConsoleSearchIndex.php':'18f98c1ba16385aa4b64a0741c1577d53a3e5d5f44b04bf327b6d1602bb858b2',
    'app/Http/DashboardController.php':'5b43e54ae7c16b9d11fc915bcd99938cb5b795334557826bdd67c0a8548bb183',
    'app/Views/console-ai.php':'0067b88d00260b9151daf89b48561a458b73381dc7eea29d2345f044d4417f7b',
    'app/Views/console-content-post-form.php':'1270675d46401d3d0a3071b086f37486a075859e386f84aab810d20d39a6c3c4',
    'app/Views/console.php':'6164eb85bb7b6277c735e25a20f34f54ad561d910cef53a3a0c86922aa9c4eae',
    'app/workspace.php':'e8b08176fda28b4a11d6182d78c82d3579367afb0766375494753320213ce6c6',
    'public/theme/sensecms-content-management.css':'2c16ee76e88fe1593adadc67468b1b50e1cb3b92155a80da7b6da5e4b84625f7',
}
new_files=set(files)-set(baseline)
receipt={'status':'preflight','checks':[]};backup=None;deployed=[];metadata={};started=int(datetime.datetime.now(datetime.timezone.utc).timestamp())

def run(command,*,user=None):
    if user:command=['sudo','-u',user,*command]
    result=subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
    if result.returncode:raise RuntimeError(result.stdout.decode(errors='replace').strip() or f'Command failed: {command[0]}')
    return result.stdout

def php(code,*args,user=None):return run(['php8.5','-r',f'require {json.dumps(str(web/"bootstrap.php"))};'+code,*map(str,args)],user=user)
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def check(ok,label):
    if not ok:raise RuntimeError(label)
    receipt['checks'].append(label);print('PASS '+label,flush=True)

def state():
    return json.loads(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$providers=$db->query('SELECT id,slug,driver,default_model,api_key_encrypted,options,enabled,priority,verified_at,updated_at FROM ai_providers ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);echo json_encode(['provider_fingerprint'=>hash('sha256',json_encode($providers,JSON_UNESCAPED_SLASHES)),'usage_rows'=>(int)$db->query('SELECT COUNT(*) FROM ai_usage_events')->fetchColumn(),'knowledge_documents'=>(int)$db->query('SELECT COUNT(*) FROM ai_knowledge_documents')->fetchColumn(),'knowledge_chunks'=>(int)$db->query('SELECT COUNT(*) FROM ai_knowledge_chunks')->fetchColumn(),'migrations'=>(new App\Installer\WorkspaceMigration($db,$r))->status()],JSON_THROW_ON_ERROR);''',web,user='sensecms'))

def active_theme():return json.loads(php(r'''$r=new App\Core\Runtime($argv[1]);echo json_encode((new App\Core\Packages\ThemeManager($r))->active(),JSON_THROW_ON_ERROR);''',web))

def deploy(relative):
    source,target=stage/'.cms/source'/relative,web/relative
    if not source.is_file() or source.is_symlink():raise RuntimeError('Invalid deployment source: '+relative)
    target.parent.mkdir(parents=True,exist_ok=True)
    if target.exists():
        if target.is_symlink() or not target.is_file():raise RuntimeError('Unsafe deployment target: '+relative)
        stat=target.stat();metadata[relative]=(stat.st_uid,stat.st_gid,stat.st_mode&0o777);previous=backup/'core'/relative;previous.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(target,previous)
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
    run(['systemctl','reload','php8.5-fpm'])

required=[
    web/'bootstrap.php',stage/'.cms/source/bootstrap.php',stage/'tests/ai-knowledge.php',stage/'tests/workspace-migration.php',
    stage/'tests/posts-ai.php',stage/'tests/page-builder-ai.php',stage/'tests/post-editor-regressions.php',
    stage/'tests/security-regressions.php',stage/'tests/ai-knowledge-ui-production.py',
    stage/'tests/post-editor-ui-production.py',stage/'tests/page-builder-ui-production.py',
    *(stage/'.cms/source'/relative for relative in files+[migration]),
]
check(stage.is_dir() and str(stage).startswith('/root/sense-ai-knowledge-'),'Private scoped deployment stage')
check(all(path.is_file() and not path.is_symlink() for path in required),'Complete Core Knowledge Base deployment payload')
check(all((web/relative).is_file() and sha(web/relative)==expected for relative,expected in baseline.items()),'Expected production Core baseline')
check(all(not (web/relative).exists() for relative in new_files),'New Knowledge Base files are absent before deployment')
migration_retained=(web/migration).is_file();check(not migration_retained or sha(web/migration)==sha(stage/'.cms/source'/migration),'Knowledge Base migration is pending or matches the retained additive migration')
theme_before=active_theme();check(theme_before['slug']=='sensecms','Pinned signed Sense CMS theme remains active')
before=state();check(before['migrations']['ready'] and not before['migrations']['pending'] and not before['migrations']['missing_tables'],'Existing Workspace schema is healthy')
for path in [stage/'.cms/source'/relative for relative in files if relative.endswith('.php')]:run(['php8.5','-l',str(path)])
node=shutil.which('node')
if node:
    for relative in [path for path in files if path.endswith('.js')]:run([node,'--check',str(stage/'.cms/source'/relative)])
    check(True,'Staged JavaScript syntax')
check(b'AI Knowledge Base checks passed: 28' in run(['php8.5',str(stage/'tests/ai-knowledge.php')]),'Knowledge Base security and architecture checks')
check(b'16 Posts AI checks passed' in run(['php8.5',str(stage/'tests/posts-ai.php')]),'Adjacent Posts AI checks')
check(b'23 Page Builder AI checks passed' in run(['php8.5',str(stage/'tests/page-builder-ai.php')]),'Adjacent Page Builder AI checks')
check(b'22 post editor regression checks passed' in run(['php8.5',str(stage/'tests/post-editor-regressions.php')]),'Post editor regressions')
check(b'Workspace migration, functional and package checks.' in run(['php8.5',str(stage/'tests/workspace-migration.php')]),'Disposable MariaDB migration rehearsal')
if b'pdo_sqlite' in run(['php8.5','-m']):check(b'92 security regression checks passed' in run(['php8.5',str(stage/'tests/security-regressions.php')]),'Security regressions')
else:print('SKIP SQLite-dependent security regressions (pdo_sqlite unavailable)',flush=True)
run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t'])

lock=Path('/root/sensecms-private/deploy-workspace.lock').open('a+b');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
try:
    stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');token=hashlib.sha256(stamp.encode()).hexdigest()[:12];backup=Path('/root/sensecms-backups')/(stamp+'-ai-knowledge');backup.mkdir(mode=0o700);receipt['backup']=str(backup)
    with (backup/'database-before.sql').open('wb') as output:
        result=subprocess.run(['mysqldump','--single-transaction','--routines','--triggers','sensecms_site'],stdout=output,stderr=subprocess.PIPE)
        if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace').strip() or 'Database backup failed.')
    os.chmod(backup/'database-before.sql',0o600);check((backup/'database-before.sql').stat().st_size>4096,'Private production database recovery dump')
    deploy(migration);applied=int(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);echo(new App\Installer\WorkspaceMigration($db,$r))->apply();''',web,user='sensecms'));check(applied==(0 if migration_retained else 1),'Knowledge Base migration 040 applied exactly once')
    schema=json.loads(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$tables=[];foreach(['ai_training_datasets','ai_training_examples','ai_training_jobs','ai_evaluation_cases']as$t){$q=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$t]);$tables[$t]=(bool)$q->fetchColumn();}$columns=[];foreach([['pages','ai_knowledge_enabled'],['posts','ai_knowledge_enabled'],['ai_knowledge_documents','body'],['ai_knowledge_documents','storage_path'],['ai_knowledge_documents','content_checksum'],['ai_knowledge_documents','index_status']]as[$t,$c]){$q=$db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$t,$c]);$columns[$t.'.'.$c]=(bool)$q->fetchColumn();}echo json_encode(['tables'=>$tables,'columns'=>$columns,'documents'=>(int)$db->query('SELECT COUNT(*) FROM ai_knowledge_documents')->fetchColumn(),'chunks'=>(int)$db->query('SELECT COUNT(*) FROM ai_knowledge_chunks')->fetchColumn(),'status'=>(new App\Installer\WorkspaceMigration($db,$r))->status()],JSON_THROW_ON_ERROR);''',web,user='sensecms'))
    check(all(schema['tables'].values()) and all(schema['columns'].values()) and schema['status']['ready'],'Knowledge Base and training schema is ready')
    check(schema['documents']==before['knowledge_documents'] and schema['chunks']==before['knowledge_chunks'],'Additive migration preserved existing knowledge data')
    for relative in files:deploy(relative)
    for relative in files+[migration]:check(sha(web/relative)==sha(stage/'.cms/source'/relative),'Deployed checksum '+relative)
    run(['systemctl','reload','php8.5-fpm']);check(run(['systemctl','is-active','php8.5-fpm']).strip()==b'active','PHP-FPM reloaded with the deployed Core')
    check(b'PASS Production Core Knowledge Base' in run(['python3',str(stage/'tests/ai-knowledge-ui-production.py')]),'Authenticated production Knowledge Base acceptance')
    check(b'PASS Production create/edit post editor' in run(['python3',str(stage/'tests/post-editor-ui-production.py')]),'Adjacent post editor acceptance')
    check(b'PASS Production Core Page Builder AI' in run(['python3',str(stage/'tests/page-builder-ui-production.py')]),'Adjacent Page Builder AI acceptance')
    for route in ['/ai/knowledge','/content/posts/new','/theme/sensecms-ai-knowledge.js?v=20260921-1','/theme/sensecms-content-management.css?v=20260921-knowledge-1']:
        try:
            with urllib.request.urlopen('https://www.sensecms.com'+route,timeout=30)as response:check(response.status in (200,302) and response.read(),'HTTP response '+route)
        except urllib.error.HTTPError as error:check(error.code in (302,401,403),'Protected HTTP response '+route)
    after=state();check(after['provider_fingerprint']==before['provider_fingerprint'] and after['usage_rows']==before['usage_rows'],'Providers and AI usage ledger are unchanged')
    check(active_theme()==theme_before,'Active signed theme was not changed')
    run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t'])
    logs=run(['journalctl','--since','@'+str(started),'--no-pager','-u','nginx','-u','php8.5-fpm']).decode(errors='replace');check(not re.search(r'PHP Fatal|Uncaught|\bcrit\b|\bpanic\b',logs,re.I),'No fresh critical service errors')
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
    if backup:(backup/'receipt.json').write_text(json.dumps(receipt,indent=2,sort_keys=True)+'\n');os.chmod(backup/'receipt.json',0o600)
    fcntl.flock(lock,fcntl.LOCK_UN);lock.close()

print(json.dumps(receipt,sort_keys=True),flush=True)
