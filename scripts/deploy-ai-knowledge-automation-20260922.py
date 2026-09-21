#!/usr/bin/env python3
"""Deploy automatic Knowledge Base sync, no-cost training preparation and AI product pages."""
from __future__ import annotations

import datetime,fcntl,hashlib,json,os,re,shutil,subprocess,urllib.request
from pathlib import Path

if os.name=='nt' or os.geteuid()!=0 or len(os.sys.argv)!=2:raise SystemExit('Run as root on Linux: deploy-ai-knowledge-automation-20260922.py <private-stage>')
os.umask(0o077);stage=Path(os.sys.argv[1]).resolve();web=Path('/home/sensecms.com/web');cron=Path('/etc/cron.d/sensecms-ai-knowledge')
files=['app/Core/AiKnowledgeBase.php','app/Core/CmsRepository.php','app/Http/DashboardController.php','app/Views/console-ai-knowledge.php','app/workspace.php','scripts/ai-knowledge-sync.php']
migration='database/workspace/041_ai_knowledge_automation.sql'
baseline={
 'app/Core/AiKnowledgeBase.php':'8417180fe4ed5ed12c54a7398e99c9d3089988c18fa4a10a377a47c4a1d1cb02',
 'app/Core/CmsRepository.php':'88415e341585b12e4491fc7db5e6f236065dc74d20a5f6bd61423de5d8e16ab0',
 'app/Http/DashboardController.php':'79a6ee5bbb09db613bdfd39808e37a1403fa6b69c1f540bd89607e970d042210',
 'app/Views/console-ai-knowledge.php':'a6ecf17d56f41cf9853610f26d74c701f900f9b169d354bf5f630e7ecc7630ca',
 'app/workspace.php':'7a40b78446f8f6a9955c1f217fa9374e8feddfe474b11f7aa03618c681758a51',
}
receipt={'status':'preflight','checks':[]};backup=None;deployed=[];metadata={};publisher_applied=False;cron_previous=None;started=int(datetime.datetime.now(datetime.timezone.utc).timestamp())
def run(command,*,user=None):
    if user:command=['sudo','-u',user,*command]
    result=subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
    if result.returncode:raise RuntimeError(result.stdout.decode(errors='replace').strip() or 'Command failed: '+command[0])
    return result.stdout
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def check(ok,label):
    if not ok:raise RuntimeError(label)
    receipt['checks'].append(label);print('PASS '+label,flush=True)
def php(code,*args,user=None):return run(['php8.5','-r',f'require {json.dumps(str(web/"bootstrap.php"))};'+code,*map(str,args)],user=user)
def state():
    return json.loads(php(r'''$r=new App\Core\Runtime($argv[1]);$db=App\Core\Runtime::connect($r->read('installed')['database']);$providers=$db->query('SELECT id,slug,driver,default_model,api_key_encrypted,options,enabled,priority,verified_at,updated_at FROM ai_providers ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);echo json_encode(['provider_fingerprint'=>hash('sha256',json_encode($providers,JSON_UNESCAPED_SLASHES)),'usage_rows'=>(int)$db->query('SELECT COUNT(*) FROM ai_usage_events')->fetchColumn(),'training_jobs'=>(int)$db->query('SELECT COUNT(*) FROM ai_training_jobs')->fetchColumn(),'documents'=>(int)$db->query('SELECT COUNT(*) FROM ai_knowledge_documents')->fetchColumn(),'chunks'=>(int)$db->query('SELECT COUNT(*) FROM ai_knowledge_chunks')->fetchColumn(),'migrations'=>(new App\Installer\WorkspaceMigration($db,$argv[1]))->status()],JSON_THROW_ON_ERROR);''',web,user='sensecms'))
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
    global publisher_applied
    if publisher_applied:
        run(['php8.5',str(stage/'scripts/publish-ai-product-pages.php'),str(web),'--rollback',str(backup)]);publisher_applied=False
        try:run(['sudo','-u','sensecms','php8.5',str(web/'scripts/ai-knowledge-sync.php'),'--reconcile'])
        except BaseException:pass
    for relative in reversed(deployed):
        if relative==migration:continue
        target,previous=web/relative,backup/'core'/relative
        if previous.is_file():
            shutil.copy2(previous,target);uid,gid,mode=metadata[relative];os.chown(target,uid,gid);os.chmod(target,mode)
        elif target.is_file() and not target.is_symlink():target.unlink()
    if cron_previous is None:
        if cron.is_file() and not cron.is_symlink():cron.unlink()
    else:cron.write_bytes(cron_previous);os.chown(cron,0,0);os.chmod(cron,0o644)
    run(['systemctl','reload','php8.5-fpm'])

test_files=['database/workspace/040_ai_knowledge_base.sql','app/Core/AiRepository.php','app/Core/AccessControl.php','app/Views/console-content-post-form.php','public/theme/sensecms-ai-knowledge.js']
required=[stage/'.cms/source'/p for p in files+[migration]+test_files]+[stage/'deploy/cron/sensecms-ai-knowledge',stage/'scripts/publish-ai-product-pages.php',stage/'tests/ai-knowledge.php',stage/'tests/ai-knowledge-ui-production.py']
check(stage.is_dir() and str(stage).startswith('/root/sense-ai-automation-'),'Private scoped deployment stage')
check(all(p.is_file() and not p.is_symlink() for p in required),'Complete AI automation deployment payload')
check(all((web/p).is_file() and sha(web/p)==expected for p,expected in baseline.items()),'Expected production Core baseline')
migration_retained=(web/migration).is_file();check((not migration_retained or sha(web/migration)==sha(stage/'.cms/source'/migration)) and not (web/'scripts/ai-knowledge-sync.php').exists(),'Automation migration is retained safely and worker is cleanly pending')
check(not cron.exists(),'AI Knowledge Base cron is cleanly pending')
before=state();check(before['migrations']['ready'] and not before['migrations']['pending'] and not before['migrations']['missing_tables'],'Existing Workspace schema is healthy')
for path in [stage/'.cms/source'/p for p in files if p.endswith('.php')]+[stage/'scripts/publish-ai-product-pages.php']:run(['php8.5','-l',str(path)])
check(b'AI Knowledge Base checks passed: 38' in run(['php8.5',str(stage/'tests/ai-knowledge.php')]),'Knowledge Base automation, training and SEO checks')
run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t'])
lock=Path('/root/sensecms-private/deploy-workspace.lock').open('a+b');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
try:
    stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');token=hashlib.sha256(stamp.encode()).hexdigest()[:12];backup=Path('/root/sensecms-backups')/(stamp+'-ai-automation');backup.mkdir(mode=0o700);receipt['backup']=str(backup)
    with (backup/'database-before.sql').open('wb')as output:
        result=subprocess.run(['mysqldump','--single-transaction','--routines','--triggers','sensecms_site'],stdout=output,stderr=subprocess.PIPE)
        if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace').strip() or 'Database backup failed.')
    os.chmod(backup/'database-before.sql',0o600);check((backup/'database-before.sql').stat().st_size>4096,'Private production database recovery dump')
    if not migration_retained:deploy(migration)
    applied=int(php(r'''$r=new App\Core\Runtime($argv[1]);$db=App\Core\Runtime::connect($r->read('installed')['database']);echo(new App\Installer\WorkspaceMigration($db,$argv[1]))->apply();''',web,user='sensecms'));check(applied==(0 if migration_retained else 1),'Knowledge automation migration 041 applied exactly once')
    schema=json.loads(php(r'''$r=new App\Core\Runtime($argv[1]);$db=App\Core\Runtime::connect($r->read('installed')['database']);$q=$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ai_knowledge_sync_queue'");$cols=[];foreach(['dataset_checksum','training_file_path','example_count','estimated_tokens','prepared_at']as$c){$s=$db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ai_training_jobs' AND column_name=?");$s->execute([$c]);$cols[$c]=(bool)$s->fetchColumn();}echo json_encode(['queue'=>(bool)$q->fetchColumn(),'columns'=>$cols,'status'=>(new App\Installer\WorkspaceMigration($db,$argv[1]))->status()],JSON_THROW_ON_ERROR);''',web,user='sensecms'));check(schema['queue'] and all(schema['columns'].values()) and schema['status']['ready'],'Queue and local training schema are ready')
    for relative in files:deploy(relative)
    cron_source=stage/'deploy/cron/sensecms-ai-knowledge';cron_previous=cron.read_bytes() if cron.is_file() else None;temporary=cron.with_name(cron.name+'.deploy-'+token);shutil.copy2(cron_source,temporary);os.chown(temporary,0,0);os.chmod(temporary,0o644);os.replace(temporary,cron)
    for relative in files+[migration]:check(sha(web/relative)==sha(stage/'.cms/source'/relative),'Deployed checksum '+relative)
    check(sha(cron)==sha(cron_source) and cron.stat().st_mode&0o777==0o644,'Root-owned Knowledge Base cron installed')
    run(['systemctl','reload','php8.5-fpm']);check(run(['systemctl','is-active','php8.5-fpm']).strip()==b'active','PHP-FPM reloaded with automated Core')
    run(['php8.5',str(stage/'scripts/publish-ai-product-pages.php'),str(web),'--apply',str(backup)]);publisher_applied=True
    reconcile=json.loads(run(['sudo','-u','sensecms','php8.5',str(web/'scripts/ai-knowledge-sync.php'),'--reconcile']));check(reconcile['failed']==0 and reconcile['ready']>0,'Public AI pages synchronized into the local RAG index')
    queue=json.loads(php(r'''$r=new App\Core\Runtime($argv[1]);$db=App\Core\Runtime::connect($r->read('installed')['database']);echo json_encode(['queued'=>(int)$db->query('SELECT COUNT(*) FROM ai_knowledge_sync_queue')->fetchColumn(),'failed'=>(int)$db->query("SELECT COUNT(*) FROM ai_knowledge_documents WHERE index_status='failed'")->fetchColumn(),'ready'=>(int)$db->query("SELECT COUNT(*) FROM ai_knowledge_documents WHERE index_status='ready'")->fetchColumn(),'chunks'=>(int)$db->query('SELECT COUNT(*) FROM ai_knowledge_chunks')->fetchColumn()],JSON_THROW_ON_ERROR);''',web,user='sensecms'));check(queue['failed']==0 and queue['ready']>=before['documents'] and queue['chunks']>=before['chunks'],'Knowledge index is healthy after the AI product-page publication')
    check(b'PASS Production Core Knowledge Base' in run(['python3',str(stage/'tests/ai-knowledge-ui-production.py')]),'Authenticated Knowledge Base acceptance')
    def get(path):
        with urllib.request.urlopen('https://www.sensecms.com'+path,timeout=30)as response:return response.status,response.read()
    status,page=get('/platform/ai');check(status==200 and b'AI content management, built into Sense CMS' in page and b'Provider-neutral AI in Core' not in page,'Dedicated public AI product page')
    check(b'rel="canonical" href="https://www.sensecms.com/platform/ai"' in page and b'CollectionPage' in page and b'AI Content Management, RAG &amp; Editorial Assistance' in page,'AI canonical, structured data and search title')
    check(b'Public visitor generation is not enabled' in page and b'Paid provider fine-tuning remains disabled' in page,'Public AI availability claims are truthful')
    for path,marker in [('/',b'AI that keeps editors in control'),('/platform',b'Provider-neutral AI in Core')]:status,body=get(path);check(status==200 and marker in body and b'/platform/ai' in body,'AI promotion on '+path)
    _,sitemap=get('/sitemap.xml');check(sitemap.count(b'https://www.sensecms.com/platform/ai</loc>')==1,'AI page has one canonical sitemap entry')
    after=state();check(after['provider_fingerprint']==before['provider_fingerprint'] and after['usage_rows']==before['usage_rows'] and after['training_jobs']==before['training_jobs'],'No provider configuration, AI usage or paid training job changed')
    run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t']);logs=run(['journalctl','--since','@'+str(started),'--no-pager','-u','nginx','-u','php8.5-fpm','-u','cron']).decode(errors='replace');check(not re.search(r'PHP Fatal|Uncaught|\bcrit\b|\bpanic\b',logs,re.I),'No fresh critical service errors')
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
