#!/usr/bin/env python3
"""Release automatic OAuth popup closure for all Social Publishing providers."""
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

if os.name == "nt" or os.geteuid() != 0 or len(sys.argv) != 2:
    raise SystemExit("Run as root on Linux: deploy-social-oauth-popup-20260918.py <private-stage>")

stage = Path(sys.argv[1]).resolve()
web = Path("/home/sensecms.com/web")
distribution = web / "storage/distribution.json"
releases = web / "storage/distribution/releases"
publisher = Path("/root/sensecms-private/publisher.ed25519")
versions = {
    "facebook-publisher": ("0.2.0", "0.2.1"),
    "x-publisher": ("0.1.0", "0.1.1"),
    "linkedin-publisher": ("0.1.0", "0.1.1"),
    "bluesky-publisher": ("0.1.2", "0.1.3"),
}
receipt = {"status": "preflight", "checks": []}
backup = install_dir = bluesky_target = None
installed = []
distribution_changed = marketplace_changed = False


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
    temporary = destination.with_name(destination.name + ".oauth-popup-new")
    shutil.copy2(source, temporary)
    stat = destination.stat()
    os.chown(temporary, stat.st_uid, stat.st_gid)
    os.chmod(temporary, stat.st_mode & 0o777)
    os.replace(temporary, destination)


def atomic_bytes(destination, payload):
    temporary = destination.with_name(destination.name + ".oauth-popup-new")
    temporary.write_bytes(payload)
    stat = destination.stat()
    os.chown(temporary, stat.st_uid, stat.st_gid)
    os.chmod(temporary, stat.st_mode & 0o777)
    os.replace(temporary, destination)


def state():
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$out=['packages'=>[]];foreach(['addon:social-publishing','plugin:facebook-publisher','plugin:x-publisher','plugin:linkedin-publisher','plugin:bluesky-publisher']as$i){[$t,$s]=explode(':',$i);$q=$db->prepare('SELECT version,active,signature_status FROM extension_packages WHERE type=? AND slug=?');$q->execute([$t,$s]);$out['packages'][$i]=$q->fetch()?:null;}$out['social']=[];foreach(['social_connections','social_post_targets','social_deliveries']as$t){$rows=$db->query("SELECT * FROM `$t` ORDER BY 1")->fetchAll();$out['social'][$t]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];}echo json_encode($out,JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web))


def install(archive):
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();$s=$m->stageLocalFile($argv[2],$owner);echo json_encode($m->install($s['token'],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web, archive, user="sensecms"))


def rollback(slug):
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();echo json_encode($m->rollback('plugin',$argv[2],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web, slug, user="sensecms"))


required = [
    stage / ".src/package-catalog.php",
    stage / "scripts/update-social-oauth-popup-marketplace.php",
    stage / "tests/social-oauth-popup.cjs",
]
for slug in versions:
    required.extend([
        stage / f".plugins/{slug}/sense-package.json",
        stage / f".plugins/{slug}/plugin.json",
        stage / f".plugins/{slug}/runtime.php",
        stage / f".plugins/{slug}/assets/{slug.removesuffix('-publisher')}.js",
    ])
check(stage.is_dir() and str(stage).startswith("/root/sense-social-oauth-popup-"), "Private scoped deployment stage")
check(all(path.is_file() and not path.is_symlink() for path in required), "Complete OAuth popup candidate")
check(web.is_dir() and distribution.is_file() and releases.is_dir() and publisher.is_file(), "Production package runtime")
before = state()
check(before["packages"]["addon:social-publishing"] == {"version": "0.2.1", "active": 1, "signature_status": "verified"}, "Signed Social Publishing 0.2.1 baseline")
for slug, (old, _) in versions.items():
    check(before["packages"][f"plugin:{slug}"] == {"version": old, "active": 1, "signature_status": "verified"}, f"Signed {slug} {old} baseline")
offer = json.loads(distribution.read_text())["products"]["plugin:bluesky-publisher"]
check(offer["version"] == "0.1.2" and offer["pricing"] == "paid" and offer["enabled"] is True, "Paid Bluesky 0.1.2 distribution baseline")
for path in [*stage.glob(".plugins/*-publisher/**/*.php"), stage / "scripts/update-social-oauth-popup-marketplace.php"]:
    run(["php8.5", "-l", str(path)])
run(["python3", "-m", "py_compile", str(stage / "scripts/deploy-social-oauth-popup-20260918.py")])
run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])

lock = Path("/root/sensecms-private/deploy-workspace.lock").open("a+b")
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
try:
    artifacts = stage / "artifacts"
    artifacts.mkdir(mode=0o700)
    build = r'''require$argv[1].'/bootstrap.php';$secret=base64_decode(trim(file_get_contents($argv[2])),true);if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid key');try{$a=App\Core\Packages\Archive::build($argv[3],$argv[4],$secret);echo json_encode(['sha256'=>$a['sha256'],'public'=>base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))],JSON_THROW_ON_ERROR);}finally{sodium_memzero($secret);}'''
    sources = [("addon", "social-publishing", "0.2.1")]
    sources.extend(("plugin", slug, new) for slug, (_, new) in versions.items())
    hashes = {}
    public = ""
    for kind, slug, version in sources:
        archive = artifacts / f"{kind}-{slug}-{version}.zip"
        result = json.loads(php(build, web, publisher, stage / f".{kind}s" / slug, archive))
        hashes[f"{kind}:{slug}"] = result["sha256"]
        public = result["public"]
    for name, identities in {
        "social-release.json": ["addon:social-publishing", "plugin:facebook-publisher"],
        "x-social-release.json": ["addon:social-publishing", "plugin:x-publisher"],
        "linkedin-social-release.json": ["addon:social-publishing", "plugin:linkedin-publisher"],
        "bluesky-social-release.json": ["addon:social-publishing", "plugin:bluesky-publisher"],
    }.items():
        (artifacts / name).write_text(json.dumps({key: hashes[key] for key in identities}, separators=(",", ":")))
        os.chmod(artifacts / name, 0o600)
    (artifacts / "trust.json").write_text(json.dumps({"sensecms-release": public}, separators=(",", ":")))
    os.chmod(artifacts / "trust.json", 0o600)
    receipt["artifacts"] = hashes

    testtree = stage / "runtime-tests"
    (testtree / "tests").mkdir(parents=True, mode=0o700)
    (testtree / ".cms").mkdir(mode=0o700)
    (testtree / ".cms/source").symlink_to(web, target_is_directory=True)
    for source in [".src", ".plugins", ".addons"]:
        (testtree / source).symlink_to(stage / source, target_is_directory=True)
    tests = [
        "facebook-publisher.php", "x-publisher.php", "linkedin-publisher.php", "bluesky-publisher.php",
        "social-package-release.php", "x-social-release.php", "linkedin-social-release.php", "bluesky-social-release.php",
        "social-oauth-popup.cjs",
    ]
    for name in tests:
        shutil.copy2(stage / "tests" / name, testtree / "tests" / name)
    for provider in ["facebook", "x", "linkedin", "bluesky"]:
        output = run(["php8.5", str(testtree / f"tests/{provider}-publisher.php")]).decode()
        check("protocol checks passed" in output, f"Isolated {provider} provider protocol")
    for test, phrase in [
        ("social-package-release.php", "signed social package checks passed"),
        ("x-social-release.php", "signed X social package checks passed"),
        ("linkedin-social-release.php", "signed LinkedIn social package checks passed"),
        ("bluesky-social-release.php", "signed Bluesky social package checks passed"),
    ]:
        output = run(["php8.5", str(testtree / "tests" / test), str(artifacts), str(artifacts / "trust.json")]).decode()
        check(phrase in output, f"Exact signed lifecycle: {test}")

    stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup = Path("/root/sensecms-backups") / (stamp + "-social-oauth-popup")
    backup.mkdir(mode=0o700)
    receipt["backup"] = str(backup)
    shutil.copy2(distribution, backup / "distribution-before.json")
    shutil.copy2(stage / "scripts/update-social-oauth-popup-marketplace.php", backup / "update-social-oauth-popup-marketplace.php")
    for slug in versions:
        shutil.copytree(web / "plugins" / slug, backup / slug)
    with (backup / "database-before.sql").open("wb") as output:
        result = subprocess.run(["mysqldump", "--single-transaction", "--routines", "--triggers", "sensecms_site"], stdout=output, stderr=subprocess.PIPE)
        if result.returncode:
            raise RuntimeError(result.stderr.decode(errors="replace").strip() or "Database backup failed")
    os.chmod(backup / "database-before.sql", 0o600)
    check((backup / "database-before.sql").stat().st_size > 4096, "Private production database recovery dump")

    uid = int(run(["id", "-u", "sensecms"]).decode())
    gid = int(run(["id", "-g", "sensecms"]).decode())
    install_dir = web / "storage/private" / ("social-oauth-popup-" + stamp)
    install_dir.mkdir(mode=0o700)
    os.chown(install_dir, uid, gid)
    for slug, (_, version) in versions.items():
        source = artifacts / f"plugin-{slug}-{version}.zip"
        candidate = install_dir / source.name
        shutil.copy2(source, candidate)
        os.chown(candidate, uid, gid)
        os.chmod(candidate, 0o600)
        result = install(candidate)
        check(result.get("version") == version and result.get("updated") is True, f"PackageManager upgraded {slug} to {version}")
        installed.append(slug)

    bluesky_source = artifacts / "plugin-bluesky-publisher-0.1.3.zip"
    bluesky_target = releases / bluesky_source.name
    check(not bluesky_target.exists(), "Immutable Bluesky 0.1.3 distribution target is new")
    payload = bluesky_source.read_bytes()
    temporary = bluesky_target.with_name(bluesky_target.name + ".new")
    temporary.write_bytes(payload)
    store_stat = distribution.stat()
    os.chown(temporary, store_stat.st_uid, store_stat.st_gid)
    os.chmod(temporary, 0o640)
    os.replace(temporary, bluesky_target)
    current = json.loads(distribution.read_text())
    current["products"]["plugin:bluesky-publisher"].update({
        "version": "0.1.3", "sha256": sha(bluesky_target), "bytes": len(payload), "file": bluesky_target.name,
    })
    atomic_bytes(distribution, (json.dumps(current, separators=(",", ":")) + "\n").encode())
    distribution_changed = True

    run(["php8.5", str(stage / "scripts/update-social-oauth-popup-marketplace.php"), str(web), "--apply", str(backup)])
    marketplace_changed = True
    after = state()
    for slug, (_, version) in versions.items():
        check(after["packages"][f"plugin:{slug}"] == {"version": version, "active": 1, "signature_status": "verified"}, f"Signed {slug} {version} active")
    check(after["social"] == before["social"], "Existing social connections, targets and deliveries preserved exactly")
    live_offer = json.loads(distribution.read_text())["products"]["plugin:bluesky-publisher"]
    check(live_offer["version"] == "0.1.3" and live_offer["sha256"] == sha(bluesky_target) and live_offer["pricing"] == "paid", "Paid Bluesky 0.1.3 distribution offer")
    run(["systemctl", "reload", "php8.5-fpm"])
    run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])
    run(["nginx", "-t"])
    receipt["status"] = "deployed"
except BaseException:
    receipt["status"] = "rolling-back"
    if marketplace_changed and backup is not None:
        run(["php8.5", str(stage / "scripts/update-social-oauth-popup-marketplace.php"), str(web), "--rollback", str(backup)])
    if distribution_changed and backup is not None:
        atomic_bytes(distribution, (backup / "distribution-before.json").read_bytes())
    if bluesky_target is not None and bluesky_target.is_file() and bluesky_target.parent == releases and bluesky_target.name == "plugin-bluesky-publisher-0.1.3.zip":
        bluesky_target.unlink()
    for slug in reversed(installed):
        rollback(slug)
    run(["systemctl", "reload", "php8.5-fpm"])
    receipt["status"] = "rolled-back"
    raise
finally:
    if install_dir is not None and install_dir.is_dir() and install_dir.name.startswith("social-oauth-popup-") and install_dir.parent == web / "storage/private":
        shutil.rmtree(install_dir)
    if backup is not None:
        (backup / "receipt.json").write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n")
        os.chmod(backup / "receipt.json", 0o600)
    fcntl.flock(lock, fcntl.LOCK_UN)
    lock.close()

print(json.dumps(receipt, sort_keys=True), flush=True)
