#!/usr/bin/env python3
"""Deploy real-time Live Chat presence, AI takeover and the refreshed Core widget."""
from __future__ import annotations

import datetime,fcntl,hashlib,json,os,re,shutil,subprocess,urllib.request
from pathlib import Path

if os.name=='nt' or os.geteuid()!=0 or len(os.sys.argv)!=2:raise SystemExit('Run as root on Linux: deploy-live-chat-realtime-20260922.py <private-stage>')
os.umask(0o077);stage=Path(os.sys.argv[1]).resolve();web=Path('/home/sensecms.com/web');started=int(datetime.datetime.now(datetime.timezone.utc).timestamp())
files=['app/Core/AiChatService.php','app/Core/AiRepository.php','app/Core/LiveChatSettings.php','app/Core/NotificationAudience.php','app/Core/NotificationDispatcher.php','app/Http/AiChatController.php','app/Http/DashboardController.php','app/Http/LiveChatController.php','app/Views/console-live-chat.php','app/Views/public-chat.php','app/workspace.php','public/assets/public-chat.css','public/assets/public-chat.js','public/theme/sensecms-live-chat.js']
migrations=['database/workspace/042_live_chat_presence_takeover.sql','database/workspace/043_live_chat_callback_email.sql'];backup=None;deployed=[];metadata={};migration_applied=False;receipt={'status':'preflight','checks':[]}
def run(command,*,user=None):
    if user:command=['sudo','-u',user,*command]
    result=subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
    if result.returncode:raise RuntimeError(result.stdout.decode(errors='replace').strip() or 'Command failed: '+command[0])
    return result.stdout
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def check(ok,label):
    if not ok:raise RuntimeError(label)
    receipt['checks'].append(label);print('PASS '+label,flush=True)
def deploy(relative):
    source,target=stage/'.cms/source'/relative,web/relative
    if not source.is_file() or source.is_symlink():raise RuntimeError('Invalid deployment source: '+relative)
    if target.exists() and (not target.is_file() or target.is_symlink()):raise RuntimeError('Unsafe deployment target: '+relative)
    target.parent.mkdir(parents=True,exist_ok=True)
    if target.exists():
        stat=target.stat();metadata[relative]=(stat.st_uid,stat.st_gid,stat.st_mode&0o777);previous=backup/'core'/relative;previous.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(target,previous)
    else:metadata[relative]=None
    temporary=target.with_name(target.name+'.deploy-'+token);shutil.copy2(source,temporary)
    owner=(web/'app').stat();os.chown(temporary,owner.st_uid,owner.st_gid);os.chmod(temporary,0o644);os.replace(temporary,target);deployed.append(relative)
def restore():
    for relative in reversed(deployed):
        if migration_applied and relative in migrations:continue
        target,previous=web/relative,backup/'core'/relative;meta=metadata[relative]
        if meta is None:
            if target.exists():target.unlink()
        else:
            shutil.copy2(previous,target);os.chown(target,meta[0],meta[1]);os.chmod(target,meta[2])
    run(['systemctl','reload','php8.5-fpm'])
required=[stage/'.cms/source'/p for p in files+migrations]+[stage/'tests/visitor-ai.php',stage/'tests/public-chat-production.py',stage/'tests/demo-access-http.py',stage/'deploy/cron/sensecms-ai-knowledge',stage/'scripts/prepare-visitor-assistant-training.php']
check(stage.is_dir() and str(stage).startswith('/root/sense-live-chat-realtime-'),'Private scoped deployment stage')
check(all(path.is_file() and not path.is_symlink() for path in required),'Complete Live Chat deployment payload')
installed=json.loads((web/'storage/installed.json').read_text());check(installed.get('base_url')=='https://www.sensecms.com','Official production installation identity')
for path in [stage/'.cms/source'/p for p in files if p.endswith('.php')]:run(['php8.5','-l',str(path)])
check(b'Visitor AI checks passed: 17' in run(['php8.5',str(stage/'tests/visitor-ai.php')]),'Core visitor AI and takeover architecture checks')
run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t'])
lock=Path('/root/sensecms-private/deploy-workspace.lock').open('a+b');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
try:
    stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');token=hashlib.sha256(stamp.encode()).hexdigest()[:12];backup=Path('/root/sensecms-backups')/(stamp+'-live-chat-realtime');backup.mkdir(mode=0o700);receipt['backup']=str(backup)
    with (backup/'database-before.sql').open('wb')as output:
        result=subprocess.run(['mysqldump','--single-transaction','--routines','--triggers','sensecms_site'],stdout=output,stderr=subprocess.PIPE)
        if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace').strip() or 'Database backup failed.')
    os.chmod(backup/'database-before.sql',0o600);check((backup/'database-before.sql').stat().st_size>4096,'Private production database recovery dump')
    for relative in files+migrations:deploy(relative)
    applied=run(['sudo','-u','sensecms','php8.5',str(web/'scripts/migrate-workspace.php')]).decode().strip();migration_applied=True;check(re.search(r'Applied Workspace migrations: [0-2]\b',applied) is not None,'Additive Live Chat migrations applied or already current')
    run(['systemctl','reload','php8.5-fpm']);check(run(['systemctl','is-active','php8.5-fpm']).strip()==b'active','PHP-FPM reloaded')
    for relative in files+migrations:check(sha(web/relative)==sha(stage/'.cms/source'/relative),'Deployed checksum '+relative)
    qa=run(['php8.5','-r',r'''require $argv[1].'/bootstrap.php';$root=$argv[1];$runtime=new App\Core\Runtime($root);$db=App\Core\Runtime::connect($runtime->read('installed')['database']);$tables=$db->query("SHOW TABLES LIKE 'live_chat_operator_presence'")->fetchColumn();$columns=$db->query("SHOW COLUMNS FROM ai_conversations WHERE Field IN ('queued_at','ai_takeover_at','email_requested_at')")->fetchAll();if(!$tables||count($columns)!==3)throw new RuntimeException('schema');$repo=new App\Core\AiRepository($db);$user=(int)$db->query('SELECT id FROM users WHERE active=1 ORDER BY id LIMIT 1')->fetchColumn();$old=$db->prepare('SELECT last_seen_at FROM live_chat_operator_presence WHERE user_id=?');$old->execute([$user]);$previous=$old->fetchColumn();$repo->touchOperatorPresence($user);if($repo->onlineOperatorCount()<1)throw new RuntimeException('presence');$settings=App\Core\LiveChatSettings::from((new App\Core\CmsRepository($db,new App\Core\EventBus(),$runtime))->setting('live_chat_settings',[]));if(!in_array($settings['assistant']['first_responder'],['ai','human'],true)||$settings['assistant']['takeover_seconds']<5||$settings['assistant']['takeover_seconds']>600)throw new RuntimeException('setting');if($previous===false)$db->prepare('DELETE FROM live_chat_operator_presence WHERE user_id=?')->execute([$user]);else$db->prepare('UPDATE live_chat_operator_presence SET last_seen_at=? WHERE user_id=?')->execute([$previous,$user]);echo 'routing-callback-ok';''',str(web)],user='sensecms')
    check(b'routing-callback-ok' in qa,'Operator presence, routing preference and callback schema verified')
    run(['python3',str(stage/'tests/public-chat-production.py'),'smoke']);check(True,'Production widget, AI endpoint and versioned assets verified over HTTPS')
    run(['systemctl','is-active','nginx','php8.5-fpm','mariadb','cron']);run(['nginx','-t']);logs=run(['journalctl','--since','@'+str(started),'--no-pager','-u','nginx','-u','php8.5-fpm']).decode(errors='replace');check(not re.search(r'PHP Fatal|Uncaught|\bcrit\b|\bpanic\b',logs,re.I),'No fresh critical service errors')
    receipt['status']='deployed';receipt['migrations']=migrations
except BaseException:
    errors=[]
    if backup:
        try:restore()
        except BaseException as error:errors.append('recovery:'+type(error).__name__)
    receipt['status']='rollback-needs-attention' if migration_applied or errors else ('rolled-back' if backup else 'preflight-failed')
    if errors:receipt['recovery_errors']=errors
    raise
finally:
    if backup:(backup/'receipt.json').write_text(json.dumps(receipt,indent=2,sort_keys=True)+'\n');os.chmod(backup/'receipt.json',0o600)
    fcntl.flock(lock,fcntl.LOCK_UN);lock.close()
print(json.dumps(receipt,sort_keys=True),flush=True)
