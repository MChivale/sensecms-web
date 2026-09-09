"""Checks imported marketing pages and theme independence on the private QA fixture only."""
import http.cookiejar
import json
from pathlib import Path
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

root = Path(__file__).resolve().parents[1]
if root.name != 'sense-workspace-test.SC495Gg0':
    raise SystemExit('Use the established private QA fixture, never production.')
base = 'http://127.0.0.1:8873'
http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
count = 0

def request(path, data=None):
    headers = {'Accept': 'application/json'} if data is not None else {}
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    try:
        with http.open(urllib.request.Request(base + path, data=body, headers=headers), timeout=20) as res:
            return res.status, res.read().decode(), res.headers
    except urllib.error.HTTPError as res:
        return res.code, res.read().decode(), res.headers

def check(ok, label):
    global count
    if not ok:
        raise RuntimeError('FAIL ' + label)
    count += 1
    print('PASS ' + label, flush=True)

paths = ['/', '/platform', '/extensions', '/docs', '/docs/installation', '/docs/licensing', '/docs/packages',
         '/docs/server', '/download', '/contact', '/extensions/modules', '/extensions/plugins', '/extensions/addons',
         '/extensions/themes', '/docs/themes']
for path in paths:
    status, body, _ = request(path)
    check(status == 200 and '<h1>' in body, 'Managed product page ' + path)
    check('rel="canonical" href="https://www.sensecms.com' + path + '"' in body, 'Canonical product path ' + path)
    check(not re.search(r'href="[^" ]*#[^" ]+"', body.replace('href="#main"', '')), 'Real navigation URLs ' + path)
    with http.open(urllib.request.Request(base + path, method='HEAD'), timeout=20) as res:
        check(res.status == 200 and res.read() == b'', 'Product HEAD ' + path)
status, sitemap, _ = request('/sitemap.xml')
locations = [node.text for node in ET.fromstring(sitemap).findall('{*}url/{*}loc')]
for path in paths:
    check(locations.count('https://www.sensecms.com' + path) == 1, 'No duplicate canonical sitemap entry ' + path)

owner = json.loads((root / 'preview-private.json').read_text())
_, login, _ = request('/login')
token = re.search(r'name="csrf" value="([a-f0-9]{64})"', login)[1]
status, body, _ = request('/login', {'csrf': token, 'email': owner['email'], 'password': owner['password']})
check(status == 200 and json.loads(body).get('ok'), 'QA owner authenticated')
_, panel, _ = request('/content/pages?q=One%20foundation')
check('/platform' in panel, 'Pages list links to managed product URLs')
state = json.loads((root / '.cms/source/storage/theme.json').read_text())
active = state['active']
alternate = next(row for row in state['releases'].values() if row['slug'] == 'qa-switch-test')
_, dashboard, _ = request('/appearance/themes')
token = re.search(r'name="csrf" value="([a-f0-9]{64})"', dashboard)[1]
read_page = r'''
require $argv[1] . '/.cms/source/bootstrap.php';
$r = new App\Core\Runtime($argv[1] . '/.cms/source');
$cms = new App\Core\CmsRepository(App\Core\Runtime::connect($r->read('installed')['database']), new App\Core\EventBus(), $r);
echo json_encode($cms->pageAdmin((int) $cms->pageAtPath('/platform')['id']));
'''
document = json.loads(subprocess.run(['php', '-r', read_page, str(root)], check=True, capture_output=True, text=True).stdout)
data = {key: document.get(key) or '' for key in ['id','facility_id','template','status','visibility','published_at','public_path','parent_id']}
data['csrf'] = token
data['status'] = 'published'
data['published_at'] = str(data['published_at']).replace(' ', 'T')[:16]
for locale, translation in document['translations'].items():
    for key in ['title','slug','excerpt','seo_title','seo_description']:
        data[f'translations[{locale}][{key}]'] = translation.get(key) or ''
for invalid in ['/login', '/api/test', '/pl/page', '/docs#section']:
    status, _, _ = request('/content/pages', {**data, 'public_path': invalid})
    check(status == 422, 'Reserved public address rejected by editor ' + invalid)
status, _, _ = request('/content/pages', {**data, 'public_path': '/contact'})
check(status == 422, 'Two pages cannot claim the same public address')
status, _, _ = request('/content/pages', {**data, 'csrf': 'invalid'})
check(status == 419, 'Public address changes require CSRF')
try:
    status, _, _ = request('/content/pages', {**data, 'status': 'draft'})
    check(status == 200, 'Owner can withdraw a product page')
    status, withdrawn, _ = request('/platform')
    check(status == 404 and 'Your organisation, connected' not in withdrawn, 'Withdrawn page never reveals theme starter fallback')
    check('https://www.sensecms.com/platform</loc>' not in request('/sitemap.xml')[1], 'Withdrawn starter route leaves sitemap too')
finally:
    status, body, _ = request('/content/pages', data)
    check(status == 200 and json.loads(body).get('ok'), 'Restore product page through the editor: ' + str(json.loads(body).get('message', '')))
try:
    status, body, _ = request('/appearance/releases/activate', {'csrf': token, 'directory': alternate['directory']})
    check(status == 200 and json.loads(body).get('ok'), 'Alternate theme activates with managed product pages')
    status, page, _ = request('/platform')
    check(status == 200 and 'Your organisation, connected' in page and '<!-- QA alternate presentation -->' in page, 'Same stored page and clean URL render in another theme')
    status, panel, _ = request('/content/pages?q=One%20foundation')
    check(status == 200 and '/platform' in panel, 'Administration and page addresses survive a theme switch')
finally:
    status, body, _ = request('/appearance/releases/activate', {'csrf': token, 'directory': active['directory']})
    check(status == 200 and json.loads(body).get('ok'), 'Restore reviewed product theme')
check('<!-- QA alternate presentation -->' not in request('/platform')[1], 'Original presentation restored')
print(f'Completed {count} managed product HTTP checks.')
