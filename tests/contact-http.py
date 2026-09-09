"""Real HTTP + loopback SMTP acceptance, explicitly isolated from production mail."""
import email
from email import policy
import http.cookiejar
import json
from pathlib import Path
import re
import socketserver
import subprocess
import threading
import urllib.error
import urllib.parse
import urllib.request

root = Path('/root/sense-workspace-test.SC495Gg0')
if not str(Path(__file__).resolve()).startswith(str(root) + '/'):
    raise SystemExit('Run only in the private QA fixture.')
base = 'http://127.0.0.1:8873'
uid = '03000000-0000-4000-a000-000000000001'
messages = []


class SMTP(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b'220 QA loopback SMTP\r\n')
        envelope = []
        while line := self.rfile.readline():
            command = line.split(b' ', 1)[0].strip().upper()
            if command == b'DATA':
                self.wfile.write(b'354 End data\r\n')
                chunks = []
                while (line := self.rfile.readline()) not in (b'.\r\n', b''):
                    if not line.endswith(b'\r\n') or len(line) > 1000:
                        raise RuntimeError('Invalid SMTP wire format')
                    chunks.append(line[1:] if line.startswith(b'..') else line)
                messages.append((envelope.copy(), b''.join(chunks)))
                self.wfile.write(b'250 Accepted by QA sink\r\n')
            elif command == b'QUIT':
                # DATA was accepted. Exercise clients against a peer that drops QUIT.
                return
            else:
                if command == b'RCPT':
                    envelope.append(line.decode().strip())
                self.wfile.write(b'250 OK\r\n')


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


server = Server(('127.0.0.1', 2526), SMTP)
threading.Thread(target=server.serve_forever, daemon=True).start()
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def request(path, data=None):
    try:
        res = client.open(urllib.request.Request(base + path, data=None if data is None else urllib.parse.urlencode(data).encode(), headers={'Accept':'application/json'} if data is not None else {}), timeout=30)
        return res.status, res.read(), res.headers
    except urllib.error.HTTPError as res:
        return res.code, res.read(), res.headers


def check(ok, name):
    if not ok:
        raise RuntimeError('FAIL ' + name)
    print('PASS ' + name, flush=True)


def new_session():
    jar.clear()
    status, body, _ = request('/contact')
    check(status == 200 and b'data-contact-form' in body, 'CMS contact form renders')
    return re.search(rb'name="csrf" value="([a-f0-9]{64})"', body)[1].decode()


def captcha():
    status, image, headers = request('/captcha/forms/' + uid + '.png')
    check(status == 200 and image.startswith(b'\x89PNG') and 'no-store' in headers.get('Cache-Control', ''), 'Native CAPTCHA returns non-cacheable PNG')
    sid = next(c.value for c in jar if c.name == 'sensecms_session')
    # Private QA-only fixture access, never exported or enabled as an application bypass.
    code = 'session_start(); session_decode(file_get_contents($argv[1])); echo $_SESSION[$argv[2]];'
    return subprocess.run(['php8.5','-r',code,str(root / '.cms/source/storage/sessions' / ('sess_' + sid)),'sensecms_captcha_form_' + uid + '_code'], capture_output=True, check=True).stdout.decode()


try:
    csrf = new_session()
    data = {'csrf':csrf,'locale':'en','website':'','fields[name]':'Private QA','fields[email]':'visitor@example.test','fields[company]':'Sense CMS QA','fields[topic]':'Technical question','fields[message]':'Native CAPTCHA and receipt acceptance.\n<script>unsafe()</script>\n.Line starting with a dot.','fields[consent]':'1'}
    path = '/api/forms/' + uid + '/submit'
    check(request(path,{**data,'csrf':''})[0] == 419, 'Missing CSRF rejected')
    check(request(path,{**data,'website':'spam'})[0] == 422, 'Honeypot rejected')
    check(request(path,{**data,'captcha':'wrong'})[0] == 422, 'Incorrect CAPTCHA rejected')
    check(request('/captcha/forms/ffffffff-ffff-4fff-afff-ffffffffffff.png')[0] == 404, 'Unknown form cannot allocate CAPTCHA')
    data['csrf'] = new_session()
    valid = captcha()
    check(request(path,{**data,'captcha':valid,'fields[email]':'invalid'})[0] == 422, 'Invalid email rejected server-side')
    check(len(messages) == 0, 'Invalid requests never send email')
    data['captcha'] = captcha()
    status, body, _ = request(path,data)
    result = json.loads(body)
    check(status == 200 and result['ok'] and result['data']['receipt_sent'] is True, 'Submission stored and sender copy accepted')
    check(len(messages) == 2, 'Exactly one notification and one copy')
    check(any('team@example.test' in address for address in messages[0][0]) and any('visitor@example.test' in address for address in messages[1][0]), 'Notification and copy use their intended recipients')
    note = email.message_from_bytes(messages[0][1], policy=policy.default)
    receipt = email.message_from_bytes(messages[1][1], policy=policy.default)
    check(note['Reply-To'] == 'visitor@example.test', 'Team can reply directly to the sender')
    html = receipt.get_body(preferencelist=('html',)).get_content()
    text = receipt.get_body(preferencelist=('plain',)).get_content()
    check('Thank you for getting in touch.' in html and 'Sense' in html and 'CMS' in html, 'Branded HTML receipt')
    check('<script>unsafe()</script>' not in html and '&lt;script&gt;unsafe()&lt;/script&gt;' in html, 'Submitted markup escaped in HTML email')
    check('Native CAPTCHA and receipt acceptance.' in text and '.Line starting with a dot.' in text, 'Plain-text copy retains submitted content')
    check(request(path,data)[0] == 422 and len(messages) == 2, 'CAPTCHA is single use; replay cannot resend')
    # An unavailable SMTP endpoint must preserve the message and disclose copy failure.
    server.shutdown(); server.server_close()
    data['csrf'] = new_session(); data['captcha'] = captcha()
    status, body, _ = request(path,data)
    result = json.loads(body)
    check(status == 200 and result['ok'] and result['data']['receipt_sent'] is False and 'saved' in result['message'], 'SMTP failure preserves inbox submission and reports copy failure')
    check('password' not in body.decode().lower() and 'SMTP' not in body.decode(), 'Public errors contain no mail configuration')
    data['csrf'] = new_session()
    for _ in range(5):
        request(path,{**data,'captcha':'invalid'})
    check(request(path,{**data,'captcha':'invalid'})[0] == 429, 'Session attempt limiter includes failed CAPTCHA')
    print('Contact acceptance passed.', flush=True)
finally:
    server.server_close()
