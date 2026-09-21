"""Read-only production acceptance for the LinkedIn Publisher workspace."""
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
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def request(path, fields=None):
    data = urllib.parse.urlencode(fields).encode() if fields is not None else None
    headers = {'Accept': 'application/json'} if fields is not None else {}
    req = urllib.request.Request(base + path, data=data, headers=headers)
    with client.open(req, timeout=25) as response:
        return response.status, response.read(), response.headers


status, body, _ = request('/login')
assert status == 200, 'Login page unavailable'
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
assert status == 200 and json.loads(body)['ok'], 'Owner login failed'

try:
    status, overview, _ = request('/social-publishing')
    assert status == 200, 'Social Publishing overview unavailable'
    for marker in (b'sensecms-unified-workspace', b'LinkedIn profile', b'0.3.0'):
        assert marker in overview, f'Overview marker missing: {marker.decode()}'
    status, page, _ = request('/social-publishing/linkedin')
    assert status == 200, 'LinkedIn Publisher page unavailable'
    csrf = re.search(rb'name="csrf" value="([a-f0-9]{64})"', page)[1].decode()
    for marker in (b'social-linkedin-page', b'Add LinkedIn profile', b'data-linkedin-oauth-modal', b'social-account-meta', b'social-steps', b'social-panel-footer', b'social-disconnect', b'0.1.2'):
        assert marker in page, f'LinkedIn page marker missing: {marker.decode()}'
    status, body, _ = request('/social-publishing/linkedin/status')
    data = json.loads(body)
    assert status == 200 and data['ok'] and data['data']['connected'] and data['data']['connected_count'] >= 1, 'Connected LinkedIn profile missing'
    status, body, _ = request('/api/social-publishing/editor?post_id=0')
    editor = json.loads(body)
    provider = next(item for item in editor['data']['providers'] if item['slug'] == 'linkedin-publisher')
    assert provider['max_message_length'] == 3000 and len(provider['connections']) == data['data']['connected_count'], 'LinkedIn editor provider contract is invalid'
    status, css, headers = request('/extension-assets/plugin/linkedin-publisher/linkedin.css?v=0.1.2')
    assert status == 200 and b'.linkedin-oauth-modal' in css and 'text/css' in headers.get_content_type(), 'LinkedIn stylesheet unavailable or invalid'
    status, script, headers = request('/extension-assets/plugin/linkedin-publisher/linkedin.js?v=0.1.2')
    assert status == 200 and b'sensecms.linkedin.oauth' in script and 'javascript' in headers.get_content_type(), 'LinkedIn script unavailable or invalid'
finally:
    try:
        request('/logout', {'csrf': csrf})
    except urllib.error.HTTPError as error:
        if error.code != 419:
            raise

print('PASS Production LinkedIn Publisher UI, connected profile and versioned assets are healthy.')
