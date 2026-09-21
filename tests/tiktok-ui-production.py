"""Read-only production acceptance for TikTok Publisher."""
from http.cookiejar import CookieJar
import json
from pathlib import Path
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

web=Path('/home/sensecms.com/web');base='https://www.sensecms.com';assert json.loads((web/'storage/installed.json').read_text())['base_url']==base;jar=CookieJar();client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def request(path,fields=None):
    data=urllib.parse.urlencode(fields).encode() if fields is not None else None;headers={'Accept':'application/json'} if fields is not None else {}
    try:
        with client.open(urllib.request.Request(base+path,data=data,headers=headers),timeout=30)as response:return response.status,response.read(),response.headers
    except urllib.error.HTTPError as error:return error.code,error.read(),error.headers
status,body,_=request('/login');assert status==200;csrf=re.search(rb'name="csrf" value="([a-f0-9]{64})"',body)[1].decode();sid=next(cookie.value for cookie in jar if cookie.name=='sensecms_session');captcha=subprocess.run(['runuser','-u','sensecms','--','php8.5','-r','session_save_path($argv[1]);session_id($argv[2]);session_start(["read_and_close"=>true]);echo $_SESSION["sensecms_captcha_login_code"]??"";',str(web/'storage/sessions'),sid],capture_output=True,check=True).stdout.decode();owner=json.loads(Path('/root/sensecms-private/owner.json').read_text());status,body,_=request('/login',{'csrf':csrf,'email':owner['email'],'password':owner['password'],'captcha':captcha});del owner,captcha;assert status==200 and json.loads(body)['ok']
try:
    status,overview,_=request('/social-publishing');assert status==200 and b'TikTok account' in overview and b'0.3.0' in overview
    status,page,_=request('/social-publishing/tiktok');assert status==200;csrf=re.search(rb'name="csrf" value="([a-f0-9]{64})"',page)[1].decode()
    for marker in (b'social-tiktok-page',b'data-tiktok-oauth-modal',b'social-account-meta',b'social-steps',b'social-panel-footer',b'social-disconnect',b'Connect with TikTok',b'0.1.1'):assert marker in page or (marker==b'Connect with TikTok' and b'Add TikTok account' in page),marker
    status,body,_=request('/social-publishing/tiktok/status');data=json.loads(body);assert status==200 and data['ok'] and data['data']['connected_count']>=0
    status,body,_=request('/api/social-publishing/editor?post_id=0');provider=next(item for item in json.loads(body)['data']['providers'] if item['slug']=='tiktok-publisher');assert provider['max_message_length']==4000 and provider['editor_options'] and len(provider['connections'])==data['data']['connected_count']
    status,css,headers=request('/extension-assets/plugin/tiktok-publisher/tiktok.css?v=0.1.1');assert status==200 and b'.tiktok-oauth-modal' in css and 'text/css' in headers.get_content_type()
    status,script,headers=request('/extension-assets/plugin/tiktok-publisher/tiktok.js?v=0.1.1');assert status==200 and b'sensecms.tiktok.oauth' in script and 'javascript' in headers.get_content_type()
finally:request('/logout',{'csrf':csrf})
print('PASS Production TikTok workspace, assets and editor contract are healthy.')
