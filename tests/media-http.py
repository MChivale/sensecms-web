"""Isolated real multipart limits. --nginx also tests a private Nginx/FPM stack, never production storage."""
import http.client
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time

root=Path(__file__).resolve().parents[1]
nginx='--nginx' in sys.argv
if nginx and (os.name!='posix' or os.geteuid()!=0):raise SystemExit('Private Linux QA only')
with tempfile.TemporaryDirectory(prefix='sense-media-http-') as tmp:
    work=Path(tmp);os.chmod(work,0o755)
    # Copy only validation code; the PHP fixture cannot access any application database.
    shutil.copyfile(root/'.cms/source/app/Core/MediaLibrary.php',work/'MediaLibrary.php')
    router=work/'index.php'
    router.write_text('''<?php
require __DIR__.'/MediaLibrary.php';
header('Content-Type: application/json');
$post=ini_parse_quantity(ini_get('post_max_size'));
if((int)($_SERVER['CONTENT_LENGTH']??0)>App\\Core\\MediaLibrary::MAX_REQUEST||($post>0&&(int)($_SERVER['CONTENT_LENGTH']??0)>$post)){http_response_code(413);echo '{}';exit;}
$db=new class extends PDO {public function __construct(){}public function prepare(string $query,array $options=[]):PDOStatement|false {throw new RuntimeException('accepted');}};
try{(new App\\Core\\MediaLibrary($db,__DIR__))->store($_FILES['asset']??[],1);}
catch(RuntimeException $e){$ok=$e->getMessage()==='accepted';http_response_code($ok?200:422);echo json_encode(['accepted'=>$ok,'message'=>$e->getMessage(),'size'=>$_FILES['asset']['size']??0]);}
''',encoding='utf-8')
    for p in [router,work/'MediaLibrary.php']:os.chmod(p,0o644)
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    processes=[]
    log=(work/'output.log').open('wb')
    try:
        if nginx:
            pool=(root/'deploy/php/sensecms.conf').read_text().replace('[sensecms]','[mediaqa]').replace('/run/php/php8.5-sensecms.sock',str(work/'php.sock')).replace('/home/sensecms.com/web/storage/php-error.log',str(work/'php-error.log'))
            (work/'php.conf').write_text('[global]\nerror_log = '+str(work/'fpm.log')+'\n'+pool)
            processes.append(subprocess.Popen(['php-fpm8.5','-F','-y',str(work/'php.conf')],stdout=log,stderr=log))
            conf=(root/'deploy/nginx/sensecms.com.conf').read_text()
            import re
            public=re.search(r'    location / \{.*?\n    \}',conf,re.S)[0]
            admin=re.search(r'    location ~ \^/\(\?:content.*?\n    \}',conf,re.S)[0]
            # Identical candidate location limits plus the normal front-controller internal redirect.
            (work/'nginx.conf').write_text(f'''user www-data;
pid {work}/nginx.pid;
error_log {work}/nginx.log;
events {{}}
http {{ access_log off; client_body_temp_path {work}/body; server {{
listen 127.0.0.1:{port}; root {work}; client_max_body_size 96m;
{public}
{admin}
location = /index.php {{ include /etc/nginx/fastcgi_params; fastcgi_param SCRIPT_FILENAME {router}; fastcgi_pass unix:{work}/php.sock; }}
}} }}''')
            processes.append(subprocess.Popen(['nginx','-c',str(work/'nginx.conf'),'-g','daemon off;'],stdout=log,stderr=log))
        else:
            processes.append(subprocess.Popen(['php','-d','upload_max_filesize=80M','-d','post_max_size=96M','-d','display_errors=0','-S',f'127.0.0.1:{port}',str(router)],stdout=log,stderr=log))
        for attempt in range(100):
            if any(p.poll() is not None for p in processes):raise RuntimeError('Private QA server failed')
            try:
                conn=http.client.HTTPConnection('127.0.0.1',port,timeout=2);conn.request('GET','/index.php');res=conn.getresponse();res.read();conn.close()
                if res.status==422:break
            except OSError:pass
            time.sleep(.05)
        else:raise RuntimeError('QA start timeout')
        for size,expected in [(80*1048576,200),(80*1048576+1,422)]:
            conn=http.client.HTTPConnection('127.0.0.1',port,timeout=45)
            prefix=b'--sense\r\nContent-Disposition: form-data; name="asset"; filename="boundary.mp4"\r\nContent-Type: video/mp4\r\n\r\n'
            suffix=b'\r\n--sense--\r\n';header=b'\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2'
            conn.putrequest('POST','/content/media/upload');conn.putheader('Content-Type','multipart/form-data; boundary=sense');conn.putheader('Content-Length',len(prefix)+size+len(suffix));conn.endheaders();conn.send(prefix+header)
            left=size-len(header);chunk=b'\0'*1048576
            while left:piece=chunk[:min(left,len(chunk))];conn.send(piece);left-=len(piece)
            conn.send(suffix);res=conn.getresponse();raw=res.read();conn.close()
            if not raw.startswith(b'{'):raise RuntimeError('QA response '+str(res.status)+': '+raw[:300].decode(errors='replace'))
            data=json.loads(raw)
            assert res.status==expected,(res.status,data)
            if expected==200:assert data['accepted'] and data['size']==size
            else:assert not data['accepted'] and 'server upload limit' in data['message']
            print('PASS real multipart',size,'bytes ->',expected,flush=True)
        if nginx:
            for path,size in [('/content/media/upload',96*1048576+1),('/contact',16385)]:
                conn=http.client.HTTPConnection('127.0.0.1',port,timeout=5);conn.putrequest('POST',path);conn.putheader('Content-Length',size);conn.endheaders()
                res=conn.getresponse();res.read();conn.close();assert res.status==413
                print('PASS Nginx early 413',path,flush=True)
        print('PASS isolated multipart transport and application validation; no production uploads or database writes.')
    except BaseException:
        for name in ['nginx.log','fpm.log','php-error.log','output.log']:
            path=work/name
            if path.exists():print(name+': '+path.read_text(errors='replace')[-2000:],file=sys.stderr)
        raise
    finally:
        for p in reversed(processes):
            p.terminate()
            try:p.wait(timeout=8)
            except subprocess.TimeoutExpired:p.kill();p.wait()
        log.close()
