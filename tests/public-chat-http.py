"""Real controllers/repository over isolated HTTP and a disposable MariaDB schema."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

fixture=Path(__file__).with_name('public-chat-fixture.php')
assert os.name!='nt','Run on private Linux MariaDB QA'
checks=0
def check(ok,label):
    global checks
    if not ok:raise RuntimeError(label)
    checks+=1;print('PASS '+label,flush=True)
def client(base):
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(path,fields=None):
        body=None if fields is None else urllib.parse.urlencode(fields).encode()
        try:res=opener.open(urllib.request.Request(base+path,data=body,headers={'Accept':'application/json'}),timeout=15)
        except urllib.error.HTTPError as error:res=error
        with res:return res.status,res.read()
    return request
with tempfile.TemporaryDirectory(prefix='sense-chat-qa-') as temporary:
    env=dict(os.environ,SENSE_CHAT_TEST_DB='sensechat_'+secrets.token_hex(6),SENSE_CHAT_TEST_PASSWORD=secrets.token_hex(24),SENSE_CHAT_TEST_SESSIONS=temporary)
    server=None
    with open(Path(temporary)/'http.log','wb') as log:
        try:
            subprocess.run(['php8.5',str(fixture),'setup'],env=env,check=True,capture_output=True)
            with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
            server=subprocess.Popen(['php8.5','-S',f'127.0.0.1:{port}',str(fixture)],env=env,stdout=log,stderr=log)
            visitor=client(f'http://127.0.0.1:{port}');other=client(f'http://127.0.0.1:{port}');operator=client(f'http://127.0.0.1:{port}')
            for _ in range(50):
                try:status,body=visitor('/');break
                except urllib.error.URLError:time.sleep(.1)
            check(status==200 and body.count(b'data-public-chat ')==1,'Core injects one widget into real product theme')
            check(body.index(b'data-public-chat ')<body.index(b'</body>'),'Widget is inside the document body')
            check(visitor('/?static=1')[1].count(b'data-public-chat ')==1,'Static product-theme homepage receives the same Core widget')
            csrf=re.search(rb'data-public-chat[^>]+data-csrf="([a-f0-9]{64})"',body)[1].decode()
            check(b'data-public-chat ' not in visitor('/?preview=1')[1],'Theme preview does not create live chat UI')
            check(json.loads(visitor('/api/chat/state')[1])['data'] is None,'Opening chat alone creates no conversation')
            fields={'csrf':csrf,'message':'QA visitor <script>unsafe</script>','name':'QA visitor','locale':'en'}
            check(visitor('/api/chat/message',dict(fields,csrf='bad'))[0]==419,'Invalid CSRF rejected')
            check(other('/api/chat/message',fields)[0]==419,'Another session cannot reuse visitor CSRF')
            check(visitor('/api/chat/message',dict(fields,message=''))[0]==422,'Empty message rejected')
            check(visitor('/api/chat/message',dict(fields,message='x'*2001))[0]==422,'Oversize message rejected')
            code,result=visitor('/api/chat/message',fields);result=json.loads(result);cid=result['conversation']
            check(code==200 and result['handoff'],'Live chat queues human support without an AI provider')
            state=json.loads(visitor('/api/chat/state')[1])['data']
            check(state['status']=='queued' and any(m['content']==fields['message'] for m in state['messages']),'Visitor message persisted in same-session conversation')
            check('visitor_ip' not in state and 'visitor_country' not in state,'Public state excludes operator-only location')
            check(json.loads(other('/api/chat/state?conversation='+cid)[1])['data'] is None,'Conversation ID does not grant another visitor access')
            code,result=operator('/login',{'password':env['SENSE_CHAT_TEST_PASSWORD']});result=json.loads(result);token=result['csrf']
            check(code==200 and result['ok'],'QA operator authenticates')
            info=json.loads(operator('/operator/'+cid+'/state',{'csrf':token})[1])['data']
            check(info['visitor_ip']=='127.0.0.1' and info['visitor_country'] is None,'Operator sees server-derived IP and honest unknown private country')
            check(operator('/operator/'+cid+'/connect',{'csrf':token})[0]==200,'Existing operator controller claims queued chat')
            code,result=operator('/operator/'+cid+'/reply',{'csrf':token,'content':'QA operator reply'});check(code==200 and json.loads(result)['ok'],'Operator reply saved')
            state=json.loads(visitor('/api/chat/state')[1])['data'];check(state['status']=='assigned' and any(m['role']=='agent' and m['content']=='QA operator reply' for m in state['messages']),'Visitor receives real operator reply')
            operator('/toggle',{'csrf':token,'enabled':'0'})
            check(b'data-public-chat ' not in visitor('/')[1],'Disabled extension hides frontend widget')
            check(visitor('/api/chat/message',fields)[0]==403 and visitor('/api/chat/state')[0]==403,'Disabled chat blocks API writes and reads')
            operator('/toggle',{'csrf':token,'enabled':'1'})
            operator('/operator/'+cid+'/close',{'csrf':token})
            info=json.loads(operator('/operator/'+cid+'/state',{'csrf':token})[1])['data']
            check(info['visitor_ip'] is None and info['visitor_country'] is None,'Deleting conversation removes visitor location')
            check(json.loads(visitor('/api/chat/state')[1])['data']['ended'],'Closed chat is reported to visitor')
            code,result=visitor('/api/chat/message',dict(fields,message='New QA conversation'));check(code==200 and json.loads(result)['conversation']!=cid,'New conversation works after operator closes prior chat')
            server.terminate();server.wait(timeout=10);server=None;log.flush()
            check(not re.search(r'PHP (Warning|Fatal|Parse|Notice)|Uncaught',Path(temporary,'http.log').read_text()),'No PHP warnings/errors in complete HTTP flow')
            print(f'{checks} isolated public chat HTTP checks passed.')
        finally:
            if server:server.terminate();server.wait(timeout=10)
            subprocess.run(['php8.5',str(fixture),'drop'],env=env,check=True,capture_output=True)
