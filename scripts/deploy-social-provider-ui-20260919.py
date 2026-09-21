#!/usr/bin/env python3
"""Release the shared Social Publishing UI for LinkedIn, Pinterest and TikTok."""
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
    raise SystemExit("Run as root on Linux: deploy-social-provider-ui-20260919.py <private-stage>")
os.umask(0o077)

stage = Path(sys.argv[1]).resolve()
web = Path("/home/sensecms.com/web")
publisher = Path("/root/sensecms-private/publisher.ed25519")
versions = {
    "linkedin-publisher": ("0.1.1", "0.1.2"),
    "pinterest-publisher": ("0.1.0", "0.1.1"),
    "tiktok-publisher": ("0.1.0", "0.1.1"),
}
receipt = {"status": "preflight", "checks": []}
backup = install_dir = None
installed = []
marketplace_changed = False
started = int(datetime.datetime.now(datetime.timezone.utc).timestamp())


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


def state():
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$out=['packages'=>[]];foreach(['addon:social-publishing','plugin:linkedin-publisher','plugin:pinterest-publisher','plugin:tiktok-publisher']as$i){[$t,$s]=explode(':',$i);$q=$db->prepare('SELECT version,active,signature_status FROM extension_packages WHERE type=? AND slug=?');$q->execute([$t,$s]);$out['packages'][$i]=$q->fetch()?:null;}$out['social']=[];foreach(['social_connections','social_post_targets','social_deliveries']as$t){$rows=$db->query("SELECT * FROM `$t` ORDER BY 1")->fetchAll();$out['social'][$t]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];}$out['due']=(int)$db->query("SELECT COUNT(*) FROM social_post_targets t INNER JOIN posts p ON p.id=t.post_id WHERE t.enabled=1 AND t.last_enqueued_revision<t.revision AND (p.status='published' OR (p.status='scheduled' AND p.published_at<=UTC_TIMESTAMP()))")->fetchColumn();echo json_encode($out,JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web))


def install(archive):
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();$s=$m->stageLocalFile($argv[2],$owner);echo json_encode($m->install($s['token'],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web, archive, user="sensecms"))


def rollback(slug):
    code = r'''$r=$argv[1];require$r.'/bootstrap.php';$x=new App\Core\Runtime($r);$db=App\Core\Runtime::connect($x->read('installed')['database']);$m=new App\Core\PackageManager($db,$r,'1.0.0');$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles x ON x.id=ur.role_id WHERE x.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();echo json_encode($m->rollback('plugin',$argv[2],$owner),JSON_THROW_ON_ERROR);'''
    return json.loads(php(code, web, slug, user="sensecms"))


required = [
    stage / ".src/package-catalog.php",
    stage / ".addons/social-publishing/sense-package.json",
    stage / ".addons/social-publishing/addon.json",
    stage / "scripts/update-social-provider-ui-marketplace.php",
    stage / "tests/package-catalog-http.py",
]
for slug, (_, version) in versions.items():
    required.extend([
        stage / f".plugins/{slug}/sense-package.json",
        stage / f".plugins/{slug}/plugin.json",
        stage / f".plugins/{slug}/runtime.php",
        stage / f".plugins/{slug}/views/{slug.removesuffix('-publisher')}.php",
        stage / f"tests/{slug.removesuffix('-publisher')}-publisher.php",
        stage / f"tests/{slug.removesuffix('-publisher')}-social-release.php",
        stage / f"tests/{slug.removesuffix('-publisher')}-ui-production.py",
    ])
    manifest = json.loads((stage / f".plugins/{slug}/sense-package.json").read_text())
    check(manifest.get("version") == version, f"Candidate {slug} version {version}")
check(stage.is_dir() and str(stage).startswith("/root/sense-social-provider-ui-"), "Private scoped Social provider UI deployment stage")
check(all(path.is_file() and not path.is_symlink() for path in required), "Complete Social provider UI candidate payload")
check(web.is_dir() and publisher.is_file(), "Production installation and signing runtime")
before = state()
check(before["packages"]["addon:social-publishing"] == {"version": "0.3.0", "active": 1, "signature_status": "verified"}, "Signed Social Publishing 0.3.0 baseline")
for slug, (old, _) in versions.items():
    check(before["packages"][f"plugin:{slug}"] == {"version": old, "active": 1, "signature_status": "verified"}, f"Signed {slug} {old} baseline")
check(before["due"] == 0, "No production social publication is waiting during deployment")
for path in [*stage.glob(".plugins/*-publisher/**/*.php"), stage / "scripts/update-social-provider-ui-marketplace.php"]:
    run(["php8.5", "-l", str(path)])
run(["python3", "-m", "py_compile", str(stage / "scripts/deploy-social-provider-ui-20260919.py"), *(str(stage / f"tests/{slug.removesuffix('-publisher')}-ui-production.py") for slug in versions), str(stage / "tests/package-catalog-http.py")])
run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])
run(["nginx", "-t"])

lock = Path("/root/sensecms-private/deploy-workspace.lock").open("a+b")
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
try:
    artifacts = stage / "artifacts"
    artifacts.mkdir(mode=0o700)
    build = r'''require$argv[1].'/bootstrap.php';$secret=base64_decode(trim(file_get_contents($argv[2])),true);if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid key');try{$a=App\Core\Packages\Archive::build($argv[3],$argv[4],$secret);echo json_encode(['sha256'=>$a['sha256'],'public'=>base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))],JSON_THROW_ON_ERROR);}finally{sodium_memzero($secret);}'''
    hashes = {}
    public = ""
    sources = [("addon", "social-publishing", "0.3.0")]
    sources.extend(("plugin", slug, new) for slug, (_, new) in versions.items())
    for kind, slug, version in sources:
        archive = artifacts / f"{kind}-{slug}-{version}.zip"
        result = json.loads(php(build, web, publisher, stage / f".{kind}s" / slug, archive))
        hashes[f"{kind}:{slug}"] = result["sha256"]
        public = result["public"]
    for slug in versions:
        identities = ["addon:social-publishing", f"plugin:{slug}"]
        name = f"{slug.removesuffix('-publisher')}-social-release.json"
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
    for slug in versions:
        short = slug.removesuffix("-publisher")
        for suffix in ["publisher.php", "social-release.php"]:
            shutil.copy2(stage / f"tests/{short}-{suffix}", testtree / "tests" / f"{short}-{suffix}")
        output = run(["php8.5", str(testtree / f"tests/{short}-publisher.php")]).decode()
        check("protocol checks passed" in output, f"Isolated {short} provider protocol")
        output = run(["php8.5", str(testtree / f"tests/{short}-social-release.php"), str(artifacts), str(artifacts / "trust.json")]).decode()
        check("signed" in output and "package checks passed" in output, f"Exact signed lifecycle: {slug}")

    stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup = Path("/root/sensecms-backups") / (stamp + "-social-provider-ui")
    backup.mkdir(mode=0o700)
    receipt["backup"] = str(backup)
    shutil.copytree(artifacts, backup / "artifacts")
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
    install_dir = web / "storage/private" / ("social-provider-ui-" + stamp)
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

    run(["php8.5", str(stage / "scripts/update-social-provider-ui-marketplace.php"), str(web), "--apply", str(backup)])
    marketplace_changed = True
    run(["systemctl", "reload", "php8.5-fpm"])
    after = state()
    for slug, (_, version) in versions.items():
        check(after["packages"][f"plugin:{slug}"] == {"version": version, "active": 1, "signature_status": "verified"}, f"Signed {slug} {version} active")
    check(after["social"] == before["social"] and after["due"] == 0, "Existing social connections, targets and deliveries preserved exactly")
    worker = json.loads(run(["php8.5", str(web / "addons/social-publishing/scripts/social-worker.php"), str(web)], user="sensecms"))
    check(worker.get("ok") is True and worker.get("published") == 0, "Production worker healthy without publishing content")
    for slug in versions:
        short = slug.removesuffix("-publisher")
        output = run(["python3", str(stage / f"tests/{short}-ui-production.py")]).decode()
        check("PASS Production" in output, f"Authenticated {short} workspace and versioned assets")
    output = run(["python3", str(stage / "tests/package-catalog-http.py"), "https://www.sensecms.com"]).decode()
    check("21 product prices/licence policies" in output, "Public marketplace and catalogue versions")
    run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])
    run(["nginx", "-t"])
    logs = run(["journalctl", "--since", "@" + str(started), "--no-pager", "-u", "nginx", "-u", "php8.5-fpm"]).decode(errors="replace")
    check(not re.search(r"PHP Fatal|\bcrit\b|\bpanic\b", logs, re.I), "No fresh critical service errors")
    receipt["status"] = "deployed"
except BaseException:
    receipt["status"] = "rolling-back"
    if marketplace_changed and backup is not None:
        run(["php8.5", str(stage / "scripts/update-social-provider-ui-marketplace.php"), str(web), "--rollback", str(backup)])
    for slug in reversed(installed):
        rollback(slug)
    run(["systemctl", "reload", "php8.5-fpm"])
    receipt["status"] = "rolled-back"
    raise
finally:
    if install_dir is not None and install_dir.is_dir() and install_dir.name.startswith("social-provider-ui-") and install_dir.parent == web / "storage/private":
        shutil.rmtree(install_dir)
    if backup is not None:
        (backup / "receipt.json").write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n")
        os.chmod(backup / "receipt.json", 0o600)
    fcntl.flock(lock, fcntl.LOCK_UN)
    lock.close()

print(json.dumps(receipt, sort_keys=True), flush=True)
