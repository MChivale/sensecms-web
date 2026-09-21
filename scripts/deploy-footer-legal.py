#!/usr/bin/env python3
"""Guarded production publication of the Sense CMS website polish and SEO."""
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
import urllib.request

if os.name == "nt" or os.geteuid() != 0 or len(sys.argv) != 2:
    raise SystemExit("Run as root on Linux: deploy-footer-legal.py <private-stage>")

stage = Path(sys.argv[1]).resolve()
source = stage / ".themes" / "sensecms"
core_source = stage / ".cms" / "source"
web = Path("/home/sensecms.com/web")
required = [source / name for name in ["sense-package.json", "theme.json", "layout.php", "pages.php", "legal-pages.php", "assets/site.css", "assets/product.css", "assets/site.js", "assets/logo-light.svg", "assets/og.image.jpg", "views/metadata.php", "views/product.php", "views/subpage.php"]]
core_files = [
    "app/Core/PublicTheme.php",
    "app/Core/SeoMeta.php",
    "app/Http/DashboardController.php",
    "app/Http/PublicController.php",
    "app/Views/console-content-page-form.php",
    "public/theme/sensecms-content-management.css",
    "public/theme/sensecms-content-management.js",
]
core_baseline = {
    "app/Core/PublicTheme.php": "11725407dc3dce91631293ccab737560b6a2b337d80f9659c5620a10441ce3b6",
    "app/Core/SeoMeta.php": "6fa9d8e2aafbd5b712e9165f38a1d67e4efe20b232ca246eb1e01979a6059882",
    "app/Http/DashboardController.php": "55e68e5f1c87be2efaf7c8c7e7e040d6c9635ab39a37275b649172a22a74812c",
    "app/Http/PublicController.php": "7ee84b4b4c0b6cf95fa4a177a2ebc14e33e37fc73dd78c0fa833329c9c972309",
    "app/Views/console-content-page-form.php": "546925836662a9b78ca10841b05f3e7547d883347fd24a4b95a017f1b1a16298",
    "public/theme/sensecms-content-management.css": "708033c816d1add5875c227b5b7066ded12480228fbe81afbb3eb8948bf11e3a",
    "public/theme/sensecms-content-management.js": "ef3f8cda8c4e61ca8afcb5f6280b1927f3201a6cc22520a0dc8989697e7790d0",
}
required += [core_source / name for name in core_files]
if not stage.is_dir() or not str(stage).startswith("/root/sense-footer-legal-") or not all(path.is_file() and not path.is_symlink() for path in required):
    raise RuntimeError("A complete private deployment stage is required.")

os.umask(0o077)
lock = Path("/root/sensecms-private/deploy-workspace.lock").open("a+b")
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
backup: Path | None = None
release: dict[str, object] | None = None
receipt: dict[str, object] = {"status": "preflight", "checks": []}
settings_before: dict[str, object] | None = None


def run(command: list[str], *, user: str | None = None) -> bytes:
    if user:
        command = ["runuser", "-u", user, "--", *command]
    result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if result.returncode:
        detail = result.stdout.decode("utf-8", "replace").strip().splitlines()
        raise RuntimeError("Command failed: " + command[0] + (" (" + " | ".join(detail[-6:])[:1200] + ")" if detail else ""))
    return result.stdout


def check(condition: bool, label: str) -> None:
    if not condition:
        raise RuntimeError(label)
    receipt["checks"].append(label)
    print("PASS " + label, flush=True)


def php(code: str, *args: str, user: str | None = None) -> bytes:
    return run(["php8.5", "-r", "require $argv[1].'/bootstrap.php';" + code, str(web), *args], user=user)


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def replace_file(src: Path, dst: Path) -> None:
    tmp = dst.with_name(dst.name + ".sensecms-deploy")
    shutil.copy2(src, tmp)
    shutil.chown(tmp, user="sensecms", group="sensecms")
    os.chmod(tmp, dst.stat().st_mode & 0o777)
    os.replace(tmp, dst)


inventory: dict[str, str] = {}
for path in sorted(source.rglob("*")):
    if path.is_symlink():
        raise RuntimeError("Theme source symlinks are forbidden.")
    if path.is_file():
        inventory[path.relative_to(source).as_posix()] = sha(path)
        if path.suffix == ".php":
            run(["php8.5", "-l", str(path)])
for relative in core_files:
    if relative.endswith(".php"):
        run(["php8.5", "-l", str(core_source / relative)])
manifest = json.loads((source / "sense-package.json").read_text())
descriptor = json.loads((source / "theme.json").read_text())
check(manifest["slug"] == descriptor["slug"] == "sensecms" and manifest["version"] == descriptor["version"] == "1.0.8", "Exact Sense CMS theme 1.0.8 candidate")
state = json.loads((web / "storage/theme.json").read_text())
check(state["active"]["slug"] == "sensecms" and state["active"]["version"] == "1.0.7", "Expected active theme 1.0.7 baseline")
check(all(sha(web / relative) == digest for relative, digest in core_baseline.items()), "Expected production Core baseline")
check(Path("/root/sensecms-private/publisher.ed25519").is_file() and Path("/root/sensecms-private/trust.json").is_file(), "Private publisher and trust provisioned")
run(["nginx", "-t"]); run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])

try:
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    started = int(datetime.datetime.now(datetime.timezone.utc).timestamp())
    backup = Path("/root/sensecms-backups") / (stamp + "-footer-wordmark")
    backup.mkdir(mode=0o700)
    shutil.copy2(web / "storage/theme.json", backup / "theme-before.json")
    run(["tar", "-czf", str(backup / "theme-storage-before.tgz"), "-C", str(web), "storage/theme.json", "storage/themes"])
    (backup / "candidate.json").write_text(json.dumps(inventory, indent=2, sort_keys=True) + "\n")
    core_backup = backup / "core-before"
    for relative in core_files:
        target = core_backup / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(web / relative, target)
    with (backup / "database-before.sql").open("wb") as dump:
        result = subprocess.run(["mysqldump", "--single-transaction", "--routines", "--triggers", "sensecms_site"], stdout=dump, stderr=subprocess.PIPE)
        if result.returncode:
            raise RuntimeError("Production database backup failed.")
    os.chmod(backup / "database-before.sql", 0o600)
    check((backup / "database-before.sql").stat().st_size > 4096, "Private database recovery dump")
    read_settings = r'''$r=new App\Core\Runtime($argv[1]);$db=App\Core\Runtime::connect($r->read('installed')['database']);$q=$db->prepare('SELECT value FROM settings WHERE `key`=?');$out=[];foreach(['theme_settings','theme_configurations']as$key){$q->execute([$key]);$value=$q->fetchColumn();$out[$key]=$value===false?false:$value;}echo json_encode($out,JSON_THROW_ON_ERROR);'''
    settings_before = json.loads(php(read_settings))
    (backup / "theme-settings-before.json").write_text(json.dumps(settings_before, indent=2, sort_keys=True) + "\n")
    os.chmod(backup / "theme-settings-before.json", 0o600)

    archive = backup / "theme-sensecms-1.0.8.zip"
    build = r'''$secret=base64_decode(trim(file_get_contents('/root/sensecms-private/publisher.ed25519')),true);if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid publisher key.');try{$a=App\Core\Packages\Archive::build($argv[2],$argv[3],$secret);echo json_encode($a,JSON_THROW_ON_ERROR);}finally{sodium_memzero($secret);}'''
    built = json.loads(php(build, source, archive))
    check(built["sha256"] == sha(archive), "Exact signed theme archive built")
    expected_directory = "sensecms-1.0.8-" + sha(archive)[:16]
    orphan = web / "storage/themes" / expected_directory
    if orphan.exists() and expected_directory not in state.get("releases", {}):
        check((orphan / "archive.zip").is_file() and sha(orphan / "archive.zip") == sha(archive), "Previous failed candidate identified exactly")
        shutil.move(orphan, backup / "orphan-release-before-retry")
    trust = json.loads(Path("/root/sensecms-private/trust.json").read_text())
    keys = json.dumps(trust, separators=(",", ":"))
    install = r'''$r=new App\Core\Runtime($argv[1]);$r->license()->enforce($r->baseUrl());$keys=array_map(fn($key)=>base64_decode($key,true),json_decode($argv[3],true,16,JSON_THROW_ON_ERROR));echo json_encode((new App\Core\Packages\ThemeManager($r))->install($argv[2],$keys,false),JSON_THROW_ON_ERROR);'''
    release = json.loads(php(install, archive, keys))
    release_root = web / "storage/themes" / str(release["directory"])
    release_path = release_root / "payload"
    run(["chown", "-R", "sensecms:sensecms", str(release_root)])
    run(["chown", "sensecms:sensecms", str(web / "storage/theme.json"), str(web / "storage/themes.lock")])
    for relative in core_files:
        replace_file(core_source / relative, web / relative)
    run(["systemctl", "reload", "php8.5-fpm"])
    check(all(sha(web / relative) == sha(core_source / relative) for relative in core_files), "Production Core files match reviewed source")
    activate = r'''$r=new App\Core\Runtime($argv[1]);$keys=array_map(fn($key)=>base64_decode($key,true),json_decode($argv[3],true,16,JSON_THROW_ON_ERROR));(new App\Core\Packages\ThemeManager($r))->activate($argv[2],$keys);'''
    php(activate, release["directory"], keys, user="sensecms")
    check(all(sha(release_path / path) == digest for path, digest in inventory.items() if path != "sense-package.json"), "Installed payload matches reviewed source")
    update_brand = r'''$r=new App\Core\Runtime($argv[1]);$db=App\Core\Runtime::connect($r->read('installed')['database']);$cms=new App\Core\CmsRepository($db,new App\Core\EventBus(),$r);$settings=(array)$cms->setting('theme_settings',[]);$configs=(array)$cms->setting('theme_configurations',[]);$current=(string)($settings['footer_brand_text']??($configs['sensecms']['footer_brand_text']??''));if(!in_array($current,['','Sense CMS','SenseCMS.'],true))throw new RuntimeException('Footer brand was customized concurrently.');$settings['footer_brand_text']='SenseCMS.';$configs['sensecms']=array_replace((array)($configs['sensecms']??[]),$settings,['footer_brand_text'=>'SenseCMS.']);$cms->saveSetting('theme_settings',$settings);$cms->saveSetting('theme_configurations',$configs);echo $current;'''
    previous_brand = php(update_brand, user="sensecms").decode().strip()
    check(previous_brand in ("", "Sense CMS", "SenseCMS."), "Footer wordmark setting migrated without overwriting customization")

    routes = {
        "/": [b"Copyright by Sense CMS", b"Privacy Policy", b"Terms &amp; Conditions", b">Cookies</a>", b'class="primary-nav"', b'class="accessibility-menu"', b'id="accessibility-panel"', b'class="language-menu"', b'https://demo.sensecms.com/', b'/theme-assets/logo-light.svg', b'/theme-assets/og.image.jpg', b'content management system for building multilingual websites'],
        "/privacy-policy": [b"company number 16902352", b"Telegram Channels", b"Instagram", b"TikTok"],
        "/terms-and-conditions": [b"Terms &amp; Conditions", b"Social publishing is review-first"],
        "/cookies": [b"sensecms_session", b"sensecms:analytics:", b"sensega_ga"],
    }
    for route, markers in routes.items():
        with urllib.request.urlopen("https://www.sensecms.com" + route, timeout=30) as response:
            body = response.read(1048576)
            check(response.status == 200 and all(marker in body for marker in markers), "Live route " + route)
            if route == "/":
                check(b"Independent by design." not in body, "Old footer slogan removed")
                wordmark = b'Sense<span class="brand-light">CMS</span><span class="brand-dot">.</span>'
                check(body.count(wordmark) == 2, "Header and footer use the same SenseCMS wordmark")
    with urllib.request.urlopen("https://www.sensecms.com/theme-assets/site.css", timeout=30) as response:
        css = response.read(1048576)
        check(b".footer a:not(.brand)" in css and b".footer-legal a{" not in css, "One shared footer link rule is live")
        check(b"justify-content:flex-end" in css and b"data-text-scale-large" in css and b".accessibility-panel" in css and b".header.is-compact" in css and b"text-align:justify" in css and b".footer:before" in css, "Compact header and separated justified footer are styled")
        check(b".footer .brand,.footer .brand-dot{color:#fff}" in css, "Footer wordmark remains white")
    with urllib.request.urlopen("https://www.sensecms.com/theme-assets/site.js", timeout=30) as response:
        script = response.read(1048576)
        check(b"sensecms:text-scale" in script and b"data-accessibility-toggle" in script and b"is-compact" in script, "Local text-size and compact header controls are live")
    for asset, mime in [("logo-light.svg", "image/svg+xml"), ("og.image.jpg", "image/jpeg")]:
        url = "https://www.sensecms.com/theme-assets/" + asset
        with urllib.request.urlopen(url, timeout=30) as response:
            data = response.read(1048576)
            check(response.status == 200 and response.headers.get_content_type() == mime and sha(release_path / "assets" / asset) == hashlib.sha256(data).hexdigest(), "Live asset " + asset)
        with urllib.request.urlopen(urllib.request.Request(url, method="HEAD"), timeout=30) as response:
            check(response.status == 200 and response.headers.get_content_type() == mime and int(response.headers.get("Content-Length", "0")) > 0, "HEAD asset " + asset)
    with urllib.request.urlopen("https://www.sensecms.com/docs/installation", timeout=30) as response:
        body = response.read(1048576)
        check(b'"@type":"BreadcrumbList"' in body and b'aria-label="Breadcrumb"' in body, "Visible and structured documentation breadcrumbs")
    with urllib.request.urlopen("https://www.sensecms.com/sitemap.xml", timeout=30) as response:
        sitemap = response.read(1048576)
        check(b"<lastmod>" in sitemap and b"/docs/installation</loc>" in sitemap, "Static sitemap change dates are live")
    panel_contract = json.loads((release_path / "theme.json").read_text())
    fields = {field["key"] for field in panel_contract["configuration"]}
    check({"footer_logo_url", "footer_brand_text", "footer_description", "footer_copyright_text", "footer_right_type", "footer_right_text", "footer_link_1_label", "footer_link_1_url", "footer_link_2_label", "footer_link_2_url", "footer_link_3_label", "footer_link_3_url"} <= fields, "Footer controls exposed through theme configuration")
    php(activate, release["directory"], keys, user="sensecms")
    check(json.loads((web / "storage/theme.json").read_text())["active"]["version"] == "1.0.8", "Signed active theme reverified")
    run(["nginx", "-t"]); run(["systemctl", "is-active", "nginx", "php8.5-fpm", "mariadb", "cron"])
    errors = run(["journalctl", "-u", "php8.5-fpm", "--since", "@" + str(started), "-p", "err", "--no-pager"])
    check(b"PHP Fatal" not in errors and b"Uncaught" not in errors, "No fresh PHP-FPM fatal errors")
    receipt.update(status="deployed", backup=str(backup), release=release, archive_sha256=sha(archive))
except BaseException:
    receipt["status"] = "rolling-back"
    if backup is not None:
        if settings_before is not None:
            restore_settings = r'''$r=new App\Core\Runtime($argv[1]);$db=App\Core\Runtime::connect($r->read('installed')['database']);$state=json_decode($argv[2],true,8,JSON_THROW_ON_ERROR);$save=$db->prepare('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');$delete=$db->prepare('DELETE FROM settings WHERE `key`=?');foreach(['theme_settings','theme_configurations']as$key){if(($state[$key]??false)===false)$delete->execute([$key]);else$save->execute([$key],$state[$key]);}'''
            php(restore_settings, json.dumps(settings_before, separators=(",", ":")), user="sensecms")
        core_backup = backup / "core-before"
        for relative in core_files:
            if (core_backup / relative).is_file():
                replace_file(core_backup / relative, web / relative)
        run(["systemctl", "reload", "php8.5-fpm"])
        restore = web / "storage/theme-header-footer-restore.json"
        shutil.copy2(backup / "theme-before.json", restore)
        shutil.chown(restore, user="sensecms", group="sensecms")
        os.replace(restore, web / "storage/theme.json")
        if release is not None:
            failed = web / "storage/themes" / str(release["directory"])
            if failed.is_dir() and not (backup / "failed-release").exists():
                shutil.move(failed, backup / "failed-release")
        receipt["status"] = "rolled-back"
    raise
finally:
    if backup is not None:
        (backup / "receipt.json").write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n")
        os.chmod(backup / "receipt.json", 0o600)
    fcntl.flock(lock, fcntl.LOCK_UN); lock.close()

print(json.dumps(receipt, sort_keys=True), flush=True)
