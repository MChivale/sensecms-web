"""Production smoke: offline AI greeting and immediate durable notification dispatch."""
import importlib.util,json,re,subprocess,time
from pathlib import Path

spec=importlib.util.spec_from_file_location('access',Path(__file__).with_name('demo-access-http.py'))
access=importlib.util.module_from_spec(spec);spec.loader.exec_module(access)

def sql(query):
    return subprocess.run(['mariadb','sensecms_site','-BN'],input=query.encode(),capture_output=True,check=True).stdout.decode().strip()

_,request=access.client('www.sensecms.com');conversation='';usage_before=int(sql("SELECT COUNT(*) FROM ai_usage_events WHERE purpose='chat';") or 0)
try:
    status,body,_=request('/');assert status==200
    csrf=re.search(rb'data-public-chat[^>]+data-csrf="([a-f0-9]{64})"',body)[1].decode()
    started=time.monotonic();status,body,_=request('/api/ai/chat',{'message':'Hi','name':'Sense CMS QA','locale':'en','csrf':csrf})
    result=json.loads(body);assert status==200 and result['handoff'] is False and re.fullmatch('[a-f0-9-]{36}',result['conversation']);conversation=result['conversation']
    status,body,_=request('/api/chat/state');state=json.loads(body)['data'];assert status==200 and state['status']=='open' and any(row['role']=='assistant' for row in state['messages'])
    rows=[]
    while time.monotonic()-started<15:
        query="SELECT plugin_slug,status FROM notification_deliveries WHERE JSON_UNQUOTE(JSON_EXTRACT(payload,'$.subject'))="+"CONVERT(0x"+conversation.encode().hex()+" USING utf8mb4) UNION ALL SELECT 'web-push',status FROM web_push_deliveries WHERE JSON_UNQUOTE(JSON_EXTRACT(payload,'$.subject'))=CONVERT(0x"+conversation.encode().hex()+" USING utf8mb4);"
        rows=[line.split('\t') for line in sql(query).splitlines() if line]
        if rows and all(row[1] not in ('pending','processing') for row in rows):break
        time.sleep(.35)
    assert rows and time.monotonic()-started<15 and all(row[1] not in ('pending','processing') for row in rows)
    assert int(sql("SELECT COUNT(*) FROM ai_usage_events WHERE purpose='chat';") or 0)==usage_before
    print(json.dumps({'ok':True,'conversation':conversation,'delivery_seconds':round(time.monotonic()-started,2),'channels':rows,'ai_provider_requests':0},separators=(',',':')))
finally:
    if conversation:
        token='CONVERT(0x'+conversation.encode().hex()+' USING utf8mb4)'
        sql("DELETE FROM notification_deliveries WHERE JSON_UNQUOTE(JSON_EXTRACT(payload,'$.subject'))="+token+"; DELETE FROM web_push_deliveries WHERE JSON_UNQUOTE(JSON_EXTRACT(payload,'$.subject'))="+token+"; DELETE FROM live_chat_reads WHERE conversation_id="+token+"; DELETE FROM live_chat_transfers WHERE conversation_id="+token+"; DELETE FROM ai_messages WHERE conversation_id="+token+"; DELETE FROM ai_conversations WHERE id="+token+';')
