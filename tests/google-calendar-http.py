"""Real licensed download acceptance, independent of external calendar delivery."""
import hashlib
import http.cookiejar
import json
from pathlib import Path
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

microsoft = '--microsoft' in sys.argv[1:]
apple = '--apple' in sys.argv[1:]
if microsoft and apple:
    raise SystemExit('Choose one provider')
args = [arg for arg in sys.argv[1:] if arg not in ['--microsoft','--apple']]
production = args == ['--production']
if args and not production:
    raise SystemExit('Use no arguments for QA or --production')
qa = Path('/root/sense-workspace-test.SC495Gg0')
candidate = qa/'candidate-038'
web = Path('/home/sensecms.com/web') if production else qa/'.cms/source'
base = 'https://www.sensecms.com' if production else 'http://127.0.0.1:8873'
slug = 'apple-calendar' if apple else ('microsoft-365-calendar' if microsoft else 'google-calendar')
kind = 'apple-calendar' if apple else ('microsoft-calendar' if microsoft else 'google-calendar')
identity = 'plugin:' + slug
raw = sys.stdin.read(64).strip()
if not re.fullmatch('[A-Za-z0-9]{32}',raw):
    raise RuntimeError('Supply only the authorised package key through private stdin')
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
count = 0
def check(ok, name):
    global count
    if not ok: raise RuntimeError(name)
    count += 1
    print('PASS '+name, flush=True)
def request(path, data=None):
    data = None if data is None else urllib.parse.urlencode(data).encode()
    try:
        with client.open(urllib.request.Request(base+path,data=data,headers={'Accept':'application/json'}),timeout=30) as response:
            return response.status,response.read(),response.headers
    except urllib.error.HTTPError as response:
        return response.code,response.read(),response.headers

status,body,headers = request('/packages/download')
data = json.loads(body)
offer = data['products'][identity]
check(status==200 and len(data['products'])==(6 if apple else (5 if microsoft else 4)) and offer['pricing']=='paid','expected inventory with separate paid integration licence')
payload = {'csrf':data['csrf'],'domain':'https://www.sensecms.com','product':identity,'license_key':raw}
raw = ''
check(request('/packages/download',dict(payload,csrf='invalid'))[0]==419,'CSRF enforced')
check(request('/packages/download',dict(payload,license_key='invalid'))[0]==403,'invalid key refused')
check(request('/packages/download',dict(payload,product='theme:sensecms'))[0]==403,'calendar integration key cannot download free CMS-licensed theme')
check(request('/packages/download',dict(payload,product='addon:calendar'))[0]==403,'calendar integration key cannot substitute for Calendar addon key')
code = r'''require $argv[1].'/bootstrap.php';$r=new App\Core\Runtime($argv[1]);$base=$argv[1].'/storage/license/';$secret=file_get_contents($base.'key.bin');$raw=file_get_contents($base.'license.lic');$box=base64_decode(substr($raw,strlen("SENSECMS-LIC-1\n")));$plain=sodium_crypto_secretbox_open(substr($box,24),substr($box,0,24),$secret);echo json_decode($plain,true)['key'];sodium_memzero($plain);sodium_memzero($secret);'''
key = subprocess.run(['php8.5','-r',code,str(web)],capture_output=True,check=True).stdout.decode()
check(request('/packages/download',dict(payload,license_key=key))[0]==403,'CMS key cannot download paid calendar integration')
key = ''
status,body,headers = request('/packages/download',payload)
payload['license_key'] = ''
check(status==200 and headers['Content-Type'].startswith('application/zip'),'real calendar integration licence returns signed ZIP')
check(hashlib.sha256(body).hexdigest()==offer['sha256'] and len(body)==offer['bytes'],'download hash and size match offer')
check(body==(candidate/('plugin-apple-calendar-0.1.0.zip' if apple else ('plugin-microsoft-365-calendar-0.1.0.zip' if microsoft else 'google-calendar-accepted-build/plugin-google-calendar-0.1.0.zip'))).read_bytes(),'download equals package tested on QA')
check(request('/storage/distribution/releases/plugin-'+slug+'-0.1.0.zip')[0]==404,'private archive not anonymously accessible')
with (candidate/('download-'+kind+('-production.zip' if production else '-qa.zip'))).open('xb') as handle:
    handle.write(body)
if not production:
    cfg=json.loads((web/'storage/distribution.json').read_text())
    receipt={'entry':cfg['products'][identity],'core':{name:hashlib.sha256((web/name).read_bytes()).hexdigest() for name in ['public/index.php','app/Core/Packages/Distribution.php','app/Http/DistributionController.php','app/Core/LicenseClient.php','app/Core/Packages/Entitlement.php']}}
    (candidate/(kind+'-accepted.json')).write_text(json.dumps(receipt))
print(f'{count} real download checks passed; live provider event delivery remains unverified.')
