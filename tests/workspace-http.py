"""HTTP regression checks against the disposable, loopback-only Workspace fixture."""
import http.cookiejar
import html
from http.cookiejar import CookieJar
import json
from pathlib import Path
import re
import secrets
import subprocess
import urllib.error
import urllib.parse
import urllib.request
import uuid
import xml.etree.ElementTree as ET

root = Path(__file__).resolve().parents[1]
if not re.fullmatch(r'sense-workspace-test\.[A-Za-z0-9]{8}', root.name):
    raise SystemExit('Use an isolated Workspace test directory.')
owner = json.loads((root / 'preview-private.json').read_text())
http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()))
base = 'http://127.0.0.1:8873'
checks = 0
assets = set()

def request(path, data=None, as_json=False):
    headers = {'Accept': 'application/json'} if data is not None or as_json else {}
    if as_json:
        headers['Content-Type'] = 'application/json'
    body = (json.dumps(data) if as_json else urllib.parse.urlencode(data, doseq=True)).encode() if data is not None else None
    try:
        with http.open(urllib.request.Request(base + path, data=body, headers=headers), timeout=20) as res:
            return res.status, res.read().decode(errors='replace'), res.headers
    except urllib.error.HTTPError as res:
        return res.code, res.read().decode(), res.headers

def check(ok, label):
    global checks
    if not ok:
        raise RuntimeError('FAIL ' + label)
    checks += 1
    print('PASS ' + label, flush=True)

status, page, _ = request('/login')
check(status == 200 and 'name="email"' in page, 'Workspace login renders')
check('Sense CMS · Secure administration' in page and 'Education CMS' not in page, 'Login branding describes a general-purpose Sense CMS')
token = re.search(r'name="csrf" value="([a-f0-9]{64})"', page)[1]
status, body, _ = request('/login', dict(csrf=token, email=owner['email'], password=owner['password']))
check(status == 200 and json.loads(body).get('redirect') == '/dashboard', 'Real QA credentials authenticate')
status, page, _ = request('/account')
check(status == 200 and 'action="/settings/password"' in page, 'Initial Core account URL retains password settings after upgrade')
theme_state = root / '.cms/source/storage/theme.json'
theme_version = json.loads((root / '.themes/sensecms/sense-package.json').read_text())['version']
if not theme_state.exists() or json.loads(theme_state.read_text())['active']['version'] != theme_version:
    provision_theme = r'''
    umask(0077);
    require $argv[1] . '/.cms/source/bootstrap.php';
    $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    App\Core\Packages\Archive::build($argv[1] . '/.themes/sensecms', $argv[1] . '/qa-theme-' . $argv[2] . '.zip', $secret);
    file_put_contents($argv[1] . '/qa-trust-' . $argv[2] . '.json', json_encode(['sensecms-release'=>base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))]));
    sodium_memzero($secret);
    '''
    build_id = secrets.token_hex(8)
    subprocess.run(['php', '-r', provision_theme, str(root), build_id], check=True, capture_output=True)
    subprocess.run(['php', str(root / '.cms/source/scripts/theme.php'), 'install', str(root / ('qa-theme-' + build_id + '.zip')), str(root / ('qa-trust-' + build_id + '.json'))], check=True, capture_output=True)
for path in ['/', '/extensions', '/docs', '/contact']:
    status, page, headers = request(path)
    check(status == 200 and 'class="app-menu"' not in page, 'Existing public website preserved: ' + path)
    check(headers.get('Set-Cookie') is None, 'Public website does not create admin sessions: ' + path)
for path in ['/dashboard','/profile','/settings','/content/facilities','/content/pages','/content/pages/new','/content/posts','/content/posts/new','/content/categories','/content/navigation','/content/media','/content/workflow','/surveys','/forms/submissions','/system/access','/system/email','/system/languages','/system/cache','/system/search-index','/system/sounds','/system/notifications','/license','/calendar','/appearance','/appearance/themes','/system/extensions','/marketplace','/system/update','/system/captcha','/ai','/conversations']:
    status, page, headers = request(path)
    check(status == 200 and 'class="app-menu"' in page, 'Screen ' + path + ' HTTP ' + str(status))
    check('nonce-' in headers.get('Content-Security-Policy', ''), 'Script nonce ' + path)
    for url in sorted(set(re.findall(r'(?:src|href)="(/(?:theme|assets|extension-assets)/[^"?#]+)', page)) - assets):
        asset_status, _, _ = request(url)
        check(asset_status == 200, 'Asset ' + url)
        assets.add(url)
token = re.search(r'name="csrf" value="([a-f0-9]{64})"', page)[1]
suffix = secrets.token_hex(4)
# Trust only the key that verifies the already installed QA theme; never a production key.
trust_current_theme = r'''
require $argv[1] . '/.cms/source/bootstrap.php';
$runtime = new App\Core\Runtime($argv[1] . '/.cms/source');
$manager = new App\Core\Packages\ThemeManager($runtime);
$archive = dirname($manager->activePath()) . '/archive.zip';
$db = App\Core\Runtime::connect($runtime->read('installed')['database']);
$packages = new App\Core\PackageManager($db, $runtime->root, '0.1.0');
foreach (glob($argv[1] . '/qa-trust*.json') as $file) {
    $encoded = json_decode(file_get_contents($file), true); $keys = [];
    foreach ($encoded as $id => $value) $keys[$id] = base64_decode($value, true);
    try { $manifest = App\Core\Packages\Archive::verify($archive, $keys); }
    catch (RuntimeException) { continue; }
    $id = $manifest['publisher']['key_id'];
    $packages->trustPublisher($id, $manifest['publisher']['name'], '', $encoded[$id], 1);
    exit(0);
}
throw new RuntimeException('Matching isolated QA theme trust not found.');
'''
subprocess.run(['php', '-r', trust_current_theme, str(root)], check=True, capture_output=True)
active_release = json.loads(theme_state.read_text())['active']
status, editor, _ = request('/appearance/themes')
published_settings = json.loads(html.unescape(re.search(r'data-published="([^"]*)"', editor)[1]))
theme_endpoint = '/appearance/themes/sensecms/'
marker = 'Homepage QA ' + suffix
draft_settings = {**published_settings, 'home_title': marker, 'home_seo_title': marker + ' SEO', 'home_detail': '<script>unsafe-homepage()</script>'}
owner_session = http
try:
    for action in ['draft', 'publish', 'discard']:
        status, _, _ = request(theme_endpoint + action, {'csrf': 'invalid', 'settings': draft_settings}, True)
        check(status == 419, 'Theme ' + action + ' enforces CSRF')
    status, _, _ = request(theme_endpoint + 'draft', {'csrf': token, 'settings': {'home_title': ['invalid']}}, True)
    check(status == 422, 'Malformed theme setting rejected')
    status, body, _ = request(theme_endpoint + 'draft', {'csrf': token, 'settings': draft_settings}, True)
    check(status == 200 and json.loads(body)['data']['settings']['home_title'] == marker, 'Homepage draft persists')
    status, body, _ = request('/')
    check(status == 200 and marker not in body, 'Saving draft leaves public homepage unchanged')
    status, body, headers = request(theme_endpoint + 'preview')
    check(status == 200 and marker in body and marker + ' SEO' in body, 'Authenticated preview renders draft and SEO')
    check('private' in headers.get('Cache-Control', '') and 'no-store' in headers.get('Cache-Control', '') and 'noindex' in headers.get('X-Robots-Tag', ''), 'Preview is private, non-cacheable and non-indexable')
    check('&lt;script&gt;unsafe-homepage()&lt;/script&gt;' in body and '<script>unsafe-homepage()' not in body, 'Draft preview escapes markup')
    http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()))
    status, body, _ = request(theme_endpoint + 'preview')
    check(marker not in body and 'name="email"' in body, 'Anonymous preview requires login')
    status, body, _ = request('/?sensecms_theme_preview=anything')
    check(marker not in body, 'Public query parameter cannot reveal theme draft')
    http = owner_session
    stale_theme_draft = r'''
    require $argv[1] . '/.cms/source/bootstrap.php';
    $runtime = new App\Core\Runtime($argv[1] . '/.cms/source');
    $cms = new App\Core\CmsRepository(App\Core\Runtime::connect($runtime->read('installed')['database']), new App\Core\EventBus(), $runtime);
    $cms->withThemeConfigurationLock(function () use ($cms): void {
        $drafts = $cms->setting('theme_drafts', []);
        $drafts['sensecms']['base_version'] = '0.0.0';
        $cms->saveSetting('theme_drafts', $drafts);
    });
    '''
    subprocess.run(['php', '-r', stale_theme_draft, str(root)], check=True, capture_output=True)
    status, _, _ = request(theme_endpoint + 'publish', {'csrf': token}, True)
    check(status == 409, 'Draft from an old release cannot be published without review')
    status, _, _ = request(theme_endpoint + 'preview', as_json=True)
    check(status == 409, 'Draft from an old release cannot silently preview against a different schema')
    status, body, _ = request('/')
    check(marker not in body, 'Rejected stale publication preserves public configuration')
    status, _, _ = request(theme_endpoint + 'draft', {'csrf': token, 'settings': draft_settings}, True)
    check(status == 200, 'Reviewed draft saves against the current release')
    status, body, _ = request(theme_endpoint + 'publish', {'csrf': token}, True)
    check(status == 200 and json.loads(body)['data']['settings']['home_title'] == marker, 'Explicit publication returns committed settings')
    status, body, headers = request('/')
    check(marker in body and marker + ' SEO' in body and headers.get('Set-Cookie') is None, 'Published homepage is session-free and uses committed SEO')
    status, _, _ = request(theme_endpoint + 'publish', {'csrf': token}, True)
    check(status == 409, 'Publishing without a saved draft is rejected')
    status, _, _ = request(theme_endpoint + 'draft', {'csrf': token, 'settings': {**draft_settings, 'home_title': 'DISCARD-' + marker}}, True)
    check(status == 200, 'Second homepage draft saves')
    status, body, _ = request(theme_endpoint + 'discard', {'csrf': token}, True)
    check(status == 200 and json.loads(body)['data']['settings']['home_title'] == marker, 'Discard returns the latest published values')
    status, body, _ = request(theme_endpoint + 'preview')
    check(marker in body and 'DISCARD-' + marker not in body, 'Discarded draft no longer appears in preview')
finally:
    http = owner_session
    status, _, _ = request(theme_endpoint + 'draft', {'csrf': token, 'settings': published_settings}, True)
    check(status == 200, 'Restore original homepage configuration draft')
    status, _, _ = request(theme_endpoint + 'publish', {'csrf': token}, True)
    check(status == 200, 'Restore original homepage configuration publication')

for endpoint in ['/appearance/releases/activate', '/appearance/releases/rollback']:
    status, _, _ = request(endpoint, {'csrf': 'invalid'})
    check(status == 419, 'Theme lifecycle CSRF rejection: ' + endpoint)
status, _, _ = request('/appearance/releases/activate', {'csrf': token, 'directory': '../outside'})
check(status == 422, 'Theme activation rejects a path outside installed releases')
status, body, _ = request('/appearance/releases/activate', {'csrf': token, 'directory': active_release['directory']})
check(status == 200 and json.loads(body)['data']['directory'] == active_release['directory'], 'Panel activation verifies the exact signed current release')
status, _, _ = request('/appearance/themes/sensecms/activate', {'csrf': token})
check(status == 409, 'Legacy database-only activation cannot report false success')
check(json.loads(theme_state.read_text())['active'] == active_release, 'Rejected activation leaves the active presentation unchanged')
status, themes_page, _ = request('/appearance/themes')
check(status == 200 and 'Installed presentation releases' in themes_page and active_release['directory'] in themes_page, 'Themes workspace lists real retained releases')
prepare_alternate = r'''
require $argv[1] . '/.cms/source/bootstrap.php';
$runtime = new App\Core\Runtime($argv[1] . '/.cms/source');
$db = App\Core\Runtime::connect($runtime->read('installed')['database']);
$packages = new App\Core\PackageManager($db, $runtime->root, '0.1.0');
$manager = $packages->themeManager();
foreach ($manager->releases() as $release) if ($release['slug'] === 'qa-switch-test') { echo $release['directory']; exit; }
$temporary = sys_get_temp_dir() . '/sense-theme-http-' . bin2hex(random_bytes(12));
mkdir($temporary, 0700); $source = $argv[1] . '/.themes/sensecms'; $secret = null;
try {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        $target = $temporary . '/source/' . substr($file->getPathname(), strlen($source) + 1);
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
        copy($file->getPathname(), $target);
    }
    $manifest = json_decode(file_get_contents($temporary . '/source/sense-package.json'), true);
    $manifest['slug'] = 'qa-switch-test'; $manifest['publisher']['key_id'] = 'sensecms-qa-switch';
    file_put_contents($temporary . '/source/sense-package.json', json_encode($manifest));
    $descriptor = json_decode(file_get_contents($temporary . '/source/theme.json'), true);
    $descriptor['slug'] = $manifest['slug'];
    file_put_contents($temporary . '/source/theme.json', json_encode($descriptor));
    file_put_contents($temporary . '/source/layout.php', "\n<!-- QA alternate presentation -->", FILE_APPEND);
    $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $packages->trustPublisher('sensecms-qa-switch', $manifest['publisher']['name'], '', base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)), 1);
    App\Core\Packages\Archive::build($temporary . '/source', $temporary . '/theme.zip', $secret);
    $stage = $packages->stageLocalFile($temporary . '/theme.zip', 1);
    $release = $packages->install($stage['token'], 1);
    echo $release['directory'];
} finally {
    if ($secret !== null) sodium_memzero($secret);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($temporary);
}
'''
alternate = subprocess.run(['php', '-r', prepare_alternate, str(root)], check=True, capture_output=True, text=True).stdout
check(json.loads(theme_state.read_text())['active'] == active_release, 'Installing an alternate theme does not change the public pointer')
status, catalog_body, _ = request('/api/marketplace?type=theme&status=installed', as_json=True)
catalog_items = {item['slug']: item for item in json.loads(catalog_body)['data']['items']}
check(status == 200 and 'sensecms' in catalog_items and 'qa-switch-test' in catalog_items, 'Marketplace lists active and inactive private themes')
check(catalog_items['sensecms']['active'] and not catalog_items['qa-switch-test']['active'], 'Marketplace active flag agrees with the public pointer')
check(all(not item['uninstallable'] for item in catalog_items.values() if item['managed_releases']), 'Marketplace hides unsupported legacy removal of retained themes')
check(catalog_items['sensecms']['trust'] == 'verified' and catalog_items['sensecms']['compatible'], 'Marketplace verifies publisher and canonical Core compatibility')
try:
    status, body, _ = request('/appearance/releases/activate', {'csrf': token, 'directory': alternate})
    check(status == 200 and json.loads(body).get('ok'), 'Alternate signed theme activated through HTTP')
    status, alternate_home, _ = request('/')
    check(status == 200 and '<!-- QA alternate presentation -->' in alternate_home, 'Public website serves the exact activated theme files')
    status, alternate_panel, _ = request('/appearance/themes')
    check(status == 200 and 'data-slug="qa-switch-test"' in alternate_panel, 'Workspace configuration follows the active public theme')
    status, catalog_body, _ = request('/api/marketplace?type=theme&status=active', as_json=True)
    active_items = json.loads(catalog_body)['data']['items']
    check(status == 200 and len(active_items) == 1 and active_items[0]['slug'] == 'qa-switch-test', 'Marketplace active filter follows an actual theme switch')
    status, body, _ = request('/appearance/releases/rollback', {'csrf': token})
    check(status == 200 and json.loads(body)['data']['directory'] == active_release['directory'], 'HTTP rollback restores the original presentation')
    status, restored_home, _ = request('/')
    check(status == 200 and '<!-- QA alternate presentation -->' not in restored_home, 'Restored website serves the original theme files')
    status, catalog_body, _ = request('/api/marketplace?type=theme&status=active', as_json=True)
    active_items = json.loads(catalog_body)['data']['items']
    check(status == 200 and len(active_items) == 1 and active_items[0]['slug'] == 'sensecms', 'Marketplace active filter follows rollback')
finally:
    if json.loads(theme_state.read_text())['active'] != active_release:
        status, body, _ = request('/appearance/releases/activate', {'csrf': token, 'directory': active_release['directory']})
        if status != 200:
            raise RuntimeError('QA theme restoration requires operator attention')
for endpoint in ['/content/facilities', '/content/categories', '/system/extensions/packages/install', '/system/access/users']:
    status, _, _ = request(endpoint, {'csrf': 'invalid'})
    check(status == 419, 'CSRF rejection ' + endpoint)
status, _, _ = request('/api/calendar/categories', {'csrf': 'invalid'}, True)
check(status == 419, 'Calendar JSON CSRF rejection')

facilities = []
for name in ['north', 'south']:
    data = {'csrf': token, 'id': 0, 'city_slug': 'london', 'facility_slug': name + '-' + suffix, 'status': 'active', 'timezone': 'Europe/London', 'translations[en][name]': name.title(), 'translations[en][city_name]': 'London'}
    status, body, _ = request('/content/facilities', data)
    result = json.loads(body)
    check(status == 200 and result.get('ok'), 'Facility created through HTTP: ' + name)
    facilities.append(int(re.search(r'edit=(\d+)', result['redirect'])[1]))
    status, page, _ = request(result['redirect'])
    check(status == 200 and name + '-' + suffix in page, 'Saved facility reloads: ' + name)
# Follow actual editorial writes through to anonymous public rendering.
published_pages = []
for index, (state, visibility, date) in enumerate([
    ('published', 'public', ''), ('published', 'public', ''),
    ('draft', 'public', ''), ('published', 'private', ''),
    ('private', 'public', ''), ('scheduled', 'public', '2099-01-01T09:00')
]):
    facility_index = 1 if index == 1 else 0
    slug = 'published-' + suffix if index < 2 else 'hidden-' + str(index) + '-' + suffix
    title = 'Editorial page ' + str(index) + ' ' + suffix
    public_path = '/qa-page-' + str(index) + '-' + suffix
    status, body, _ = request('/content/pages', {
        'csrf': token, 'facility_id': facilities[facility_index], 'status': state,
        'visibility': visibility, 'published_at': date, 'template': 'default',
        'public_path': public_path,
        'translations[en][title]': title, 'translations[en][slug]': slug,
        'translations[pl][title]': 'Strona ' + str(index) + ' ' + suffix,
        'translations[pl][slug]': slug,
    })
    result = json.loads(body)
    check(status == 200 and result.get('ok'), 'Editorial page saved: ' + str(index))
    page_id = int(re.search(r'/pages/(\d+)/edit', result['redirect'])[1])
    block = {'uid': str(uuid.uuid4()), 'type': 'text', 'visible': True, 'shared': {}, 'localized': {
        'en': {'title': 'Section ' + str(index), 'text': '<p>Published content ' + str(index) + ' ' + suffix + '</p><script>alert(1)</script>'},
        'pl': {'title': 'Sekcja', 'text': '<p>Polska treść ' + suffix + '</p>'},
    }}
    payload = {'csrf': token, 'version': 0, 'blocks': [block]}
    status, body, _ = request('/content/builder/' + str(page_id), {**payload, 'csrf': 'invalid'}, True)
    check(status == 419, 'Builder rejects invalid CSRF: ' + str(index))
    status, body, _ = request('/content/builder/' + str(page_id), payload, True)
    check(status == 200 and json.loads(body).get('ok'), 'Builder saves content: ' + str(index))
    status, _, _ = request('/content/builder/' + str(page_id), payload, True)
    check(status == 409, 'Stale builder version cannot overwrite content: ' + str(index))
    status, editor, _ = request('/content/builder?document=' + str(page_id))
    check(status == 200 and title in editor, 'Builder reloads saved document: ' + str(index))
    check(public_path in editor, 'Builder preview uses installation-owned public path: ' + str(index))
    owner_session = http
    http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()))
    route = '/en/facilities/london/' + ['north-', 'south-'][facility_index] + suffix + '/' + slug
    status, public, headers = request(route)
    visible = index in [0, 1]
    check(status == (200 if visible else 404), 'Anonymous publication boundary: ' + str(index))
    clean_status, clean_page, _ = request(public_path)
    check(clean_status == (200 if visible else 404), 'Clean URL preserves publication boundary: ' + str(index))
    check((title in clean_page) == visible, 'Clean URL preserves facility content isolation: ' + str(index))
    if visible:
        check('rel="canonical" href="https://www.sensecms.com' + public_path + '"' in clean_page, 'Clean URL is the canonical address: ' + str(index))
    if visible:
        check(title in public and 'Published content ' + str(index) + ' ' + suffix in public, 'Public content matches facility and saved blocks: ' + str(index))
        check("frame-ancestors 'self'" in headers.get('Content-Security-Policy', ''), 'Same-origin builder preview allowed: ' + str(index))
        check('<script>alert(1)</script>' not in public and 'alert(1)' not in public, 'Executable editorial HTML removed: ' + str(index))
        status, localized, _ = request(route.replace('/en/', '/pl/', 1))
        check(status == 200 and 'Polska treść ' + suffix in localized and 'lang="pl"' in localized, 'Localized publication: ' + str(index))
        with http.open(urllib.request.Request(base + route, method='HEAD'), timeout=20) as response:
            check(response.status == 200 and response.read() == b'', 'Published page HEAD: ' + str(index))
    else:
        check(title not in public, 'Nonpublic content absent from response: ' + str(index))
    http = owner_session
    published_pages.append(page_id)

status, body, _ = request('/content/categories', {'csrf': token, 'slug': 'news-' + suffix, 'translations[en][name]': 'Company news'})
check(status == 200 and json.loads(body).get('ok'), 'Own content category saved')
category_data = {'csrf': token, 'slug': 'meeting-' + suffix, 'name': 'Team meeting', 'color': '#8b5cf6', 'active': True}
status, body, _ = request('/api/calendar/categories', category_data, True)
result = json.loads(body)
check(status == 200 and result.get('ok'), 'Own calendar category saved through JSON API')
category = result['data']['category']
event_data = {'csrf': token, 'title': 'QA meeting ' + suffix, 'facility_id': facilities[0], 'event_type': category['slug'], 'timezone': 'Europe/London', 'start_at': '2027-02-15T09:00', 'end_at': '2027-02-15T10:00', 'visibility': 'facility', 'override_conflicts': True, 'channels': ['internal']}
status, body, _ = request('/api/calendar/events', event_data, True)
result = json.loads(body)
check(status == 200 and result.get('ok'), 'Calendar event saved with own category')
status, body, _ = request('/api/calendar/events?' + urllib.parse.urlencode({'from': '2027-02-01', 'to': '2027-03-01', 'event_type': category['slug']}))
events = json.loads(body)['data']['events']
check(status == 200 and len(events) == 1 and events[0]['title'] == event_data['title'], 'Calendar event reloads in category-filtered list')
status, body, _ = request('/api/calendar/categories', {**category, 'csrf': token, 'active': False}, True)
check(status == 200 and json.loads(body).get('ok'), 'Calendar category can be deactivated')
status, _, _ = request('/api/calendar/events', event_data, True)
check(status == 422, 'Deactivated category rejected for new events')
status, body, _ = request('/api/calendar/events', {**event_data, 'id': events[0]['id'], 'title': 'Updated QA meeting'}, True)
check(status == 200 and json.loads(body).get('ok'), 'Existing event editable with inactive category')
status, body, _ = request('/api/calendar/events/' + str(events[0]['id']) + '/cancel', {'csrf': token}, True)
check(status == 200 and json.loads(body).get('ok'), 'Calendar event cancelled without deleting history')

# Product navigation uses the same editorial settings without starting sessions.
for location in ['primary', 'footer', 'footer-connect']:
    status, _, _ = request('/content/navigation', {'csrf': 'invalid', 'location': location})
    check(status == 419, 'Navigation CSRF rejection: ' + location)
    navigation_data = {
        'csrf': token, 'location': location, 'items[0][visible]': 1, 'items[0][target]': '_blank',
        'items[0][translations][en][label]': 'Sense navigation ' + location + ' ' + suffix,
        'items[0][translations][en][url]': '/docs',
        'items[0][translations][pl][label]': 'Nawigacja ' + location + ' ' + suffix,
        'items[0][translations][pl][url]': '/contact',
    }
    status, body, _ = request('/content/navigation', {**navigation_data, 'items[0][translations][en][url]': 'javascript:alert(1)'})
    check(status == 422, 'Executable menu URL rejected: ' + location)
    status, body, _ = request('/content/navigation', navigation_data)
    check(status == 200 and json.loads(body).get('ok'), 'Navigation saved: ' + location)
    status, home, headers = request('/')
    check(status == 200 and navigation_data['items[0][translations][en][label]'] in home, 'Product site renders configured navigation: ' + location)
    check(headers.get('Set-Cookie') is None, 'Product navigation does not create a session: ' + location)
    if location == 'primary':
        status, body, _ = request('/content/navigation', {'csrf': token, 'location': location})
        check(status == 200 and json.loads(body).get('ok'), 'An intentionally empty menu can be saved')
        status, home, _ = request('/')
        empty_nav = re.search(r'<nav id="navigation"[^>]*>(.*?)</nav>', home, re.S)
        check(status == 200 and empty_nav is not None and not empty_nav[1].strip(), 'Empty saved menu does not restore hardcoded links')
        status, body, _ = request('/content/navigation', navigation_data)
        check(status == 200 and json.loads(body).get('ok'), 'Menu restored through the normal editor endpoint')

route = '/en/facilities/london/north-' + suffix + '/published-' + suffix
status, public, _ = request(route.replace('/en/', '/pl/', 1))
check(status == 200 and 'Nawigacja primary ' + suffix in public, 'Managed pages render localized shared navigation')
check('rel="noopener noreferrer"' in public, 'New-window menu links protect the opener')
status, sitemap, _ = request('/sitemap.xml')
check(status == 200 and '/docs/packages</loc>' in sitemap and '/qa-page-0-' + suffix + '</loc>' in sitemap, 'Sitemap combines product and canonical content URLs')
ET.fromstring(sitemap)
check(all('hidden-' + str(index) + '-' + suffix not in sitemap for index in [2, 3, 4, 5]), 'Sitemap excludes draft, private and scheduled-future pages')
status, body, _ = request('/seo/page', {'csrf': token, 'document_type': 'page', 'document_id': published_pages[0],
    'seo[en][title]': 'SEO title ' + suffix, 'seo[en][description]': 'SEO description ' + suffix,
    'seo[en][og_title]': 'Social title ' + suffix, 'seo[en][robots]': 'noindex,follow'})
check(status == 200 and json.loads(body).get('ok'), 'Document SEO saved through the panel endpoint')
status, public, _ = request(route)
check(status == 200 and '<title>SEO title ' + suffix + '</title>' in public, 'Public page uses saved SEO title')
check('name="description" content="SEO description ' + suffix + '"' in public, 'Public page uses saved SEO description')
check('property="og:title" content="Social title ' + suffix + '"' in public, 'Saved social sharing metadata renders')
check('name="robots" content="noindex,follow"' in public, 'Saved indexing preference renders')
check('hreflang="pl"' in public and '/theme-assets/sensecms/images/' not in public, 'Language alternatives render without obsolete default imagery')
schema = re.search(r'<script type="application/ld\+json"[^>]*>(.*?)</script>', public, re.S)[1]
check(json.loads(schema)['@context'] == 'https://schema.org', 'Managed page emits valid structured metadata')
status, sitemap, _ = request('/sitemap.xml')
check(status == 200 and '/qa-page-0-' + suffix + '</loc>' not in sitemap and route.replace('/en/', '/pl/', 1) + '</loc>' in sitemap, 'Sitemap respects per-language noindex without hiding other translations')
status, robots, _ = request('/robots.txt')
check(status == 200 and 'Disallow: /content/' in robots and 'Sitemap:' in robots, 'Robots advertises combined sitemap and excludes administration')

# Exercise the request-level facility guard with a real non-owner login.
status, page, _ = request('/system/access?tab=roles')
permission_ids = {name: value for value, name in re.findall(r'name="permission_ids\[\]" value="(\d+)"[^>]*><span><b>([^<]+)</b>', page)}
required = ['Access console', 'View facilities', 'Manage facilities', 'View calendar', 'Manage calendar']
check(all(name in permission_ids for name in required), 'Permission catalog exposes scoped operations')
status, body, _ = request('/system/access/roles', {'csrf': token, 'name': 'QA scoped manager', 'slug': 'qa-scoped-' + suffix, 'active': 1, 'permission_ids[]': [permission_ids[name] for name in required]})
result = json.loads(body)
check(status == 200 and result.get('ok'), 'Scoped role created through HTTP')
role_id = int(re.search(r'edit_role=(\d+)', result['redirect'])[1])
password = secrets.token_hex(24)
user_data = {'csrf': token, 'name': 'QA scoped manager', 'email': 'qa-' + suffix + '@example.test', 'password': password, 'role_id': role_id, 'facility_ids[]': [facilities[0]]}
# Create disabled to avoid outbound invitations, then activate the existing QA user.
status, body, _ = request('/system/access/users', user_data)
result = json.loads(body)
check(status == 200 and result.get('ok'), 'Scoped QA user created without outbound invitation')
user_data['id'] = int(re.search(r'edit_user=(\d+)', result['redirect'])[1])
user_data['active'] = 1
status, body, _ = request('/system/access/users', user_data)
check(status == 200 and json.loads(body).get('ok'), 'Scoped QA user activated')
owner_http = http
http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()))
status, page, _ = request('/login')
scoped_token = re.search(r'name="csrf" value="([a-f0-9]{64})"', page)[1]
status, body, _ = request('/login', {'csrf': scoped_token, 'email': user_data['email'], 'password': password})
check(status == 200 and json.loads(body).get('ok'), 'Scoped user authenticates')
status, _, _ = request('/appearance/releases/activate', {'csrf': scoped_token, 'directory': active_release['directory']})
check(status == 403, 'Theme activation denied without appearance permission')
status, _, _ = request('/appearance/releases/rollback', {'csrf': scoped_token})
check(status == 403, 'Theme rollback denied without appearance permission')
status, page, _ = request('/content/facilities?edit=' + str(facilities[0]))
check(status == 200 and 'north-' + suffix in page, 'Scoped user can edit assigned facility')
scoped_token = re.search(r'name="csrf" value="([a-f0-9]{64})"', page)[1]
status, _, _ = request('/content/facilities?edit=' + str(facilities[1]))
check(status == 403, 'Unassigned facility GET denied')
for action in ['draft', 'publish', 'discard', 'preview']:
    status, _, _ = request(theme_endpoint + action, None if action == 'preview' else {'csrf': scoped_token, 'settings': {}}, True)
    check(status == 403, 'Scoped user cannot access global theme ' + action)
status, _, _ = request('/content/facilities', {'csrf': scoped_token, 'id': facilities[1], 'city_slug': 'london', 'facility_slug': 'changed', 'translations[en][name]': 'Unauthorized', 'translations[en][city_name]': 'London'})
check(status == 403, 'Unassigned facility POST denied before mutation')
status, _, _ = request('/api/calendar/categories', {**category_data, 'csrf': scoped_token, 'slug': 'forbidden-' + suffix}, True)
check(status == 403, 'Calendar category management requires its own permission')
status, _, _ = request('/api/calendar/events', {**event_data, 'csrf': scoped_token, 'event_type': 'general', 'facility_id': facilities[1]}, True)
check(status == 422, 'Calendar creation outside assigned facility rejected')
http = owner_http
status, page, _ = request('/content/facilities?edit=' + str(facilities[1]))
check(status == 200 and 'south-' + suffix in page and 'Unauthorized' not in page, 'Rejected cross-facility write leaves data unchanged')
print(f'Completed {checks} Workspace HTTP checks.')
