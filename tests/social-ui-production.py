"""Read-only production acceptance for the connected Social Publishing workspace."""
from http.cookiejar import CookieJar
import json
from pathlib import Path
import re
import subprocess
import urllib.parse
import urllib.request

web = Path('/home/sensecms.com/web')
base = 'https://www.sensecms.com'
assert json.loads((web / 'storage/installed.json').read_text())['base_url'] == base
jar = CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def request(path, fields=None):
    data = urllib.parse.urlencode(fields).encode() if fields is not None else None
    headers = {'Accept': 'application/json'} if fields is not None else {}
    req = urllib.request.Request(base + path, data=data, headers=headers)
    with client.open(req, timeout=25) as response:
        return response.status, response.read(), response.headers


status, body, _ = request('/login')
assert status == 200
csrf = re.search(rb'name="csrf" value="([a-f0-9]{64})"', body)[1].decode()
sid = next(cookie.value for cookie in jar if cookie.name == 'sensecms_session')
captcha = subprocess.run([
    'runuser', '-u', 'sensecms', '--', 'php8.5', '-r',
    'session_save_path($argv[1]);session_id($argv[2]);session_start(["read_and_close"=>true]);echo $_SESSION["sensecms_captcha_login_code"]??"";',
    str(web / 'storage/sessions'), sid,
], capture_output=True, check=True).stdout.decode()
owner = json.loads(Path('/root/sensecms-private/owner.json').read_text())
status, body, _ = request('/login', {'csrf': csrf, 'email': owner['email'], 'password': owner['password'], 'captcha': captcha})
del owner, captcha
assert status == 200 and json.loads(body)['ok']

try:
    status, overview, _ = request('/social-publishing')
    assert status == 200
    for marker in (b'sensecms-unified-workspace', b'social-publishing-stats', b'social-provider-identity', b'social-table-empty', b'0.2.1'):
        assert marker in overview
    status, facebook, _ = request('/social-publishing/facebook')
    assert status == 200
    csrf = re.search(rb'name="csrf" value="([a-f0-9]{64})"', facebook)[1].decode()
    for marker in (b'social-facebook-page', b'social-account-list', b'social-account-card', b'Add Facebook Page', b'social-steps', b'data-facebook-connected="1"', b'0.2.1'):
        assert marker in facebook
    assert re.search(rb'action="/social-publishing/facebook/connections/\d+/disconnect"', facebook)
    status, body, _ = request('/social-publishing/facebook/status')
    status_data = json.loads(body)
    assert status == 200 and status_data['ok'] and status_data['data']['connected'] and status_data['data']['connected_count'] >= 1
    assert len(status_data['data']['connection_ids']) == status_data['data']['connected_count'] and isinstance(status_data['data']['revision'], int)
    status, body, _ = request('/api/social-publishing/editor?post_id=0')
    editor = json.loads(body)
    facebook_provider = next(item for item in editor['data']['providers'] if item['slug'] == 'facebook-publisher')
    assert len(facebook_provider['connections']) == status_data['data']['connected_count']
    status, css, headers = request('/extension-assets/addon/social-publishing/social.css?v=0.2.0')
    assert status == 200 and b'.social-account-list' in css and 'text/css' in headers.get_content_type()
    status, script, headers = request('/extension-assets/plugin/facebook-publisher/facebook.js?v=0.2.1')
    assert status == 200 and b'facebookOauthRevision' in script and 'javascript' in headers.get_content_type()
finally:
    request('/logout', {'csrf': csrf})

print('PASS Production Social Publishing UI, connected Facebook Page and versioned assets are healthy.')
