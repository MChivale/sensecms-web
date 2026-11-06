"""Run with the disposable local PostgreSQL port and PGPASSWORD. Mail never leaves loopback."""
from email import policy
from email.parser import BytesParser
from pathlib import Path
import shutil
import socketserver
import subprocess
import sys
import tempfile
import threading

messages = []
refusals = []
class Sink(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b"220 test SMTP\r\n")
        to = ""
        while line := self.rfile.readline():
            cmd = line.split(maxsplit=1)[0].upper()
            if cmd == b"RCPT":
                to = line.decode().split("<")[1].split(">")[0]
                if to == "retry@example.test" and not refusals:
                    refusals.append(to)
                    self.wfile.write(b"451 retry later\r\n")
                    continue
            if cmd == b"DATA":
                self.wfile.write(b"354 ready\r\n")
                data = bytearray()
                while (part := self.rfile.readline()) not in (b"", b".\r\n"):
                    data.extend(part[1:] if part.startswith(b"..") else part)
                messages.append((to, BytesParser(policy=policy.default).parsebytes(bytes(data))))
                if to == "unknown@example.test":
                    return
            if cmd == b"QUIT":
                self.wfile.write(b"221 bye\r\n")
                return
            self.wfile.write(b"250 OK\r\n")

root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory() as tmp, socketserver.TCPServer(("127.0.0.1", 0), Sink) as smtp:
    handler = Path(tmp, "addons/calendar/scripts/chat-callback-tasks.php")
    handler.parent.mkdir(parents=True)
    shutil.copyfile(root / ".addons/calendar/scripts/chat-callback-tasks.php", handler)
    thread = threading.Thread(target=smtp.serve_forever, daemon=True)
    thread.start()
    try:
        subprocess.run(["php", str(root / "tests/chat-callback.php"), sys.argv[1], str(smtp.server_address[1]), tmp], check=True)
    finally:
        smtp.shutdown()
assert len(messages) == 9
for to, msg in messages:
    text = msg.get_body(preferencelist=("plain",)).get_content()
    html = msg.get_body(preferencelist=("html",)).get_content()
    assert "Studio & Test" in msg["Subject"] and "Studio &amp; Test" in html
    assert 'https://installation.example/uploads/logo.png' in html and '#227755' in html
    assert 'Sense CMS' not in text and 'Secure message from Sense CMS' not in html
    if to in ("owner@example.test", "team@example.test"):
        assert "/conversations?conversation=" in text and "visitor-one@" not in text
    if to == "visitor-one@example.test":
        assert "Dziękujemy za rozmowę z Studio & Test." in text
    if to == "visitor-two@example.test":
        assert "Someone on our team" in text
print("PASS Local SMTP captured branded plain text and HTML, visitor and team delivery, with no duplicate copies.")
