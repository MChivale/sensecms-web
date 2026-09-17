"""Inspect and remove one marked live QA conversation through actual Owner routes."""
import importlib.util,ipaddress,json,re,subprocess,sys
from pathlib import Path
spec=importlib.util.spec_from_file_location('access',Path(__file__).with_name('demo-access-http.py'))
access=importlib.util.module_from_spec(spec);spec.loader.exec_module(access)
cid=sys.argv[1];assert re.fullmatch('[a-f0-9-]{36}',cid)
owner=json.loads(Path('/root/sensecms-private/owner.json').read_text())
req=access.login('www.sensecms.com','/home/sensecms.com/web',{'email':owner['email'],'password':owner['password']},True)
csrf=None;verified=False
try:
    status,body,_=req('/api/operator/conversations/'+cid);info=json.loads(body)['data']
    assert status==200 and any(m['content']=='QA IP COUNTRY 20260913-0816' for m in info['messages']);verified=True
    ip=info['visitor_ip'];ipaddress.ip_address(ip);assert ip!='8.8.8.8' and re.fullmatch('[A-Z]{2}',info['visitor_country'])
    print('PASS Operator sees captured IP and a resolved country; spoofed forwarding header ignored')
    resolved=subprocess.run(['runuser','-u','sensecms','--','php8.5','-r','require $argv[1]."/bootstrap.php";echo App\\Core\\IpCountry::lookup($argv[2]);','/home/sensecms.com/web',ip],capture_output=True,check=True).stdout.decode()
    assert resolved==info['visitor_country'];print('PASS Saved country matches the private offline database')
    status,body,_=req('/api/operator/chat-events')
    # Existing event route is inspected independently before claiming the queue item.
    if status==200:
        pending=json.loads(body)['data']['pending'];assert any(p['id']==cid and p['visitor_ip']==ip and p['visitor_country']==resolved for p in pending)
        print('PASS Incoming operator panel receives visitor location')
    else:raise RuntimeError('Operator events route unavailable')
    status,body,_=req('/conversations?conversation='+cid);assert status==200
    csrf=re.search(rb'name="csrf" value="([a-f0-9]{64})"',body)[1].decode()
    fragment=re.search(rb'<div class="sensecms-chat-location" data-chat-visitor-location>(.*?)</div>',body,re.S)[1]
    assert ip.encode() in fragment and resolved.encode() in fragment and b'IP Geolocation by DB-IP' in fragment
    assert b'location-1' in body;print('PASS Actual backend HTML displays IP, country, approximation and source attribution')
    status,body,_=req('/api/operator/conversations/'+cid+'/connect',{'csrf':csrf});assert status==200 and json.loads(body)['ok']
    status,body,_=req('/conversations/'+cid+'/messages',{'csrf':csrf,'content':'QA location verified.'});assert status==200 and json.loads(body)['ok']
    print('PASS Existing operator claim and reply still work')
finally:
    if csrf and verified:
        status,body,_=req('/conversations/'+cid+'/delete',{'csrf':csrf});assert status==200 and json.loads(body)['ok']
        query="SELECT COUNT(*) FROM ai_conversations WHERE id='"+cid+"' AND visitor_ip IS NULL AND visitor_country IS NULL AND status='closed';"
        assert subprocess.run(['mariadb','sensecms_site','-BN'],input=query.encode(),capture_output=True,check=True).stdout.strip()==b'1'
        print('PASS QA contents and location removed, closed marker retained');req('/logout',{'csrf':csrf})
