#!/usr/bin/env python3
"""Pinned production deployment for Bluesky Publisher 0.1.0 and its OAuth broker."""
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
    raise SystemExit("Run as root on Linux: deploy-bluesky-publisher-0.1.0.py <private-stage>")

stage = Path(sys.argv[1]).resolve()
web = Path("/home/sensecms.com/web")
nginx = Path("/etc/nginx/sites-available/sensecms.com")
publisher = Path("/root/sensecms-private/publisher.ed25519")
broker_dir = web / "storage/private/bluesky"
expected = {
    web / "app/Core/LicenseService.php": "fd200bac3060ab9edc534c48ef8852e6a50dbc751a5a01464cae41964cf07bdf",
    web / "config/workspace.php": "067683ba5fa7e061a1fcdd80ae6e501c8d26863fdf4c9595701dc88be884c500",
    nginx: "529b64388a13151f7daf185b20e66ef394ad56fd122dd6f20c03bbf433be0753",
}
core_files = ["app/Core/LicenseService.php", "config/workspace.php"]
website_files = ["AtprotoCrypto.php", "BlueskyBrokerService.php", "BlueskyOAuthEndpoint.php", "bluesky-oauth.php"]
receipt = {"status": "preflight", "checks": []}
backup = install_dir = None
bluesky_installed = mutated = False
created_website = []


def run(cmd, *, user=None, input=None):
    if user:
        cmd = ["sudo", "-u", user, *cmd]
    result = subprocess.run(cmd, input=input, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if result.returncode:
        detail = result.stdout.decode(errors="replace").strip()
        raise RuntimeError(detail or f"Command failed with exit code {result.returncode}.")
    return result.stdout


def php(code, *args, user=None):
    return run(["php8.5", "-r", code, *map(str, args)], user=user)


def check(ok, label):
    if not ok:
        raise RuntimeError(label)
    receipt["checks"].append(label)
    print("PASS " + label, flush=True)


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def atomic(source, target):
    temp = target.with_name(target.name + ".bluesky-new")
    shutil.copy2(source, temp)
    if target.exists():
        os.chown(temp, target.stat().st_uid, target.stat().st_gid)
        os.chmod(temp, target.stat().st_mode & 0o777)
    else:
        os.chown(temp, 0, 0)
        os.chmod(temp, 0o644)
    os.replace(temp, target)


def state():
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$packages=[];foreach([['addon','social-publishing'],['plugin','facebook-publisher'],['plugin','x-publisher'],['plugin','linkedin-publisher'],['plugin','bluesky-publisher']]as$p){$q=$db->prepare("SELECT version,active,signature_status FROM extension_packages WHERE type=? AND slug=?");$q->execute($p);$packages[$p[0].':'.$p[1]]=$q->fetch()?:null;}$tables=['social_connections','social_post_targets','social_deliveries'];$rows=[];$fingerprints=[];foreach($tables as$t){$data=$db->query("SELECT * FROM `$t` ORDER BY 1")->fetchAll();$rows[$t]=count($data);$fingerprints[$t]=hash('sha256',json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}$due=(int)$db->query("SELECT COUNT(*) FROM social_post_targets t INNER JOIN posts p ON p.id=t.post_id WHERE t.enabled=1 AND t.last_enqueued_revision<t.revision AND (p.status='published' OR (p.status='scheduled' AND p.published_at<=UTC_TIMESTAMP()))")->fetchColumn();echo json_encode(['packages'=>$packages,'rows'=>$rows,'fingerprints'=>$fingerprints,'due'=>$due],JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web))


def install(archive):
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();$s=$m->stageLocalFile($argv[2],$owner);echo json_encode($m->install($s['token'],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web, archive, user="sensecms"))


def uninstall_bluesky():
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();if($m->package('plugin','bluesky-publisher'))$m->uninstall('plugin','bluesky-publisher',$owner);'''
    php(code, web, user="sensecms")


required = [
    *(stage / ".src" / name for name in website_files),
    *(stage / ".cms/source" / name for name in core_files),
    stage / ".addons/social-publishing/sense-package.json",
    stage / ".addons/social-publishing/addon.json",
    stage / ".plugins/bluesky-publisher/sense-package.json",
    stage / ".plugins/bluesky-publisher/plugin.json",
    stage / "deploy/nginx/sensecms.com.conf",
    stage / "tests/bluesky-broker.php",
    stage / "tests/bluesky-publisher.php",
    stage / "tests/bluesky-social-release.php",
    stage / "scripts/provision-bluesky-broker.php",
]
check(stage.is_dir() and str(stage).startswith("/root/sense-bluesky-publisher-"), "Private scoped deployment stage")
check(all(path.is_file() and not path.is_symlink() for path in required), "Complete Bluesky candidate payload")
check(web.is_dir() and nginx.is_file() and publisher.is_file(), "Production installation and publisher key")
check(all(path.is_file() and sha(path) == digest for path, digest in expected.items()), "Pinned production Core and Nginx baseline")
check(all(not (web / "website" / name).exists() for name in website_files), "Bluesky broker files absent before first deployment")
check(not broker_dir.exists() and not broker_dir.is_symlink(), "Bluesky private broker storage absent before first provisioning")
before = state()
addon_before = before["packages"]["addon:social-publishing"]
facebook_before = before["packages"]["plugin:facebook-publisher"]
x_before = before["packages"]["plugin:x-publisher"]
linkedin_before = before["packages"]["plugin:linkedin-publisher"]
check(addon_before and addon_before["version"] == "0.2.1" and addon_before["active"] and addon_before["signature_status"] == "verified", "Installed signed Social Publishing 0.2.1 baseline")
check(facebook_before and facebook_before["version"] == "0.2.0" and facebook_before["active"] and facebook_before["signature_status"] == "verified", "Installed signed Facebook Publisher 0.2.0 baseline")
check(x_before and x_before["version"] == "0.1.0" and x_before["active"] and x_before["signature_status"] == "verified", "Installed signed X Publisher 0.1.0 baseline")
check(linkedin_before and linkedin_before["version"] == "0.1.0" and linkedin_before["active"] and linkedin_before["signature_status"] == "verified", "Installed signed LinkedIn Publisher 0.1.0 baseline")
check(before["packages"]["plugin:bluesky-publisher"] is None, "Bluesky Publisher absent before first installation")
for path in [*stage.glob(".src/*.php"), *stage.glob(".plugins/bluesky-publisher/**/*.php"), stage / "tests/bluesky-broker.php", stage / "tests/bluesky-publisher.php", stage / "tests/bluesky-social-release.php", stage / "scripts/provision-bluesky-broker.php"]:
    run(["php8.5", "-l", str(path)])
run(["python3", "-m", "py_compile", str(stage / "scripts/deploy-bluesky-publisher-0.1.0.py")])
run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])

lock = Path("/root/sensecms-private/deploy-workspace.lock").open("a+b")
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
try:
    artifacts = stage / "artifacts"
    artifacts.mkdir(mode=0o700)
    build = r'''require$argv[1].'/bootstrap.php';$secret=base64_decode(trim(file_get_contents($argv[2])),true);if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid key');try{$a=App\Core\Packages\Archive::build($argv[3],$argv[4],$secret);echo json_encode(['sha256'=>$a['sha256'],'public'=>base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))],JSON_THROW_ON_ERROR);}finally{sodium_memzero($secret);}'''
    releases = [("addon", "social-publishing", "0.2.1"), ("plugin", "bluesky-publisher", "0.1.0")]
    hashes = {}
    public = ""
    for kind, slug, version in releases:
        archive = artifacts / f"{kind}-{slug}-{version}.zip"
        result = json.loads(php(build, web, publisher, stage / f".{kind}s" / slug, archive))
        hashes[f"{kind}:{slug}"] = result["sha256"]
        public = result["public"]
    (artifacts / "bluesky-social-release.json").write_text(json.dumps(hashes, separators=(",", ":")))
    (artifacts / "trust.json").write_text(json.dumps({"sensecms-release": public}, separators=(",", ":")))
    os.chmod(artifacts / "bluesky-social-release.json", 0o600)
    os.chmod(artifacts / "trust.json", 0o600)
    receipt["artifacts"] = hashes

    testtree = stage / "runtime-tests"
    (testtree / "tests").mkdir(parents=True, mode=0o700)
    (testtree / ".cms").mkdir(mode=0o700)
    (testtree / ".cms/source").symlink_to(web, target_is_directory=True)
    (testtree / ".src").symlink_to(stage / ".src", target_is_directory=True)
    (testtree / ".plugins").symlink_to(stage / ".plugins", target_is_directory=True)
    (testtree / ".addons").symlink_to(stage / ".addons", target_is_directory=True)
    for name in ["bluesky-broker.php", "bluesky-publisher.php", "bluesky-social-release.php"]:
        shutil.copy2(stage / "tests" / name, testtree / "tests" / name)
    check("12 Bluesky broker checks passed" in run(["php8.5", str(testtree / "tests/bluesky-broker.php")]).decode(), "Isolated Bluesky OAuth broker protocol")
    check("13 Bluesky Publisher protocol checks passed" in run(["php8.5", str(testtree / "tests/bluesky-publisher.php")]).decode(), "Isolated Bluesky publishing protocol")
    check("signed Bluesky social package checks passed" in run(["php8.5", str(testtree / "tests/bluesky-social-release.php"), str(artifacts), str(artifacts / "trust.json")]).decode(), "Exact signed Bluesky package lifecycle on isolated MariaDB")

    stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup = Path("/root/sensecms-backups") / (stamp + "-bluesky-publisher-010")
    backup.mkdir(mode=0o700)
    receipt["backup"] = str(backup)
    (backup / "core").mkdir(mode=0o700)
    (backup / "website").mkdir(mode=0o700)
    for name in core_files:
        target = web / name
        destination = backup / "core" / name
        destination.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        shutil.copy2(target, destination)
    shutil.copy2(nginx, backup / "nginx-before.conf")
    for name in website_files:
        target = web / "website" / name
        if target.exists():
            shutil.copy2(target, backup / "website" / name)
        else:
            created_website.append(target)
    with (backup / "database-before.sql").open("wb") as out:
        subprocess.run(["mysqldump", "--single-transaction", "--routines", "--triggers", "sensecms_site"], check=True, stdout=out, stderr=subprocess.PIPE)
    os.chmod(backup / "database-before.sql", 0o600)
    check((backup / "database-before.sql").stat().st_size > 4096, "Private production database recovery dump")

    for name in core_files:
        atomic(stage / ".cms/source" / name, web / name)
    for name in website_files:
        target = web / "website" / name
        atomic(stage / ".src" / name, target)
        os.chown(target, 0, 0)
        os.chmod(target, 0o644)
    atomic(stage / "deploy/nginx/sensecms.com.conf", nginx)
    mutated = True
    run(["nginx", "-t"])
    run(["systemctl", "reload", "nginx"])
    run(["systemctl", "reload", "php8.5-fpm"])

    uid = int(run(["id", "-u", "sensecms"]).decode())
    gid = int(run(["id", "-g", "sensecms"]).decode())
    install_dir = web / "storage/private" / ("bluesky-publisher-deploy-" + stamp)
    install_dir.mkdir(mode=0o700)
    os.chown(install_dir, uid, gid)
    candidate = install_dir / "plugin-bluesky-publisher-0.1.0.zip"
    shutil.copy2(artifacts / candidate.name, candidate)
    os.chown(candidate, uid, gid)
    os.chmod(candidate, 0o600)
    result = install(candidate)
    bluesky_installed = True
    check(result["version"] == "0.1.0", "PackageManager installed Bluesky Publisher 0.1.0")

    after = state()
    bluesky_after = after["packages"]["plugin:bluesky-publisher"]
    check(bluesky_after and bluesky_after["version"] == "0.1.0" and bluesky_after["active"] and bluesky_after["signature_status"] == "verified", "Signed Bluesky Publisher 0.1.0 active")
    check(after["packages"]["addon:social-publishing"] == addon_before, "Existing Social Publishing add-on retained")
    check(after["packages"]["plugin:facebook-publisher"] == facebook_before, "Existing Facebook Publisher retained")
    check(after["packages"]["plugin:x-publisher"] == x_before, "Existing X Publisher retained")
    check(after["packages"]["plugin:linkedin-publisher"] == linkedin_before, "Existing LinkedIn Publisher retained")
    check(after["rows"] == before["rows"] and after["fingerprints"] == before["fingerprints"], "Existing social connections, targets and deliveries preserved exactly")
    for source, deployed in [
        *((stage / ".cms/source" / name, web / name) for name in core_files),
        *((stage / ".src" / name, web / "website" / name) for name in website_files),
        (stage / ".plugins/bluesky-publisher/runtime.php", web / "plugins/bluesky-publisher/runtime.php"),
        (stage / ".plugins/bluesky-publisher/src/provider.php", web / "plugins/bluesky-publisher/src/provider.php"),
    ]:
        check(sha(source) == sha(deployed), "Deployed checksum " + deployed.name)
    if after["due"] == 0:
        worker = json.loads(run(["php8.5", str(web / "addons/social-publishing/scripts/social-worker.php"), str(web)], user="sensecms"))
        check(worker.get("ok") is True and worker.get("published") == 0, "Production worker healthy without publishing content")
    run(["php8.5", str(stage / "scripts/provision-bluesky-broker.php"), str(web)])
    run(["chown", "-R", "sensecms:sensecms", str(broker_dir)])
    check((web / "storage/private/bluesky/key.bin").stat().st_size == 32 and (web / "storage/private/bluesky/client-private.pem").stat().st_size > 200, "Private Bluesky broker keys provisioned")
    request = urllib.request.Request("https://www.sensecms.com/api/social/bluesky/v1/start", data=b"{}", headers={"Content-Type":"application/json"}, method="POST")
    try:
        urllib.request.urlopen(request, timeout=30)
        api_status = 200
    except urllib.error.HTTPError as error:
        api_status = error.code
    check(api_status == 401, "Bluesky broker route is live and rejects unauthenticated starts")
    with urllib.request.urlopen("https://www.sensecms.com/api/social/bluesky/v1/client-metadata.json", timeout=30) as response:
        metadata = json.load(response)
    check(metadata.get("client_id") == "https://www.sensecms.com/api/social/bluesky/v1/client-metadata.json", "Public Bluesky OAuth client metadata is live")
    run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])
    run(["nginx", "-t"])
    receipt["status"] = "deployed"
    shutil.rmtree(install_dir)
    install_dir = None
except BaseException:
    receipt["status"] = "rolling-back" if mutated else "preflight-failed"
    if mutated and backup is not None:
        if bluesky_installed:
            try:
                uninstall_bluesky()
            except BaseException:
                pass
        for name in core_files:
            source = backup / "core" / name
            if source.exists():
                atomic(source, web / name)
        for name in website_files:
            source = backup / "website" / name
            target = web / "website" / name
            if source.exists():
                atomic(source, target)
            elif target in created_website and target.exists():
                target.unlink()
        if (backup / "nginx-before.conf").exists():
            atomic(backup / "nginx-before.conf", nginx)
        if broker_dir.is_dir() and not broker_dir.is_symlink():
            shutil.rmtree(broker_dir)
        run(["nginx", "-t"])
        run(["systemctl", "reload", "nginx"])
        run(["systemctl", "reload", "php8.5-fpm"])
        receipt["status"] = "rolled-back"
    raise
finally:
    if install_dir is not None and install_dir.is_dir() and install_dir.name.startswith("bluesky-publisher-deploy-") and install_dir.parent == web / "storage/private":
        shutil.rmtree(install_dir)
    if backup is not None:
        (backup / "receipt.json").write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n")
        os.chmod(backup / "receipt.json", 0o600)
    fcntl.flock(lock, fcntl.LOCK_UN)
    lock.close()

print(json.dumps(receipt, sort_keys=True), flush=True)
