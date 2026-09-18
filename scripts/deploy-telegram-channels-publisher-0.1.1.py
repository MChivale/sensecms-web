#!/usr/bin/env python3
"""Build, verify, upgrade and distribute Telegram Channels Publisher 0.1.1."""
from __future__ import annotations

import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys

if os.name == "nt" or os.geteuid() != 0 or len(sys.argv) != 2:
    raise SystemExit("Run as root on Linux: deploy-telegram-channels-publisher-0.1.1.py <private-stage>")

stage = Path(sys.argv[1]).resolve()
web = Path("/home/sensecms.com/web")
distribution = web / "storage/distribution.json"
releases = web / "storage/distribution/releases"
publisher = Path("/root/sensecms-private/publisher.ed25519")
console = web / "app/Views/console.php"
identity = "plugin:telegram-channels-publisher"
expected_archive = "def0f4abd1eb5bb34eb8c0304c32d036830a1878ff538fd0bf72b124cf6a62c0"
expected_plugin = "e8ce81e85f489904f45debc1d31987859b19e0c74841bd691bfd588496926651"
expected_provider = "acc8dfa01544de3e9fb91755d7c231cd836209b388fb010d3bacf844298501db"
expected_console = "0d0163745afebe367b62e7140d918f76f437454876e1986e34d3802cc7dc633a"
expected_addon = "1841c826df1ad337b5168187427fede4b5015f1249cda5672cd2d14e115f5efa"
receipt = {"status": "preflight", "checks": []}
backup = install_dir = target = None
installed = distribution_changed = console_changed = marketplace_changed = False


def run(command, *, user=None):
    if user:
        command = ["sudo", "-u", user, *command]
    result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if result.returncode:
        raise RuntimeError(result.stdout.decode(errors="replace").strip() or f"Command failed: {command[0]}")
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
    temporary = destination.with_name(destination.name + ".telegram-011-new")
    shutil.copy2(source, temporary)
    stat = destination.stat()
    os.chown(temporary, stat.st_uid, stat.st_gid)
    os.chmod(temporary, stat.st_mode & 0o777)
    os.replace(temporary, destination)


def atomic_bytes(destination, payload):
    temporary = destination.with_name(destination.name + ".telegram-011-new")
    temporary.write_bytes(payload)
    stat = destination.stat()
    os.chown(temporary, stat.st_uid, stat.st_gid)
    os.chmod(temporary, stat.st_mode & 0o777)
    os.replace(temporary, destination)


def state():
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$q=$db->prepare("SELECT version,active,signature_status FROM extension_packages WHERE type='plugin' AND slug='telegram-channels-publisher'");$q->execute();$social=[];foreach(['social_connections','social_post_targets','social_deliveries']as$t){$rows=$db->query("SELECT * FROM `$t` ORDER BY 1")->fetchAll();$social[$t]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];}$due=(int)$db->query("SELECT COUNT(*) FROM social_post_targets t INNER JOIN posts p ON p.id=t.post_id WHERE t.enabled=1 AND t.last_enqueued_revision<t.revision AND (p.status='published' OR (p.status='scheduled' AND p.published_at<=UTC_TIMESTAMP()))")->fetchColumn();echo json_encode(['plugin'=>$q->fetch()?:null,'social'=>$social,'due'=>$due],JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web))


def install(archive):
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();$s=$m->stageLocalFile($argv[2],$owner);echo json_encode($m->install($s['token'],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web, archive, user="sensecms"))


def rollback_package():
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();echo json_encode($m->rollback('plugin','telegram-channels-publisher',$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web, user="sensecms"))


required = [
    stage / ".addons/social-publishing/sense-package.json",
    stage / ".plugins/telegram-channels-publisher/sense-package.json",
    stage / ".plugins/telegram-channels-publisher/plugin.json",
    stage / ".plugins/telegram-channels-publisher/src/provider.php",
    stage / ".cms/source/app/Views/console.php",
    stage / ".src/package-catalog.php",
    stage / "scripts/update-telegram-channels-marketplace-0.1.1.php",
    stage / "tests/telegram-channels-publisher.php",
    stage / "tests/telegram-channels-social-release.php",
    stage / "tests/telegram-channels-ui-production.py",
    stage / "tests/package-catalog-http.py",
]
check(stage.is_dir() and str(stage).startswith("/root/sense-telegram-channels-fix-"), "Private scoped Telegram Channels deployment stage")
check(all(path.is_file() and not path.is_symlink() for path in required), "Complete Telegram Channels 0.1.1 candidate")
check(web.is_dir() and distribution.is_file() and releases.is_dir() and publisher.is_file(), "Production package and distribution runtime")
check(sha(web / "plugins/telegram-channels-publisher/plugin.json") == expected_plugin and sha(web / "plugins/telegram-channels-publisher/src/provider.php") == expected_provider and sha(console) == expected_console, "Pinned Telegram Channels 0.1.0 and console baselines")
before = state()
check(before["plugin"] == {"version": "0.1.0", "active": 1, "signature_status": "verified"}, "Signed Telegram Channels Publisher 0.1.0 baseline")
offer = json.loads(distribution.read_text()).get("products", {}).get(identity, {})
check(offer.get("version") == "0.1.0" and offer.get("sha256") == expected_archive and offer.get("pricing") == "paid" and offer.get("enabled") is True, "Paid Telegram Channels 0.1.0 distribution baseline")
for path in [*stage.glob(".plugins/telegram-channels-publisher/**/*.php"), stage / ".cms/source/app/Views/console.php", stage / "scripts/update-telegram-channels-marketplace-0.1.1.php", stage / "tests/telegram-channels-publisher.php", stage / "tests/telegram-channels-social-release.php"]:
    run(["php8.5", "-l", str(path)])
run(["python3", "-m", "py_compile", str(stage / "scripts/deploy-telegram-channels-publisher-0.1.1.py"), str(stage / "tests/telegram-channels-ui-production.py"), str(stage / "tests/package-catalog-http.py")])
check(b"'error' => ['bg-danger/10 text-danger', 'circle-x']" in (stage / ".cms/source/app/Views/console.php").read_bytes(), "Console renders error flashes with the danger treatment")
run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])
run(["nginx", "-t"])
logs = {path: path.stat().st_size for path in [Path("/var/log/nginx/sensecms.com.error.log"), web / "storage/php-error.log"] if path.exists()}

lock = Path("/root/sensecms-private/deploy-workspace.lock").open("a+b")
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
try:
    artifacts = stage / "artifacts"
    artifacts.mkdir(mode=0o700)
    build = r'''require$argv[1].'/bootstrap.php';$secret=base64_decode(trim(file_get_contents($argv[2])),true);if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid key');try{$a=App\Core\Packages\Archive::build($argv[3],$argv[4],$secret);echo json_encode(['sha256'=>$a['sha256'],'public'=>base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))],JSON_THROW_ON_ERROR);}finally{sodium_memzero($secret);}'''
    hashes = {}
    public = ""
    for kind, slug, version in [("addon", "social-publishing", "0.2.2"), ("plugin", "telegram-channels-publisher", "0.1.1")]:
        archive = artifacts / f"{kind}-{slug}-{version}.zip"
        result = json.loads(php(build, web, publisher, stage / f".{kind}s" / slug, archive))
        hashes[f"{kind}:{slug}"] = result["sha256"]
        public = result["public"]
    check(hashes["addon:social-publishing"] == expected_addon, "Unchanged exact Social Publishing 0.2.2 dependency")
    (artifacts / "telegram-channels-social-release.json").write_text(json.dumps(hashes, separators=(",", ":")))
    (artifacts / "trust.json").write_text(json.dumps({"sensecms-release": public}, separators=(",", ":")))
    os.chmod(artifacts / "telegram-channels-social-release.json", 0o600)
    os.chmod(artifacts / "trust.json", 0o600)
    receipt["artifacts"] = hashes

    testtree = stage / "runtime-tests"
    (testtree / "tests").mkdir(parents=True, mode=0o700)
    (testtree / ".cms").mkdir(mode=0o700)
    (testtree / ".cms/source").symlink_to(web, target_is_directory=True)
    (testtree / ".plugins").symlink_to(stage / ".plugins", target_is_directory=True)
    (testtree / ".addons").symlink_to(stage / ".addons", target_is_directory=True)
    for name in ["telegram-channels-publisher.php", "telegram-channels-social-release.php"]:
        shutil.copy2(stage / "tests" / name, testtree / "tests" / name)
    check("15 Telegram Channels publisher checks passed" in run(["php8.5", str(testtree / "tests/telegram-channels-publisher.php")]).decode(), "Isolated Telegram Channels provider protocol")
    check("signed Telegram Channels social package checks passed" in run(["php8.5", str(testtree / "tests/telegram-channels-social-release.php"), str(artifacts), str(artifacts / "trust.json")]).decode(), "Exact signed Telegram Channels package lifecycle on isolated MariaDB")

    stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup = Path("/root/sensecms-backups") / (stamp + "-telegram-channels-publisher-011")
    backup.mkdir(mode=0o700)
    receipt["backup"] = str(backup)
    shutil.copy2(distribution, backup / "distribution-before.json")
    shutil.copy2(console, backup / "console.php")
    shutil.copytree(web / "plugins/telegram-channels-publisher", backup / "plugin-0.1.0")
    shutil.copy2(artifacts / "plugin-telegram-channels-publisher-0.1.1.zip", backup / "plugin-telegram-channels-publisher-0.1.1.zip")
    with (backup / "database-before.sql").open("wb") as output:
        result = subprocess.run(["mariadb-dump", "--single-transaction", "--skip-lock-tables", "--hex-blob", "--no-tablespaces", "sensecms_site"], stdout=output, stderr=subprocess.PIPE)
        if result.returncode:
            raise RuntimeError(result.stderr.decode(errors="replace").strip() or "Database backup failed.")
    os.chmod(backup / "database-before.sql", 0o600)
    check((backup / "database-before.sql").stat().st_size > 4096, "Private production database recovery dump")

    uid = int(run(["id", "-u", "sensecms"]).decode())
    gid = int(run(["id", "-g", "sensecms"]).decode())
    install_dir = web / "storage/private" / ("telegram-channels-deploy-" + stamp)
    install_dir.mkdir(mode=0o700)
    os.chown(install_dir, uid, gid)
    candidate = install_dir / "plugin-telegram-channels-publisher-0.1.1.zip"
    shutil.copy2(artifacts / candidate.name, candidate)
    os.chown(candidate, uid, gid)
    os.chmod(candidate, 0o600)
    result = install(candidate)
    installed = True
    check(result.get("version") == "0.1.1" and result.get("updated") is True, "PackageManager upgraded Telegram Channels Publisher to 0.1.1")
    atomic_file(stage / ".cms/source/app/Views/console.php", console)
    console_changed = True

    target = releases / candidate.name
    check(not target.exists(), "Immutable Telegram Channels 0.1.1 distribution target is new")
    payload = (artifacts / candidate.name).read_bytes()
    temporary = target.with_name(target.name + ".new")
    temporary.write_bytes(payload)
    store_stat = distribution.stat()
    os.chown(temporary, store_stat.st_uid, store_stat.st_gid)
    os.chmod(temporary, 0o640)
    os.replace(temporary, target)
    current = json.loads(distribution.read_text())
    current["products"][identity].update({"version": "0.1.1", "sha256": hashlib.sha256(payload).hexdigest(), "bytes": len(payload), "file": target.name})
    atomic_bytes(distribution, (json.dumps(current, separators=(",", ":")) + "\n").encode())
    distribution_changed = True
    run(["php8.5", str(stage / "scripts/update-telegram-channels-marketplace-0.1.1.php"), str(web), "--apply", str(backup)])
    marketplace_changed = True

    after = state()
    check(after["plugin"] == {"version": "0.1.1", "active": 1, "signature_status": "verified"}, "Signed Telegram Channels Publisher 0.1.1 active")
    check(after["social"] == before["social"], "Existing social connections, targets and deliveries preserved exactly")
    for source in stage.glob(".plugins/telegram-channels-publisher/**/*"):
        if source.is_file() and source.name != "sense-package.json":
            check(sha(source) == sha(web / "plugins/telegram-channels-publisher" / source.relative_to(stage / ".plugins/telegram-channels-publisher")), "Deployed checksum " + source.name)
    check(sha(console) == sha(stage / ".cms/source/app/Views/console.php"), "Deployed console error-state checksum")
    live = json.loads(distribution.read_text())["products"][identity]
    check(live["version"] == "0.1.1" and live["sha256"] == sha(target) and live["pricing"] == "paid", "Paid Telegram Channels 0.1.1 distribution offer")
    provider_check = r'''$r=$argv[1];require$r.'/bootstrap.php';$p=require$r.'/plugins/telegram-channels-publisher/src/provider.php';$p->initialize($r);$m=new ReflectionMethod($p,'client');$c=$m->invoke($p);if(!$c instanceof SenseCMS\TelegramChannels\TelegramChannelsClient)throw new RuntimeException('Provider client unavailable.');echo'OK';'''
    check(php(provider_check, web, user="sensecms") == b"OK", "Production provider initializes the complete workspace configuration")
    if after["due"] == 0:
        worker = json.loads(run(["php8.5", str(web / "addons/social-publishing/scripts/social-worker.php"), str(web)], user="sensecms"))
        check(worker.get("ok") is True and worker.get("published") == 0, "Production worker healthy without publishing content")
    run(["systemctl", "reload", "php8.5-fpm"])
    check("PASS Production Telegram Channels workspace" in run(["python3", str(stage / "tests/telegram-channels-ui-production.py")]).decode(), "Authenticated Telegram Channels workspace and assets")
    check("managed marketplace routes" in run(["python3", str(stage / "tests/package-catalog-http.py"), "https://www.sensecms.com"]).decode(), "Public marketplace route and Telegram Channels detail")
    run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])
    run(["nginx", "-t"])
    check(all(not re.search(rb"PHP (?:Warning|Fatal|Parse|Notice)|Uncaught|\[(?:crit|alert|emerg|error)\]", path.read_bytes()[position:], re.I) for path, position in logs.items()), "No fresh PHP/Nginx errors")
    receipt["status"] = "deployed"
except BaseException:
    receipt["status"] = "rolling-back"
    if marketplace_changed and backup is not None:
        try:
            run(["php8.5", str(stage / "scripts/update-telegram-channels-marketplace-0.1.1.php"), str(web), "--rollback", str(backup)])
        except Exception:
            pass
    if distribution_changed and backup is not None:
        atomic_bytes(distribution, (backup / "distribution-before.json").read_bytes())
    if target is not None and target.is_file() and target.parent == releases and target.name == "plugin-telegram-channels-publisher-0.1.1.zip":
        target.unlink()
    if console_changed and backup is not None:
        atomic_file(backup / "console.php", console)
    if installed:
        rollback_package()
    run(["systemctl", "reload", "php8.5-fpm"])
    receipt["status"] = "rolled-back"
    raise
finally:
    if install_dir is not None and install_dir.is_dir() and install_dir.name.startswith("telegram-channels-deploy-") and install_dir.parent == web / "storage/private":
        shutil.rmtree(install_dir)
    if backup is not None:
        (backup / "receipt.json").write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n")
        os.chmod(backup / "receipt.json", 0o600)
    fcntl.flock(lock, fcntl.LOCK_UN)
    lock.close()

print(json.dumps(receipt, sort_keys=True), flush=True)
