"""Read-only production acceptance for the Bluesky Publisher workspace and OAuth start."""
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
    with client.open(req, timeout=30) as response:
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
    assert status == 200 and b'Bluesky account' in overview and b'0.2.1' in overview, 'Bluesky provider missing from overview'
    status, page, _ = request('/social-publishing/bluesky')
    assert status == 200, 'Bluesky Publisher page unavailable'
    csrf = re.search(rb'name="csrf" value="([a-f0-9]{64})"', page)[1].decode()
    for marker in (b'social-bluesky-page', b'Add Bluesky account', b'data-bluesky-oauth-modal', b'0.1.3'):
        assert marker in page, f'Bluesky page marker missing: {marker.decode()}'
    status, body, _ = request('/social-publishing/bluesky/status')
    data = json.loads(body)
    assert status == 200 and data['ok'] and data['data']['connected'] and data['data']['connected_count'] >= 1, 'Connected Bluesky account missing'
    status, body, _ = request('/api/social-publishing/editor?post_id=0')
    editor = json.loads(body)
    provider = next(item for item in editor['data']['providers'] if item['slug'] == 'bluesky-publisher')
    assert provider['max_message_length'] == 300 and len(provider['connections']) == data['data']['connected_count'], 'Bluesky editor provider contract is invalid'
    status, css, headers = request('/extension-assets/plugin/bluesky-publisher/bluesky.css?v=0.1.3')
    assert status == 200 and b'.bluesky-oauth-modal' in css and 'text/css' in headers.get_content_type(), 'Bluesky stylesheet unavailable'
    status, script, headers = request('/extension-assets/plugin/bluesky-publisher/bluesky.js?v=0.1.3')
    assert status == 200 and b'sensecms.bluesky.oauth' in script and 'javascript' in headers.get_content_type(), 'Bluesky script unavailable'
    status, body, _ = request('/social-publishing/bluesky/connect', {'csrf': csrf})
    start = json.loads(body)
    assert status == 200 and start['ok'], 'Bluesky OAuth start failed'
    authorize = urllib.parse.urlparse(start['authorize_url'])
    assert authorize.scheme == 'https' and authorize.netloc == 'www.sensecms.com' and authorize.path == '/api/social/bluesky/v1/authorize', 'Unsafe Bluesky authorize URL'

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            return None

    direct = urllib.request.build_opener(NoRedirect())
    try:
        direct.open(start['authorize_url'], timeout=30)
        raise AssertionError('Bluesky authorization endpoint did not redirect')
    except urllib.error.HTTPError as error:
        location = error.headers.get('Location', '')
        assert error.code == 302 and location.startswith('https://bsky.social/oauth/authorize?'), 'Bluesky PAR redirect invalid'
finally:
    try:
        request('/logout', {'csrf': csrf})
    except urllib.error.HTTPError as error:
        if error.code != 419:
            raise

print('PASS Production Bluesky workspace, assets and live PAR authorization start are healthy.')
