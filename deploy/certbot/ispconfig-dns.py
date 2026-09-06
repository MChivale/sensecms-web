#!/usr/bin/python3
"""Certbot DNS-01 hooks scoped to the Sense CMS certificate only."""

import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time
import urllib.request

API = "https://mas.ittsp.net:333/remote/json.php"
CREDENTIALS = Path("/etc/letsencrypt/sensecms/DNS.txt")
DOMAIN = "sensecms.com"
NAME = "_acme-challenge.sensecms.com."
ZONE_ID = 117
CLIENT_ID = 9  # Verified in ISPConfig; avoids granting Client API functions.
# Authoritative answers are the source of truth; unrelated recursive caches can
# retain old NXDOMAIN responses for an hour and are not ACME's validators.
RESOLVERS = ("ns1.ittsp.net", "ns2.ittsp.net")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise RuntimeError("DNS API redirects are not allowed")


class Dns:
    def __init__(self):
        meta = CREDENTIALS.lstat()
        if not stat.S_ISREG(meta.st_mode) or meta.st_uid != 0 or meta.st_mode & 0o077:
            raise RuntimeError("DNS credentials must be a root-owned private file")
        cfg = {}
        for line in CREDENTIALS.read_text(encoding="utf-8-sig").splitlines():
            match = re.match(r"^\s*([^:=]+)\s*[:=]\s*(.*)$", line)
            if match:
                cfg[match[1].strip()] = match[2].strip()
        self.http = urllib.request.build_opener(NoRedirect)
        self.session = None
        self.session = self.call("login", username=cfg["User"], password=cfg["Pass"], client_login=False)
        if not isinstance(self.session, str) or not self.session:
            raise RuntimeError("DNS API did not return a session")

    def call(self, method, **params):
        if self.session:
            params["session_id"] = self.session
        req = urllib.request.Request(API + "?" + method, data=json.dumps(params).encode(),
                                     headers={"Content-Type": "application/json"})
        with self.http.open(req, timeout=30) as res:
            reply = json.load(res)
        if reply.get("code") != "ok":
            # API messages may contain sensitive request fields; never log them.
            raise RuntimeError("DNS API rejected " + method)
        return reply["response"]

    def close(self):
        try:
            self.call("logout")
        except Exception:
            print("Warning: DNS API logout failed; session will expire", file=sys.stderr)

    def zone(self):
        zone = self.call("dns_zone_get", primary_id=ZONE_ID)
        if (not isinstance(zone, dict) or zone.get("origin") != DOMAIN + "."
                or int(zone.get("id", 0)) != ZONE_ID or zone.get("active", "").lower() != "y"
                or int(zone.get("sys_userid", 0)) != 10 or int(zone.get("sys_groupid", 0)) != 10
                or int(zone.get("server_id", 0)) != 3):
            raise RuntimeError("DNS zone identity or active status mismatch")
        return zone

    def records(self, token):
        rows = self.call("dns_txt_get", primary_id={"zone": ZONE_ID, "name": NAME, "type": "TXT"})
        if not isinstance(rows, list):
            raise RuntimeError("Unexpected DNS record response")
        return [row for row in rows if int(row.get("zone", 0)) == ZONE_ID
                and row.get("name") == NAME and row.get("type", "").upper() == "TXT"
                and row.get("data") == token]

    def cleanup(self, token):
        for row in self.records(token):
            result = self.call("dns_txt_delete", primary_id=int(row["id"]), update_serial=True)
            if not result:
                raise RuntimeError("DNS API did not confirm challenge deletion")
        if self.records(token):
            raise RuntimeError("Challenge record still exists after cleanup")


def propagated(token, present=True):
    for server in RESOLVERS:
        result = subprocess.run(["/usr/bin/dig", "@" + server, NAME, "TXT", "+time=3",
                                 "+tries=1", "+noall", "+comments", "+answer"],
                                capture_output=True, text=True, timeout=6)
        output = result.stdout
        valid = ("status: NOERROR" in output or (not present and "status: NXDOMAIN" in output))
        found = '"' + token + '"' in output
        if result.returncode or not valid or found != present:
            return False
    return True


def wait_dns(token, present=True):
    deadline = time.monotonic() + 600
    while time.monotonic() < deadline:
        if propagated(token, present):
            return
        time.sleep(10)
    raise RuntimeError("DNS propagation timed out")


def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("check", "auth", "cleanup"):
        raise RuntimeError("Usage: ispconfig-dns.py check|auth|cleanup")
    action = sys.argv[1]
    token = os.environ.get("CERTBOT_VALIDATION", "")
    if action != "check":
        if os.environ.get("CERTBOT_DOMAIN") not in (DOMAIN, "*." + DOMAIN):
            raise RuntimeError("Certificate domain is outside the permitted scope")
        if not re.fullmatch(r"[A-Za-z0-9_-]{43}", token):
            raise RuntimeError("Invalid DNS-01 validation token")
    dns = Dns()
    try:
        zone = dns.zone()
        if action == "check":
            dns.records("check")
            print("DNS API login and Sense CMS zone/owner lookup: OK")
        elif action == "cleanup":
            dns.cleanup(token)
            wait_dns(token, present=False)
            print("Sense CMS DNS challenge cleaned up", file=sys.stderr)
        else:
            try:
                rows = dns.records(token)
                if not rows:
                    record = dns.call("dns_txt_add", client_id=CLIENT_ID, update_serial=True,
                                      params={"server_id": int(zone["server_id"]), "zone": ZONE_ID,
                                              "name": NAME, "type": "TXT", "data": token,
                                              "aux": 0, "ttl": 60, "active": "y",
                                              "stamp": time.strftime("%Y-%m-%d %H:%M:%S"),
                                              "serial": int(time.time())})
                    if isinstance(record, bool) or not str(record).isdigit() or int(record) <= 0:
                        raise RuntimeError("DNS API did not confirm challenge creation")
                elif any(row.get("active", "").lower() != "y" for row in rows):
                    raise RuntimeError("Matching DNS challenge is inactive")
                wait_dns(token)
                # Allow an earlier 60-second positive answer to expire.
                time.sleep(65)
                print("Sense CMS DNS challenge propagated", file=sys.stderr)
            except Exception:
                dns.cleanup(token)
                raise
    finally:
        dns.close()


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        # Suppress third-party exception details and tracebacks containing secrets.
        detail = str(exc) if type(exc) is RuntimeError else type(exc).__name__
        print("Sense CMS DNS hook failed: " + detail, file=sys.stderr)
        sys.exit(1)
