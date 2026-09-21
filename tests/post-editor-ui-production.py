"""Read-only production acceptance for the rich post editor and social sidebar."""
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
    markers=(b'data-post-editor',b'data-post-rich-editor',b'data-sidebar-section="publication"',b'data-sidebar-section="ai"',b'data-post-ai-review',b'data-sidebar-section="media"',b'Media & distribution',b'featured_media_id',b'audio_media_id',b'video_media_id',b'data-post-tags',b'data-media-picker-search',b'data-media-picker-folders',b'data-media-picker-upload',b'data-media-folder-create',b'/theme/sensecms-content-management.css?v=20260921-post-ai-1',b'/theme/sensecms-editor-workflow.js?v=20260920-post-editor-2',b'/assets/lib/quill/quill.js?v=2.0.3',b'/theme/sensecms-post-editor.js?v=20260921-1',b'/theme/sensecms-post-ai.js?v=20260921-1',b'/extension-assets/addon/social-publishing/editor.js?v=0.5.2')
    for path in ('/content/posts/new','/content/posts/1/edit'):
        status,page,_=request(path);assert status==200,path
        for marker in markers:assert marker in page,(path,marker)
        assert b'Editorial guidance' not in page
    status,script,headers=request('/theme/sensecms-post-editor.js?v=20260921-1');assert status==200 and b'sensecms:post-media-change' in script and b'sensecms-tag-chip' in script and b'data-media-folder-save' in script and b'sensecms:content-ready' in script and 'javascript' in headers.get_content_type()
    status,script,headers=request('/theme/sensecms-post-ai.js?v=20260921-1');assert status==200 and b'/content/posts/ai' in script and b'fingerprint' in script and b'data-post-ai-apply' in script and 'javascript' in headers.get_content_type()
    status,script,headers=request('/assets/lib/quill/quill.js?v=2.0.3');assert status==200 and b'Quill' in script and 'javascript' in headers.get_content_type()
    status,style,headers=request('/assets/lib/quill/quill.snow.css?v=2.0.3');assert status==200 and b'.ql-toolbar' in style and 'text/css' in headers.get_content_type()
    status,script,headers=request('/extension-assets/addon/social-publishing/editor.js?v=0.5.2');assert status==200 and b'social-editor-provider-toggle' in script and b'data-media-distribution' in script and b'sensecms:content-ready' in script and 'javascript' in headers.get_content_type()
    status,body,_=request('/api/social-publishing/editor?post_id=0');data=json.loads(body);assert status==200 and data['ok'];providers=data['data']['providers'];labels=[item['label'] for item in providers];assert labels==['LinkedIn','X (Twitter)','Facebook','Telegram','WhatsApp','Instagram','Threads','YouTube','TikTok','Pinterest','Bluesky','Mastodon'];planned={item['label'] for item in providers if item.get('planned')};assert planned=={'WhatsApp','Instagram','Threads'}
finally:request('/logout',{'csrf':csrf})
print('PASS Production create/edit post editor, local assets and ordered social sidebar are healthy.')
