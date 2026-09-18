"""Production acceptance for X OAuth up to, but not including, user consent."""
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


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, file_pointer, code, message, headers, new_url):
        return None


follow = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
stop = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect())


def request(path, fields=None, redirects=True, headers=None):
    data = urllib.parse.urlencode(fields).encode() if fields is not None else None
    req = urllib.request.Request(base + path, data=data, headers=headers or ({'Accept': 'application/json'} if fields is not None else {}))
    try:
        response = (follow if redirects else stop).open(req, timeout=25)
    except urllib.error.HTTPError as error:
        response = error
    with response:
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
    status, page, _ = request('/social-publishing/x')
    assert status == 200
    csrf = re.search(rb'name="csrf" value="([a-f0-9]{64})"', page)[1].decode()
    status, body, _ = request('/social-publishing/x/connect', {'csrf': csrf}, True, {'Accept': 'application/json', 'X-SenseCMS-Request': '1'})
    assert status == 200
    response = json.loads(body)
    assert response['ok'] is True
    location = response['authorize_url']
    parts = urllib.parse.urlparse(location)
    query = urllib.parse.parse_qs(parts.query)
    assert parts.scheme == 'https' and parts.netloc == 'www.sensecms.com' and parts.path == '/api/social/x/v1/authorize'
    assert re.fullmatch(r'[A-Za-z0-9_-]{43}', query['request'][0])
    status, _, headers = request(parts.path + '?' + parts.query, None, False)
    assert status == 302
    x = urllib.parse.urlparse(headers['Location'])
    params = urllib.parse.parse_qs(x.query)
    private = json.loads((web / 'storage/private/x/config.json').read_text())
    assert x.scheme == 'https' and x.netloc == 'x.com' and x.path == '/i/oauth2/authorize'
    assert params['response_type'] == ['code'] and params['client_id'] == [private['client_id']]
    assert params['redirect_uri'] == [base + '/api/social/x/v1/callback']
    assert set(params['scope'][0].split()) == {'tweet.read', 'tweet.write', 'users.read', 'offline.access'}
    assert params['state'] == query['request'] and params['code_challenge_method'] == ['S256']
    assert re.fullmatch(r'[A-Za-z0-9_-]{43}', params['code_challenge'][0])
finally:
    try:
        request('/logout', {'csrf': csrf})
    except urllib.error.HTTPError as error:
        if error.code != 419:
            raise

print('PASS Production X OAuth reaches the exact PKCE consent URL without submitting authorization.')
