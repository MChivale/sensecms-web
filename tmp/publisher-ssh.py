from pathlib import Path
import os
import subprocess
import sys
import tempfile

root = Path(__file__).resolve().parents[1]
config = {}
for line in (root / '.cfg' / 'SSH.txt').read_text(encoding='utf-8-sig').splitlines():
    for marker in (':', '='):
        if marker in line:
            key, value = line.split(marker, 1)
            config[key.strip()] = value.strip()
            break

if len(sys.argv) < 3 or sys.argv[1] not in {'exec', 'upload', 'download'}:
    raise SystemExit('Usage: publisher-ssh.py exec <command> | upload <local> <absolute-remote> | download <absolute-remote> <local>')

with tempfile.TemporaryDirectory(prefix='publisher-ssh-') as temp:
    password = Path(temp) / 'password.txt'
    password.write_text(config['Pass'], encoding='utf-8')
    os.chmod(password, 0o600)
    common = ['-batch', '-hostkey', 'ssh-ed25519 255 SHA256:l3EXiFPkZPk+edE3UmXnSgWEhmnsvcsIeMtKZB2sOTg', '-P', config['Port'], '-pwfile', str(password)]
    target = config['User'] + '@' + config['Server IPv4']
    if sys.argv[1] == 'exec' and len(sys.argv) == 3:
        command = [r'D:\Program Files\PuTTY\plink.exe', *common, target, sys.argv[2]]
    elif sys.argv[1] == 'upload' and len(sys.argv) == 4 and Path(sys.argv[2]).is_file() and sys.argv[3].startswith('/'):
        command = [r'D:\Program Files\PuTTY\pscp.exe', *common, sys.argv[2], target + ':' + sys.argv[3]]
    elif sys.argv[1] == 'download' and len(sys.argv) == 4 and sys.argv[2].startswith('/'):
        command = [r'D:\Program Files\PuTTY\pscp.exe', *common, target + ':' + sys.argv[2], sys.argv[3]]
    else:
        raise SystemExit('Invalid operation or path.')
    raise SystemExit(subprocess.run(command).returncode)
