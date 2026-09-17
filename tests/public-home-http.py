"""Real front-controller routing with isolated runtime/repository test doubles; no licence or DB calls."""
from pathlib import Path
import json
import os
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

project = Path(__file__).resolve().parents[1]
source = project / '.cms/source'
stub = r'''<?php
namespace App\Core {
 class Runtime {
  public function __construct(public string $root) {}
  public function baseUrl(){return 'https://localhost';}
  public function read($key){return $key==='installed'?['database'=>[]]:($key==='workspace'?['enabled'=>true]:[]);}
  public static function connect($config){return null;}
  public function license(){return new class {public function enforce($url){if(isset($_GET['invalid_license']))throw new LicenseException('test-only invalid licence');}};}
 }
 class CmsRepository {
  public function __construct(...$args){}
  public function languages(){return [['locale'=>'en'],['locale'=>'km'],['locale'=>'zh']];}
  public function setting($key,$fallback=null){return $key==='site_name'?'Test <unsafe> & site':$fallback;}
 }
}
namespace App\Core\Packages {class ThemeManager {public function __construct($runtime){} public function activePath(){return null;}}}
namespace {require SOURCE;}
'''
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None
http = urllib.request.build_opener(NoRedirect())
count = 0
def check(ok, label):
    global count
    assert ok, label
    count += 1
    print('PASS '+label, flush=True)

with tempfile.TemporaryDirectory(prefix='sense-home-http-') as temp:
    router = Path(temp)/'router.php'
    router.write_text(stub.replace('SOURCE', json.dumps(str(source/'public/index.php').replace('\\','/'))))
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    with (Path(temp)/'http.log').open('w+') as log:
        process = subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(source/'public'),str(router)], env=dict(os.environ,SENSE_LOCAL_HTTP='1'),stdout=log,stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
                except OSError:time.sleep(.05)
            def request(path,method='GET'):
                try:response=http.open(urllib.request.Request(f'http://127.0.0.1:{port}'+path,method=method),timeout=10)
                except urllib.error.HTTPError as error:response=error
                with response:return response.status,response.headers,response.read()
            status,headers,body=request('/')
            check(status==200 and b'data-public-home' in body,'Fresh installation renders homepage directly')
            check(b'Test &lt;unsafe&gt; &amp; site' in body and b'<unsafe>' not in body,'Site name is escaped')
            check(b'href="/login"' in body and b'Facility not found' not in body,'Homepage exposes sign-in, not technical facility error')
            check(headers.get('Set-Cookie') is None,'Public homepage starts no administration session')
            check(headers.get('X-Robots-Tag')=='noindex, nofollow' and headers.get('Content-Security-Policy'),'Unconfigured homepage keeps security and noindex headers')
            for path in ['/en/home','/km/home','/zh/home','/en']:
                status,headers,body=request(path)
                check(status==302 and headers.get('Location')=='/','Legacy home redirects once to root: '+path)
            for path in ['/xx/home','/en/missing','/not-a-page','/theme-assets/missing.css']:
                status,headers,body=request(path)
                check(status==404 and headers.get('Location') is None,'Missing path stays 404: '+path)
            status,headers,body=request('/','HEAD')
            check(status==200 and body==b'','HEAD homepage is bodyless')
            status,headers,body=request('/','POST')
            check(status==405 and headers.get('Allow')=='GET, HEAD','Homepage does not accept writes')
            status,headers,body=request('/?invalid_license=1')
            check(status==503 and b'data-public-home' not in body,'Homepage cannot bypass licence failure')
        finally:
            process.terminate();process.wait(timeout=10)
            log.seek(0);text=log.read()
            check(not any(s in text for s in ['PHP Warning','PHP Fatal','PHP Parse','Uncaught']),'No PHP errors in isolated routing fixture')
print(f'{count} homepage routing checks passed; runtime/license doubles only, live acceptance is separate.')
