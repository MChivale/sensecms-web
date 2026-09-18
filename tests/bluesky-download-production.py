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
        with http.open(urllib.request.Request(base + path, data=body, headers={'Accept': 'application/json'}), timeout=30) as response:
            return response.status, response.read(), response.headers
    except urllib.error.HTTPError as error:
        return error.code, error.read(), error.headers


status, body, _ = request()
catalog = json.loads(body)
offer = catalog['products']['plugin:bluesky-publisher']
assert status == 200 and offer['pricing'] == 'paid' and offer['version'] == '0.1.3'
assert offer['sha256'] == '4bc55acc611e1e413a80e7df8bd772cce4dc186d4feb593078df030365888755'
bluesky = licence(root / '.cfg/License-Bluesky.txt')
cms = licence(root / '.cfg/License.txt')
payload = {'product': 'plugin:bluesky-publisher', 'domain': base, 'csrf': catalog['csrf'], 'license_key': 'invalid'}
assert request(payload)[0] == 403
payload['license_key'] = cms['LicenseKey']
assert request(payload)[0] == 403, 'Core key must not authorize Bluesky'
payload['product'] = 'theme:sensecms'
payload['license_key'] = bluesky['LicenseKey']
wrong_status = request(payload)[0]
assert wrong_status == 403, f'Bluesky key must not authorize another package; received HTTP {wrong_status}'
payload['product'] = 'plugin:bluesky-publisher'
status, archive, headers = request(payload)
payload['license_key'] = ''
bluesky.clear()
cms.clear()
assert status == 200 and headers['Content-Type'].startswith('application/zip')
assert len(archive) == offer['bytes'] and hashlib.sha256(archive).hexdigest() == offer['sha256']
assert request(path='/storage/distribution/releases/plugin-bluesky-publisher-0.1.3.zip')[0] == 404
print('PASS Separate Bluesky licence, cross-product refusals and exact paid ZIP download.')
