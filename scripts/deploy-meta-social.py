#!/usr/bin/env python3
"""Pinned production deployment for Sense CMS Social and the official Meta broker."""
from __future__ import annotations

import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import urllib.error
import urllib.request

if os.name == "nt" or os.geteuid() != 0 or len(sys.argv) != 2:
    raise SystemExit("Run as root on Linux: deploy-meta-social.py <private-stage>")

stage = Path(sys.argv[1]).resolve()
web = Path("/home/sensecms.com/web")
nginx = Path("/etc/nginx/sites-available/sensecms.com")
cron = Path("/etc/cron.d/sensecms-social-publishing")
publisher = Path("/root/sensecms-private/publisher.ed25519")
expected = {
    "app/Core/ExtensionContext.php": "2247d4a572176efc474f954f3f0846c38c0233f1c507266977769ea6471f12a6",
    "app/workspace.php": "5f6fbf8ac54730195e460bbc7c30d9e2cfb7320356e337c2a5da20a98dc14abc",
    "app/Core/LicenseService.php": "d446b2bcc2bcd2b3862b7c0476ab53cdbe567be37f1f4d95178e92fdb593fd32",
    "config/workspace.php": "e21fd479b4d8e23df4ac54f46d95d0f149c06116fbbb56c4a7f6c295e9c8a704",
    "public/index.php": "085e66b68bb2a19eab54f8792a33b91e8703dae82c04c554658bd93c7f33a449",
}
core_files = list(expected)
website_files = ["MetaBrokerService.php", "MetaOAuthEndpoint.php", "meta-oauth.php"]
receipt: dict[str, object] = {"status": "preflight", "stage": str(stage), "checks": []}
installed: list[tuple[str, str]] = []
created_website: list[Path] = []
legal_started = False
backup: Path | None = None
install_dir: Path | None = None


def run(command: list[str], *, input: bytes | None = None, user: str | None = None) -> bytes:
    if user:
        command = ["sudo", "-u", user, *command]
    return subprocess.run(command, input=input, check=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT).stdout


def check(ok: bool, label: str) -> None:
    if not ok:
        raise RuntimeError(label)
    receipt["checks"].append(label)
    print("PASS " + label, flush=True)


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def atomic(source: Path, target: Path) -> None:
    temp = target.with_name(target.name + ".meta-new")
    shutil.copy2(source, temp)
    if target.exists():
        os.chown(temp, target.stat().st_uid, target.stat().st_gid)
        os.chmod(temp, target.stat().st_mode & 0o777)
    else:
        os.chown(temp, 0, 0)
        os.chmod(temp, 0o644)
    os.replace(temp, target)


def php(code: str, *args: str, user: str | None = None) -> bytes:
    return run(["php8.5", "-r", code, *args], user=user)


def package(action: str, kind: str, slug: str) -> None:
    code = r'''$root=$argv[1];require $root.'/bootstrap.php';$r=new App\Core\Runtime($root);$db=App\Core\Runtime::connect($r->read('installed')['database']);$m=new App\Core\PackageManager($db,$root,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();'''
    if action == "install":
        code += r'''$s=$m->stageLocalFile($argv[2],$owner);echo json_encode($m->install($s['token'],$owner),JSON_THROW_ON_ERROR);'''
        if install_dir is None:
            raise RuntimeError("Private install staging is unavailable.")
        result = json.loads(php(code, str(web), str(install_dir / f"{kind}-{slug}-0.1.0.zip"), user="sensecms"))
        check(result["version"] == "0.1.0", f"Installed signed {kind}:{slug}@0.1.0")
    else:
        code += r'''$p=$m->package($argv[2],$argv[3]);if($p)$m->uninstall($argv[2],$argv[3],$owner);'''
        php(code, str(web), kind, slug, user="sensecms")


required = [
    stage / ".src" / name for name in website_files
] + [
    stage / ".cms/source" / name for name in core_files
] + [
    stage / ".addons/social-publishing/sense-package.json",
    stage / ".plugins/facebook-publisher/sense-package.json",
    stage / "scripts/publish-meta-legal-pages.php",
    stage / "deploy/nginx/sensecms.com.conf",
    stage / "deploy/cron/sensecms-social-publishing",
]
check(stage.is_dir() and str(stage).startswith("/root/sense-meta-social-"), "Private scoped deployment stage")
check(all(path.is_file() and not path.is_symlink() for path in required), "Complete candidate payload")
check(web.is_dir() and nginx.is_file() and publisher.is_file(), "Expected production installation and publisher key")
check(all(sha(web / name) == digest for name, digest in expected.items()), "Pinned production Core baseline")
product = php(r'''$p=require $argv[1].'/config/product.php';echo $p['core_version'];''', str(web)).decode()
theme = json.loads((web / "storage/theme.json").read_text())["active"]
check(product == "1.0.0" and theme["slug"] == "sensecms" and theme["version"] == "1.0.0", "Core and active theme baseline")
present = php(r'''$r=$argv[1];require $r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);foreach(['addon:social-publishing','plugin:facebook-publisher']as$i){[$t,$s]=explode(':',$i);$q=$db->prepare('SELECT 1 FROM extension_packages WHERE type=? AND slug=?');$q->execute([$t,$s]);if($q->fetchColumn())exit(9);}''', str(web))
check(present == b"", "Social packages absent before first installation")
run(["nginx", "-t"])
run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])

lock_path = Path("/root/sensecms-private/deploy-workspace.lock")
lock = lock_path.open("a+b")
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
try:
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup = Path("/root/sensecms-backups") / (stamp + "-meta-social")
    backup.mkdir(mode=0o700)
    (backup / "core").mkdir(mode=0o700)
    (backup / "website").mkdir(mode=0o700)
    for name in core_files:
        target = web / name
        destination = backup / "core" / name
        destination.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        shutil.copy2(target, destination)
    shutil.copy2(nginx, backup / "nginx-before.conf")
    if cron.exists():
        shutil.copy2(cron, backup / "cron-before")
    for name in website_files:
        target = web / "website" / name
        if target.exists():
            shutil.copy2(target, backup / "website" / name)
        else:
            created_website.append(target)
    with (backup / "database-before.sql").open("wb") as dump:
        subprocess.run(["mysqldump", "--single-transaction", "--routines", "--triggers", "sensecms_site"], check=True, stdout=dump, stderr=subprocess.PIPE)
    os.chmod(backup / "database-before.sql", 0o600)
    check((backup / "database-before.sql").stat().st_size > 4096, "Private production database recovery dump")

    artifacts = stage / "artifacts"
    artifacts.mkdir(mode=0o700)
    build = r'''require $argv[1].'/bootstrap.php';$secret=base64_decode(trim(file_get_contents($argv[2])),true);if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid production publisher key.');try{$a=App\Core\Packages\Archive::build($argv[3],$argv[4],$secret);echo json_encode(['sha256'=>$a['sha256'],'public'=>base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))],JSON_THROW_ON_ERROR);}finally{sodium_memzero($secret);}'''
    hashes: dict[str, str] = {}
    public = ""
    for kind, slug in [("addon", "social-publishing"), ("plugin", "facebook-publisher")]:
        archive = artifacts / f"{kind}-{slug}-0.1.0.zip"
        result = json.loads(php(build, str(web), str(publisher), str(stage / f".{kind}s" / slug), str(archive)))
        hashes[f"{kind}:{slug}"] = result["sha256"]
        public = result["public"]
    (artifacts / "social-release.json").write_text(json.dumps(hashes, separators=(",", ":")))
    (artifacts / "trust.json").write_text(json.dumps({"sensecms-release": public}, separators=(",", ":")))
    os.chmod(artifacts / "social-release.json", 0o600); os.chmod(artifacts / "trust.json", 0o600)
    receipt["artifacts"] = hashes

    testtree = stage / "runtime-tests"
    (testtree / "tests").mkdir(parents=True, mode=0o700)
    (testtree / ".cms").mkdir(mode=0o700)
    (testtree / ".cms/source").symlink_to(web, target_is_directory=True)
    (testtree / ".src").symlink_to(stage / ".src", target_is_directory=True)
    (testtree / ".plugins").symlink_to(stage / ".plugins", target_is_directory=True)
    for name in ["meta-broker.php", "facebook-publisher.php", "social-package-release.php"]:
        shutil.copy2(stage / "tests" / name, testtree / "tests" / name)
    run(["php8.5", str(testtree / "tests/meta-broker.php")])
    run(["php8.5", str(testtree / "tests/facebook-publisher.php")])
    release_output = run(["php8.5", str(testtree / "tests/social-package-release.php"), str(artifacts), str(artifacts / "trust.json")]).decode()
    check("signed social package checks passed" in release_output, "Exact signed package lifecycle on isolated MariaDB")

    for name in core_files:
        atomic(stage / ".cms/source" / name, web / name)
    check(all(sha(web / name) == sha(stage / ".cms/source" / name) for name in core_files), "Exact Core candidate hashes deployed")
    (web / "website").mkdir(exist_ok=True)
    for name in website_files:
        atomic(stage / ".src" / name, web / "website" / name)
        os.chown(web / "website" / name, 0, 0); os.chmod(web / "website" / name, 0o644)
    atomic(stage / "deploy/nginx/sensecms.com.conf", nginx)
    run(["nginx", "-t"])
    run(["systemctl", "reload", "nginx"])
    run(["systemctl", "reload", "php8.5-fpm"])

    legal_started = True
    run(["php8.5", str(stage / "scripts/publish-meta-legal-pages.php"), str(web), "--apply", str(backup)])
    install_dir = web / "storage/private" / ("social-deploy-" + stamp)
    install_dir.mkdir(mode=0o700)
    sensecms_uid = int(run(["id", "-u", "sensecms"]).decode().strip()); sensecms_gid = int(run(["id", "-g", "sensecms"]).decode().strip())
    os.chown(install_dir, sensecms_uid, sensecms_gid)
    for kind, slug in [("addon", "social-publishing"), ("plugin", "facebook-publisher")]:
        target = install_dir / f"{kind}-{slug}-0.1.0.zip"; shutil.copy2(artifacts / target.name, target); os.chown(target, sensecms_uid, sensecms_gid); os.chmod(target, 0o600)
    package("install", "addon", "social-publishing"); installed.append(("addon", "social-publishing"))
    package("install", "plugin", "facebook-publisher"); installed.append(("plugin", "facebook-publisher"))
    atomic(stage / "deploy/cron/sensecms-social-publishing", cron); os.chown(cron, 0, 0); os.chmod(cron, 0o644)

    state = json.loads(php(r'''$r=$argv[1];require $r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$result=[];foreach(['addon:social-publishing','plugin:facebook-publisher']as$i){[$t,$s]=explode(':',$i);$q=$db->prepare('SELECT version,active FROM extension_packages WHERE type=? AND slug=?');$q->execute([$t,$s]);$result[$i]=$q->fetch();}$result['tables']=[];foreach(['social_connections','social_post_targets','social_deliveries']as$t)$result['tables'][$t]=(bool)$db->query("SHOW TABLES LIKE '$t'")->fetchColumn();echo json_encode($result,JSON_THROW_ON_ERROR);''', str(web)))
    check(all(state[i]["version"] == "0.1.0" and state[i]["active"] for i in ["addon:social-publishing", "plugin:facebook-publisher"]), "Production social packages active")
    check(all(state["tables"].values()), "Production social schema installed")
    for route, marker in [("privacy-policy", b"Privacy Policy"), ("terms-of-service", b"Terms of Service"), ("data-deletion", b"Facebook Data Deletion")]:
        with urllib.request.urlopen(f"https://www.sensecms.com/{route}", timeout=30) as response:
            check(response.status == 200 and marker in response.read(524288), "Live legal page /" + route)
    request = urllib.request.Request("https://www.sensecms.com/api/social/meta/v1/start", data=b'{}', headers={"Content-Type":"application/json"}, method="POST")
    try:
        urllib.request.urlopen(request, timeout=30)
        api_status = 200
    except urllib.error.HTTPError as error:
        api_status = error.code
    check(api_status in (401, 503), "Meta broker route is live and fails closed before authorization")
    request = urllib.request.Request("https://www.sensecms.com/social-publishing", method="GET")
    opener = urllib.request.build_opener(urllib.request.HTTPHandler())
    try:
        response = opener.open(request, timeout=30); social_status=response.status
    except urllib.error.HTTPError as error:
        social_status=error.code
    check(social_status in (200, 302), "Social Publishing route is reachable")
    run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])
    run(["nginx", "-t"])
    shutil.rmtree(install_dir); install_dir = None
    receipt.update(status="deployed-awaiting-meta-secret", backup=str(backup), api_status=api_status)
except BaseException:
    receipt["status"] = "rolling-back"
    if backup is not None:
        if legal_started and (backup / "meta-legal-pages.json").exists():
            try: run(["php8.5", str(stage / "scripts/publish-meta-legal-pages.php"), str(web), "--rollback", str(backup)])
            except BaseException: pass
        for kind, slug in reversed(installed):
            try: package("uninstall", kind, slug)
            except BaseException: pass
        for name in core_files:
            source = backup / "core" / name
            if source.exists(): atomic(source, web / name)
        for name in website_files:
            source = backup / "website" / name; target = web / "website" / name
            if source.exists(): atomic(source, target)
            elif target in created_website and target.exists(): target.unlink()
        if (backup / "nginx-before.conf").exists(): atomic(backup / "nginx-before.conf", nginx)
        if (backup / "cron-before").exists(): atomic(backup / "cron-before", cron)
        elif cron.exists(): cron.unlink()
        run(["nginx", "-t"]); run(["systemctl", "reload", "nginx"]); run(["systemctl", "reload", "php8.5-fpm"])
        receipt["status"] = "rolled-back"
    if install_dir is not None and install_dir.is_dir() and install_dir.name.startswith("social-deploy-") and install_dir.parent == web / "storage/private":
        shutil.rmtree(install_dir)
    raise
finally:
    if backup is not None:
        (backup / "receipt.json").write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n")
        os.chmod(backup / "receipt.json", 0o600)
    fcntl.flock(lock, fcntl.LOCK_UN); lock.close()

print(json.dumps(receipt, sort_keys=True), flush=True)
