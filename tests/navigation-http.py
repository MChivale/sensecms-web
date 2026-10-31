"""Real navigation/controller tests; creates and drops an isolated MariaDB schema."""
import http.cookiejar
import html
import json
import os
from pathlib import Path
import re
import secrets
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

assert os.name != 'nt', 'Run in private Linux MariaDB QA'
fixture = Path(__file__).with_name('navigation-fixture.php')
checks = 0
def check(ok, label):
    global checks
    if not ok: raise RuntimeError('FAIL ' + label)
    checks += 1
    print('PASS ' + label, flush=True)

with tempfile.TemporaryDirectory(prefix='sense-nav-qa-') as temp:
    env = dict(os.environ, SENSE_NAV_TEST_DB='sensenav_' + secrets.token_hex(6), SENSE_NAV_TEST_PASSWORD=secrets.token_hex(16), SENSE_NAV_TEST_SESSIONS=temp)
    server = None
    try:
        setup = subprocess.run(['php8.5', str(fixture), 'setup'], env=env, capture_output=True, text=True)
        assert setup.returncode == 0, setup.stderr
        ids = json.loads(setup.stdout)['facilities']
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        with open(Path(temp) / 'http.log', 'w') as log:
            server = subprocess.Popen(['php8.5', '-S', f'127.0.0.1:{port}', str(fixture)], env=env, stdout=log, stderr=log)
        base = f'http://127.0.0.1:{port}'
        for _ in range(50):
            try:
                with socket.create_connection(('127.0.0.1', port), .1): break
            except OSError: time.sleep(.1)
        def client():
            opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            def request(path, fields=None):
                body = None if fields is None else urllib.parse.urlencode(fields).encode()
                try: res = opener.open(urllib.request.Request(base + path, data=body, headers={'Accept':'application/json'} if fields else {}), timeout=20)
                except urllib.error.HTTPError as error: res = error
                with res: return res.status, res.read().decode(), res.geturl()
            return request
        owner = client(); editor = client()
        token = json.loads(owner('/login', {'password':env['SENSE_NAV_TEST_PASSWORD']})[1])['csrf']
        editor_token = json.loads(editor('/login', {'email':'editor@example.test','password':env['SENSE_NAV_TEST_PASSWORD']})[1])['csrf']
        status, page, _ = owner('/content/pages')
        check(status == 200 and 'Alpha page' in page and 'Beta page' in page, 'All-facilities lists include both units')
        check('aria-label="Filter by facility"' in page and 'All facilities · shared workspace' not in page, 'All-facilities scope keeps its optional filter and does not imply global settings')
        check(page.count('<h1>Pages</h1>') == 1 and '<h2>Pages</h2>' not in page, 'Pages has one primary heading and a compact action strip')
        menu = page[page.index('<ul class="side-nav'):page.index('</ul>', page.index('<ul class="side-nav'))]
        order = ['/dashboard','/content/facilities','/content/workflow','/content/pages','/content/builder','/content/posts','/content/categories','/content/media','/content/navigation','/seo']
        check([menu.index('href="'+p+'"') for p in order] == sorted(menu.index('href="'+p+'"') for p in order), 'Workspace and Content navigation follow the agreed order')
        check(owner('/workspace/context', {'csrf':'invalid','facility_id':ids[1]})[0] == 419, 'Context switching rejects invalid CSRF')
        check(owner('/workspace/context', {'csrf':token,'facility_id':999999})[0] == 403, 'Unknown facility selection is rejected')
        status, page, url = owner('/workspace/context', {'csrf':token,'facility_id':ids[1],'return':'/content/pages?facility=1&page=4'})
        check(status == 200 and 'Beta page' in page and 'Alpha page' not in page, 'Selected facility narrows the real pages query')
        check('aria-label="Filter by facility"' not in page, 'Specific-facility context removes the redundant filter')
        check(url.endswith('/content/pages'), 'Switching removes stale facility and pagination filters')
        state = json.loads(owner('/context-state')[1])
        check(state['scope'] == [ids[1]] and state['authorized'] is None, 'Working context narrows lists without changing owner permissions')
        page=owner('/content/facilities?lang=pl')[1]
        check('Current facility: Beta' in page and '>Current facility</span>' in page and 'aria-label="Work in Alpha"' in page, 'Facility directory distinguishes current context from the visible Work here action')
        check('aria-current="page" href="/content/facilities"' in page and 'aria-label="Facility sections"' in page, 'Facility directory announces its active section')
        owner('/content/facilities?edit='+str(ids[0])+'&lang=pl')
        check(json.loads(owner('/context-state')[1])['id']==ids[1], 'Editing another facility does not change the working context')
        page=owner('/content/facilities?tab=geolocation&lang=pl')[1]
        check('aria-current="page" href="/content/facilities?tab=geolocation"' in page, 'Geolocation announces its own active section')
        page = owner('/content/posts')[1]
        check('Beta post' in page and 'Alpha post' not in page, 'Facility context persists across content sections')
        page = owner('/content/posts/new')[1]
        form = re.search(r'<form[^>]*id="content-post-form".*?</form>', page, re.S)[0]
        field = re.search(r'<select[^>]*name="facility_id".*?</select>', form, re.S)[0]
        check(f'value="{ids[1]}"' in field and f'value="{ids[0]}"' not in field, 'New content defaults to the chosen facility')
        page = owner('/content/pages?facility='+str(ids[0]))[1]
        check('Alpha page' not in page, 'A stale list filter cannot override working context')
        page = owner('/content/pages/2/edit?return='+urllib.parse.quote('/content/pages?q=Beta&status=published', safe=''))[1]
        check('href="/content/pages?q=Beta&amp;status=published"' in page, 'Editor Back preserves the original query and status')
        check('aria-label="Current page tools"' in page and '/seo?type=page&amp;document=2' in page, 'Document tools retain page identity and provide contextual SEO navigation')
        status, page, _ = owner('/content/pages/1/edit')
        check(status == 200 and json.loads(owner('/context-state')[1])['id'] == ids[0], 'Authorized direct record links align the working context')
        status, page, _ = editor('/content/pages')
        check(status == 200 and 'Alpha page' in page and 'Beta page' not in page, 'All-facilities mode respects restricted-user assignments')
        check(editor('/workspace/context', {'csrf':editor_token,'facility_id':ids[1]})[0] == 403, 'Context selection cannot grant access to an unassigned facility')
        check(editor('/content/pages/2/edit')[0] == 403, 'Direct record navigation cannot bypass facility authorization')
        editor('/workspace/context', {'csrf':editor_token,'facility_id':0,'return':'/content/pages'})
        check(json.loads(editor('/context-state')[1])['scope'] == [ids[0]], 'Returning to all facilities retains the permission boundary')
        status, _, url = owner('/workspace/context', {'csrf':token,'facility_id':0,'return':'https://example.invalid/steal'})
        check(status == 200 and url == base+'/dashboard', 'Context return URLs cannot redirect to another origin')
        for path in ['/content/posts/1/edit','/content/pages/new','/content/media','/surveys','/content/workflow','/content/facilities','/seo']:
            status, page, _ = owner(path)
            check(status == 200 and 'data-workspace-id=' in page and 'Warning:' not in page, 'Context-aware screen renders: '+path)
        for path in ['/content/pages/new','/content/posts/new','/content/facilities','/seo']:
            status,page,_=owner(path+'?lang=pl')
            check(status == 200 and 'data-language-tab="pl" aria-selected="true"' in page, 'Polish is selected consistently: '+path)
            status,page,_=owner(path+'?lang=invalid')
            check(status == 200 and 'data-language-tab="en" aria-selected="true"' in page, 'Invalid language falls back to the enabled default: '+path)
        log = (Path(temp) / 'http.log').read_text()
        check(not re.search(r'PHP (Warning|Fatal|Notice|Deprecated)',log), 'HTTP navigation has no PHP warnings or errors')
        print(f'{checks} isolated navigation HTTP checks passed.')
    finally:
        if server: server.terminate(); server.wait(timeout=10)
        subprocess.run(['php8.5', str(fixture), 'drop'], env=env, check=True, capture_output=True)
