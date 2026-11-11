#!/bin/sh
# One-shot operator publication of a pre-verified signed Marketplace release.
set -eu
umask 077

stage=${1:?stage directory required}
root=${2:?private application root required}
public=${3:?public document root required}
release=${4:?signed release directory required}
case "$stage:$root:$public:$release" in
  /root/sensecms-stage-live-marketplace-v2:/var/www/clients/client9/web128/private/sensecms:/var/www/clients/client9/web128/web:/root/sensecms-releases/20261110-live) ;;
  *) echo "Unexpected publication paths." >&2; exit 2 ;;
esac

for path in "$stage/.src/marketplace-catalog.php" "$stage/.src/marketplace-package.php" "$stage/deploy/ispconfig/public-index.php" "$release/release-set.json" "$release/publisher-public.json" "$root/bootstrap.php" "$root/storage/distribution.json" "$public/index.php"; do
    test -f "$path" && test ! -L "$path" || { echo "Required path is unavailable." >&2; exit 1; }
done
php8.5 -l "$stage/.src/marketplace-catalog.php" >/dev/null
php8.5 -l "$stage/.src/marketplace-package.php" >/dev/null
php8.5 -l "$stage/.cms/source/app/Core/Packages/Distribution.php" >/dev/null
php8.5 -l "$stage/.cms/source/app/Core/SystemUpdate.php" >/dev/null
php8.5 -l "$stage/.cms/source/app/Http/DashboardController.php" >/dev/null

backup=$(mktemp -d /root/sensecms-backups/marketplace-live.XXXXXXXX)
trap 'echo "Publication failed; recovery copy: $backup" >&2' HUP INT TERM EXIT
mkdir -m 700 "$backup/files"
for relative in app/Core/Packages/Distribution.php app/Core/SystemUpdate.php app/Http/DashboardController.php storage/distribution.json storage/marketplace-catalog.json website/marketplace-catalog.php website/marketplace-package.php; do
    if test -e "$root/$relative"; then
        mkdir -p "$backup/files/$(dirname "$relative")"
        cp -p "$root/$relative" "$backup/files/$relative"
    fi
done
cp -p "$public/index.php" "$backup/files/public-index.php"
cp -p "$release/release-set.json" "$backup/release-set.json"
cp -p "$release/release-set.sig" "$backup/release-set.sig"

archive_dir="$root/storage/distribution/releases"
test -d "$archive_dir" && test ! -L "$archive_dir" || { echo "Unsafe archive directory." >&2; exit 1; }
php8.5 -r '
require $argv[1]."/bootstrap.php";
$release=$argv[2];$inventory=json_decode(file_get_contents($release."/release-set.json"),true,32,JSON_THROW_ON_ERROR);
foreach($inventory["products"] as $product){$file=$product["file"]??"";App\Core\Packages\Manifest::path($file);if(!is_file($release."/".$file)||is_link($release."/".$file))throw new RuntimeException("Missing verified release archive.");}
' "$root" "$release"
for archive in "$release"/*.zip; do
    target="$archive_dir/$(basename "$archive")"
    test ! -e "$target" || { echo "Release archive already exists; immutable publication refused." >&2; exit 1; }
    install -o web128 -g client9 -m 0600 "$archive" "$target"
done

install_atomic() {
    source=$1 target=$2 mode=$3
    temp="$target.marketplace.$$"
    # shellcheck disable=SC2086
    install -o web128 -g client9 -m "$mode" "$source" "$temp"
    mv -f "$temp" "$target"
}
install_atomic "$stage/.cms/source/app/Core/Packages/Distribution.php" "$root/app/Core/Packages/Distribution.php" 0644
install_atomic "$stage/.cms/source/app/Core/SystemUpdate.php" "$root/app/Core/SystemUpdate.php" 0644
install_atomic "$stage/.cms/source/app/Http/DashboardController.php" "$root/app/Http/DashboardController.php" 0644

php8.5 -r '
require $argv[1]."/bootstrap.php";
$root=$argv[1];$release=$argv[2];$runtime=new App\Core\Runtime($root);$config=$runtime->read("distribution");
$trust=json_decode(file_get_contents($release."/publisher-public.json"),true,16,JSON_THROW_ON_ERROR);$key=$trust["sensecms-release"]??null;
if(!is_string($key)||strlen((string)base64_decode($key,true))!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)throw new RuntimeException("Release publisher trust is invalid.");
$inventory=json_decode(file_get_contents($release."/release-set.json"),true,32,JSON_THROW_ON_ERROR);$products=[];
foreach($inventory["products"] as $entry){foreach(["identity","file","sha256","bytes","version","channel"]as$field)if(!array_key_exists($field,$entry))throw new RuntimeException("Release inventory is incomplete.");if($entry["channel"]!=="stable"||!preg_match("~^(theme|addon|plugin):[a-z0-9-]+$~D",$entry["identity"]))throw new RuntimeException("Unexpected release product.");[$type,$slug]=explode(":",$entry["identity"],2);$products[$entry["identity"]]=["enabled"=>true,"type"=>$type,"slug"=>$slug,"version"=>$entry["version"],"file"=>$entry["file"],"sha256"=>$entry["sha256"],"bytes"=>$entry["bytes"],"channel"=>"stable","pricing"=>"free"];}
$config["enabled"]=true;$config["publishers"]=array_replace((array)($config["publishers"]??[]),$trust);$config["public_key"]=$key;$config["products"]=$products;$runtime->write("distribution",$config);
' "$root" "$release"
chown web128:client9 "$root/storage/distribution.json"

php8.5 -r '
require $argv[1]."/bootstrap.php";$r=new App\Core\Runtime($argv[1]);$d=new App\Core\Packages\Distribution($r);$inventory=json_decode(file_get_contents($argv[2]."/release-set.json"),true,32,JSON_THROW_ON_ERROR);foreach($inventory["products"]as$entry)$d->archive($entry["identity"]);echo "All release archives accepted by live distribution.\n";
' "$root" "$release"
php8.5 "$stage/scripts/publish-marketplace-catalog.php" "$root" "$stage/.src/package-catalog.php" /root/sensecms-private/publisher.ed25519
chown web128:client9 "$root/storage/marketplace-catalog.json"
install_atomic "$stage/.src/marketplace-catalog.php" "$root/website/marketplace-catalog.php" 0644
install_atomic "$stage/.src/marketplace-package.php" "$root/website/marketplace-package.php" 0644
install_atomic "$stage/deploy/ispconfig/public-index.php" "$public/index.php" 0644
php8.5 -l "$public/index.php" >/dev/null

php8.5 -r '
require $argv[1]."/bootstrap.php";$r=new App\Core\Runtime($argv[1]);$raw=file_get_contents($argv[1]."/storage/marketplace-catalog.json");$catalog=App\Core\OfficialCatalog::verify($raw);if(count($catalog["products"])!==21)throw new RuntimeException("Unexpected public catalog size.");foreach($catalog["products"]as$p)if(($p["channel"]??null)!=="stable"||($p["pricing"]??null)!=="free")throw new RuntimeException("Unexpected Marketplace terms.");echo "Signed public Marketplace catalogue accepted.\n";
' "$root"
trap - HUP INT TERM EXIT
echo "Live Marketplace published; recovery copy: $backup"
