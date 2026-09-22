#!/usr/bin/env python3
"""Deploy the Core RAG visitor assistant and prepare its local training package."""
from __future__ import annotations

import datetime,fcntl,hashlib,json,os,re,shutil,subprocess,urllib.request
from pathlib import Path

if os.name=='nt' or os.geteuid()!=0 or len(os.sys.argv)!=2:raise SystemExit('Run as root on Linux: deploy-visitor-ai-20260922.py <private-stage>')
os.umask(0o077);stage=Path(os.sys.argv[1]).resolve();web=Path('/home/sensecms.com/web');cron=Path('/etc/cron.d/sensecms-ai-knowledge')
files=['app/Core/AiChatService.php','app/Core/AiRepository.php','app/Core/LiveChatSettings.php','app/Http/DashboardController.php','app/Views/console-live-chat.php','app/Views/public-chat.php','app/workspace.php','public/assets/public-chat.js','public/assets/public-chat.css']
baseline={'app/Core/AiChatService.php':'7a04621bf1ea9d5d6514e319c3e9a3f26f20e0e6f0a48cfdc86ac468c7b24031','app/Core/AiRepository.php':'b51cddc13a530959291d89ffa1c73599f594432eaebfb0f1430b7149b7787023','app/Core/LiveChatSettings.php':'edfcc7a0f5563195c23f311a15f7fbddb4cbcf3e1d629db086f426f8aec4bdf9','app/Http/DashboardController.php':'cf68b56267d04524c56934008670114a89a2e23831aadee5adb611c08d12a813','app/Views/console-live-chat.php':'78aa0f543c53142a2204fbe8d74ed45228ba0f5e06b4390e67c86447082a36ac','app/Views/public-chat.php':'2920bfd3e1d2321cf645c62c766c9f4a10b4c347e511f3ad1bfc6e941a2ce941','app/workspace.php':'88c6bf50299348200904f2cb328e82550b60280818db12c6cbba1f0a5bd5fe3c','public/assets/public-chat.js':'532e757e0df40a3998fbe9770877fa2f90a332dbfcb5119741b9bf2511cb34fc','public/assets/public-chat.css':'93fc4cdc9e81fe36b8d9ba57e6477a8000a0b40f34f2b7177f655f8944740ebd'}
receipt={'status':'preflight','checks':[]};backup=None;deployed=[];metadata={};cron_previous=None;training=None;page_applied=False;state_before=None;started=int(datetime.datetime.now(datetime.timezone.utc).timestamp())
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
    return json.loads(php(r'''$r=new App\Core\Runtime($argv[1]);$db=App\Core\Runtime::connect($r->read('installed')['database']);$provider=$db->query("SELECT id,options,updated_at FROM ai_providers WHERE enabled=1 AND api_key_encrypted IS NOT NULL AND api_key_encrypted<>'' ORDER BY priority,id LIMIT 1")->fetch(PDO::FETCH_ASSOC);$q=$db->prepare('SELECT value FROM settings WHERE `key`=?');$q->execute(['live_chat_settings']);$setting=$q->fetchColumn();echo json_encode(['provider'=>$provider,'setting_exists'=>$setting!==false,'setting_raw'=>$setting===false?null:$setting,'usage_rows'=>(int)$db->query('SELECT COUNT(*) FROM ai_usage_events')->fetchColumn(),'datasets'=>(int)$db->query('SELECT COUNT(*) FROM ai_training_datasets')->fetchColumn(),'examples'=>(int)$db->query('SELECT COUNT(*) FROM ai_training_examples')->fetchColumn(),'jobs'=>(int)$db->query('SELECT COUNT(*) FROM ai_training_jobs')->fetchColumn(),'ready'=>(int)$db->query("SELECT COUNT(*) FROM ai_knowledge_documents WHERE index_status='ready'")->fetchColumn(),'chunks'=>(int)$db->query('SELECT COUNT(*) FROM ai_knowledge_chunks')->fetchColumn()],JSON_THROW_ON_ERROR);''',web,user='sensecms'))
def deploy(relative):
    source,target=stage/'.cms/source'/relative,web/relative
    if not source.is_file() or source.is_symlink():raise RuntimeError('Invalid deployment source: '+relative)
    if not target.is_file() or target.is_symlink():raise RuntimeError('Unsafe or missing deployment target: '+relative)
    stat=target.stat();metadata[relative]=(stat.st_uid,stat.st_gid,stat.st_mode&0o777);previous=backup/'core'/relative;previous.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(target,previous);temporary=target.with_name(target.name+'.deploy-'+token);shutil.copy2(source,temporary);os.chown(temporary,stat.st_uid,stat.st_gid);os.chmod(temporary,stat.st_mode&0o777);os.replace(temporary,target);deployed.append(relative)
def cleanup_training():
    if not training:return
    job,dataset=int(training['job_id']),int(training['dataset_id'])
    info=json.loads(php(r'''$r=new App\Core\Runtime($argv[1]);$db=App\Core\Runtime::connect($r->read('installed')['database']);$q=$db->prepare('SELECT training_file_path,dataset_checksum FROM ai_training_jobs WHERE id=? AND dataset_id=?');$q->execute([(int)$argv[2],(int)$argv[3]]);echo json_encode($q->fetch(PDO::FETCH_ASSOC)?:[],JSON_THROW_ON_ERROR);''',web,job,dataset))
    path=str(info.get('training_file_path',''));checksum=str(info.get('dataset_checksum',''))
    php(r'''$r=new App\Core\Runtime($argv[1]);$db=App\Core\Runtime::connect($r->read('installed')['database']);$db->beginTransaction();try{$db->prepare('DELETE FROM ai_training_jobs WHERE id=? AND dataset_id=?')->execute([(int)$argv[2],(int)$argv[3]]);$db->prepare('DELETE FROM ai_training_examples WHERE dataset_id=?')->execute([(int)$argv[3]]);$db->prepare('DELETE FROM ai_training_datasets WHERE id=?')->execute([(int)$argv[3]]);$db->commit();}catch(Throwable$e){$db->rollBack();throw$e;}''',web,job,dataset)
    for target in [web/path if path.startswith('storage/ai-training/') else None,web/'storage/ai-training'/('visitor-assistant-'+checksum+'.json') if re.fullmatch(r'[a-f0-9]{64}',checksum) else None]:
        if target and target.is_file() and not target.is_symlink():target.unlink()
def restore():
    global page_applied
    if page_applied and (backup/'ai-visitor-product-page.json').is_file():run(['php8.5',str(stage/'scripts/update-ai-visitor-product-page.php'),str(web),'--rollback',str(backup)]);page_applied=False
    cleanup_training()
    if state_before:
        php(r'''$r=new App\Core\Runtime($argv[1]);$db=App\Core\Runtime::connect($r->read('installed')['database']);$db->prepare('UPDATE ai_providers SET options=?,updated_at=? WHERE id=?')->execute([$argv[2],$argv[3],(int)$argv[4]]);if($argv[5]==='1')$db->prepare('INSERT INTO settings (`key`,value) VALUES ("live_chat_settings",?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([$argv[6]]);else$db->exec('DELETE FROM settings WHERE `key`="live_chat_settings"');''',web,state_before['provider']['options'],state_before['provider']['updated_at'],state_before['provider']['id'],'1' if state_before['setting_exists'] else '0',state_before['setting_raw'] or '')
    for relative in reversed(deployed):
        target,previous=web/relative,backup/'core'/relative
        shutil.copy2(previous,target);uid,gid,mode=metadata[relative];os.chown(target,uid,gid);os.chmod(target,mode)
    if cron_previous is not None:cron.write_bytes(cron_previous);os.chown(cron,0,0);os.chmod(cron,0o644)
    run(['systemctl','reload','php8.5-fpm'])
    try:run(['sudo','-u','sensecms','php8.5',str(web/'scripts/ai-knowledge-sync.php'),'--reconcile'])
    except BaseException:pass
required=[stage/'.cms/source'/p for p in files]+[stage/'deploy/cron/sensecms-ai-knowledge',stage/'scripts/prepare-visitor-assistant-training.php',stage/'scripts/update-ai-visitor-product-page.php',stage/'tests/visitor-ai.php',stage/'tests/ai-knowledge.php']
check(stage.is_dir() and str(stage).startswith('/root/sense-visitor-ai-'),'Private scoped deployment stage');check(all(p.is_file() and not p.is_symlink() for p in required),'Complete visitor AI deployment payload');check(all((web/p).is_file() and sha(web/p)==expected for p,expected in baseline.items()),'Expected production Core baseline');check(cron.is_file() and not cron.is_symlink() and sha(cron)=='cb518e1eb8588102143cbbe695a452f8bb16cbd040a9963ae4b27471cce3f8c3','Expected production Knowledge Base schedule')
for path in [stage/'.cms/source'/p for p in files if p.endswith('.php')]+[stage/'scripts/prepare-visitor-assistant-training.php',stage/'scripts/update-ai-visitor-product-page.php',stage/'tests/visitor-ai.php']:run(['php8.5','-l',str(path)])
check(b'Visitor AI checks passed: 10' in run(['php8.5',str(stage/'tests/visitor-ai.php')]),'Core visitor AI architecture and safety checks');check(b'AI Knowledge Base checks passed: 38' in run(['php8.5',str(stage/'tests/ai-knowledge.php')]),'Knowledge Base regression checks');run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t']);state_before=state();check(state_before['provider'] and state_before['datasets']==0 and state_before['examples']==0 and state_before['jobs']==0,'Reviewed provider and empty training baseline')
lock=Path('/root/sensecms-private/deploy-workspace.lock').open('a+b');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
try:
    stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');token=hashlib.sha256(stamp.encode()).hexdigest()[:12];backup=Path('/root/sensecms-backups')/(stamp+'-visitor-ai');backup.mkdir(mode=0o700);receipt['backup']=str(backup)
    with (backup/'database-before.sql').open('wb')as output:
        result=subprocess.run(['mysqldump','--single-transaction','--routines','--triggers','sensecms_site'],stdout=output,stderr=subprocess.PIPE)
        if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace').strip() or 'Database backup failed.')
    os.chmod(backup/'database-before.sql',0o600);check((backup/'database-before.sql').stat().st_size>4096,'Private production database recovery dump')
    for relative in files:deploy(relative)
    cron_previous=cron.read_bytes();temporary=cron.with_name(cron.name+'.deploy-'+token);shutil.copy2(stage/'deploy/cron/sensecms-ai-knowledge',temporary);os.chown(temporary,0,0);os.chmod(temporary,0o644);os.replace(temporary,cron)
    php(r'''$r=new App\Core\Runtime($argv[1]);$db=App\Core\Runtime::connect($r->read('installed')['database']);$q=$db->prepare('SELECT options FROM ai_providers WHERE id=?');$q->execute([(int)$argv[2]]);$options=json_decode((string)$q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);$purposes=array_values(array_unique(array_merge((array)($options['purposes']??[]),['chat'])));$options['purposes']=$purposes;$db->prepare('UPDATE ai_providers SET options=?,updated_at=NOW() WHERE id=?')->execute([json_encode($options,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),(int)$argv[2]]);$cms=new App\Core\CmsRepository($db,new App\Core\EventBus(),$r);$settings=App\Core\LiveChatSettings::from($cms->setting('live_chat_settings',[]));$settings['assistant']=['enabled'=>true,'daily_requests'=>25,'max_output_tokens'=>300];$cms->saveSetting('live_chat_settings',$settings);''',web,state_before['provider']['id'],user='sensecms')
    training=json.loads(run(['php8.5',str(stage/'scripts/prepare-visitor-assistant-training.php'),str(web)]));check(training['ok'] and training['examples']==12 and not training['provider_request_sent'],'Private 12-example JSONL package prepared without provider request')
    info=json.loads(php(r'''$r=new App\Core\Runtime($argv[1]);$db=App\Core\Runtime::connect($r->read('installed')['database']);$q=$db->prepare('SELECT training_file_path,dataset_checksum,status FROM ai_training_jobs WHERE id=?');$q->execute([(int)$argv[2]]);echo json_encode($q->fetch(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);''',web,training['job_id']))
    training_dir=web/'storage/ai-training';storage_owner=(web/'storage').stat();os.chown(training_dir,storage_owner.st_uid,storage_owner.st_gid);os.chmod(training_dir,0o700)
    for target in [web/info['training_file_path'],training_dir/training['manifest']]:os.chown(target,storage_owner.st_uid,storage_owner.st_gid);os.chmod(target,0o640)
    page_applied=True;run(['php8.5',str(stage/'scripts/update-ai-visitor-product-page.php'),str(web),'--apply',str(backup)]);reconcile=json.loads(run(['sudo','-u','sensecms','php8.5',str(web/'scripts/ai-knowledge-sync.php'),'--reconcile']));check(reconcile['failed']==0,'Updated AI page synchronized into RAG')
    run(['systemctl','reload','php8.5-fpm']);check(run(['systemctl','is-active','php8.5-fpm']).strip()==b'active','PHP-FPM reloaded with visitor assistant')
    for relative in files:check(sha(web/relative)==sha(stage/'.cms/source'/relative),'Deployed checksum '+relative)
    check(sha(cron)==sha(stage/'deploy/cron/sensecms-ai-knowledge') and b'0 5 * * * sensecms' in cron.read_bytes(),'Nightly full reconciliation scheduled at 05:00')
    after=state();options=json.loads(after['provider']['options']);settings=json.loads(after['setting_raw']);check('chat' in options.get('purposes',[]) and settings.get('assistant',{}).get('enabled') is True,'Chat purpose and RAG visitor assistant enabled');check(after['datasets']==1 and after['examples']==12 and after['jobs']==1 and after['usage_rows']==state_before['usage_rows'],'Local training records prepared with zero provider usage');check(info['status']=='prepared' and sha(web/info['training_file_path'])==training['checksum'],'Private JSONL package checksum verified');manifest=json.loads((web/'storage/ai-training'/training['manifest']).read_text());check(manifest['provider_request_sent'] is False and manifest['estimated_provider_cost_usd'] is None,'Private JSON manifest confirms no provider submission or cost')
    def get(path):
        with urllib.request.urlopen('https://www.sensecms.com'+path,timeout=30)as response:return response.status,response.read(),response.headers
    status,home,_=get('/');check(status==200 and b'data-endpoint="/api/ai/chat"' in home and b'20260922-ai-1' in home,'Public site serves the enabled Core AI widget');status,page,_=get('/platform/ai');check(status==200 and b'RAG visitor assistant with human handoff' in page and b'Public visitor generation is not enabled' not in page,'Public AI product information reflects current availability');status,js,headers=get('/assets/public-chat.js?v=20260922-ai-1');check(status==200 and b'widget.dataset.endpoint' in js and 'javascript' in headers.get_content_type(),'Visitor assistant JavaScript is healthy');status,css,headers=get('/assets/public-chat.css?v=20260922-ai-1');check(status==200 and b'.sense-chat-source' in css and 'css' in headers.get_content_type(),'Visitor assistant source-link styling is healthy')
    run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t']);logs=run(['journalctl','--since','@'+str(started),'--no-pager','-u','nginx','-u','php8.5-fpm','-u','cron']).decode(errors='replace');check(not re.search(r'PHP Fatal|Uncaught|\bcrit\b|\bpanic\b',logs,re.I),'No fresh critical service errors');receipt['status']='deployed';receipt['training']={k:training[k] for k in ['dataset_id','job_id','examples','estimated_tokens','checksum','manifest']}
except BaseException:
    errors=[]
    if backup:
        try:restore()
        except BaseException as error:errors.append('recovery:'+type(error).__name__)
    receipt['status']='rollback-needs-attention' if errors else ('rolled-back' if backup else 'preflight-failed')
    if errors:receipt['recovery_errors']=errors
    raise
finally:
    if backup:(backup/'receipt.json').write_text(json.dumps(receipt,indent=2,sort_keys=True)+'\n');os.chmod(backup/'receipt.json',0o600)
    fcntl.flock(lock,fcntl.LOCK_UN);lock.close()
print(json.dumps(receipt,sort_keys=True),flush=True)
