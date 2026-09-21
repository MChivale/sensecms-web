#!/usr/bin/env python3
"""Deploy the review-first Core Posts AI assistant with guarded rollback."""
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
    raise SystemExit('Run as root on Linux: deploy-posts-ai-20260921.py <private-stage>')

os.umask(0o077)
stage = Path(os.sys.argv[1]).resolve()
web = Path('/home/sensecms.com/web')
files = [
    'app/Core/AccessControl.php', 'app/Core/AiContentService.php', 'app/Core/AiRepository.php',
    'app/Http/DashboardController.php', 'app/Views/console-ai.php',
    'app/Views/console-content-post-form.php', 'app/Views/console.php', 'app/workspace.php',
    'public/theme/sensecms-content-management.css', 'public/theme/sensecms-content-management.js',
    'public/theme/sensecms-post-editor.js', 'public/theme/sensecms-post-ai.js',
]
migration = 'database/workspace/039_posts_ai.sql'
baseline = {
    'app/Core/AccessControl.php': '0b7a8658fa0b3e15d5d540391294db4ed432cda564e3946a9e7ae0eea94b7ce5',
    'app/Core/AiContentService.php': '419367d2cdc6343de6867c6609651f0770cf91f77832afb64b98d5f275c19500',
    'app/Core/AiRepository.php': '40c913c9716c00427a3941692df81d2d6fcab871a85eaec8e0f92b139f61d00b',
    'app/Http/DashboardController.php': '77779a4f59b83fc5f9259c864fd63a16ce890b8ec889f4af1733c5e38ff31a42',
    'app/Views/console-ai.php': 'dbd5266e0db9c181189c363d92f4c12509407cc00963e70eb2df4f1e889db4fd',
    'app/Views/console-content-post-form.php': '66eb54b4037c650520fa6868fcf96757c411e186d584942649c7054432667462',
    'app/Views/console.php': '88bbe6e6465832726198b1004903ec0e51ccaf5682164e0a4ce8aa9fff756019',
    'app/workspace.php': 'ca08568093eaae07c2e6d378c37218a017f7c3a3975bba67aa8d80070469cb62',
    'public/theme/sensecms-content-management.css': 'dc5402afc0dc8f51de23ea17315cd4ee0b32bb4d1a212a01064a2759cf69ca4e',
    'public/theme/sensecms-content-management.js': '127ee92352d83169589ce3bce4ffd17b5f6c018466f09372ff9c9cba52541258',
    'public/theme/sensecms-post-editor.js': '31caa64b7c809e61fea230543bae3401f4ef6ec01e7fc39c8163d4a7b3537138',
}
receipt = {'status': 'preflight', 'checks': []}
backup = None
deployed: list[str] = []
metadata: dict[str, tuple[int, int, int]] = {}
provider_before = None
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


def state():
    return json.loads(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$q=$db->prepare("SELECT id,slug,driver,default_model,api_key_encrypted,options,enabled,priority,verified_at,updated_at FROM ai_providers WHERE slug='openai-mtc'");$q->execute();$p=$q->fetch(PDO::FETCH_ASSOC);$usage=(int)$db->query('SELECT COUNT(*) FROM ai_usage_events')->fetchColumn();echo json_encode(['provider'=>$p?['id'=>(int)$p['id'],'slug'=>$p['slug'],'driver'=>$p['driver'],'model'=>$p['default_model'],'credential_fingerprint'=>hash('sha256',$p['api_key_encrypted']),'options'=>json_decode($p['options'],true),'enabled'=>(bool)$p['enabled'],'priority'=>(int)$p['priority'],'verified_at'=>$p['verified_at'],'updated_at'=>$p['updated_at']]:null,'usage_rows'=>$usage,'migrations'=>(new App\Installer\WorkspaceMigration($db,$r))->status()],JSON_THROW_ON_ERROR);''', web, user='sensecms'))


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
    if provider_before:
        php(r'''$r=new App\Core\Runtime($argv[1]);$db=App\Core\Runtime::connect($r->read('installed')['database']);$q=$db->prepare('UPDATE ai_providers SET options=?,updated_at=? WHERE id=?');$q->execute([$argv[2],$argv[3],(int)$argv[4]]);''', web, json.dumps(provider_before['options'], separators=(',', ':')), provider_before['updated_at'], provider_before['id'], user='sensecms')


required = [
    web / 'bootstrap.php', stage / '.cms/source/bootstrap.php',
    stage / 'tests/posts-ai.php', stage / 'tests/post-editor-regressions.php',
    stage / 'tests/page-builder-ai.php', stage / 'tests/builder-defaults.php',
    stage / 'tests/security-regressions.php', stage / 'tests/post-editor-ui-production.py',
    stage / 'tests/page-builder-ui-production.py',
    *(stage / '.cms/source' / relative for relative in files + [migration]),
]
check(stage.is_dir() and str(stage).startswith('/root/sense-posts-ai-'), 'Private scoped deployment stage')
check(all(path.is_file() and not path.is_symlink() for path in required), 'Complete Core Posts AI deployment payload')
check(all((web / relative).is_file() and sha(web / relative) == expected for relative, expected in baseline.items()), 'Expected production Core baseline')
check(not (web / 'public/theme/sensecms-post-ai.js').exists(), 'New Posts AI browser module is absent before deployment')
check(not (web / migration).exists(), 'Posts AI migration is cleanly pending')
theme_before = active_theme()
check(theme_before['slug'] == 'sensecms', 'Pinned signed Sense CMS theme remains active')
before = state()
provider_before = before['provider']
check(before['migrations']['ready'] and not before['migrations']['pending'] and not before['migrations']['missing_tables'], 'Existing Workspace schema is healthy')
check(provider_before and provider_before['enabled'] and provider_before['verified_at'], 'Existing OpenAI provider is enabled and verified')
check('builder' in provider_before['options'].get('purposes', []) and 'posts' not in provider_before['options'].get('purposes', []), 'OpenAI provider is currently scoped to Builder only')
for path in [stage / '.cms/source' / relative for relative in files if relative.endswith('.php')]:
    run(['php8.5', '-l', str(path)])
node = shutil.which('node')
if node:
    for relative in [path for path in files if path.endswith('.js')]:
        run([node, '--check', str(stage / '.cms/source' / relative)])
    check(True, 'Staged JavaScript syntax')
check(b'16 Posts AI checks passed' in run(['php8.5', str(stage / 'tests/posts-ai.php')]), 'Review-first Posts AI regressions without network calls')
check(b'22 post editor regression checks passed' in run(['php8.5', str(stage / 'tests/post-editor-regressions.php')]), 'Post editor regressions')
check(b'23 Page Builder AI checks passed' in run(['php8.5', str(stage / 'tests/page-builder-ai.php')]), 'Shared provider and usage-control regressions')
check(b'282 builder preset/compatibility checks passed' in run(['php8.5', str(stage / 'tests/builder-defaults.php')]), 'Builder contract regressions')
if b'pdo_sqlite' in run(['php8.5', '-m']):
    check(b'92 security regression checks passed' in run(['php8.5', str(stage / 'tests/security-regressions.php')]), 'Security regressions')
else:
    print('SKIP SQLite-dependent security regressions (pdo_sqlite unavailable)', flush=True)
run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb', 'cron'])
run(['nginx', '-t'])

lock = Path('/root/sensecms-private/deploy-workspace.lock').open('a+b')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
try:
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    token = hashlib.sha256(stamp.encode()).hexdigest()[:12]
    backup = Path('/root/sensecms-backups') / (stamp + '-posts-ai')
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
    check(applied == 1, 'Posts AI migration 039 applied exactly once')
    schema = json.loads(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$permission=(int)$db->query("SELECT COUNT(*) FROM permissions WHERE slug='content.posts.ai'")->fetchColumn();$grants=(int)$db->query("SELECT COUNT(DISTINCT r.slug) FROM roles r INNER JOIN role_permissions rp ON rp.role_id=r.id INNER JOIN permissions p ON p.id=rp.permission_id WHERE p.slug='content.posts.ai' AND r.slug IN ('owner','administrator','content-manager','editor')")->fetchColumn();echo json_encode(['permission'=>$permission,'grants'=>$grants,'status'=>(new App\Installer\WorkspaceMigration($db,$r))->status()],JSON_THROW_ON_ERROR);''', web, user='sensecms'))
    check(schema['permission'] == 1 and schema['grants'] == 4 and schema['status']['ready'], 'Posts AI permission and least-privilege grants are ready')
    for relative in files:
        deploy(relative)
    for relative in files + [migration]:
        check(sha(web / relative) == sha(stage / '.cms/source' / relative), 'Deployed checksum ' + relative)
    configured = json.loads(php(r'''$r=new App\Core\Runtime($argv[1]);$db=App\Core\Runtime::connect($r->read('installed')['database']);$db->beginTransaction();try{$q=$db->prepare("SELECT id,options FROM ai_providers WHERE slug='openai-mtc' FOR UPDATE");$q->execute();$p=$q->fetch(PDO::FETCH_ASSOC);if(!$p)throw new RuntimeException('OpenAI provider unavailable.');$o=json_decode($p['options'],true)?:[];$purposes=array_values(array_unique(array_merge((array)($o['purposes']??[]),['posts'])));$o['purposes']=$purposes;$u=$db->prepare('UPDATE ai_providers SET options=?,updated_at=NOW() WHERE id=?');$u->execute([json_encode($o,JSON_THROW_ON_ERROR),(int)$p['id']]);$db->commit();echo json_encode(['id'=>(int)$p['id'],'purposes'=>$purposes],JSON_THROW_ON_ERROR);}catch(Throwable$e){if($db->inTransaction())$db->rollBack();throw$e;}''', web, user='sensecms'))
    check(configured['id'] == provider_before['id'] and 'builder' in configured['purposes'] and 'posts' in configured['purposes'] and 'chat' not in configured['purposes'], 'OpenAI provider enabled for Builder and Posts only')
    after_config = state()
    check(after_config['provider']['credential_fingerprint'] == provider_before['credential_fingerprint'] and after_config['provider']['model'] == provider_before['model'] and after_config['provider']['driver'] == provider_before['driver'] and after_config['provider']['priority'] == provider_before['priority'] and after_config['provider']['options'].get('limits') == provider_before['options'].get('limits'), 'Provider credential, model, priority and budgets are unchanged')
    check(after_config['usage_rows'] == before['usage_rows'], 'Deployment made no external AI request')
    check(b'PASS Production create/edit post editor' in run(['python3', str(stage / 'tests/post-editor-ui-production.py')]), 'Authenticated production create/edit Posts AI acceptance')
    check(b'PASS Production Core Page Builder AI' in run(['python3', str(stage / 'tests/page-builder-ui-production.py')]), 'Adjacent Page Builder AI acceptance')
    for route in ['/content/posts/new', '/ai', '/theme/sensecms-post-ai.js?v=20260921-1', '/theme/sensecms-content-management.css?v=20260921-post-ai-1']:
        try:
            with urllib.request.urlopen('https://www.sensecms.com' + route, timeout=30) as response:
                check(response.status in (200, 302) and response.read(), 'HTTP response ' + route)
        except urllib.error.HTTPError as error:
            check(error.code in (302, 401, 403), 'Protected HTTP response ' + route)
    after = state()
    check(after['usage_rows'] == before['usage_rows'], 'Acceptance made no paid AI request')
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
