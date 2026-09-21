#!/usr/bin/env python3
"""Deploy provider-neutral Core Page Builder AI with guarded rollback."""
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
import urllib.error
import urllib.request

if os.name == 'nt' or os.geteuid() != 0 or len(sys.argv) != 2:
    raise SystemExit('Run as root on Linux: deploy-page-builder-ai-20260921.py <private-stage>')

os.umask(0o077)
stage = Path(sys.argv[1]).resolve()
web = Path('/home/sensecms.com/web')
files = [
    'app/Core/AccessControl.php', 'app/Core/AiChatService.php', 'app/Core/AiRepository.php',
    'app/Core/AiProviderClient.php', 'app/Core/AiContentService.php', 'app/Core/PageBuilder.php',
    'app/Http/DashboardController.php', 'app/Views/console-page-builder.php',
    'app/Views/console.php', 'app/workspace.php',
    'public/theme/sensecms-content-management.css', 'public/theme/sensecms-content-management.js',
    'public/theme/sensecms-page-builder.css', 'public/theme/sensecms-page-builder.js',
]
migration = 'database/workspace/037_page_builder_ai.sql'
baseline = {
    'app/Core/AccessControl.php': '28cc6a8bcae7d4604dc3727935ad98a8151ba0a4cc1a3df8b6c6f7b717ffcee4',
    'app/Core/AiChatService.php': '9225d2443462b2f680a2592ff8b3b89cab33684627f1f3626d7eb320da0d5822',
    'app/Core/AiRepository.php': 'cab3c25598647b6c135258ada6c2196d2d3b13fdc175efe9ca307acda7b7a903',
    'app/Core/PageBuilder.php': '9e1c819fd986cf12a0a49399f137ba3083a662d76f2fd861df052e5c6f900c2f',
    'app/Http/DashboardController.php': '462f5aefd46cc36d42973b4c82c0caea2a866bf9a686d5b58b227caff72a6d6f',
    'app/Views/console-page-builder.php': 'bc51cc58f088eaffc6759c1086e8897e866daf5e516d0bc5921221ad7eea1e5e',
    'app/Views/console.php': 'b145336c2f025a3dc7e9f71869a40e1c811462669ab3fe3975c98dcbc4e5330e',
    'app/workspace.php': 'cfc08f1a0df445f01ab220e5cd082f5cafa0c853ab1ded88fd43a5d976cae95e',
    'public/theme/sensecms-content-management.css': '23c301936c81eab3a52655876b821659a21d20577fdea471480723b69a4b23a7',
    'public/theme/sensecms-content-management.js': '11ba5191f2a4afbecab5cc24fb1c8ea051963bc57c5dd75db1f017517c4c9335',
    'public/theme/sensecms-page-builder.css': '0e47e20aa5508c3ba9895a3929178c7c2af48cb9ff57b35520a05cb1ebeba080',
    'public/theme/sensecms-page-builder.js': '30b2aa26d6748a8dea834551094c876ffce2dee80f02d4b41790a7ed1c61eef3',
}
receipt = {'status': 'preflight', 'checks': []}
backup = None
deployed: list[str] = []
metadata: dict[str, tuple[int, int, int]] = {}
started = int(datetime.datetime.now(datetime.timezone.utc).timestamp())


def run(command, *, user=None):
    if user:
        command = ['sudo', '-u', user, *command]
    result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if result.returncode:
        raise RuntimeError(result.stdout.decode(errors='replace').strip() or f'Command failed: {command[0]}')
    return result.stdout


def php(code, *args, user=None):
    return run(['php8.5', '-r', f'require {json.dumps(str(web / "bootstrap.php"))};' + code, *map(str, args)], user=user)


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def check(ok, label):
    if not ok:
        raise RuntimeError(label)
    receipt['checks'].append(label)
    print('PASS ' + label, flush=True)


def runtime_state():
    return json.loads(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$providers=$db->query('SELECT id,slug,name,driver,base_url,default_model,api_key_encrypted,options,enabled,created_at,updated_at FROM ai_providers ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);echo json_encode(['provider_count'=>count($providers),'provider_fingerprint'=>hash('sha256',json_encode($providers,JSON_UNESCAPED_SLASHES)),'migrations'=>(new App\Installer\WorkspaceMigration($db,$r))->status()],JSON_THROW_ON_ERROR);''', web, user='sensecms'))


def active_theme():
    return json.loads(php(r'''$r=new App\Core\Runtime($argv[1]);echo json_encode((new App\Core\Packages\ThemeManager($r))->active(),JSON_THROW_ON_ERROR);''', web))


def deploy(relative):
    source, target = stage / '.cms/source' / relative, web / relative
    if not source.is_file() or source.is_symlink():
        raise RuntimeError('Invalid deployment source: ' + relative)
    target.parent.mkdir(parents=True, exist_ok=True)
    if target.exists():
        if target.is_symlink() or not target.is_file():
            raise RuntimeError('Unsafe deployment target: ' + relative)
        stat = target.stat()
        metadata[relative] = (stat.st_uid, stat.st_gid, stat.st_mode & 0o777)
        previous = backup / 'core' / relative
        previous.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(target, previous)
    else:
        parent = target.parent.stat()
        metadata[relative] = (parent.st_uid, parent.st_gid, 0o644)
    uid, gid, mode = metadata[relative]
    temporary = target.with_name(target.name + '.deploy-' + token)
    shutil.copy2(source, temporary)
    os.chown(temporary, uid, gid)
    os.chmod(temporary, mode)
    os.replace(temporary, target)
    deployed.append(relative)


def restore():
    for relative in reversed(deployed):
        if relative == migration:
            continue
        target, previous = web / relative, backup / 'core' / relative
        if previous.is_file():
            shutil.copy2(previous, target)
            uid, gid, mode = metadata[relative]
            os.chown(target, uid, gid)
            os.chmod(target, mode)
        elif target.is_file() and not target.is_symlink():
            target.unlink()


required = [
    web / 'bootstrap.php', stage / '.cms/source/bootstrap.php',
    stage / 'tests/builder-defaults.php', stage / 'tests/page-builder-ai.php',
    stage / 'tests/security-regressions.php', stage / 'tests/maintenance-regressions.php',
    stage / 'tests/page-builder-ui-production.py',
    *(stage / '.cms/source' / relative for relative in files + [migration]),
]
check(stage.is_dir() and str(stage).startswith('/root/sense-builder-ai-'), 'Private scoped deployment stage')
check(all(path.is_file() and not path.is_symlink() for path in required), 'Complete Core AI deployment payload')
check(all((web / relative).is_file() and sha(web / relative) == expected for relative, expected in baseline.items()), 'Expected production Page Builder baseline')
for relative in set(files) - set(baseline):
    check(not (web / relative).exists(), 'New Core file is absent before deployment ' + relative)
check(not (web / migration).exists(), 'AI migration is cleanly pending')
theme_before = active_theme()
check(theme_before['slug'] == 'sensecms', 'Pinned signed Sense CMS theme remains active')
before = runtime_state()
check(before['migrations']['ready'] and not before['migrations']['pending'] and not before['migrations']['missing_tables'], 'Existing Workspace schema is healthy')
for path in [stage / '.cms/source' / relative for relative in files if relative.endswith('.php')]:
    run(['php8.5', '-l', str(path)])
node = shutil.which('node')
if node:
    for relative in [path for path in files if path.endswith('.js')]:
        run([node, '--check', str(stage / '.cms/source' / relative)])
    check(True, 'Staged JavaScript syntax')
check(b'282 builder preset/compatibility checks passed' in run(['php8.5', str(stage / 'tests/builder-defaults.php')]), 'Builder contract regressions')
check(b'16 Page Builder AI checks passed' in run(['php8.5', str(stage / 'tests/page-builder-ai.php')]), 'Provider-neutral AI regressions without network calls')
if b'pdo_sqlite' in run(['php8.5', '-m']):
    check(b'92 security regression checks passed' in run(['php8.5', str(stage / 'tests/security-regressions.php')]), 'Security regressions')
    check(b'16 maintenance checks passed' in run(['php8.5', str(stage / 'tests/maintenance-regressions.php')]), 'Maintenance regressions')
else:
    print('SKIP SQLite-dependent security and maintenance regressions (pdo_sqlite unavailable)')
run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb', 'cron'])
run(['nginx', '-t'])

lock = Path('/root/sensecms-private/deploy-workspace.lock').open('a+b')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
try:
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    token = hashlib.sha256(stamp.encode()).hexdigest()[:12]
    backup = Path('/root/sensecms-backups') / (stamp + '-page-builder-ai')
    backup.mkdir(mode=0o700)
    receipt['backup'] = str(backup)
    with (backup / 'database-before.sql').open('wb') as output:
        result = subprocess.run(['mysqldump', '--single-transaction', '--routines', '--triggers', 'sensecms_site'], stdout=output, stderr=subprocess.PIPE)
        if result.returncode:
            raise RuntimeError(result.stderr.decode(errors='replace').strip() or 'Database backup failed.')
    os.chmod(backup / 'database-before.sql', 0o600)
    check((backup / 'database-before.sql').stat().st_size > 4096, 'Private production database recovery dump')
    deploy(migration)
    applied = int(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);echo(new App\Installer\WorkspaceMigration($db,$r))->apply();''', web, user='sensecms'))
    check(applied == 1, 'AI migration 037 applied exactly once')
    schema = json.loads(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$columns=[];foreach(['priority','verified_at','last_error']as$c){$q=$db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="ai_providers" AND column_name=?');$q->execute([$c]);$columns[$c]=(bool)$q->fetchColumn();}$table=(bool)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name="page_builder_ai_runs"')->fetchColumn();$permission=(int)$db->query("SELECT COUNT(*) FROM permissions WHERE slug='content.pages.ai'")->fetchColumn();$grants=(int)$db->query("SELECT COUNT(DISTINCT r.slug) FROM roles r INNER JOIN role_permissions rp ON rp.role_id=r.id INNER JOIN permissions p ON p.id=rp.permission_id WHERE p.slug='content.pages.ai' AND r.slug IN ('owner','administrator','content-manager','editor')")->fetchColumn();$runs=$table?(int)$db->query('SELECT COUNT(*) FROM page_builder_ai_runs')->fetchColumn():-1;echo json_encode(['columns'=>$columns,'table'=>$table,'permission'=>$permission,'grants'=>$grants,'runs'=>$runs,'status'=>(new App\Installer\WorkspaceMigration($db,$r))->status()],JSON_THROW_ON_ERROR);''', web, user='sensecms'))
    check(all(schema['columns'].values()) and schema['table'] and schema['permission'] == 1 and schema['grants'] == 4 and schema['runs'] == 0 and schema['status']['ready'], 'AI schema and least-privilege grants are ready without generated content')
    migrated = runtime_state()
    check(migrated['provider_count'] == before['provider_count'] and migrated['provider_fingerprint'] == before['provider_fingerprint'], 'Existing encrypted AI provider data is unchanged')
    for relative in files:
        deploy(relative)
    for relative in files + [migration]:
        check(sha(web / relative) == sha(stage / '.cms/source' / relative), 'Deployed checksum ' + relative)
    time.sleep(3)
    check(b'PASS Production Core Page Builder AI' in run(['python3', str(stage / 'tests/page-builder-ui-production.py')]), 'Authenticated production AI and Builder acceptance')
    for route in ['/content/builder', '/ai', '/theme/sensecms-page-builder.css?v=20260921-ai-1', '/theme/sensecms-page-builder.js?v=20260921-ai-1']:
        try:
            with urllib.request.urlopen('https://www.sensecms.com' + route, timeout=30) as response:
                check(response.status in (200, 302) and response.read(), 'HTTP response ' + route)
        except urllib.error.HTTPError as error:
            check(error.code in (302, 401, 403), 'Protected HTTP response ' + route)
    after = runtime_state()
    check(after['provider_count'] == before['provider_count'] and after['provider_fingerprint'] == before['provider_fingerprint'], 'Deployment and acceptance preserved all configured providers')
    run_count = int(php(r'''$r=new App\Core\Runtime($argv[1]);$db=App\Core\Runtime::connect($r->read('installed')['database']);echo$db->query('SELECT COUNT(*) FROM page_builder_ai_runs')->fetchColumn();''', web, user='sensecms'))
    check(run_count == 0, 'Acceptance made no external AI request')
    check(active_theme() == theme_before, 'Active signed theme was not changed')
    run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb', 'cron'])
    run(['nginx', '-t'])
    logs = run(['journalctl', '--since', '@' + str(started), '--no-pager', '-u', 'nginx', '-u', 'php8.5-fpm']).decode(errors='replace')
    check(not re.search(r'PHP Fatal|Uncaught|\bcrit\b|\bpanic\b', logs, re.I), 'No fresh critical service errors')
    receipt['status'] = 'deployed'
except BaseException:
    errors = []
    if backup:
        try:
            restore()
        except BaseException as error:
            errors.append('recovery:' + type(error).__name__)
    receipt['status'] = 'rollback-needs-attention' if errors else ('rolled-back-with-additive-migration-retained' if backup else 'preflight-failed')
    if errors:
        receipt['recovery_errors'] = errors
    raise
finally:
    if backup:
        (backup / 'receipt.json').write_text(json.dumps(receipt, indent=2, sort_keys=True) + '\n')
        os.chmod(backup / 'receipt.json', 0o600)
    fcntl.flock(lock, fcntl.LOCK_UN)
    lock.close()

print(json.dumps(receipt, sort_keys=True), flush=True)
