"""Post-deployment read/check acceptance on the explicitly selected official installation."""
import base64
from http.cookiejar import CookieJar
import json
from pathlib import Path
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

web = Path('/home/sensecms.com/web')
base = 'https://www.sensecms.com'
assert json.loads((web / 'storage/installed.json').read_text())['base_url'] == base
jar = CookieJar()
http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

def request(path, fields=None):
    data = urllib.parse.urlencode(fields).encode() if fields is not None else None
    req = urllib.request.Request(base + path, data=data, headers={'Accept':'application/json'} if data is not None or path.startswith('/api/') else {})
    try:
        response = http.open(req, timeout=25)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.read(), response.headers

for path in ['/', '/update', '/extensions', '/contact', '/docs', '/login', '/theme-assets/updates.js']:
    status, body, headers = request(path)
    assert status == 200 and body and headers.get('Content-Security-Policy'), 'Public route ' + path
status, body, _ = request('/update')
assert body.index(b'subpage-hero') < body.index(b'data-update-center') < body.index(b'data-stable-releases')
assert b'data-site-update-form' not in body and b'Check my CMS' not in body
status, _, _ = request('/update-connect')
assert status == 404, 'Removed cross-domain landing'
status, body, _ = request('/api/updates/v1/catalog')
assert status == 200
feed = json.loads(base64.b64decode(json.loads(body)['signed_payload']))
assert feed['channel'] == 'stable' and feed['releases'] == [], 'No unaccepted Stable publication'
status, _, _ = request('/api/updates/v1/catalog', {})
assert status == 405
status, body, _ = request('/login')
csrf = re.search(rb'name="csrf" value="([a-f0-9]{64})"', body)[1].decode()
sid = next(c.value for c in jar if c.name == 'sensecms_session')
assert re.fullmatch(r'[A-Za-z0-9,-]{16,128}', sid)
captcha = subprocess.run(['runuser','-u','sensecms','--','php8.5','-r',
    'session_save_path($argv[1]);session_id($argv[2]);session_start(["read_and_close"=>true]);echo $_SESSION["sensecms_captcha_login_code"]??"";',
    str(web/'storage/sessions'),sid], capture_output=True, check=True).stdout.decode()
owner = json.loads(Path('/root/sensecms-private/owner.json').read_text())
status, body, _ = request('/login', dict(csrf=csrf,email=owner['email'],password=owner['password'],captcha=captcha))
del owner, captcha
assert status == 200 and json.loads(body)['ok']
try:
    status, body, _ = request('/system/update?bridge='+'a'*48)
    assert status == 200 and b'Check for updates' in body and b'data-update-bridge' not in body
    csrf = re.search(rb'data-csrf="([a-f0-9]{64})"',body)[1].decode()
    status, _, _ = request('/system/update',dict(csrf='invalid',action='check'))
    assert status == 419
    status, body, _ = request('/system/update',dict(csrf=csrf,action='check'))
    assert status == 200 and json.loads(body)['ok'], 'Live signed release check'
    state=json.loads(body)['data']
    version=subprocess.check_output(['php8.5','-r','echo (require $argv[1]."/config/product.php")["core_version"];',str(web)]).decode()
    assert state['verified'] and state['supported'] and not state['available'] and not state['install_supported'] and state['version']==version
    assert state['latest']==[] and state['checked_at']>0
    status, _, _ = request('/system/update',dict(csrf=csrf,action='install',version='9.9.9'))
    assert status == 503
    status, body, _ = request('/api/notifications')
    assert status == 200 and json.loads(body)['ok']
    assert all(item['title'] != 'SenseCMS update available' for item in json.loads(body)['data']['items'])
    for path in ['/dashboard','/content/pages','/system/access','/appearance/themes','/content/media','/system/extensions/popups']:
        status, body, _ = request(path)
        assert status == 200 and b'class="app-menu"' in body
        if path == '/system/extensions/popups':
            assert b'id="page-popup-form"' in body and b'Campaign content' in body
        if path == '/content/media':
            assert b'video 80 MiB' in body and b'total 95 MiB per upload' in body
            assert b'sensecms-media-library.js?v=20260910-upload-1' in body
    for path in ['/system/extensions?tab=modules','/content/posts','/content/navigation?location=footer','/content/builder']:
        status, body, _ = request(path)
        assert status == 200 and b'class="app-menu"' in body, path
        if path.startswith('/system/extensions'):
            assert b'built-in page sections' in body and b'15 portable SenseCMS modules' not in body
            assert b'Process steps' in body and b'Services' in body
        if path == '/content/posts': assert b'school stories' not in body and b'school story' not in body
        if path.startswith('/content/navigation'): assert b'{{site_name}}' in body and b'school profile' not in body
        if path == '/content/builder':
            assert b'Section library' in body and b'available sections' in body
            payload=re.search(rb'<script[^>]*data-builder-payload[^>]*>(.*?)</script>',body,re.S)
            assert payload, 'Actual builder catalogue must be present'
            catalog=json.loads(payload[1])['catalog']
            assert set(catalog)=={'text','custom-html','contact-form'}
finally:
    request('/logout',dict(csrf=csrf))
print('PASS Releases-only website, removed bridge, real owner check, CSRF, notifications and adjacent panel routes; no installation.')
