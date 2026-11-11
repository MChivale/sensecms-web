from pathlib import Path
import os
import re
import shlex
import subprocess
import sys
import tempfile

root = Path(__file__).resolve().parents[1]
lines = (root / '.srv' / 'elingo.school.txt').read_text(encoding='utf-8-sig').splitlines()
sections = {}
current = None
for line in lines:
    if line.startswith('### '):
        current = line[4:].strip()
        sections[current] = {}
        continue
    if current is None:
        continue
    for marker in (':', '='):
        if marker in line:
            key, value = line.split(marker, 1)
            sections[current][key.strip()] = value.strip()
            break
config = sections['SSH']
chown_match = re.search(r'\bchown\s+(?:-[A-Za-z]+\s+)*([A-Za-z0-9_-]+:[A-Za-z0-9_-]+)\b', sections['WEB']['Chown'])
if not chown_match:
    raise SystemExit('Invalid web ownership configuration.')
web_owner = chown_match.group(1)

if len(sys.argv) < 2 or sys.argv[1] not in {'exec', 'upload', 'verify'}:
    raise SystemExit('Usage: elingo-ssh.py exec <command> | upload <local> <absolute-remote> | verify')

with tempfile.TemporaryDirectory(prefix='elingo-ssh-') as temp:
    password = Path(temp) / 'password.txt'
    password.write_text(config['Pass'], encoding='utf-8')
    os.chmod(password, 0o600)
    common = ['-batch', '-hostkey', 'ssh-ed25519 255 SHA256:RSnbfNlVT+HQqw4ehMaGgKCE4RhaVnJz8l0WByhUSrg', '-P', config['Port'], '-pwfile', str(password)]
    target = config['User'] + '@' + config['IPv4']
    if sys.argv[1] == 'verify' and len(sys.argv) == 2:
        web_directory = shlex.quote(sections['WEB']['Directory'])
        remote = (
            f'taskSearchRoot="$(dirname \"$(realpath {web_directory})\")"; taskBootstrap="$(find \"$taskSearchRoot\" -maxdepth 6 -type f -name bootstrap.php -print -quit 2>/dev/null)"; '
            'test -n "$taskBootstrap"; '
            "php8.5 -r 'require $argv[1]; $runtime = new App\\Core\\Runtime(dirname($argv[1])); "
            "if ($runtime->baseUrl() !== \"https://elingo.school\") exit(1); if (!$runtime->read(\"installed\")) exit(1); "
            "echo \"Verified Elingo runtime.\\n\";' \"$taskBootstrap\""
        )
        command = [r'D:\Program Files\PuTTY\plink.exe', *common, target, remote]
    elif sys.argv[1] == 'exec' and len(sys.argv) == 3:
        remote = (sys.argv[2]
            .replace('{web}', shlex.quote(sections['WEB']['Directory']))
            .replace('{public}', shlex.quote(sections['WEB']['Public Directory']))
            .replace('{chown_owner}', shlex.quote(web_owner)))
        command = [r'D:\Program Files\PuTTY\plink.exe', *common, target, remote]
    elif sys.argv[1] == 'upload' and len(sys.argv) == 4 and Path(sys.argv[2]).is_file() and sys.argv[3].startswith('/'):
        command = [r'D:\Program Files\PuTTY\pscp.exe', *common, sys.argv[2], target + ':' + sys.argv[3]]
    else:
        raise SystemExit('Invalid operation or path.')
    raise SystemExit(subprocess.run(command).returncode)
