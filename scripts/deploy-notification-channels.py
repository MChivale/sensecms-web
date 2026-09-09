"""Narrow production overlay after private QA. Preserve application data and rollback files."""
import datetime
import fcntl
import hashlib
import os
from pathlib import Path
import pwd
import shutil
import subprocess

qa = Path('/root/sense-workspace-test.SC495Gg0')
web = Path('/home/sensecms.com/web')
files = ["app/Core/LicenseService.php","app/Core/TelegramConnectionClient.php","app/Core/NotificationChannels.php","app/Core/NotificationDispatcher.php","app/Http/TelegramConnectionController.php","app/Http/NotificationController.php","app/Views/console-notification-settings.php","app/Views/console.php","config/workspace.php","public/theme/sensecms-notifications.css","scripts/notifications.php"]
os.umask(0o077)
with Path('/root/sensecms-private/deploy-workspace.lock').open('a') as lock:
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    if hashlib.sha256((web/'app/Core/LicenseService.php').read_bytes()).hexdigest() != '40581afca05edf0af3a22ee9311fa405df3df0ab87f06fb26c4f17aa9eccaaba':
        raise RuntimeError('Unexpected production licensing baseline')
    nginx = Path('/etc/nginx/sites-available/sensecms.com')
    if hashlib.sha256(nginx.read_bytes()).hexdigest() != '12e5181581d49f871e92d66e84b6464ac75851aef418aa5c8c80efdc5240dd86':
        raise RuntimeError('Unexpected production Nginx baseline')
    for name in files:
        source = qa/'.cms/source'/name
        if name.endswith('.php'):
            subprocess.run(['php8.5', '-l', str(source)], check=True, stdout=subprocess.DEVNULL)
    backup = Path('/root/sensecms-backups')/(datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'-notification-channels')
    backup.mkdir(mode=0o700)
    subprocess.run(['tar','-czf',str(backup/'web-before.tgz'),'-C',str(web),'.'],check=True)
    with (backup/'database-before.sql').open('wb') as output:
        subprocess.run(['mariadb-dump','--single-transaction','--skip-lock-tables','--hex-blob','--no-tablespaces','sensecms_site'],stdout=output,check=True)
    if (backup/'database-before.sql').stat().st_size<1000:
        raise RuntimeError('Database backup incomplete')
    shutil.copy2(nginx,backup/'nginx-before.conf')
    owner=pwd.getpwnam('sensecms')
    pairs=[(qa/'.cms/source'/name,web/name) for name in files]+[(qa/'.src/TelegramWebhook.php',web/'website/TelegramWebhook.php')]
    for source,target in pairs:
        if target.is_symlink():
            raise RuntimeError('Unexpected symlink target')
        target.parent.mkdir(parents=True,exist_ok=True)
        temp=target.with_name(target.name+'.channel-deploy')
        if temp.exists():
            raise RuntimeError('Unexpected pending deployment file')
        temp.write_bytes(source.read_bytes())
        os.chown(temp,owner.pw_uid,owner.pw_gid)
        os.chmod(temp,0o644 if '/public/' in str(target) else 0o640)
        os.replace(temp,target)
    shutil.copyfile(qa/'deploy/nginx/sensecms.com.conf',nginx)
    os.chmod(nginx,0o644)
    try:
        subprocess.run(['nginx','-t'],check=True)
    except subprocess.CalledProcessError:
        shutil.copyfile(backup/'nginx-before.conf',nginx)
        raise
    subprocess.run(['systemctl','reload','nginx'],check=True)
    for source,target in pairs:
        if hashlib.sha256(source.read_bytes()).digest()!=hashlib.sha256(target.read_bytes()).digest():
            raise RuntimeError('Deployed file mismatch')
    print('DEPLOYED '+str(backup),flush=True)
