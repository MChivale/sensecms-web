"""Post-transition HTTPS checks, with only private test credentials in memory."""
import hashlib
import http.cookiejar
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

stage=Path('/root/sense-release-1.0-eDoxsJCH')
backup=Path('/root/sensecms-backups/20260913T052855Z-release-1.0')
web=Path('/home/sensecms.com/web')
products={item['identity']:item for item in json.loads((stage/'packages/release-set.json').read_text())['products']}
checks=[]
def check(ok,label):
    if not ok:raise RuntimeError(label)
    checks.append(label);print('PASS '+label,flush=True)

spec=importlib.util.spec_from_file_location('access',str(stage/'source/tests/demo-access-http.py'))
access=importlib.util.module_from_spec(spec);spec.loader.exec_module(access)
for host,root in [('www.sensecms.com',str(web)),('demo.sensecms.com','/home/demo.sensecms.com/web')]:
    if host.startswith('www'):
        owner=json.loads(Path('/root/sensecms-private/owner.json').read_text());cred={key:owner[key] for key in ['email','password']}
    else:cred=access.credentials('/root/sensecms-private/Demo-user.txt')
    req=access.login(host,root,cred,True);cred.clear();csrf=None
    try:
        status,body,_=req('/system/update')
        state=json.loads(re.search(rb'<script[^>]*data-update-bootstrap[^>]*>(.*?)</script>',body,re.S)[1])
        csrf=re.search(rb'data-csrf="([a-f0-9]{64})"',body)[1].decode()
        check(status==200 and state['version']=='1.0.0','Backend reports Core 1.0.0: '+host)
        check(not state['install_supported'],'Automatic Core replacement remains unavailable: '+host)
        status,body,_=req('/appearance/themes')
        dirs=re.findall(rb'name="directory" value="([^"]+)"',body)
        check(status==200 and (dirs==[('sensecms-1.0.0-'+products['theme:sensecms']['sha256'][:16]).encode()] if host.startswith('www') else not dirs),'Exact separate theme inventory: '+host)
        status,body,_=req('/marketplace')
        data=json.loads(re.search(rb'<script[^>]*data-marketplace-bootstrap[^>]*>(.*?)</script>',body,re.S)[1])
        if host.startswith('www'):
            for identity,item in products.items():
                kind,slug=identity.split(':');rows=[row for row in data['items'] if row['type']==kind and row['slug']==slug]
                check(len(rows)==1 and rows[0]['version']==item['version'] and rows[0]['compatible'],'One compatible current Marketplace row: '+identity)
        for path in (['/calendar','/system/extensions/google-analytics','/system/notifications'] if host.startswith('www') else ['/dashboard','/system/access']):
            status,body,_=req(path);check(status==200 and b'class="app-menu"' in body,'Operational backend '+host+path)
    finally:
        if csrf:req('/logout',{'csrf':csrf})

http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(path,fields=None,origin=None):
    headers={'Accept':'application/json'}
    if origin:headers['Origin']=origin
    data=urllib.parse.urlencode(fields).encode() if fields is not None else None
    try:res=http.open(urllib.request.Request('https://www.sensecms.com'+path,data=data,headers=headers),timeout=40)
    except urllib.error.HTTPError as error:res=error
    with res:return res.status,res.read(),res.headers
status,body,headers=request('/packages/download');data=json.loads(body)
check(status==200 and set(data['products'])==set(products),'Public catalogue preserves exact seven product identities')
for identity,item in products.items():
    offer=data['products'][identity]
    check(all(offer[key]==item[key] for key in ['version','sha256','bytes']) and 'file' not in offer,'Current public offer metadata: '+identity)
    check(request('/storage/distribution/releases/'+item['file'])[0]==404,'Private signed archive not exposed: '+identity)
fields={'product':'theme:sensecms','domain':'https://www.sensecms.com','license_key':'invalid','csrf':data['csrf']}
check(request('/packages/download',dict(fields,csrf='bad'))[0]==419,'CSRF remains mandatory')
check(request('/packages/download',fields,'https://example.test')[0]==419,'Cross-origin download blocked')
check(request('/packages/download',fields)[0]==403,'Invalid licence blocked')
code=r'''$base=$argv[1].'/storage/license/';$secret=file_get_contents($base.'key.bin');$raw=file_get_contents($base.'license.lic');$box=base64_decode(substr($raw,strlen("SENSECMS-LIC-1\n")));$plain=sodium_crypto_secretbox_open(substr($box,24),substr($box,0,24),$secret);$data=json_decode($plain,true,16,JSON_THROW_ON_ERROR);echo $data['key'];sodium_memzero($plain);sodium_memzero($secret);'''
fields['license_key']=subprocess.check_output(['php8.5','-r',code,str(web)]).decode()
for identity in ['theme:sensecms','plugin:google-analytics']:
    fields['product']=identity;status,body,headers=request('/packages/download',fields,'https://www.sensecms.com');item=products[identity]
    check(status==200 and headers.get('Content-Type','').startswith('application/zip'),'Valid Core licence downloads '+identity)
    check(hashlib.sha256(body).hexdigest()==item['sha256'] and len(body)==item['bytes'] and item['file'] in headers.get('Content-Disposition',''),'Downloaded exact accepted signed bytes: '+identity)
for identity in ['addon:calendar','plugin:telegram-notifications']:
    fields['product']=identity;check(request('/packages/download',fields)[0]==403,'Core licence cannot substitute for paid entitlement: '+identity)
fields['license_key']=''
for path in ['/','/extensions','/update','/contact','/docs','/theme-assets/sensecms/images/sensecms-logo-email.png']:
    check(request(path)[0]==200,'Public regression '+path)
for identity,item in products.items():
    kind,slug=identity.split(':');path='/extensions/catalog/'+kind+'/'+slug
    status,body,_=request(path)
    check(status==200,'Product detail remains accessible: '+identity)
    if b'Available version:' in body:check(('Available version: '+item['version']+'.').encode() in body,'Product page version matches download: '+identity)
state=json.loads((web/'storage/theme.json').read_text())
check(len(state['releases'])==1 and not state.get('previous'),'Old theme no longer appears as an installed release')
check(len(list((web/'storage/themes').iterdir()))==1,'Only current theme directory in installation')
check((backup/'sensecms-0.3.11-e5bba71253c7fa36/archive.zip').is_file(),'Prior theme recoverable outside installation')
(backup/'https-acceptance.json').write_text(json.dumps({'passed':len(checks),'checks':checks},indent=2))
print(str(len(checks))+' post-release HTTPS checks passed.')
