"""Read-only public and early-rejection checks on the exact official host. No file is uploaded."""
import http.client
import json
from pathlib import Path
base='www.sensecms.com'
assert json.loads(Path('/home/sensecms.com/web/storage/installed.json').read_text())['base_url']=='https://'+base
for path in ['/forgot-password','/theme/sensecms-media-library.js','/update']:
    conn=http.client.HTTPSConnection(base,timeout=15);conn.request('GET',path);res=conn.getresponse();body=res.read();conn.close()
    assert res.status==200
    if path=='/forgot-password':assert b'Base CMS' not in body and b'Sense CMS' in body
    if path.endswith('.js'):assert b'99614720' in body and b'response.status===413' in body
    print('PASS public',path)
for path,size in [('/content/media/upload',100663297),('/contact',16385)]:
    conn=http.client.HTTPSConnection(base,timeout=15);conn.putrequest('POST',path);conn.putheader('Content-Length',size);conn.endheaders()
    res=conn.getresponse();res.read();conn.close();assert res.status==413
    print('PASS early size rejection without body',path)
print('PASS production media/default read-only checks; no uploads, mail or content writes.')
