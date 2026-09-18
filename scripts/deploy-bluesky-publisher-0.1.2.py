#!/usr/bin/env python3
"""Pinned production release for Bluesky PDS discovery and versioned assets."""
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
    raise SystemExit("Run as root on Linux: deploy-bluesky-publisher-0.1.2.py <private-stage>")

stage = Path(sys.argv[1]).resolve()
web = Path("/home/sensecms.com/web")
broker = web / "website/BlueskyBrokerService.php"
crypto = web / "website/AtprotoCrypto.php"
catalog = web / "website/package-catalog.php"
provider = web / "plugins/bluesky-publisher/src/provider.php"
distribution = web / "storage/distribution.json"
releases = web / "storage/distribution/releases"
publisher = Path("/root/sensecms-private/publisher.ed25519")
expected = {
    broker: "3b29f521a462056736535bc28ed07ced08dc738288925dc464a6b8a455e4bbad",
    crypto: "73ae7b5ee8d61fb24e27c45e22175e36e1a01d0450bb45836e3433d8e4b7a05a",
    provider: "0e37638d61ad88a35fe4de90979c7da39e3f4848f9deed159017962b4d8b15cf",
}
receipt = {"status": "preflight", "checks": []}
backup = install_dir = target = None
installed = distribution_changed = broker_changed = crypto_changed = catalog_changed = False


def run(cmd, *, user=None):
    if user:
        cmd = ["sudo", "-u", user, *cmd]
    result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if result.returncode:
        raise RuntimeError(result.stdout.decode(errors="replace").strip() or f"Command failed: {cmd[0]}")
    return result.stdout


def php(code, *args, user=None):
    return run(["php8.5", "-r", code, *map(str, args)], user=user)


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def check(ok, label):
    if not ok:
        raise RuntimeError(label)
    receipt["checks"].append(label)
    print("PASS " + label, flush=True)


def atomic_file(source, destination):
    temporary = destination.with_name(destination.name + ".bluesky-012-new")
    shutil.copy2(source, temporary)
    stat = destination.stat()
    os.chown(temporary, stat.st_uid, stat.st_gid)
    os.chmod(temporary, stat.st_mode & 0o777)
    os.replace(temporary, destination)


def atomic_bytes(destination, payload):
    temporary = destination.with_name(destination.name + ".bluesky-012-new")
    temporary.write_bytes(payload)
    stat = destination.stat()
    os.chown(temporary, stat.st_uid, stat.st_gid)
    os.chmod(temporary, stat.st_mode & 0o777)
    os.replace(temporary, destination)


def state():
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$q=$db->prepare("SELECT version,active,signature_status FROM extension_packages WHERE type='plugin' AND slug='bluesky-publisher'");$q->execute();$tables=['social_connections','social_post_targets','social_deliveries'];$rows=[];$fingerprints=[];foreach($tables as$t){$data=$db->query("SELECT * FROM `$t` ORDER BY 1")->fetchAll();$rows[$t]=count($data);$fingerprints[$t]=hash('sha256',json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}$due=(int)$db->query("SELECT COUNT(*) FROM social_post_targets t INNER JOIN posts p ON p.id=t.post_id WHERE t.enabled=1 AND t.last_enqueued_revision<t.revision AND (p.status='published' OR (p.status='scheduled' AND p.published_at<=UTC_TIMESTAMP()))")->fetchColumn();echo json_encode(['plugin'=>$q->fetch()?:null,'rows'=>$rows,'fingerprints'=>$fingerprints,'due'=>$due],JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web))


def install(archive):
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();$s=$m->stageLocalFile($argv[2],$owner);echo json_encode($m->install($s['token'],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web, archive, user="sensecms"))


def rollback_package():
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();echo json_encode($m->rollback('plugin','bluesky-publisher',$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web, user="sensecms"))


required = [
    stage / ".src/AtprotoCrypto.php",
    stage / ".src/BlueskyBrokerService.php",
    stage / ".src/BlueskyOAuthEndpoint.php",
    stage / ".src/package-catalog.php",
    stage / ".plugins/bluesky-publisher/sense-package.json",
    stage / ".plugins/bluesky-publisher/plugin.json",
    stage / ".plugins/bluesky-publisher/src/provider.php",
    stage / ".addons/social-publishing/sense-package.json",
    stage / "tests/bluesky-broker.php",
    stage / "tests/bluesky-publisher.php",
    stage / "tests/bluesky-social-release.php",
]
check(stage.is_dir() and str(stage).startswith("/root/sense-bluesky-publisher-fix-"), "Private scoped deployment stage")
check(all(path.is_file() and not path.is_symlink() for path in required), "Complete Bluesky 0.1.2 candidate")
check(web.is_dir() and distribution.is_file() and releases.is_dir() and publisher.is_file(), "Production package and distribution runtime")
check(all(path.is_file() and sha(path) == digest for path, digest in expected.items()), "Pinned Bluesky 0.1.1 source baseline")
before = state()
check(before["plugin"] == {"version": "0.1.1", "active": 1, "signature_status": "verified"}, "Signed Bluesky Publisher 0.1.1 baseline")
config = json.loads(distribution.read_text())
offer = config.get("products", {}).get("plugin:bluesky-publisher", {})
check(offer.get("version") == "0.1.1" and offer.get("pricing") == "paid" and offer.get("enabled") is True, "Paid Bluesky 0.1.1 distribution baseline")
for path in [stage / ".src/BlueskyBrokerService.php", *stage.glob(".plugins/bluesky-publisher/**/*.php"), stage / "tests/bluesky-broker.php", stage / "tests/bluesky-publisher.php", stage / "tests/bluesky-social-release.php"]:
    run(["php8.5", "-l", str(path)])
run(["python3", "-m", "py_compile", str(stage / "scripts/deploy-bluesky-publisher-0.1.2.py")])
run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])

lock = Path("/root/sensecms-private/deploy-workspace.lock").open("a+b")
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
try:
    artifacts = stage / "artifacts"
    artifacts.mkdir(mode=0o700)
    build = r'''require$argv[1].'/bootstrap.php';$secret=base64_decode(trim(file_get_contents($argv[2])),true);if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid key');try{$a=App\Core\Packages\Archive::build($argv[3],$argv[4],$secret);echo json_encode(['sha256'=>$a['sha256'],'public'=>base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))],JSON_THROW_ON_ERROR);}finally{sodium_memzero($secret);}'''
    hashes = {}
    public = ""
    for kind, slug, version in [("addon", "social-publishing", "0.2.1"), ("plugin", "bluesky-publisher", "0.1.2")]:
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
    check("Bluesky broker checks passed" in run(["php8.5", str(testtree / "tests/bluesky-broker.php")]).decode(), "Isolated Bluesky OAuth broker protocol")
    check("Bluesky Publisher protocol checks passed" in run(["php8.5", str(testtree / "tests/bluesky-publisher.php")]).decode(), "Isolated Bluesky publishing protocol")
    check("signed Bluesky social package checks passed" in run(["php8.5", str(testtree / "tests/bluesky-social-release.php"), str(artifacts), str(artifacts / "trust.json")]).decode(), "Exact signed Bluesky package lifecycle on isolated MariaDB")

    stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup = Path("/root/sensecms-backups") / (stamp + "-bluesky-publisher-012")
    backup.mkdir(mode=0o700)
    receipt["backup"] = str(backup)
    shutil.copy2(broker, backup / "BlueskyBrokerService.php")
    shutil.copy2(crypto, backup / "AtprotoCrypto.php")
    if catalog.is_file():
        shutil.copy2(catalog, backup / "package-catalog.php")
    shutil.copy2(distribution, backup / "distribution-before.json")
    shutil.copytree(web / "plugins/bluesky-publisher", backup / "plugin-0.1.1")
    shutil.copy2(artifacts / "plugin-bluesky-publisher-0.1.2.zip", backup / "plugin-bluesky-publisher-0.1.2.zip")
    with (backup / "database-before.sql").open("wb") as output:
        result = subprocess.run(["mysqldump", "--single-transaction", "--routines", "--triggers", "sensecms_site"], stdout=output, stderr=subprocess.PIPE)
        if result.returncode:
            raise RuntimeError(result.stderr.decode(errors="replace").strip() or "Database backup failed.")
    os.chmod(backup / "database-before.sql", 0o600)
    check((backup / "database-before.sql").stat().st_size > 4096, "Private production database recovery dump")

    atomic_file(stage / ".src/BlueskyBrokerService.php", broker)
    broker_changed = True
    atomic_file(stage / ".src/AtprotoCrypto.php", crypto)
    crypto_changed = True
    if catalog.is_file():
        atomic_file(stage / ".src/package-catalog.php", catalog)
        catalog_changed = True
    uid = int(run(["id", "-u", "sensecms"]).decode())
    gid = int(run(["id", "-g", "sensecms"]).decode())
    install_dir = web / "storage/private" / ("bluesky-publisher-deploy-" + stamp)
    install_dir.mkdir(mode=0o700)
    os.chown(install_dir, uid, gid)
    candidate = install_dir / "plugin-bluesky-publisher-0.1.2.zip"
    shutil.copy2(artifacts / candidate.name, candidate)
    os.chown(candidate, uid, gid)
    os.chmod(candidate, 0o600)
    result = install(candidate)
    installed = True
    check(result.get("version") == "0.1.2" and result.get("updated") is True, "PackageManager upgraded Bluesky Publisher to 0.1.2")

    target = releases / candidate.name
    check(not target.exists(), "Immutable Bluesky 0.1.2 distribution target is new")
    payload = (artifacts / candidate.name).read_bytes()
    temporary = target.with_name(target.name + ".new")
    temporary.write_bytes(payload)
    store_stat = distribution.stat()
    os.chown(temporary, store_stat.st_uid, store_stat.st_gid)
    os.chmod(temporary, 0o640)
    os.replace(temporary, target)
    current = json.loads(distribution.read_text())
    current_offer = current["products"]["plugin:bluesky-publisher"]
    current_offer.update({"version": "0.1.2", "sha256": hashlib.sha256(payload).hexdigest(), "bytes": len(payload), "file": target.name})
    atomic_bytes(distribution, (json.dumps(current, separators=(",", ":")) + "\n").encode())
    distribution_changed = True

    after = state()
    check(after["plugin"] == {"version": "0.1.2", "active": 1, "signature_status": "verified"}, "Signed Bluesky Publisher 0.1.2 active")
    check(after["rows"] == before["rows"] and after["fingerprints"] == before["fingerprints"], "Existing social connections, targets and deliveries preserved exactly")
    check(sha(stage / ".src/BlueskyBrokerService.php") == sha(broker), "Deployed broker checksum")
    check(sha(stage / ".src/AtprotoCrypto.php") == sha(crypto), "Deployed AT Protocol crypto checksum")
    check(sha(stage / ".plugins/bluesky-publisher/src/provider.php") == sha(provider), "Deployed provider checksum")
    live_config = json.loads(distribution.read_text())
    live_offer = live_config["products"]["plugin:bluesky-publisher"]
    check(live_offer["version"] == "0.1.2" and live_offer["sha256"] == sha(target) and live_offer["pricing"] == "paid", "Paid Bluesky 0.1.2 distribution offer")
    if after["due"] == 0:
        worker = json.loads(run(["php8.5", str(web / "addons/social-publishing/scripts/social-worker.php"), str(web)], user="sensecms"))
        check(worker.get("ok") is True and worker.get("published") == 0, "Production worker healthy without publishing content")
    request = urllib.request.Request("https://www.sensecms.com/api/social/bluesky/v1/start", data=b"{}", headers={"Content-Type": "application/json"}, method="POST")
    try:
        urllib.request.urlopen(request, timeout=30)
        api_status = 200
    except urllib.error.HTTPError as error:
        api_status = error.code
    check(api_status == 401, "Bluesky broker route rejects unauthenticated starts")
    run(["systemctl", "reload", "php8.5-fpm"])
    run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])
    run(["nginx", "-t"])
    receipt["status"] = "deployed"
except BaseException:
    receipt["status"] = "rolling-back"
    if distribution_changed and backup is not None:
        atomic_bytes(distribution, (backup / "distribution-before.json").read_bytes())
    if target is not None and target.is_file() and target.parent == releases and target.name == "plugin-bluesky-publisher-0.1.2.zip":
        target.unlink()
    if installed:
        rollback_package()
    if broker_changed and backup is not None:
        atomic_file(backup / "BlueskyBrokerService.php", broker)
    if crypto_changed and backup is not None:
        atomic_file(backup / "AtprotoCrypto.php", crypto)
    if catalog_changed and backup is not None:
        atomic_file(backup / "package-catalog.php", catalog)
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
