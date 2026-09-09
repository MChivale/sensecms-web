"""Actual HTTP license gate; keys arrive on stdin and are never printed or saved."""
import hashlib, http.cookiejar, json, sys, urllib.error, urllib.parse, urllib.request
from pathlib import Path
base=sys.argv[1]
if base not in ['http://127.0.0.1:8873','https://www.sensecms.com']: raise RuntimeError('Unexpected target')
keys=json.load(sys.stdin)
http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(data=None,path='/packages/download'):
    body=urllib.parse.urlencode(data).encode() if data is not None else None
    try:
        with http.open(urllib.request.Request(base+path,data=body,headers={'Accept':'application/json'}),timeout=35) as response:return response.status,response.read(),response.headers
    except urllib.error.HTTPError as response:return response.code,response.read(),response.headers
code,body,headers=request();data=json.loads(body);offer=data['products']['plugin:telegram-notifications']
assert code==200 and offer['pricing']=='paid' and offer['version']=='0.1.1'
payload={'product':'plugin:telegram-notifications','domain':'https://www.sensecms.com','csrf':data['csrf'],'license_key':'invalid'}
assert request(dict(payload,csrf='bad'))[0]==419
assert request(payload)[0]==403
payload['license_key']=keys['Sense CMS'];assert request(payload)[0]==403
payload['license_key']=keys['Sense CMS Telegram Notifications'];code,body,headers=request(payload)
payload['license_key']='';keys.clear()
assert code==200 and headers['Content-Type'].startswith('application/zip')
assert len(body)==offer['bytes'] and hashlib.sha256(body).hexdigest()==offer['sha256']
assert request(path='/storage/distribution/releases/plugin-telegram-notifications-0.1.1.zip')[0]==404
print('PASS HTTP: offer, CSRF, invalid key, CMS key rejected for paid plugin, valid plugin key, exact ZIP bytes, private archive protection.')
if base.startswith('http:'):
    qa=Path('/root/sense-workspace-test.SC495Gg0');web=qa/'.cms/source'
    names=['public/index.php','app/Core/Packages/Distribution.php','app/Http/DistributionController.php','app/Core/LicenseClient.php','app/Core/Packages/Entitlement.php']
    cfg=json.loads((web/'storage/distribution.json').read_text())
    receipt={'entry':cfg['products']['plugin:telegram-notifications'],'core':{name:hashlib.sha256((web/name).read_bytes()).hexdigest() for name in names}}
    (qa/'candidate-038/telegram-accepted.json').write_text(json.dumps(receipt))
