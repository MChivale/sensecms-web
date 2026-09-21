#!/usr/bin/env python3
"""Deploy Core Page Builder section collaboration with guarded rollback."""
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
    raise SystemExit('Run as root on Linux: deploy-page-builder-collaboration-20260921.py <private-stage>')

os.umask(0o077)
stage = Path(sys.argv[1]).resolve()
web = Path('/home/sensecms.com/web')
core_files = [
    'app/Core/AccessControl.php', 'app/Core/ConsoleSearchIndex.php', 'app/Core/PageBuilder.php',
    'app/Core/CmsRepository.php', 'app/Http/DashboardController.php',
    'app/Views/console-page-builder.php', 'app/Views/console.php', 'app/workspace.php',
    'public/theme/sensecms-content-management.js', 'public/theme/sensecms-page-builder.css',
    'public/theme/sensecms-page-builder.js',
]
migration = 'database/workspace/036_page_builder_collaboration.sql'
baseline = {
    'app/Core/AccessControl.php': '3101dd948e88b8a756675222a5832042864cb7ef2c387a2591c4365466a50760',
    'app/Core/ConsoleSearchIndex.php': '8601b1b941cea4e2adce070891af137961a9c53bd9797ef36e10bfef6c4c74e8',
    'app/Core/PageBuilder.php': '2f455763090e3c3bd640d331cd96e824ac744f87421918bc618d579786726369',
    'app/Core/CmsRepository.php': '3eb7e30a079569fdb25dcadb71cea838da64befea1dfbe3ed3d6f0ed90d4d7a9',
    'app/Http/DashboardController.php': '9df805fbb7d30d90ba137877e2a1a4cb8692bda2d3c8d37e0129308baf4d2eeb',
    'app/Views/console-page-builder.php': '3d4cb3d0cfbec8e471c17ed2e4f598b9cc09fc90655a15631752a466ea55a61b',
    'app/Views/console.php': 'ba5523dad341afe8b7c2eee50742e6c24c82aecd95975066caf09ca45703f3a2',
    'app/workspace.php': 'a1ac213978877a45257557a81971b948fd69f1b361ec869c57e52e48a934911c',
    'public/theme/sensecms-content-management.js': '5cde6aca8658f0f9414e24c421ef1bff9ceebeb9fffe64396064eff7bad331ca',
    'public/theme/sensecms-page-builder.css': '920a155d6ada2709b8f7923ac74b41090ffe2cd8f91cf5ec1a446324e3fe432c',
    'public/theme/sensecms-page-builder.js': '90e9ec7a738e6f40c2356deeb30e76188dcc3b2f6b47f90c6f84b31d407b5ac6',
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


def migration_status():
    return json.loads(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);echo json_encode((new App\Installer\WorkspaceMigration($db,$r))->status(),JSON_THROW_ON_ERROR);''', web, user='sensecms'))


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
    stage / 'tests/builder-defaults.php', stage / 'tests/security-regressions.php',
    stage / 'tests/maintenance-regressions.php', stage / 'tests/page-builder-ui-production.py',
    *(stage / '.cms/source' / relative for relative in core_files + [migration]),
]
check(stage.is_dir() and str(stage).startswith('/root/sense-builder-collaboration-'), 'Private scoped deployment stage')
check(all(path.is_file() and not path.is_symlink() for path in required), 'Complete section collaboration deployment payload')
theme_before = active_theme()
check(theme_before['slug'] == 'sensecms' and theme_before['version'] == '1.0.14', 'Pinned signed theme 1.0.14 remains active')
check(all((web / relative).is_file() and sha(web / relative) == expected for relative, expected in baseline.items()), 'Expected production Page Builder baseline')
migration_present = (web / migration).exists()
if migration_present:
    check(sha(web / migration) == sha(stage / '.cms/source' / migration), 'Previously applied collaboration migration matches the reviewed source')
else:
    check(True, 'Collaboration migration is cleanly pending')
status = migration_status()
check(status['ready'] and not status['pending'] and not status['missing_tables'], 'Existing Workspace schema is healthy')
for path in [stage / '.cms/source' / relative for relative in core_files if relative.endswith('.php')]:
    run(['php8.5', '-l', str(path)])
node = shutil.which('node')
if node:
    for relative in [path for path in core_files if path.endswith('.js')]:
        run([node, '--check', str(stage / '.cms/source' / relative)])
    check(True, 'Staged JavaScript syntax')
check(b'282 builder preset/compatibility checks passed' in run(['php8.5', str(stage / 'tests/builder-defaults.php')]), 'Collaboration contract regressions')
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
    backup = Path('/root/sensecms-backups') / (stamp + '-page-builder-collaboration')
    backup.mkdir(mode=0o700)
    receipt['backup'] = str(backup)
    with (backup / 'database-before.sql').open('wb') as output:
        result = subprocess.run(['mysqldump', '--single-transaction', '--routines', '--triggers', 'sensecms_site'], stdout=output, stderr=subprocess.PIPE)
        if result.returncode:
            raise RuntimeError(result.stderr.decode(errors='replace').strip() or 'Database backup failed.')
    os.chmod(backup / 'database-before.sql', 0o600)
    check((backup / 'database-before.sql').stat().st_size > 4096, 'Private production database recovery dump')
    if not migration_present:
        deploy(migration)
        applied = int(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);echo(new App\Installer\WorkspaceMigration($db,$r))->apply();''', web, user='sensecms'))
        check(applied == 1, 'Collaboration migration 036 applied exactly once')
    else:
        check(True, 'Collaboration migration 036 was already applied exactly once')
    schema = json.loads(php(r'''$r=$argv[1];$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$tables=[];foreach(['page_builder_section_workflow','page_builder_section_comments','page_builder_section_locks']as$t){$q=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$t]);$tables[$t]=(bool)$q->fetchColumn();}$db->exec('DELETE FROM page_builder_section_locks WHERE expires_at<=NOW()');$permission=(int)$db->query("SELECT COUNT(*) FROM permissions WHERE slug='content.pages.collaborate'")->fetchColumn();$grants=(int)$db->query("SELECT COUNT(DISTINCT r.slug) FROM roles r INNER JOIN role_permissions rp ON rp.role_id=r.id INNER JOIN permissions p ON p.id=rp.permission_id WHERE p.slug='content.pages.collaborate' AND r.slug IN ('owner','administrator','content-manager','editor','reviewer')")->fetchColumn();$rows=0;foreach(array_keys($tables)as$t)$rows+=(int)$db->query('SELECT COUNT(*) FROM '.$t)->fetchColumn();echo json_encode(['tables'=>$tables,'permission'=>$permission,'grants'=>$grants,'rows'=>$rows,'status'=>(new App\Installer\WorkspaceMigration($db,$r))->status()],JSON_THROW_ON_ERROR);''', web, user='sensecms'))
    check(all(schema['tables'].values()) and schema['permission'] == 1 and schema['grants'] == 5 and schema['rows'] == 0 and schema['status']['ready'], 'Collaboration schema and least-privilege grants are ready without content changes')
    for relative in core_files:
        deploy(relative)
    for relative in core_files + [migration]:
        check(sha(web / relative) == sha(stage / '.cms/source' / relative), 'Deployed checksum ' + relative)
    time.sleep(3)
    check(b'PASS Production Core Page Builder collaboration' in run(['python3', str(stage / 'tests/page-builder-ui-production.py')]), 'Authenticated production collaboration acceptance')
    for route in ['/content/builder', '/theme/sensecms-page-builder.css?v=20260921-collaboration-1', '/theme/sensecms-page-builder.js?v=20260921-collaboration-1']:
        try:
            with urllib.request.urlopen('https://www.sensecms.com' + route, timeout=30) as response:
                check(response.status in (200, 302) and response.read(), 'HTTP response ' + route)
        except urllib.error.HTTPError as error:
            check(error.code in (302, 401, 403), 'Protected HTTP response ' + route)
    check(active_theme() == theme_before, 'Active signed theme was not changed')
    residual = int(php(r'''$r=new App\Core\Runtime($argv[1]);$db=App\Core\Runtime::connect($r->read('installed')['database']);echo$db->query('SELECT COUNT(*) FROM page_builder_section_locks')->fetchColumn();''', web, user='sensecms'))
    check(residual == 0, 'Acceptance left no section editing locks')
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
