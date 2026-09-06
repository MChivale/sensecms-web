<?php

declare(strict_types=1);

// Explicit first-install operation. Run offline before publishing public/index.php.
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0 || ($argv[1] ?? '') !== '--provision-sensecms') exit(1);
umask(0077);
$web = '/home/sensecms.com/web';
$private = '/root/sensecms-private';
require $web . '/bootstrap.php';
try {
    if ($argc !== 4 || realpath($web) !== $web || is_file($web . '/public/index.php')) throw new RuntimeException('Only an unpublished initial installation is supported.');
    $runtime = new App\Core\Runtime($web);
    if ($runtime->read('installed') || $runtime->read('installing')) throw new RuntimeException('Existing installation must be preserved.');
    $rootDb = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($rootDb->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='sensecms_site'")->fetchColumn()
        || $rootDb->query("SELECT COUNT(*) FROM mysql.user WHERE User='sensecms_site'")->fetchColumn()) throw new RuntimeException('Database identity already exists; do not overwrite.');
    $license = (string) file_get_contents($argv[2]);
    if (!preg_match('/^License\s*Key\s*[:=]\s*([A-Za-z0-9]{32})\s*$/mi', $license, $match)) throw new RuntimeException('Invalid private license input.');
    $runtime->write('setup', ['base_url' => 'https://www.sensecms.com']);
    $runtime->license()->install($match[1], $runtime->baseUrl());
    unset($license, $match);
    if (!is_dir($private) && !mkdir($private, 0700)) throw new RuntimeException('Cannot create operator storage.');
    if (is_link($private) || is_file($private . '/owner.json')) throw new RuntimeException('Existing operator credentials must be preserved.');
    $owner = ['url' => 'https://www.sensecms.com/login', 'email' => 'info@sensecms.com', 'password' => bin2hex(random_bytes(24))];
    $databasePassword = bin2hex(random_bytes(32));
    file_put_contents($private . '/owner.json', json_encode($owner, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
    file_put_contents($private . '/database.json', json_encode(['name' => 'sensecms_site', 'user' => 'sensecms_site', 'password' => $databasePassword], JSON_THROW_ON_ERROR), LOCK_EX);
    $rootDb->exec('CREATE DATABASE sensecms_site CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $rootDb->exec("CREATE USER 'sensecms_site'@'127.0.0.1' IDENTIFIED BY " . $rootDb->quote($databasePassword));
    $rootDb->exec("GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,REFERENCES ON sensecms_site.* TO 'sensecms_site'@'127.0.0.1'");
    (new App\Installer\Installer($runtime))->install(['db_host' => '127.0.0.1', 'db_port' => 3306, 'db_name' => 'sensecms_site', 'db_user' => 'sensecms_site', 'db_password' => $databasePassword,
        'admin_name' => 'Sense CMS Owner', 'admin_email' => $owner['email'], 'admin_password' => $owner['password'], 'admin_confirm' => $owner['password'], 'site_name' => 'Sense CMS']);
    $keyFile = $private . '/publisher.ed25519';
    if (!is_file($keyFile)) file_put_contents($keyFile, base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), LOCK_EX);
    if (is_link($keyFile) || (fileperms($keyFile) & 0077)) throw new RuntimeException('Unsafe publisher key.');
    $secret = base64_decode(trim((string) file_get_contents($keyFile)), true);
    if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new RuntimeException('Invalid publisher key.');
    try {
        $keys = ['sensecms-release' => sodium_crypto_sign_publickey_from_secretkey($secret)];
        file_put_contents($private . '/trust.json', json_encode(array_map('base64_encode', $keys), JSON_THROW_ON_ERROR), LOCK_EX);
        $archive = $private . '/sensecms-theme-0.1.0.zip';
        App\Core\Packages\Archive::build($argv[3], $archive, $secret);
        (new App\Core\Packages\ThemeManager($runtime))->install($archive, $keys);
    } finally { sodium_memzero($secret); }
    $runtime->license()->enforce($runtime->baseUrl());
    echo "Sense CMS initialized, license verified, owner created and signed theme activated.\n";
} catch (Throwable $error) {
    // Never expose a PDO exception (SQL can contain a newly generated password).
    fwrite(STDERR, 'Provisioning stopped: ' . ($error instanceof PDOException ? 'database operation failed; inspect private installation state' : $error->getMessage()) . PHP_EOL);
    exit(1);
}
