<?php

declare(strict_types=1);

// Linux host test: creates and removes only a cryptographically named disposable DB.
if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY === 'Windows') exit("Run on the isolated MariaDB test host.\n");
set_error_handler(static function (int $severity, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });
$root = dirname(__DIR__) . '/.cms/source';
require $root . '/bootstrap.php';
$server = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$name = 'senseqa_' . bin2hex(random_bytes(6)); $count = 0;
$packageRoot = sys_get_temp_dir() . '/sense-workspace-package-' . bin2hex(random_bytes(12));
$assert = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) throw new RuntimeException($label);
    $count++; echo "PASS $label\n";
};
$reject = static function (callable $action, string $label) use ($assert): void {
    try { $action(); } catch (RuntimeException) { $assert(true, $label); return; }
    $assert(false, $label);
};
try {
    $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $server->exec("USE `$name`");
    foreach (explode(';', (string) file_get_contents($root . '/database/001_core.sql')) as $sql) if (trim($sql) !== '') $server->exec($sql);
    $hash = password_hash(bin2hex(random_bytes(20)), PASSWORD_ARGON2ID);
    $server->prepare('INSERT INTO users (name,email,password,created_at) VALUES (?,?,?,UTC_TIMESTAMP()),(?,?,?,UTC_TIMESTAMP())')->execute(['Owner','owner@example.test',$hash,'Editor','editor@example.test',$hash]);
    $server->exec("INSERT INTO roles (slug,name) VALUES ('owner','Owner'),('editor','Editor'); INSERT INTO user_roles VALUES (1,1),(2,2); INSERT INTO settings (`key`,value) VALUES ('site_name','Preserved site'); INSERT INTO activity_log (user_id,event,created_at) VALUES (1,'test.preserved',UTC_TIMESTAMP())");
    $server->prepare("INSERT INTO migrations (name,checksum,applied_at) VALUES ('001_core',?,UTC_TIMESTAMP())")->execute([hash_file('sha256', $root . '/database/001_core.sql')]);
    $before = $server->query('SELECT id,email,password,active,session_version FROM users ORDER BY id')->fetchAll();
    $migration = new App\Installer\WorkspaceMigration($server, $root);
    $tablesBefore = $server->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $status = $migration->status();
    $assert(!$status['ready'] && $status['applied'] === 0 && count($status['pending']) > 20, 'Read-only status identifies the initial Core');
    $assert($tablesBefore === $server->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), 'Status does not create a migration journal');
    mkdir($packageRoot, 0700);
    $activationRoot = $packageRoot . '/activation';
    mkdir($activationRoot . '/database/workspace', 0700, true);
    copy($root . '/database/001_core.sql', $activationRoot . '/database/001_core.sql');
    foreach (glob($root . '/database/workspace/*.sql') as $file) copy($file, $activationRoot . '/database/workspace/' . basename($file));
    $activation = new App\Installer\WorkspaceMigration($server, $activationRoot);
    $activationRuntime = new App\Core\Runtime($activationRoot);
    $reject(fn() => $activation->enable($activationRuntime), 'Activation rejects an unfinished Core installation');
    $activationRuntime->write('installed', ['core_version'=>'0.1.0']);
    $reject(fn() => $activation->enable($activationRuntime), 'Activation cannot implicitly migrate an initial Core');
    $assert(!file_exists($activationRoot . '/storage/workspace.json'), 'Failed activation creates no Workspace identity');
    $assert($migration->apply() > 20, 'Workspace migrations applied');
    $assert($migration->apply() === 0, 'Migration is repeat-safe');
    $assert($migration->status()['ready'], 'Completed migration reports schema readiness');
    $journal = $server->query('SELECT name,checksum FROM workspace_migrations ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
    $first = array_key_first($journal); $last = array_key_last($journal);
    $server->prepare('DELETE FROM workspace_migrations WHERE name=?')->execute([$first]);
    $server->prepare('UPDATE workspace_migrations SET checksum=? WHERE name=?')->execute([str_repeat('0', 64), $last]);
    $reject(fn() => $migration->apply(), 'A corrupt later journal entry blocks earlier pending DDL');
    $assert((int) $server->query('SELECT COUNT(*) FROM workspace_migrations')->fetchColumn() === count($journal) - 1, 'Rejected preflight leaves migration history unchanged');
    $server->prepare('UPDATE workspace_migrations SET checksum=? WHERE name=?')->execute([$journal[$last], $last]);
    $reject(fn() => $migration->apply(), 'Migration history gaps are rejected before writes');
    $server->prepare('INSERT INTO workspace_migrations VALUES (?,?,UTC_TIMESTAMP())')->execute([$first, $journal[$first]]);
    $server->prepare("UPDATE migrations SET checksum=? WHERE name='001_core'")->execute([str_repeat('0', 64)]);
    $reject(fn() => $migration->status(), 'Core identity is checked even after Workspace migrations');
    $server->prepare("UPDATE migrations SET checksum=? WHERE name='001_core'")->execute([hash_file('sha256', $root . '/database/001_core.sql')]);
    $server->exec('RENAME TABLE ai_knowledge_chunks TO qa_missing_chunks');
    $assert(!$migration->status()['ready'], 'Readiness detects missing tables despite complete journal');
    $reject(fn() => $activation->enable($activationRuntime), 'Missing table blocks activation');
    $server->exec('RENAME TABLE qa_missing_chunks TO ai_knowledge_chunks');
    $other = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=' . $name, 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $lock = 'sense-workspace-' . substr(hash('sha256', $name), 0, 40);
    $other->prepare('SELECT GET_LOCK(?,0)')->execute([$lock]);
    $reject(fn() => $activation->enable($activationRuntime), 'Concurrent migration lock prevents activation');
    $reject(fn() => $migration->apply(), 'Concurrent migration lock prevents DDL');
    $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    $activation->enable($activationRuntime);
    $identity = $activationRuntime->read('workspace');
    $assert($identity['enabled'] === true && preg_match('/^[a-f0-9]{64}$/D', $identity['secret']) === 1, 'Explicit activation provisions a local private identity');
    $rawIdentity = file_get_contents($activationRoot . '/storage/workspace.json');
    $activation->enable($activationRuntime);
    $assert(file_get_contents($activationRoot . '/storage/workspace.json') === $rawIdentity, 'Repeated activation preserves exact private state');
    $cipher = (new App\Core\Secrets($identity['secret']))->encrypt('fixture-not-a-real-credential');
    $server->prepare('INSERT INTO settings (`key`,value) VALUES (?,?)')->execute(['email_system_settings', json_encode(['server'=>['password_cipher'=>$cipher]], JSON_THROW_ON_ERROR)]);
    unlink($activationRoot . '/storage/workspace.json');
    $reject(fn() => $activation->enable($activationRuntime), 'Missing identity cannot orphan existing SMTP ciphertext');
    $assert(!file_exists($activationRoot . '/storage/workspace.json'), 'Ciphertext safeguard does not replace a lost identity');
    $activationRuntime->write('workspace', []);
    $reject(fn() => $activation->enable($activationRuntime), 'Empty existing identity cannot be replaced');
    $activationRuntime->write('workspace', ['enabled'=>false,'secret'=>bin2hex(random_bytes(32))]);
    $reject(fn() => $activation->enable($activationRuntime), 'Wrong well-formed key cannot activate unreadable data');
    $activationRuntime->write('workspace', array_replace($identity, ['enabled'=>false,'retained'=>'custom']));
    $activation->enable($activationRuntime);
    $restored = $activationRuntime->read('workspace');
    $assert($restored['secret'] === $identity['secret'] && $restored['retained'] === 'custom' && $restored['enabled'] === true, 'Activation preserves restored encryption key and additional state');
    $server->exec("DELETE FROM settings WHERE `key`='email_system_settings'");
    $cipherFixtures = [
        ['ai_providers', "INSERT INTO ai_providers (slug,name,driver,default_model,api_key_encrypted,created_at,updated_at) VALUES ('qa-key','QA','disabled','none',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())"],
        ['notification_channel_settings', "INSERT INTO notification_channel_settings (plugin_slug,subject_type,subject_id,encrypted_settings,updated_at) VALUES ('qa-key','installation',0,?,UTC_TIMESTAMP())"],
        ['web_push_subscriptions', "INSERT INTO web_push_subscriptions (user_id,endpoint_hash,encrypted_subscription,device_label,last_seen_at,created_at,updated_at) VALUES (1,REPEAT('a',64),?,'QA',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())"],
    ];
    foreach ($cipherFixtures as [$table, $sql]) {
        $server->prepare($sql)->execute([$cipher]);
        unlink($activationRoot . '/storage/workspace.json');
        $reject(fn() => $activation->enable($activationRuntime), 'Missing key blocks activation with ciphertext in ' . $table);
        $assert($server->getAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY) === true, 'Failed cipher scan restores buffered query mode');
        $activationRuntime->write('workspace', array_replace($identity, ['enabled'=>false]));
        $activation->enable($activationRuntime);
        $assert($activationRuntime->read('workspace')['secret'] === $identity['secret'], 'Matching key preserves encrypted data in ' . $table);
        $server->exec("DELETE FROM `$table`"); // Disposable fixture tables are empty before these inserts.
    }
    $assert($before === $server->query('SELECT id,email,password,active,session_version FROM users ORDER BY id')->fetchAll(), 'Users and credentials unchanged');
    $assert((int) $server->query("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE r.slug='owner'")->fetchColumn() === 1, 'No privilege escalation for existing editor');
    $assert($server->query("SELECT value FROM settings WHERE `key`='site_name'")->fetchColumn() === 'Preserved site', 'Site settings preserved');
    $assert((int) $server->query("SELECT COUNT(*) FROM activity_log WHERE event='test.preserved'")->fetchColumn() === 1, 'Existing audit preserved');
    $assert((int) $server->query('SELECT COUNT(*) FROM facilities')->fetchColumn() === 0, 'No fabricated facility seeded');
    $assert((int) $server->query('SELECT COUNT(*) FROM installed_plugins')->fetchColumn() === 0, 'No phantom integrations seeded');
    $ownerAccess = App\Core\AccessControl::forUser($server, 1);
    $editorAccess = App\Core\AccessControl::forUser($server, 2);
    $assert($ownerAccess->allows('system.owner'), 'Existing owner retains access');
    $assert(!$editorAccess->allows('system.owner') && !$editorAccess->allows('users.manage'), 'Editor remains restricted');
    $assert($editorAccess->facilityIds() === [], 'Unassigned editor has no facility scope');
    $cms = new App\Core\CmsRepository($server, new App\Core\EventBus());
    $assert(count($cms->languages()) === 2, 'English and Polish configured');
    $assert($cms->setting('site_name') === 'Preserved site', 'Legacy plain settings remain readable');
    $beforeSettings = $server->query('SELECT * FROM settings ORDER BY `key`')->fetchAll();
    $reject(fn() => $cms->withThemeConfigurationLock(function () use ($cms): void {
        $cms->saveSetting('theme_configurations', ['sensecms'=>['home_title'=>'Must roll back']]);
        $cms->saveSetting('theme_drafts', []);
        $cms->recordActivity(1, 'theme.configuration.rollback-test', 'theme');
        throw new RuntimeException('Injected failure before commit.');
    }), 'Theme publication propagates failure before commit');
    $assert($beforeSettings === $server->query('SELECT * FROM settings ORDER BY `key`')->fetchAll(), 'Failed theme publication rolls back every setting and new lock row');
    $assert(!$server->inTransaction(), 'Failed publication leaves no open transaction');
    $assert((int) $server->query("SELECT COUNT(*) FROM activity_log WHERE event='theme.configuration.rollback-test'")->fetchColumn() === 0, 'Failed publication also rolls back its audit');
    $facilities = new App\Core\FacilityRepository($server);
    $createFacility = static fn(string $slug): int => $facilities->save(['id'=>0,'city_slug'=>'london','facility_slug'=>$slug,'status'=>'active','timezone'=>'Europe/London'], ['en'=>['name'=>ucfirst($slug),'city_name'=>'London','short_description'=>'','address'=>'','seo_title'=>'','seo_description'=>'']], 1);
    $north = $createFacility('north'); $south = $createFacility('south');
    $assert(count($facilities->adminList()) === 2, 'Multiple facilities persisted');
    $assert(count($facilities->adminList([$north])) === 1 && $facilities->adminList([]) === [], 'Facility listing respects assigned scope');
    $facilities->setPrimary($north, 1);
    $reject(fn() => $facilities->setStatus($north, 'archived', 1), 'Primary facility cannot be archived');
    $facilities->setStatus($south, 'archived', 1);
    $assert($facilities->admin($south)['status'] === 'archived', 'Facility archive preserves the record');
    $facilities->setStatus($south, 'active', 1);

    $password = bin2hex(random_bytes(24));
    $editorInput = ['id'=>2,'name'=>'Editor','email'=>'editor@example.test','active'=>1,'role_id'=>2,'facility_ids'=>[$north],'password'=>$password];
    $ownerAccess->saveUser($editorInput, 1);
    $editor = $server->query('SELECT password,session_version FROM users WHERE id=2')->fetch();
    $assert(password_verify($password, $editor['password']) && password_get_info($editor['password'])['algoName'] === 'argon2id', 'Administrator password changes use Argon2id');
    $assert((int) $editor['session_version'] === 2, 'Administrator changes revoke existing sessions');
    $editorAccess = App\Core\AccessControl::forUser($server, 2);
    $assert($editorAccess->facilityIds() === [$north] && !$editorAccess->allows('content.pages.edit', $south), 'Editor cannot access another facility');
    $reject(fn() => $ownerAccess->saveUser(array_replace($editorInput, ['password'=>str_repeat('a', 201)]), 1), 'Overlong administrator password rejected');
    $reject(fn() => $ownerAccess->saveUser(['id'=>1,'name'=>'Owner','email'=>'owner@example.test','active'=>0,'role_id'=>1], 1), 'Final owner cannot be disabled');
    $token = bin2hex(random_bytes(32));
    $server->prepare("INSERT INTO email_action_tokens (user_id,purpose,token_hash,request_ip_hash,expires_at,created_at) VALUES (2,'password_reset',?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),UTC_TIMESTAMP())")->execute([hash('sha256', $token),hash('sha256', '127.0.0.1')]);
    $email = new App\Core\EmailSystem($server, $cms, new App\Core\Secrets(bin2hex(random_bytes(32))), [], 'https://example.test');
    $email->setPassword($token, $password . 'new');
    $editor = $server->query('SELECT password,session_version FROM users WHERE id=2')->fetch();
    $assert(password_verify($password . 'new', $editor['password']) && (int) $editor['session_version'] === 3, 'Recovery changes password and revokes sessions');
    $reject(fn() => $email->setPassword($token, $password), 'Recovery token is single-use');

    $addon = dirname(__DIR__) . '/.addons/calendar';
    $manifest = json_decode(file_get_contents($addon . '/addon.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($manifest['migrations'] as $item) foreach (explode(';', file_get_contents($addon . '/' . $item['up'])) as $sql) if (trim($sql) !== '') $server->exec($sql);
    require $addon . '/src/CalendarConflictException.php';
    require $addon . '/src/CalendarRepository.php';
    $calendar = new SenseCMS\Calendar\CalendarRepository($server, 1, true);
    $category = $calendar->saveCategory(['slug'=>'consultation','name'=>'Consultation','color'=>'#8b5cf6','active'=>true], 1);
    $assert($category['slug'] === 'consultation', 'Own calendar category created');
    $reject(fn() => $calendar->saveCategory(['slug'=>'consultation','name'=>'Duplicate'], 1), 'Duplicate category rejected');
    $eventInput = ['title'=>'Planning session','facility_id'=>$north,'event_type'=>'consultation','visibility'=>'facility','timezone'=>'Europe/London','start_at'=>'2026-11-16T09:00','end_at'=>'2026-11-16T10:00','channels'=>['internal']];
    $saved = $calendar->save($eventInput, 1, null, false);
    $eventId = (int) $server->query('SELECT id FROM calendar_events ORDER BY id DESC LIMIT 1')->fetchColumn();
    $event = $calendar->event($eventId, null);
    $assert($event['event_type'] === 'consultation' && $event['color'] === '#8b5cf6', 'Event retains category and category color');
    $scopedCalendar = new SenseCMS\Calendar\CalendarRepository($server, 2, false);
    $assert($scopedCalendar->event($eventId, [$south]) === null, 'Calendar blocks cross-facility event access');
    $reject(fn() => $scopedCalendar->save($eventInput, 2, [$south], false), 'Calendar blocks cross-facility event creation');
    $calendar->saveCategory(array_replace($category, ['active'=>false]), 1);
    $reject(fn() => $calendar->save($eventInput, 1, null, false), 'Inactive category cannot be used for new events');
    $calendar->save(array_replace($eventInput, ['id'=>$eventId,'title'=>'Updated planning']), 1, null, false);
    $assert($calendar->event($eventId, null)['title'] === 'Updated planning', 'Existing event remains editable after category deactivation');
    $assert(count($calendar->events('2026-11-01', '2026-12-01', null, ['event_type'=>'consultation'])) === 1, 'Inactive category remains searchable without losing events');
    $general = array_values(array_filter($calendar->categories(), static fn(array $row): bool => $row['slug'] === 'general'))[0];
    $reject(fn() => $calendar->saveCategory(array_replace($general, ['active'=>false]), 1), 'General category cannot be disabled');
    $packageSource = $packageRoot . '/source';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($addon, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile() || $file->isLink()) throw new RuntimeException('Unsafe test package source.');
        $destination = $packageSource . '/' . substr($file->getPathname(), strlen($addon) + 1);
        if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0700, true);
        copy($file->getPathname(), $destination);
    }
    $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $publicKey = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
    $packages = new App\Core\PackageManager($server, $packageRoot, '0.1.0');
    $v1 = $packageRoot . '/calendar-0.1.0.zip';
    App\Core\Packages\Archive::build($packageSource, $v1, $secret);
    $reject(fn() => $packages->stageLocalFile($v1, 1), 'Workspace rejects an untrusted canonical package');
    $packages->trustPublisher('sensecms-release', 'QUANT Software House Limited', '', $publicKey, 1);
    $stage = $packages->stageLocalFile($v1, 1);
    $assert($stage['signature'] === 'verified' && $stage['migrations'] === 4 && $stage['release_channel'] === 'development', 'Workspace verifies canonical package and signed runtime metadata');
    $packages->install($stage['token'], 1);
    $assert(is_file($packageRoot . '/addons/calendar/runtime.php') && $packages->package('addon', 'calendar')['version'] === '0.1.0', 'Calendar installs through the real package lifecycle');
    $assert((int) $server->query('SELECT COUNT(*) FROM calendar_events')->fetchColumn() === 1, 'Calendar installation preserves existing events');
    foreach (['sense-package.json', 'addon.json'] as $file) {
        $json = json_decode(file_get_contents($packageSource . '/' . $file), true, flags: JSON_THROW_ON_ERROR);
        $json['version'] = '0.1.1';
        file_put_contents($packageSource . '/' . $file, json_encode($json, JSON_THROW_ON_ERROR));
    }
    $v2 = $packageRoot . '/calendar-0.1.1.zip';
    App\Core\Packages\Archive::build($packageSource, $v2, $secret);
    $stage = $packages->stageLocalFile($v2, 1);
    $packages->install($stage['token'], 1);
    $assert($packages->package('addon', 'calendar')['version'] === '0.1.1', 'Signed calendar update installs');
    $packages->setPublisherActive('sensecms-release', false, 1);
    $reject(fn() => $packages->rollback('addon', 'calendar', 1), 'Rollback reverifies current publisher trust');
    $assert($packages->package('addon', 'calendar')['version'] === '0.1.1', 'Rejected rollback keeps current package active');
    $packages->setPublisherActive('sensecms-release', true, 1);
    $recoveryArchive = $packageRoot . '/storage/packages/sources/' . hash_file('sha256', $v1) . '.zip';
    $zip = new ZipArchive(); $zip->open($recoveryArchive);
    $zip->addFromString('payload/runtime.php', '<?php /* modified QA payload */'); $zip->close();
    $reject(fn() => $packages->rollback('addon', 'calendar', 1), 'Rollback rejects tampered recovery payload');
    $assert($packages->package('addon', 'calendar')['version'] === '0.1.1', 'Tampered rollback leaves installed version unchanged');
    copy($v1, $recoveryArchive);
    $packages->rollback('addon', 'calendar', 1);
    $assert($packages->package('addon', 'calendar')['version'] === '0.1.0', 'Rollback restores the original signed release');
    $assert((int) $server->query('SELECT COUNT(*) FROM calendar_events')->fetchColumn() === 1, 'Rollback preserves calendar data');
    $packages->uninstall('addon', 'calendar', 1);
    $assert(!is_dir($packageRoot . '/addons/calendar') && !$packages->package('addon', 'calendar'), 'Uninstall removes only extension code and registration');
    $assert((int) $server->query('SELECT COUNT(*) FROM calendar_events')->fetchColumn() === 1, 'Uninstall retains user events for recovery');
    $stage = $packages->stageLocalFile($v1, 1);
    $packages->install($stage['token'], 1);
    $assert((int) $server->query('SELECT COUNT(*) FROM calendar_events')->fetchColumn() === 1, 'Reinstall reuses preserved calendar data');
    $upSql = file_get_contents($packageSource . '/migrations/up.sql');
    file_put_contents($packageSource . '/migrations/up.sql', $upSql . "\nSELECT 1;\n");
    $changedMigration = $packageRoot . '/calendar-changed-migration.zip';
    App\Core\Packages\Archive::build($packageSource, $changedMigration, $secret);
    $stage = $packages->stageLocalFile($changedMigration, 1);
    $reject(fn() => $packages->install($stage['token'], 1), 'Previously applied migration cannot change under the same ID');
    $assert($packages->package('addon', 'calendar')['version'] === '0.1.0', 'Changed migration leaves prior package registration intact');
    file_put_contents($packageSource . '/migrations/up.sql', $upSql);
    foreach (['sense-package.json', 'addon.json'] as $file) {
        $json = json_decode(file_get_contents($packageSource . '/' . $file), true, flags: JSON_THROW_ON_ERROR);
        $json['version'] = '0.1.2';
        if ($file === 'addon.json') $json['migrations'][] = ['id'=>'qa-failure','up'=>'migrations/qa-failure.sql','down'=>'migrations/qa-retain.sql'];
        file_put_contents($packageSource . '/' . $file, json_encode($json, JSON_THROW_ON_ERROR));
    }
    file_put_contents($packageSource . '/migrations/qa-failure.sql', "CREATE TABLE qa_retained_data (id INT PRIMARY KEY); INSERT INTO qa_retained_data VALUES (7); SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA deliberate migration failure';");
    file_put_contents($packageSource . '/migrations/qa-retain.sql', 'DROP TABLE qa_retained_data;');
    $failedRelease = $packageRoot . '/calendar-0.1.2.zip';
    App\Core\Packages\Archive::build($packageSource, $failedRelease, $secret);
    $stage = $packages->stageLocalFile($failedRelease, 1);
    $reject(fn() => $packages->install($stage['token'], 1), 'Failed migration does not publish the new release');
    $assert($packages->package('addon', 'calendar')['version'] === '0.1.0' && json_decode(file_get_contents($packageRoot . '/addons/calendar/addon.json'), true)['version'] === '0.1.0', 'Failed update restores prior code and package registration');
    $assert((int) $server->query('SELECT id FROM qa_retained_data')->fetchColumn() === 7 && (int) $server->query('SELECT COUNT(*) FROM calendar_events')->fetchColumn() === 1, 'Failed migration retains data instead of running destructive down SQL');
    $themeSource = $packageRoot . '/theme-source';
    $originalTheme = dirname(__DIR__) . '/.themes/sensecms';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($originalTheme, FilesystemIterator::SKIP_DOTS)) as $file) {
        $destination = $themeSource . '/' . substr($file->getPathname(), strlen($originalTheme) + 1);
        if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0700, true);
        copy($file->getPathname(), $destination);
    }
    mkdir($packageRoot . '/config', 0700);
    copy($root . '/config/product.php', $packageRoot . '/config/product.php');
    $themeArchive = $packageRoot . '/theme.zip';
    App\Core\Packages\Archive::build($themeSource, $themeArchive, $secret);
    $themeStage = $packages->stageLocalFile($themeArchive, 1);
    $themeInstall = $packages->install($themeStage['token'], 1);
    $themeManager = $packages->themeManager();
    $assert($themeManager->active() === null && count($themeManager->releases()) === 1, 'Panel theme installation retains release without activating it');
    $reject(fn() => $packages->uninstall('theme', 'sensecms', 1), 'Legacy package removal cannot delete retained signed themes');
    $runtime = new App\Core\Runtime($packageRoot);
    $runtime->write('workspace', ['enabled'=>true]);
    $liveCms = new App\Core\CmsRepository($server, new App\Core\EventBus(), $runtime);
    $cms->saveSetting('active_theme', 'legacy-other-theme');
    $cms->saveSetting('theme_settings', ['accent'=>'#ff0000']);
    $themeManager->activate($themeInstall['directory'], $packages->trustedKeys());
    $assert($liveCms->setting('active_theme') === 'sensecms', 'Workspace reads the same active theme as the public renderer');
    $assert($liveCms->setting('theme_settings') === [], 'Settings from a different legacy theme do not leak into the active theme');
    $cms->saveSetting('theme_configurations', ['sensecms'=>['accent'=>'#123456']]);
    $assert($liveCms->setting('theme_settings')['accent'] === '#123456', 'Active theme uses its own scoped configuration');
    $packages->setPublisherActive('sensecms-release', false, 1);
    $reject(fn() => $themeManager->activate($themeInstall['directory'], $packages->trustedKeys()), 'Panel activation rejects a revoked publisher key');
    $assert($themeManager->active()['directory'] === $themeInstall['directory'], 'Revoked activation leaves the current theme unchanged');
    $packages->setPublisherActive('sensecms-release', true, 1);
    $assert((int) $server->query('SELECT COUNT(*) FROM calendar_events')->fetchColumn() === 1, 'Theme lifecycle preserves existing extension data');
    $sourceThemeVersion = $themeInstall['version'];
    $projected = $packages->package('theme', 'sensecms');
    $assert($projected['active'] && $projected['version'] === $sourceThemeVersion && $projected['signature_status'] === 'verified', 'Package registry projects signed active theme metadata');
    $assert(count($packages->packages('theme')) === 1, 'Signed theme is listed once without a database package row');
    $themeManifest = json_decode(file_get_contents($themeSource . '/sense-package.json'), true);
    $themeDescriptor = json_decode(file_get_contents($themeSource . '/theme.json'), true);
    $parts = explode('.', $sourceThemeVersion); $parts[2] = (string) ((int) $parts[2] + 1);
    $nextThemeVersion = implode('.', $parts);
    $themeManifest['version'] = $themeDescriptor['version'] = $nextThemeVersion;
    file_put_contents($themeSource . '/sense-package.json', json_encode($themeManifest));
    file_put_contents($themeSource . '/theme.json', json_encode($themeDescriptor));
    App\Core\Packages\Archive::build($themeSource, $packageRoot . '/theme-update.zip', $secret);
    $themeUpdate = $packages->stageLocalFile($packageRoot . '/theme-update.zip', 1);
    $themeUpdated = $packages->install($themeUpdate['token'], 1);
    $projected = $packages->package('theme', 'sensecms');
    $assert($projected['version'] === $sourceThemeVersion && $projected['installed_version'] === $nextThemeVersion && $projected['pending_version'] === $nextThemeVersion, 'Staged upgrade is distinct from the serving release in package registry');
    $again = $packages->stageLocalFile($packageRoot . '/theme-update.zip', 1);
    $reject(fn() => $packages->install($again['token'], 1), 'Already staged upgrade cannot be installed twice');
    $themeManager->activate($themeUpdated['directory'], $packages->trustedKeys());
    $projected = $packages->package('theme', 'sensecms');
    $assert($projected['version'] === $nextThemeVersion && $projected['pending_version'] === null, 'Activation immediately updates the registry projection');
    $themeManager->rollback($packages->trustedKeys());
    $assert($packages->package('theme', 'sensecms')['version'] === $sourceThemeVersion, 'Rollback immediately restores the registry version');
    $packages->setPublisherActive('sensecms-release', false, 1);
    $projected = $packages->package('theme', 'sensecms');
    $assert($projected['signature_status'] === 'unverified' && $projected['active'] && $projected['last_error'] !== null, 'Revoked trust is visible without erasing the serving theme');
    $packages->setPublisherActive('sensecms-release', true, 1);
    $assert($packages->package('theme', 'sensecms')['signature_status'] === 'verified', 'Restored trust refreshes the package projection');
    $assert((int) $server->query("SELECT COUNT(*) FROM extension_packages WHERE type='theme'")->fetchColumn() === 0, 'Reading private themes does not create duplicate database state');
    $server->exec("INSERT INTO extension_packages (type,slug,name,version,publisher,source,active,manifest,update_url,available_version,installed_at,updated_at) VALUES ('theme','sensecms','Stale theme','9.0.0','Old publisher','package',0,'{}','https://example.invalid/update','10.0.0',NOW(),NOW())");
    $staleRow = $server->query("SELECT * FROM extension_packages WHERE type='theme'")->fetch();
    $projected = $packages->package('theme', 'sensecms');
    $assert($projected['version'] === $sourceThemeVersion && $projected['active'] && count($packages->packages('theme')) === 1, 'Private release overrides stale database version without duplication');
    $assert($packages->updateCount() === 0, 'Stale legacy theme update does not inflate the update count');
    $assert($packages->checkUpdates()['checked'] === 0, 'Private themes do not query an obsolete legacy update endpoint');
    $assert($staleRow === $server->query("SELECT * FROM extension_packages WHERE type='theme'")->fetch(), 'Projection preserves the legacy database row for controlled migration');
    $retainedArchive = $packageRoot . '/storage/themes/' . $themeInstall['directory'] . '/archive.zip';
    file_put_contents($retainedArchive, 'QA deliberately damaged retained archive');
    $freshPackages = new App\Core\PackageManager($server, $packageRoot, $packages->engineVersion());
    $projected = $freshPackages->package('theme', 'sensecms');
    $assert($projected['active'] && $projected['signature_status'] === 'unverified', 'Damaged archive stays visible without a false verified signature');
    $assert($themeManager->active()['directory'] === $themeInstall['directory'], 'Metadata verification failure does not silently switch the website');
    sodium_memzero($secret);
    echo "Completed $count Workspace migration, functional and package checks.\n";
} finally {
    // The target is generated here, never accepted from arguments or environment.
    if (!preg_match('/^senseqa_[a-f0-9]{12}$/D', $name)) throw new RuntimeException('Unsafe cleanup target.');
    $server->exec("DROP DATABASE IF EXISTS `$name`");
    if (is_dir($packageRoot)) {
        if (!preg_match('#^' . preg_quote(sys_get_temp_dir(), '#') . '/sense-workspace-package-[a-f0-9]{24}$#D', $packageRoot)) throw new RuntimeException('Unsafe package test cleanup target.');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packageRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($packageRoot);
    }
}
