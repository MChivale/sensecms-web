"""Real HTTP acceptance; retrieve the existing operator licence only in memory."""
import hashlib
import http.cookiejar
import json
import re
from pathlib import Path
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

base = sys.argv[1]
calendar = sys.argv[2:] == ['--calendar']
if sys.argv[2:] and not calendar:
    raise SystemExit('Unexpected test arguments')
if base not in ('http://127.0.0.1:8873', 'https://www.sensecms.com'):
    raise SystemExit('Unexpected test target')
qa = Path('/root/sense-workspace-test.SC495Gg0')
candidate = qa / 'candidate-038'
web = Path('/home/sensecms.com/web') if base.startswith('https:') else qa / '.cms/source'
http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
count = 0

def check(ok, name):
    global count
    if not ok:
        raise RuntimeError(name)
    count += 1
    print('PASS ' + name, flush=True)

def request(data=None, path='/packages/download', origin=None):
    headers = {'Accept': 'application/json'}
    if origin:
        headers['Origin'] = origin
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    try:
        with http.open(urllib.request.Request(base + path, data=body, headers=headers), timeout=30) as response:
            return response.status, response.read(), response.headers
    except urllib.error.HTTPError as response:
        return response.code, response.read(), response.headers

status, body, headers = request()
data = json.loads(body)
offer = data['products']['theme:sensecms']
check(status == 200 and set(data['products']) == ({'theme:sensecms', 'addon:calendar'} if calendar else {'theme:sensecms'}), 'only approved releases offered')
check('no-store' in headers['Cache-Control'] and 'HttpOnly' in headers['Set-Cookie'], 'private response and protected session cookie')
check('file' not in offer and 'license_key' not in data, 'no private file or key disclosure')
payload = {'product': 'theme:sensecms', 'domain': 'https://www.sensecms.com', 'license_key': 'invalid', 'csrf': data['csrf']}
check(request(dict(payload, csrf='bad'))[0] == 419, 'invalid CSRF rejected before validation')
check(request(payload, origin='https://example.test')[0] == 419, 'cross-origin submission rejected')
check(request(dict(payload, product='plugin:unpublished'))[0] == 404, 'unpublished package rejected')
check(request(payload)[0] == 403, 'invalid key rejected')
check(request(path='/packages/download?license_key=invalid')[0] == 400, 'query credentials rejected')
check(request(path='/storage/distribution/releases/theme-sensecms-0.3.8.zip')[0] == 404, 'private archive not anonymously accessible')
code = r'''$base=$argv[1].'/storage/license/';$secret=file_get_contents($base.'key.bin');$raw=file_get_contents($base.'license.lic');$box=base64_decode(substr($raw,strlen("SENSECMS-LIC-1\n")));$plain=sodium_crypto_secretbox_open(substr($box,24),substr($box,0,24),$secret);$data=json_decode($plain,true,16,JSON_THROW_ON_ERROR);echo $data['key'];sodium_memzero($plain);sodium_memzero($secret);'''
# This subprocess's stdout is consumed in memory, never printed or written to logs.
key = subprocess.check_output(['php8.5', '-r', code, str(web)]).decode()
payload['license_key'] = key
if calendar:
    check(request(dict(payload, product='addon:calendar'))[0] == 403, 'real CMS key cannot download paid Calendar')
status, body, headers = request(payload)
payload['license_key'] = ''; key = ''
check(status == 200 and headers['Content-Type'].startswith('application/zip'), 'real CMS licence returns ZIP bytes')
check(hashlib.sha256(body).hexdigest() == offer['sha256'] and len(body) == offer['bytes'], 'downloaded bytes match trusted release')
check('attachment;' in headers['Content-Disposition'] and headers['X-Package-SHA256'] == offer['sha256'], 'download response metadata matches release')
download = candidate / (('calendar-check-theme-production.zip' if base.startswith('https:') else 'calendar-check-theme-qa.zip') if calendar else ('download-production.zip' if base.startswith('https:') else 'download-qa.zip'))
with download.open('xb') as handle:
    handle.write(body)
if calendar:
    # Operator passes the authorized key through SSH stdin, never argv or a server file.
    raw = sys.stdin.read(4096)
    match = re.search(r'^Sense CMS Calendar:\s*([A-Za-z0-9]{32})\s*$', raw, re.M)
    if not match:
        raise RuntimeError('Calendar key missing from private stdin')
    calendar_key = match[1]; raw = ''; match = None
    payload['license_key'] = calendar_key
    check(request(payload)[0] == 403, 'Calendar key cannot substitute for CMS key on free theme')
    payload['product'] = 'addon:calendar'
    check(request(dict(payload, license_key='invalid'))[0] == 403, 'invalid Calendar key rejected')
    status, body, headers = request(payload)
    payload['license_key'] = ''; calendar_key = ''
    offer = data['products']['addon:calendar']
    check(status == 200 and headers['Content-Type'].startswith('application/zip'), 'real Calendar licence returns paid ZIP')
    check(offer['pricing'] == 'paid' and hashlib.sha256(body).hexdigest() == offer['sha256'] and len(body) == offer['bytes'], 'paid ZIP matches trusted release bytes')
    check(request(path='/storage/distribution/releases/addon-calendar-0.1.0.zip')[0] == 404, 'paid archive remains private')
    output = candidate / ('download-calendar-production.zip' if base.startswith('https:') else 'download-calendar-qa.zip')
    with output.open('xb') as handle:
        handle.write(body)
    accepted = candidate / 'install-acceptance'
    check(body == (accepted / 'addon-calendar-0.1.0.zip').read_bytes(), 'download is byte-identical to signed installation acceptance artifact')
    check(download.read_bytes() == (accepted / 'theme-sensecms-0.3.8.zip').read_bytes(), 'free theme unchanged during paid rollout')
    if not base.startswith('https:'):
        subprocess.run(['php8.5', str(qa / 'packages-candidate-20260907/tests/package-release.php'), str(accepted), '/root/sensecms-private/trust.json'], check=True)
        cfg = json.loads((web / 'storage/distribution.json').read_text())
        receipt = {'entry': cfg['products']['addon:calendar'], 'core': {name: hashlib.sha256((web / name).read_bytes()).hexdigest() for name in
                   ['public/index.php', 'app/Core/Packages/Distribution.php', 'app/Http/DistributionController.php', 'app/Core/LicenseClient.php', 'app/Core/Packages/Entitlement.php']}}
        (candidate / 'calendar-accepted.json').write_text(json.dumps(receipt))
elif not base.startswith('https:'):
    names = ['public/index.php','app/Core/Packages/Distribution.php','app/Http/DistributionController.php']
    inventory = {name: hashlib.sha256((candidate / '.cms/source' / name).read_bytes()).hexdigest() for name in names}
    inventory['theme'] = hashlib.sha256(b''.join(p.read_bytes() for p in sorted((candidate / '.themes/sensecms').rglob('*')) if p.is_file())).hexdigest()
    (candidate / 'distribution-accepted.json').write_text(json.dumps(inventory))
print(f'{count} real distribution HTTP checks passed; downloaded artifact retained privately.')
