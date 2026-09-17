#!/usr/bin/env python3
"""Pinned production UI update for Social Publishing 0.1.2 and Facebook Publisher 0.1.3."""
from __future__ import annotations

import datetime
import fcntl
import hashlib
import json
import os
import shutil
import subprocess
import sys
from pathlib import Path

if os.name == "nt" or os.geteuid() != 0 or len(sys.argv) != 2:
    raise SystemExit("Run as root on Linux: deploy-social-ui-0.1.2.py <private-stage>")

stage = Path(sys.argv[1]).resolve()
web = Path("/home/sensecms.com/web")
publisher = Path("/root/sensecms-private/publisher.ed25519")
receipt = {"status": "preflight", "checks": []}
backup = install_dir = None
updated_addon = updated_plugin = False


def run(cmd, *, user=None):
    if user:
        cmd = ["sudo", "-u", user, *cmd]
    return subprocess.run(cmd, check=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT).stdout


def php(code, *args, user=None):
    return run(["php8.5", "-r", code, *map(str, args)], user=user)


def check(ok, label):
    if not ok:
        raise RuntimeError(label)
    receipt["checks"].append(label)
    print("PASS " + label, flush=True)


def state():
    return json.loads(php(r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$packages=[];foreach([['addon','social-publishing'],['plugin','facebook-publisher']]as$p){$q=$db->prepare("SELECT version,active,signature_status FROM extension_packages WHERE type=? AND slug=?");$q->execute($p);$packages[$p[0].':'.$p[1]]=$q->fetch();}$rows=[];foreach(['social_connections','social_post_targets','social_deliveries']as$t)$rows[$t]=(int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();echo json_encode(['packages'=>$packages,'rows'=>$rows],JSON_THROW_ON_ERROR);''', web))


def install(archive):
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();$s=$m->stageLocalFile($argv[2],$owner);echo json_encode($m->install($s['token'],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web, archive, user="sensecms"))


def rollback(kind, slug):
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();echo json_encode($m->rollback($argv[2],$argv[3],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web, kind, slug, user="sensecms"))


check(stage.is_dir() and str(stage).startswith("/root/sense-social-ui-"), "Private scoped update stage")
required = [
    stage / ".addons/social-publishing/sense-package.json",
    stage / ".addons/social-publishing/addon.json",
    stage / ".addons/social-publishing/runtime.php",
    stage / ".addons/social-publishing/src/SocialController.php",
    stage / ".addons/social-publishing/views/social.php",
    stage / ".addons/social-publishing/assets/social.css",
    stage / ".plugins/facebook-publisher/sense-package.json",
    stage / ".plugins/facebook-publisher/plugin.json",
    stage / ".plugins/facebook-publisher/runtime.php",
    stage / ".plugins/facebook-publisher/views/facebook.php",
    stage / "tests/social-package-release.php",
]
for path in required:
    check(path.is_file() and not path.is_symlink(), "Candidate file " + path.name)

before = state()
addon_before = before["packages"]["addon:social-publishing"]
plugin_before = before["packages"]["plugin:facebook-publisher"]
check(addon_before["version"] == "0.1.1" and addon_before["active"] and addon_before["signature_status"] == "verified", "Installed signed addon 0.1.1 baseline")
check(plugin_before["version"] == "0.1.2" and plugin_before["active"] and plugin_before["signature_status"] == "verified", "Installed signed plugin 0.1.2 baseline")
check(publisher.is_file() and web.is_dir(), "Production publisher and installation")
for path in [stage / ".addons/social-publishing/runtime.php", stage / ".addons/social-publishing/src/SocialController.php", stage / ".addons/social-publishing/views/social.php", stage / ".plugins/facebook-publisher/runtime.php", stage / ".plugins/facebook-publisher/views/facebook.php"]:
    run(["php8.5", "-l", str(path)])
run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])

lock = Path("/root/sensecms-private/deploy-workspace.lock").open("a+b")
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
try:
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup = Path("/root/sensecms-backups") / (stamp + "-social-ui-012")
    backup.mkdir(mode=0o700)
    receipt["backup"] = str(backup)
    with (backup / "database-before.sql").open("wb") as out:
        subprocess.run(["mysqldump", "--single-transaction", "--routines", "--triggers", "sensecms_site"], check=True, stdout=out, stderr=subprocess.PIPE)
    os.chmod(backup / "database-before.sql", 0o600)
    check((backup / "database-before.sql").stat().st_size > 4096, "Private database recovery dump")
    run(["tar", "-czf", str(backup / "packages-before.tgz"), "-C", str(web), "addons/social-publishing", "plugins/facebook-publisher"])
    os.chmod(backup / "packages-before.tgz", 0o600)

    artifacts = stage / "artifacts"
    artifacts.mkdir(mode=0o700)
    build = r'''require$argv[1].'/bootstrap.php';$secret=base64_decode(trim(file_get_contents($argv[2])),true);if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid key');try{$a=App\Core\Packages\Archive::build($argv[3],$argv[4],$secret);echo json_encode(['sha256'=>$a['sha256'],'public'=>base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))],JSON_THROW_ON_ERROR);}finally{sodium_memzero($secret);}'''
    hashes = {}
    public = ""
    releases = [("addon", "social-publishing", "0.1.2"), ("plugin", "facebook-publisher", "0.1.3")]
    for kind, slug, version in releases:
        archive = artifacts / f"{kind}-{slug}-{version}.zip"
        result = json.loads(php(build, web, publisher, stage / f".{kind}s" / slug, archive))
        hashes[f"{kind}:{slug}"] = result["sha256"]
        public = result["public"]
    (artifacts / "social-release.json").write_text(json.dumps(hashes, separators=(",", ":")))
    (artifacts / "trust.json").write_text(json.dumps({"sensecms-release": public}, separators=(",", ":")))
    os.chmod(artifacts / "social-release.json", 0o600)
    os.chmod(artifacts / "trust.json", 0o600)
    receipt["artifacts"] = hashes

    testtree = stage / "runtime-tests"
    (testtree / "tests").mkdir(parents=True, mode=0o700)
    (testtree / ".cms").mkdir(mode=0o700)
    (testtree / ".cms/source").symlink_to(web, target_is_directory=True)
    (testtree / ".plugins").symlink_to(stage / ".plugins", target_is_directory=True)
    (testtree / ".addons").symlink_to(stage / ".addons", target_is_directory=True)
    shutil.copy2(stage / "tests/social-package-release.php", testtree / "tests/social-package-release.php")
    qa = run(["php8.5", str(testtree / "tests/social-package-release.php"), str(artifacts), str(artifacts / "trust.json")]).decode()
    check("signed social package checks passed" in qa, "Signed social UI lifecycle on isolated MariaDB")

    uid = int(run(["id", "-u", "sensecms"]).decode())
    gid = int(run(["id", "-g", "sensecms"]).decode())
    install_dir = web / "storage/private" / ("social-ui-deploy-" + stamp)
    install_dir.mkdir(mode=0o700)
    os.chown(install_dir, uid, gid)
    candidates = []
    for kind, slug, version in releases:
        candidate = install_dir / f"{kind}-{slug}-{version}.zip"
        shutil.copy2(artifacts / candidate.name, candidate)
        os.chown(candidate, uid, gid)
        os.chmod(candidate, 0o600)
        candidates.append(candidate)

    result = install(candidates[0])
    updated_addon = True
    check(result["version"] == "0.1.2" and result["updated"], "PackageManager upgraded Social Publishing to 0.1.2")
    result = install(candidates[1])
    updated_plugin = True
    check(result["version"] == "0.1.3" and result["updated"], "PackageManager upgraded Facebook Publisher to 0.1.3")

    after = state()
    check(after["packages"]["addon:social-publishing"]["version"] == "0.1.2" and after["packages"]["addon:social-publishing"]["active"] and after["packages"]["addon:social-publishing"]["signature_status"] == "verified", "Signed Social Publishing 0.1.2 active")
    check(after["packages"]["plugin:facebook-publisher"]["version"] == "0.1.3" and after["packages"]["plugin:facebook-publisher"]["active"] and after["packages"]["plugin:facebook-publisher"]["signature_status"] == "verified", "Signed Facebook Publisher 0.1.3 active")
    check(after["rows"] == before["rows"], "Social connections, targets and deliveries preserved")
    for source, deployed in [
        (stage / ".addons/social-publishing/assets/social.css", web / "addons/social-publishing/assets/social.css"),
        (stage / ".addons/social-publishing/views/social.php", web / "addons/social-publishing/views/social.php"),
        (stage / ".plugins/facebook-publisher/views/facebook.php", web / "plugins/facebook-publisher/views/facebook.php"),
        (stage / ".plugins/facebook-publisher/runtime.php", web / "plugins/facebook-publisher/runtime.php"),
    ]:
        check(hashlib.sha256(source.read_bytes()).digest() == hashlib.sha256(deployed.read_bytes()).digest(), "Deployed checksum " + deployed.name)
    worker = json.loads(run(["php8.5", str(web / "addons/social-publishing/scripts/social-worker.php"), str(web)], user="sensecms"))
    check(worker.get("ok") is True, "Production social worker healthy")
    run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])
    run(["nginx", "-t"])
    receipt["status"] = "deployed"
    shutil.rmtree(install_dir)
    install_dir = None
except BaseException:
    if updated_plugin:
        try:
            rollback("plugin", "facebook-publisher")
        except BaseException:
            pass
    if updated_addon:
        try:
            rollback("addon", "social-publishing")
        except BaseException:
            pass
    receipt["status"] = "rolled-back"
    raise
finally:
    if install_dir is not None and install_dir.is_dir() and install_dir.name.startswith("social-ui-deploy-") and install_dir.parent == web / "storage/private":
        shutil.rmtree(install_dir)
    if backup is not None:
        (backup / "receipt.json").write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n")
        os.chmod(backup / "receipt.json", 0o600)
    fcntl.flock(lock, fcntl.LOCK_UN)
    lock.close()

print(json.dumps(receipt, sort_keys=True), flush=True)
