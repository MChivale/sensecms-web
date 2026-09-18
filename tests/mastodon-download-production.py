"""Production paid-download acceptance using private local licence files in memory."""
import hashlib
import http.cookiejar
import json
from pathlib import Path
import urllib.error
import urllib.parse
import urllib.request

base = 'https://www.sensecms.com'
root = Path(__file__).resolve().parents[1]
http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def licence(path):
    fields = {}
    for line in path.read_text().splitlines():
        name, value = line.split(':', 1)
        fields[''.join(name.split())] = value.strip()
    assert len(fields.get('LicenseKey', '')) == 32
    return fields


def request(fields=None, path='/packages/download'):
    body = urllib.parse.urlencode(fields).encode() if fields is not None else None
    try:
        with http.open(urllib.request.Request(base+path,data=body,headers={'Accept':'application/json'}),timeout=30) as response:
            return response.status,response.read(),response.headers
    except urllib.error.HTTPError as error:
        return error.code,error.read(),error.headers


status, body, _ = request()
catalog = json.loads(body)
offer = catalog['products']['plugin:mastodon-publisher']
assert status == 200 and offer['pricing'] == 'paid' and offer['version'] == '0.1.1'
mastodon = licence(root/'.cfg/License-Mastodon.txt')
cms = licence(root/'.cfg/License.txt')
payload = {'product':'plugin:mastodon-publisher','domain':base,'csrf':catalog['csrf'],'license_key':'invalid'}
assert request(payload)[0] == 403
payload['license_key'] = cms['LicenseKey']
assert request(payload)[0] == 403, 'Core key must not authorize Mastodon'
payload['product'] = 'plugin:bluesky-publisher'
payload['license_key'] = mastodon['LicenseKey']
assert request(payload)[0] == 403, 'Mastodon key must not authorize Bluesky'
payload['product'] = 'plugin:mastodon-publisher'
status, archive, headers = request(payload)
payload['license_key'] = ''
mastodon.clear();cms.clear()
assert status == 200 and headers['Content-Type'].startswith('application/zip')
assert len(archive) == offer['bytes'] and hashlib.sha256(archive).hexdigest() == offer['sha256']
assert request(path='/storage/distribution/releases/plugin-mastodon-publisher-0.1.1.zip')[0] == 404
print('PASS Separate Mastodon licence, cross-product refusals and exact paid ZIP download.')
