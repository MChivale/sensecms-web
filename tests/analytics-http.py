"""Private QA runtime acceptance and real licence-gated analytics download."""
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

qa = Path('/root/sense-workspace-test.SC495Gg0')
candidate = qa / 'candidate-038'
production = sys.argv[1:] == ['--production']
if sys.argv[1:] and not production:
    raise SystemExit('Use no arguments for QA or --production')
web = Path('/home/sensecms.com/web') if production else qa / '.cms/source'
base = 'https://www.sensecms.com' if production else 'http://127.0.0.1:8873'
anonymous = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
admin = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
count = 0
def check(ok, name):
    global count
    if not ok:
        raise RuntimeError(name)
    count += 1
    print('PASS ' + name, flush=True)
def request(client, path, data=None, method=None):
    body = None if data is None else urllib.parse.urlencode(data).encode()
    try:
        with client.open(urllib.request.Request(base+path, data=body, headers={'Accept':'application/json'}, method=method), timeout=30) as response:
            return response.status, response.read(), response.headers
    except urllib.error.HTTPError as response:
        return response.code, response.read(), response.headers
def php(code, *args):
    process = subprocess.run(['php8.5','-r','require $argv[1]."/bootstrap.php";'+code,str(web),*args],capture_output=True)
    if process.returncode:
        raise RuntimeError('Private PHP verification failed')
    return process.stdout

if not production:
    check(b'data-sense-ga' not in request(anonymous,'/')[1], 'unconfigured plugin leaves site untracked')
    check(b'name="email"' in request(anonymous,'/system/extensions/google-analytics')[1], 'anonymous configuration redirects to login')
    owner = json.loads((qa/'preview-private.json').read_text())
    csrf = re.search(rb'name="csrf" value="([a-f0-9]{64})"',request(admin,'/login')[1])[1].decode()
    response = request(admin,'/login',{'csrf':csrf,'email':owner['email'],'password':owner['password']})
    owner = None
    check(json.loads(response[1]).get('redirect')=='/dashboard', 'QA owner authentication succeeds')
    status, body, _ = request(admin,'/system/extensions/google-analytics')
    check(status==200 and b'data-ga-settings' in body and b'Sense CMS' in body,'configuration renders inside CMS panel')
    csrf = re.search(rb'name="csrf" value="([a-f0-9]{64})"',body)[1].decode()
    check(request(admin,'/system/extensions/google-analytics',{'csrf':'bad','measurement_id':'G-ABC123DEF4'})[0]==419,'CSRF rejection protects settings')
    check(request(admin,'/system/extensions/google-analytics',{'csrf':csrf,'measurement_id':'<script>'})[0]==422,'invalid identifier rejected')
    check(json.loads(request(admin,'/system/extensions/google-analytics',{'csrf':csrf,'measurement_id':'abc123def4'})[1]).get('measurement_id')=='G-ABC123DEF4','AJAX save normalizes identifier')
    for path in ['/', '/contact', '/docs']:
        status, body, headers = request(anonymous,path)
        check(status==200 and body.count(b'data-sense-ga')==1, 'anonymous CMS page injects once: '+path)
        check('https://www.googletagmanager.com' in headers['Content-Security-Policy'] and "frame-ancestors" in headers['Content-Security-Policy'],'analytics CSP preserves existing protections: '+path)
    for path in ['/login','/packages/download','/does-not-exist','/docs?sensecms_theme_preview=invalid']:
        check(b'data-sense-ga' not in request(anonymous,path)[1], 'non-public/preview response untracked: '+path)
    check(b'data-sense-ga' not in request(admin,'/')[1], 'signed-in administrator excluded')
    for file in ['analytics.js','analytics.css','settings.js']:
        status, body, headers = request(anonymous,'/extension-assets/plugin/google-analytics/'+file)
        check(status==200 and len(body)>0 and headers['X-Content-Type-Options']=='nosniff','installed asset served: '+file)
    toggle = '$r=new App\\Core\\Runtime($argv[1]);$db=App\\Core\\Runtime::connect($r->read("installed")["database"]);(new App\\Core\\PackageManager($db,$argv[1],"0.1.0"))->setActive("plugin","google-analytics",$argv[2]==="1",1);'
    php(toggle,'0')
    check(b'data-sense-ga' not in request(anonymous,'/')[1],'disabling package stops injection')
    php(toggle,'1')
    check(b'data-sense-ga' in request(anonymous,'/')[1],'reenabling package preserves configuration')
    check(json.loads(request(admin,'/system/extensions/google-analytics',{'csrf':csrf,'measurement_id':''})[1]).get('ok'),'empty ID disables tracking')
    check(b'data-sense-ga' not in request(anonymous,'/')[1],'disabled setting stops injection')

status, body, headers = request(anonymous,'/packages/download')
data = json.loads(body); offer=data['products']['plugin:google-analytics']
check(status==200 and offer['pricing']=='free' and len(data['products'])==3,'free analytics offered beside existing releases')
payload={'csrf':data['csrf'],'product':'plugin:google-analytics','domain':'https://www.sensecms.com','license_key':'invalid'}
check(request(anonymous,'/packages/download',payload)[0]==403,'invalid CMS licence refused')
code = r'''$base=$argv[1].'/storage/license/';$secret=file_get_contents($base.'key.bin');$raw=file_get_contents($base.'license.lic');$box=base64_decode(substr($raw,strlen("SENSECMS-LIC-1\n")));$plain=sodium_crypto_secretbox_open(substr($box,24),substr($box,0,24),$secret);$data=json_decode($plain,true,16,JSON_THROW_ON_ERROR);echo $data['key'];sodium_memzero($plain);sodium_memzero($secret);'''
payload['license_key']=php(code).decode()
status, body, headers = request(anonymous,'/packages/download',payload)
payload['license_key']=''
check(status==200 and headers['Content-Type'].startswith('application/zip'),'real CMS key returns analytics ZIP')
check(hashlib.sha256(body).hexdigest()==offer['sha256'] and len(body)==offer['bytes'],'download matches signed artifact')
check(body==(candidate/'plugin-google-analytics-0.1.1.zip').read_bytes(),'published bytes equal tested installation archive')
check(request(anonymous,'/storage/distribution/releases/plugin-google-analytics-0.1.1.zip')[0]==404,'analytics archive inaccessible anonymously')
with (candidate/('download-analytics-011-production.zip' if production else 'download-analytics-011-qa.zip')).open('xb') as handle:
    handle.write(body)
if not production:
    cfg=json.loads((web/'storage/distribution.json').read_text())
    receipt={'entry':cfg['products']['plugin:google-analytics'],'core':{name:hashlib.sha256((web/name).read_bytes()).hexdigest() for name in ['public/index.php','app/Core/Packages/Distribution.php','app/Http/DistributionController.php','app/Core/LicenseClient.php','app/Core/Packages/Entitlement.php']}}
    (candidate/'analytics-011-accepted.json').write_text(json.dumps(receipt))
else:
    check(b'data-sense-ga' not in request(anonymous,'/')[1],'production website tracking not enabled')
print(f'{count} analytics HTTP checks passed.')
