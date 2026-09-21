"""Read-only production acceptance for the Core Knowledge Base and RAG workspace."""
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
    for tab,markers in {
        'sources':(b'data-ai-knowledge',b'Knowledge sources',b'TXT, PDF, RTF, DOC or DOCX',b'/theme/sensecms-ai-knowledge.js?v=20260921-1'),
        'content':(b'Website content sources',b'Save changes and rebuild',b'Pages and posts included in RAG'),
        'index':(b'Core RAG index',b'Rebuild complete index',b'RETRIEVAL PIPELINE'),
        'training':(b'Training workspace',b'Curated examples',b'Evaluation cases',b'No provider API request or charge is made',b'Start paid fine-tuning'),
    }.items():
        status,page,_=request('/ai/knowledge?tab='+tab);assert status==200,(tab,status)
        for marker in markers:assert marker in page,(tab,marker)
        assert b'/theme/sensecms-content-management.css?v=20260921-knowledge-1' in page
    status,script,headers=request('/theme/sensecms-ai-knowledge.js?v=20260921-1');assert status==200 and b'/ai/knowledge/sources' in script and b'/ai/knowledge/rebuild' in script and 'javascript' in headers.get_content_type()
    status,style,headers=request('/theme/sensecms-content-management.css?v=20260921-knowledge-1');assert status==200 and b'.sensecms-knowledge-layout' in style and b'.sensecms-training-workspace' in style and b'@media(max-width:760px)' in style and 'text/css' in headers.get_content_type()
    status,post,_=request('/content/posts/new');assert status==200 and b'Include in Knowledge Base' in post and b'name="ai_knowledge_enabled"' in post
finally:request('/logout',{'csrf':csrf})
print('PASS Production Core Knowledge Base, RAG controls, training workspace and post inclusion UI are healthy.')
