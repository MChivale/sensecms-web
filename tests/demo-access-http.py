"""Operator-only HTTPS acceptance for the two explicitly configured installations.

Reads CAPTCHA from this test's own session; never disables production CAPTCHA.
Uses private per-installation credentials and performs only blocked Demo writes.
"""
import json,pwd,re,subprocess,urllib.request,urllib.parse,urllib.error
from pathlib import Path
from http.cookiejar import CookieJar

def check(ok,label):
    if not ok:raise RuntimeError(label)
    print('PASS '+label,flush=True)
def credentials(path):
    fields=dict(re.findall(r'^([^:\n]+):\s*(.+)$',Path(path).read_text(),re.M))
    fields={k.strip().lower():v.strip() for k,v in fields.items()}
    return {'email':fields.get('email',fields.get('user','demo@sensecms.com')),'password':fields.get('password',fields.get('pass'))}
def client(domain):
    jar=CookieJar();http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    def req(path,fields=None,method=None):
        data=urllib.parse.urlencode(fields).encode() if fields is not None else None
        headers={'Accept':'application/json'} if data is not None else {}
        try:res=http.open(urllib.request.Request('https://'+domain+path,data=data,headers=headers,method=method),timeout=30)
        except urllib.error.HTTPError as err:res=err
        with res:return res.status,res.read(),res.headers
    return jar,req
def login(domain,web,cred,allowed):
    jar,req=client(domain);status,body,_=req('/login');check(status==200,'Login page '+domain)
    csrf=re.search(rb'name="csrf" value="([a-f0-9]{64})"',body)[1].decode()
    sid=next(c.value for c in jar if c.name=='sensecms_session');check(bool(re.fullmatch(r'[A-Za-z0-9,-]{16,128}',sid)),'Valid private QA session')
    runtime_user=pwd.getpwuid(Path(web+'/storage').stat().st_uid).pw_name
    check(runtime_user in ['sensecms','demo-sensecms'],'Expected installation runtime identity')
    captcha=subprocess.run(['runuser','-u',runtime_user,'--','php8.5','-r','session_save_path($argv[1]);session_id($argv[2]);session_start(["read_and_close"=>true]);echo $_SESSION["sensecms_captcha_login_code"]??"";',web+'/storage/sessions',sid],capture_output=True,check=True).stdout.decode()
    check(bool(captcha) and bool(cred['password']),'Private test credentials and CAPTCHA available')
    status,body,_=req('/login',dict(csrf=csrf,captcha=captcha,**cred));payload=json.loads(body)
    check((status==200 and payload.get('ok') is True) if allowed else (status==422 and payload.get('ok') is False),'Login '+('accepted ' if allowed else 'blocked ')+domain)
    return req

if __name__=='__main__':
    owner=json.loads(Path('/root/sensecms-private/owner.json').read_text())
    req=login('www.sensecms.com','/home/sensecms.com/web',{'email':owner['email'],'password':owner['password']},True)
    try:
        status,body,_=req('/system/access?tab=users&edit_user=2');check(status==200,'Owner users screen')
        active=re.search(rb'<input[^>]*type="checkbox"[^>]*name="active"[^>]*>',body)[0]
        check(b'disabled' not in active and b'checked' not in active,'Owner can enable inactive official Demo')
        csrf=re.search(rb'name="csrf" value="([a-f0-9]{64})"',body)[1].decode()
    finally:
        if 'csrf' in locals():req('/logout',{'csrf':csrf})
    req=login('www.sensecms.com','/home/sensecms.com/web',credentials('/root/sensecms-private/Demo-user.www.txt'),False)
    status,body,_=req('/dashboard');check(b'class="app-menu"' not in body,'Inactive account cannot enter dashboard')
    req=login('demo.sensecms.com','/home/demo.sensecms.com/web',credentials('/root/sensecms-private/Demo-user.txt'),True)
    try:
        status,body,_=req('/dashboard');check(status==200 and b'data-demo-welcome' in body and b'write operations cannot be saved' in body.lower(),'Actual login read-only notice')
        csrf=re.search(rb'name="csrf" value="([a-f0-9]{64})"',body)[1].decode()
        for path in ['/system/access?tab=users&edit_user=2','/system/access?tab=roles','/system/access?tab=teams','/system/update','/system/email','/license','/appearance/themes','/content/pages','/content/posts','/content/media','/content/builder','/system/extensions?tab=modules']:
            status,body,_=req(path);check(status==200 and b'class="app-menu"' in body,'Demo full backend view '+path)
            if 'edit_user' in path:
                check(b'disabled' in re.search(rb'<input[^>]*type="checkbox"[^>]*name="active"[^>]*>',body)[0],'Demo cannot operate account switch')
        for path in ['/system/access/users','/system/access/roles','/system/access/teams','/settings','/settings/password','/content/pages','/content/media/upload','/system/update','/system/notifications']:
            status,body,headers=req(path,{'csrf':csrf,'id':'2','active':'0','name':'Blocked probe'})
            check(status==403 and headers.get('X-SenseCMS-Demo-Mode')=='1' and 'read-only' in json.loads(body)['message'],'Server blocks Demo write '+path)
    finally:
        req('/logout',{'csrf':csrf})
