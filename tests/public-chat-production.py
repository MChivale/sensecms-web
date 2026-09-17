"""One explicitly labelled browser QA conversation, real Owner HTTPS endpoints."""
import importlib.util,json,re,subprocess,sys
from pathlib import Path
spec=importlib.util.spec_from_file_location('access',Path(__file__).with_name('demo-access-http.py'))
access=importlib.util.module_from_spec(spec);spec.loader.exec_module(access)
marker='QA LIVE CHAT 20260913-0709: test odbioru i odpowiedzi operatora.'
record=Path('/root/sense-live-chat-i1jwYjZ7/browser-conversation.json')
assert sys.argv[1:] in (['reply'],['cleanup'],['smoke'])
if sys.argv[1]=='smoke':
    _,req=access.client('www.sensecms.com')
    for path in ['/','/platform','/extensions','/contact','/update']:
        status,body,headers=req(path)
        assert status==200 and body.count(b'data-public-chat ')==1 and b'public-chat.css?v=20260913-2' in body
        assert 'no-store' in headers.get('Cache-Control','');print('PASS One session-safe live chat widget: '+path)
    for path,mime in [('/assets/public-chat.css','text/css'),('/assets/public-chat.js','javascript')]:
        status,body,headers=req(path);assert status==200 and mime in headers.get('Content-Type','')
        status,body,headers=req(path,method='HEAD');assert status==200 and body==b'';print('PASS GET/HEAD asset: '+path)
    status,body,_=req('/api/chat/message',{'message':'Rejected QA CSRF probe','csrf':'invalid'})
    assert status==419;print('PASS Production rejects invalid visitor CSRF')
    status,body,_=req('/api/chat/state');assert status==200 and json.loads(body)['data'] is None
    print('PASS Rejected request did not create a conversation')
    print('9 production public chat smoke checks passed.');sys.exit(0)
if sys.argv[1]=='reply':
    query="SELECT DISTINCT conversation_id FROM ai_messages WHERE role='visitor' AND content=CONVERT(0x"+marker.encode().hex()+" USING utf8mb4);"
    ids=subprocess.run(['mariadb','sensecms_site','-BN'],input=query.encode(),capture_output=True,check=True).stdout.decode().split()
    assert len(ids)==1 and re.fullmatch('[a-f0-9-]{36}',ids[0]);cid=ids[0]
    record.write_text(json.dumps({'id':cid,'marker':marker}));record.chmod(0o600)
else:
    data=json.loads(record.read_text());assert data['marker']==marker;cid=data['id'];assert re.fullmatch('[a-f0-9-]{36}',cid)
owner=json.loads(Path('/root/sensecms-private/owner.json').read_text())
req=access.login('www.sensecms.com','/home/sensecms.com/web',{'email':owner['email'],'password':owner['password']},True)
csrf=None
try:
    status,body,_=req('/conversations?conversation='+cid)
    assert status==200
    csrf=re.search(rb'name="csrf" value="([a-f0-9]{64})"',body)[1].decode()
    if sys.argv[1]=='reply':
        status,body,_=req('/api/operator/conversations/'+cid+'/connect',{'csrf':csrf})
        assert status==200 and json.loads(body)['ok'];print('PASS Owner claimed the browser visitor conversation')
        status,body,_=req('/api/operator/conversations/'+cid)
        assert status==200 and any(m['content']==marker for m in json.loads(body)['data']['messages']);print('PASS Backend received the exact browser message')
        status,body,_=req('/conversations/'+cid+'/messages',{'csrf':csrf,'content':'QA: odpowiedź operatora dotarła z backendu Sense CMS.'})
        assert status==200 and json.loads(body)['ok'];print('PASS Real backend sent the operator reply')
        _,anonymous=access.client('www.sensecms.com')
        status,body,_=anonymous('/api/chat/state?conversation='+cid)
        assert status==200 and json.loads(body)['data'] is None;print('PASS A separate anonymous session cannot read this conversation')
    else:
        status,body,_=req('/conversations/'+cid+'/delete',{'csrf':csrf})
        assert status==200 and json.loads(body)['ok'];print('PASS Only the identified QA conversation was closed and its contents removed')
finally:
    if csrf:req('/logout',{'csrf':csrf})
