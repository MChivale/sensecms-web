#!/usr/bin/env python3
"""Pinned production release for multi-Page Social Publishing and Facebook Publisher 0.2.0."""
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
    raise SystemExit("Run as root on Linux: deploy-facebook-multi-0.2.0.py <private-stage>")

stage = Path(sys.argv[1]).resolve()
web = Path("/home/sensecms.com/web")
publisher = Path("/root/sensecms-private/publisher.ed25519")
receipt = {"status": "preflight", "checks": []}
backup = install_dir = None
mutated = False


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
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$packages=[];foreach([['addon','social-publishing'],['plugin','facebook-publisher']]as$p){$q=$db->prepare("SELECT version,active,signature_status FROM extension_packages WHERE type=? AND slug=?");$q->execute($p);$packages[$p[0].':'.$p[1]]=$q->fetch();}$queries=['social_connections'=>"SELECT plugin_slug,external_account_id,display_name,enabled,last_verified_at,last_error,created_at,updated_at FROM social_connections ORDER BY plugin_slug,external_account_id",'social_post_targets'=>"SELECT post_id,plugin_slug,enabled,message,revision,last_enqueued_revision,updated_at FROM social_post_targets ORDER BY post_id,plugin_slug",'social_deliveries'=>"SELECT id,post_id,plugin_slug,target_revision,payload,payload_hash,status,attempts,available_at,locked_at,external_id,external_url,last_error,created_at,published_at,updated_at FROM social_deliveries ORDER BY id"];$rows=[];$fingerprints=[];foreach($queries as$t=>$sql){$data=$db->query($sql)->fetchAll();$rows[$t]=count($data);$fingerprints[$t]=hash('sha256',json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}$due=(int)$db->query("SELECT COUNT(*) FROM social_post_targets t INNER JOIN posts p ON p.id=t.post_id WHERE t.enabled=1 AND t.last_enqueued_revision<t.revision AND (p.status='published' OR (p.status='scheduled' AND p.published_at<=UTC_TIMESTAMP()))")->fetchColumn();echo json_encode(['packages'=>$packages,'rows'=>$rows,'fingerprints'=>$fingerprints,'due'=>$due],JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web))


def install(archive):
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();$s=$m->stageLocalFile($argv[2],$owner);echo json_encode($m->install($s['token'],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web, archive, user="sensecms"))


check(stage.is_dir() and str(stage).startswith("/root/sense-facebook-multi-"), "Private scoped update stage")
required = [
    stage / ".addons/social-publishing/sense-package.json",
    stage / ".addons/social-publishing/addon.json",
    stage / ".addons/social-publishing/migrations/multi-connections-up.sql",
    stage / ".addons/social-publishing/src/SocialIntegrationManager.php",
    stage / ".addons/social-publishing/src/SocialRepository.php",
    stage / ".addons/social-publishing/src/SocialDispatcher.php",
    stage / ".addons/social-publishing/assets/editor.js",
    stage / ".plugins/facebook-publisher/sense-package.json",
    stage / ".plugins/facebook-publisher/plugin.json",
    stage / ".plugins/facebook-publisher/runtime.php",
    stage / ".plugins/facebook-publisher/views/facebook.php",
    stage / "tests/social-package-release.php",
    stage / "tests/social-ui-production.py",
]
for path in required:
    check(path.is_file() and not path.is_symlink(), "Candidate file " + path.name)

before = state()
addon_before = before["packages"]["addon:social-publishing"]
plugin_before = before["packages"]["plugin:facebook-publisher"]
check(addon_before["version"] == "0.1.2" and addon_before["active"] and addon_before["signature_status"] == "verified", "Installed signed addon 0.1.2 baseline")
check(plugin_before["version"] == "0.1.3" and plugin_before["active"] and plugin_before["signature_status"] == "verified", "Installed signed plugin 0.1.3 baseline")
check(publisher.is_file() and web.is_dir(), "Production publisher and installation")
for path in [*stage.glob(".addons/social-publishing/**/*.php"), *stage.glob(".plugins/facebook-publisher/**/*.php"), stage / "tests/social-package-release.php"]:
    run(["php8.5", "-l", str(path)])
run(["python3", "-m", "py_compile", str(stage / "tests/social-ui-production.py")])
run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])

lock = Path("/root/sensecms-private/deploy-workspace.lock").open("a+b")
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
try:
    artifacts = stage / "artifacts"
    artifacts.mkdir(mode=0o700)
    build = r'''require$argv[1].'/bootstrap.php';$secret=base64_decode(trim(file_get_contents($argv[2])),true);if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid key');try{$a=App\Core\Packages\Archive::build($argv[3],$argv[4],$secret);echo json_encode(['sha256'=>$a['sha256'],'public'=>base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))],JSON_THROW_ON_ERROR);}finally{sodium_memzero($secret);}'''
    hashes = {}
    public = ""
    releases = [("addon", "social-publishing", "0.2.0"), ("plugin", "facebook-publisher", "0.2.0")]
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
    check("signed social package checks passed" in qa, "Signed multi-connection lifecycle on isolated MariaDB")

    stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup = Path("/root/sensecms-backups") / (stamp + "-facebook-multi-020")
    backup.mkdir(mode=0o700)
    receipt["backup"] = str(backup)
    with (backup / "database-before.sql").open("wb") as out:
        subprocess.run(["mysqldump", "--single-transaction", "--routines", "--triggers", "sensecms_site"], check=True, stdout=out, stderr=subprocess.PIPE)
    os.chmod(backup / "database-before.sql", 0o600)
    check((backup / "database-before.sql").stat().st_size > 4096, "Private database recovery dump")
    run(["tar", "-czf", str(backup / "packages-before.tgz"), "-C", str(web), "addons/social-publishing", "plugins/facebook-publisher"])
    os.chmod(backup / "packages-before.tgz", 0o600)

    uid = int(run(["id", "-u", "sensecms"]).decode())
    gid = int(run(["id", "-g", "sensecms"]).decode())
    install_dir = web / "storage/private" / ("facebook-multi-deploy-" + stamp)
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
    mutated = True
    check(result["version"] == "0.2.0" and result["updated"], "PackageManager upgraded Social Publishing to 0.2.0")
    result = install(candidates[1])
    check(result["version"] == "0.2.0" and result["updated"], "PackageManager upgraded Facebook Publisher to 0.2.0")

    after = state()
    check(after["packages"]["addon:social-publishing"]["version"] == "0.2.0" and after["packages"]["addon:social-publishing"]["active"] and after["packages"]["addon:social-publishing"]["signature_status"] == "verified", "Signed Social Publishing 0.2.0 active")
    check(after["packages"]["plugin:facebook-publisher"]["version"] == "0.2.0" and after["packages"]["plugin:facebook-publisher"]["active"] and after["packages"]["plugin:facebook-publisher"]["signature_status"] == "verified", "Signed Facebook Publisher 0.2.0 active")
    check(after["rows"] == before["rows"] and after["fingerprints"] == before["fingerprints"], "Existing connections, targets and deliveries preserved exactly")
    schema = json.loads(php(r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$columns=[];foreach(['social_connections'=>['id'],'social_post_targets'=>['id','connection_id'],'social_deliveries'=>['connection_id','destination_external_id','destination_display_name']]as$t=>$names){foreach($names as$n){$q=$db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");$q->execute([$t,$n]);$columns[$t.'.'.$n]=(bool)$q->fetchColumn();}}$mapped=(int)$db->query("SELECT COUNT(*) FROM social_connections c LEFT JOIN social_post_targets t ON t.connection_id=c.id LEFT JOIN social_deliveries d ON d.connection_id=c.id WHERE c.id>0")->fetchColumn();echo json_encode(['columns'=>$columns,'connections'=>(int)$db->query('SELECT COUNT(*) FROM social_connections WHERE id>0')->fetchColumn(),'mapped'=>$mapped],JSON_THROW_ON_ERROR);''', web))
    check(all(schema["columns"].values()) and schema["connections"] == before["rows"]["social_connections"], "Multi-connection schema active with existing connection IDs")

    for source, deployed in [
        (stage / ".addons/social-publishing/src/SocialIntegrationManager.php", web / "addons/social-publishing/src/SocialIntegrationManager.php"),
        (stage / ".addons/social-publishing/src/SocialRepository.php", web / "addons/social-publishing/src/SocialRepository.php"),
        (stage / ".addons/social-publishing/assets/editor.js", web / "addons/social-publishing/assets/editor.js"),
        (stage / ".plugins/facebook-publisher/runtime.php", web / "plugins/facebook-publisher/runtime.php"),
        (stage / ".plugins/facebook-publisher/views/facebook.php", web / "plugins/facebook-publisher/views/facebook.php"),
    ]:
        check(hashlib.sha256(source.read_bytes()).digest() == hashlib.sha256(deployed.read_bytes()).digest(), "Deployed checksum " + deployed.name)
    if after["due"] == 0:
        worker = json.loads(run(["php8.5", str(web / "addons/social-publishing/scripts/social-worker.php"), str(web)], user="sensecms"))
        check(worker.get("ok") is True and worker.get("published") == 0, "Production worker healthy without publishing content")
    run(["python3", str(stage / "tests/social-ui-production.py")])
    check(True, "Authenticated production multi-Page UI and API acceptance")
    run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])
    run(["nginx", "-t"])
    receipt["status"] = "deployed"
    shutil.rmtree(install_dir)
    install_dir = None
except BaseException:
    receipt["status"] = "recovery-required" if mutated else "preflight-failed"
    raise
finally:
    if install_dir is not None and install_dir.is_dir() and install_dir.name.startswith("facebook-multi-deploy-") and install_dir.parent == web / "storage/private":
        shutil.rmtree(install_dir)
    if backup is not None:
        (backup / "receipt.json").write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n")
        os.chmod(backup / "receipt.json", 0o600)
    fcntl.flock(lock, fcntl.LOCK_UN)
    lock.close()

print(json.dumps(receipt, sort_keys=True), flush=True)
