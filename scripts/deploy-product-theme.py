"""Theme-only release: rehearse on private QA, then publish the exact accepted source."""
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import urllib.request

theme_only = '--theme-only' in sys.argv[1:]
args = [arg for arg in sys.argv[1:] if arg != '--theme-only']
qa = Path('/root/sense-workspace-test.SC495Gg0')
candidate = qa / ('candidate-038' if theme_only else 'candidate-030')
source = candidate / '.themes/sensecms'
version = '0.3.8' if theme_only else '0.3.0'
previous = '0.3.7' if theme_only else '0.2.1'
qa_publisher = 'sensecms-qa-design-' + version.replace('.', '')
core_files = {} if theme_only else {
    'app/Core/EmailSystem.php': 'e7b067b595e17b280706c0c7bef7d608cd5a0a56d377f50f91e4f5a3c0d768aa',
    'app/Http/FormController.php': '14fe0d2f753c25a011e9f3e88a64c54e5801b24e7304fd22a3d69c658bdeafb8',
    'app/Core/CmsRepository.php': '907ae972db8b7158573baaa14db228847660dda1740a3475a4c83eb8f27bca49',
    'app/Core/PageBuilder.php': 'b7e4f554d9d651a92ed9aa3049173b9641e970738a18919f784cca28154cc9f0',
    'app/Core/MailService.php': '62122af767216ee0ac1e4df53b7f30f33297e103be6c923e4af6655a11aceaa0',
    'app/workspace.php': '90719df36570b751834b2d8a2b15c4b14e9f2d5037d7f74832bab7850e81b85a',
    'config/page-builder.php': '10a2619277d35e731ea63b92fe7bbe1731bf3a3e0451d37fc880b7bc71ed263c',
    'app/Core/FormMail.php': None,
}
production = args == ['--production']
if not production and args != ['--qa']:
    raise SystemExit('Choose --qa or --production, optionally with --theme-only')
web = Path('/home/sensecms.com/web') if production else qa / '.cms/source'
base = 'https://www.sensecms.com' if production else 'http://127.0.0.1:8873'
os.umask(0o077)
lock = Path('/root/sensecms-private/deploy-workspace.lock').open('a')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)


def run(args):
    result = subprocess.run(args, capture_output=True)
    if result.returncode:
        raise RuntimeError('Verification failed: ' + args[0] + ' (private output withheld)')
    return result.stdout


def php(code, *args, runtime_user=False):
    command = ['php8.5', '-r', 'require $argv[1]."/bootstrap.php"; ' + code, str(web), *map(str, args)]
    if runtime_user and production:
        command = ['runuser', '-u', 'sensecms', '--', *command]
    return run(command)


def health():
    for route in ['/', '/platform', '/extensions', '/extensions/modules', '/extensions/plugins', '/extensions/addons', '/extensions/themes', '/docs', '/docs/themes', '/docs/packages', '/docs/licensing', '/docs/server', '/docs/installation', '/download', '/contact', '/login', '/theme-assets/product.css', '/theme-assets/workspace.png']:
        with urllib.request.urlopen(base + route, timeout=20) as res:
            body = res.read()
            if res.status != 200 or not body:
                raise RuntimeError('Route failed: ' + route)
            if route == '/' and b'product-hero' not in body:
                raise RuntimeError('New theme not visible')
            if route == '/platform' and (b'platform-hero' not in body or b'data-back-to-top' not in body):
                raise RuntimeError('Platform presentation not visible')
            if route == '/contact' and b'data-contact-form' not in body:
                raise RuntimeError('Managed contact form not visible')
            if theme_only and route == '/contact' and (b'contact-composed' not in body or b'class="contact-details"' not in body):
                raise RuntimeError('Composed contact not visible')
            if theme_only and route == '/docs' and body.count(b'resource-start') != 1:
                raise RuntimeError('Documentation starting point not visible')


inventory = {}
for file in sorted(source.rglob('*')):
    if file.is_symlink():
        raise RuntimeError('Source symlinks are forbidden')
    if file.is_file():
        inventory[file.relative_to(source).as_posix()] = hashlib.sha256(file.read_bytes()).hexdigest()
        if file.suffix == '.php':
            run(['php8.5', '-l', str(file)])
manifest = json.loads((source / 'sense-package.json').read_text())
for path, digest in core_files.items():
    current = web / path
    if digest is None:
        if current.exists():
            raise RuntimeError('New Core file already exists: ' + path)
    elif hashlib.sha256(current.read_bytes()).hexdigest() != digest:
        raise RuntimeError('Core baseline changed: ' + path)
    file = candidate / '.cms/source' / path
    run(['php8.5', '-l', str(file)])
    inventory['core/' + path] = hashlib.sha256(file.read_bytes()).hexdigest()
for path in ([] if theme_only else ['scripts/setup-product-contact.php', 'tests/contact-http.py']):
    inventory[path] = hashlib.sha256((candidate / path).read_bytes()).hexdigest()
if manifest['version'] != version or manifest['slug'] != 'sensecms':
    raise RuntimeError('Unexpected candidate')
state = json.loads((web / 'storage/theme.json').read_text())
if state['active']['slug'] != 'sensecms' or state['active']['version'] != previous:
    raise RuntimeError('Active release changed; inspect instead of rerunning')
evidence = qa / ('product-design-' + version.replace('.', '') + '-accepted.json')
if production and json.loads(evidence.read_text()) != inventory:
    raise RuntimeError('Candidate differs from accepted QA source')

stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
backup = (Path('/root/sensecms-backups') if production else qa) / (stamp + '-product-design')
backup.mkdir(mode=0o700)
shutil.copy2(web / 'storage/theme.json', backup / 'theme-before.json')
run(['tar', '-czf', str(backup / 'theme-storage-before.tgz'), '-C', str(web), 'storage/theme.json', 'storage/themes'])
(backup / 'candidate.json').write_text(json.dumps(inventory, indent=2))
db_code = '$r=new App\\Core\\Runtime($argv[1]); $db=App\\Core\\Runtime::connect($r->read("installed")["database"]); '
for path, digest in core_files.items():
    if digest is not None:
        target = backup / 'core' / path
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(web / path, target)
package = backup / ('sensecms-theme-' + version + '.zip')
if production:
    trust = json.loads(Path('/root/sensecms-private/trust.json').read_text())
    php('$secret=base64_decode(trim(file_get_contents("/root/sensecms-private/publisher.ed25519")),true); try { App\\Core\\Packages\\Archive::build($argv[2],$argv[3],$secret); } finally { sodium_memzero($secret); }', source, package)
else:
    # An isolated publisher cannot replace the QA trust for an older signed release.
    qa_source = backup / 'source'
    shutil.copytree(source, qa_source)
    test_manifest = dict(manifest)
    test_manifest['publisher'] = {'key_id': qa_publisher, 'name': 'Sense CMS private QA'}
    (qa_source / 'sense-package.json').write_text(json.dumps(test_manifest))
    encoded = php('$pair=sodium_crypto_sign_keypair(); $secret=sodium_crypto_sign_secretkey($pair); try { App\\Core\\Packages\\Archive::build($argv[2],$argv[3],$secret); echo base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)); } finally { sodium_memzero($secret); }', qa_source, package)
    trust = {qa_publisher: encoded.decode()}
    php(db_code + '$p=new App\\Core\\PackageManager($db,$argv[1],"0.1.0"); $p->trustPublisher($argv[3],"Sense CMS private QA","",$argv[2],1);', trust[qa_publisher], qa_publisher)

keys = json.dumps(trust)
core_changed = []
try:
    for path in core_files:
        target = web / path
        temp = target.with_name(target.name + '.contact-release')
        with temp.open('xb') as handle:
            handle.write((candidate / '.cms/source' / path).read_bytes())
        temp.chmod(0o644)
        os.replace(temp, target)
        core_changed.append(path)
    # Stage privately, repair ownership, then activate as PHP user: no unreadable active window.
    release = json.loads(php('$r=new App\\Core\\Runtime($argv[1]); $r->license()->enforce($r->baseUrl()); $keys=array_map(fn($k)=>base64_decode($k,true),json_decode($argv[3],true)); echo json_encode((new App\\Core\\Packages\\ThemeManager($r))->install($argv[2],$keys,false));', package, keys))
    if production:
        run(['chown', '-R', 'sensecms:sensecms', str(web / 'storage/themes' / release['directory'])])
        run(['chown', 'sensecms:sensecms', str(web / 'storage/theme.json'), str(web / 'storage/themes.lock')])
    php('$r=new App\\Core\\Runtime($argv[1]); $keys=array_map(fn($k)=>base64_decode($k,true),json_decode($argv[3],true)); (new App\\Core\\Packages\\ThemeManager($r))->activate($argv[2],$keys);', release['directory'], keys, runtime_user=True)
    if not theme_only:
        run(['php8.5', str(candidate / 'scripts/setup-product-contact.php'), str(web), '--apply', str(backup)])
    health()
    if not production:
        log = run(['python3', str(qa / 'tests/product-pages-http.py')])
        if b'Completed 92 managed product HTTP checks.' not in log:
            raise RuntimeError('Managed page acceptance incomplete')
        (backup / 'http-checks.log').write_bytes(log)
        if not theme_only:
            log = run(['python3', str(candidate / 'tests/contact-http.py')])
            (backup / 'contact-checks.log').write_bytes(log)
            if b'Contact acceptance passed.' not in log:
                raise RuntimeError('Contact acceptance incomplete')
        evidence.write_text(json.dumps(inventory))
    else:
        run(['systemctl', 'is-active', 'nginx', 'php8.5-fpm', 'mariadb'])
        # Independently verify active signed archive and every installed payload hash.
        php('$r=new App\\Core\\Runtime($argv[1]); $keys=array_map(fn($k)=>base64_decode($k,true),json_decode($argv[3],true)); (new App\\Core\\Packages\\ThemeManager($r))->activate($argv[2],$keys);', release['directory'], keys, runtime_user=True)
except BaseException:
    if (backup / 'contact-before.json').exists():
        run(['php8.5', str(candidate / 'scripts/setup-product-contact.php'), str(web), '--rollback', str(backup)])
    for path in core_changed:
        target = web / path
        if core_files[path] is None:
            target.unlink()
        else:
            temp = target.with_name(target.name + '.contact-restore')
            shutil.copy2(backup / 'core' / path, temp)
            os.replace(temp, target)
    tmp = web / 'storage/theme-design-restore.json'
    with tmp.open('xb') as handle:
        handle.write((backup / 'theme-before.json').read_bytes())
    if production:
        shutil.chown(tmp, user='sensecms', group='sensecms')
    os.replace(tmp, web / 'storage/theme.json')
    raise
print(json.dumps({'environment': 'production' if production else 'qa', 'release': release, 'backup': str(backup), 'health': 'passed'}))
