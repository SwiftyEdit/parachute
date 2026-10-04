<?php
/**
 * Helpers for the Parachute backup plugin. Four groups:
 *
 * 1. Access / settings / database (pc_can_access, pc_db, pc_get_settings, ...)
 * 2. Sources - what can be backed up (pc_sources, pc_collect_files, ...)
 * 3. Creating a backup (pc_create_backup and its helpers)
 * 4. Backup files, retention, audit log
 *
 * Backups are written to plugins/parachute/data/backups/ - outside the
 * public/ document root, so a ZIP is never reachable by URL. Downloads go
 * through global/xhr.php, which checks the same permission as the backend.
 */

use Medoo\Medoo;

if (!defined('PC_DATA')) {
    define('PC_DATA', SE_ROOT.'plugins/parachute/data/');
    define('PC_BACKUPS', PC_DATA.'backups/');
    define('PC_TMP', PC_DATA.'tmp/');
}

/* -------------------------------------------------------------------
 * 1. Access / settings / database
 * ---------------------------------------------------------------- */

/**
 * Administrators with the "sensitive files" permission only - the highest
 * permission level SwiftyEdit has (the same one that guards installing
 * plugins and editing theme files). A backup holds the user database and
 * the SMTP password, so being "an administrator" is not enough.
 */
function pc_can_access(): bool {
    if (($_SESSION['user_class'] ?? '') !== 'administrator') {
        return false;
    }
    return function_exists('se_hasPermission') && se_hasPermission('drm_acp_sensitive_files');
}

function pc_db(): ?Medoo {
    global $parachute_db;

    if (isset($parachute_db) && $parachute_db instanceof Medoo) {
        return $parachute_db;
    }

    $file = PC_DATA.'parachute.sqlite3';
    if (!is_file($file)) {
        return null;
    }

    $parachute_db = new Medoo([
        'type' => 'sqlite',
        'database' => $file
    ]);
    return $parachute_db;
}

function pc_get_settings(): array {
    $db = pc_db();
    if ($db === null) {
        return [];
    }
    $result = $db->select('settings', ['key', 'value']);
    return array_column($result, 'value', 'key');
}

function pc_save_setting(string $key, $value): void {
    $db = pc_db();
    if ($db === null) {
        return;
    }

    if ($db->has('settings', ['key' => $key])) {
        $db->update('settings', ['value' => (string) $value], ['key' => $key]);
    } else {
        $db->insert('settings', ['key' => $key, 'value' => (string) $value]);
    }
}

function pc_get_default_settings(): array {
    return [
        // number of successful backups kept on the server, older ones are
        // deleted after each new backup - 0 = keep all
        'keep_count' => 5,
        // JSON list of the source ids selected for the last backup - used
        // to pre-check the form next time. Empty = the defaults from
        // pc_sources().
        'last_selection' => '',
    ];
}

/**
 * Ensure table exists and matches current schema.
 */
function pc_updateOrCreateTable(string $table_name, array $expected_columns): void {
    $db = pc_db();

    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name = '$table_name'")->fetchAll();

    if (empty($tables)) {
        $col_definitions = [];
        foreach ($expected_columns as $col_name => $col_type) {
            $col_definitions[] = "$col_name $col_type";
        }
        $db->query("CREATE TABLE IF NOT EXISTS $table_name (" . implode(', ', $col_definitions) . ")");
        return;
    }

    $tableInfo = $db->query("PRAGMA table_info($table_name)")->fetchAll();
    $existing_columns = array_column($tableInfo, 'name');

    foreach ($expected_columns as $col_name => $col_type) {
        if (!in_array($col_name, $existing_columns, true)) {
            $db->query("ALTER TABLE $table_name ADD COLUMN $col_name $col_type");
        }
    }
}

/**
 * data/, data/backups/, data/tmp/ plus deny-all files. plugins/ is outside
 * the document root anyway - the .htaccess is a second line of defence for
 * installations where the whole project folder is (wrongly) served.
 */
function pc_ensure_dirs(): void {
    foreach ([PC_DATA, PC_BACKUPS, PC_TMP] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    if (!is_file(PC_DATA.'.htaccess')) {
        file_put_contents(PC_DATA.'.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }
    foreach ([PC_DATA, PC_BACKUPS] as $dir) {
        if (!is_file($dir.'index.html')) {
            file_put_contents($dir.'index.html', '');
        }
    }
}

function pc_user(): array {
    return [
        'id' => (int) ($_SESSION['user_id'] ?? 0),
        'nick' => (string) ($_SESSION['user_nick'] ?? ''),
    ];
}

function pc_lang(string $key): string {
    global $addon_lang;
    return (string) ($addon_lang[$key] ?? $key);
}

function pc_format_bytes(int|float $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return number_format($bytes, $i === 0 ? 0 : 1, ',', '.').' '.$units[$i];
}

function pc_se_version(): string {
    $file = SE_ROOT.'version.json';
    $info = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($info)) {
        return '';
    }
    return trim(($info['version'] ?? '').' / '.($info['build'] ?? ''), ' /');
}

function pc_zip_available(): bool {
    return class_exists('ZipArchive');
}

function pc_aes_supported(): bool {
    return pc_zip_available()
        && defined('ZipArchive::EM_AES_256')
        && method_exists('ZipArchive', 'isEncryptionMethodSupported')
        && ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256);
}


/* -------------------------------------------------------------------
 * 2. Sources
 * ---------------------------------------------------------------- */

/**
 * Everything that can be backed up, grouped for the form. Item ids
 * ("db:content.sqlite3", "plugin:former", ...) are what the form posts and
 * what is stored in backups.sources - the paths themselves are always
 * rebuilt here and never taken from the request.
 *
 * Not offered on purpose:
 * - data/cache/ (rebuilt automatically)
 * - the "administration" and "default" themes (replaced by every core update)
 * - plugins/parachute/ itself (would back up the backups)
 *
 * @return array<string, array{label: string, hint: string, items: array<string, array{label: string, hint?: string, paths: string[], default: bool}>}>
 */
function pc_sources(): array {
    global $img_path, $img_tmb_path, $files_path, $se_branding_path;

    $groups = [];

    /* core databases - MySQL dump and/or SQLite files */
    $items = [];
    $pdo = pc_mysql_pdo();
    if ($pdo !== null) {
        try {
            $stats = pc_mysql_stats($pdo);
            $items['mysql:dump'] = [
                'label' => 'MySQL: '.$stats['database'],
                'hint' => sprintf(pc_lang('hint_mysql_dump'), $stats['pattern']),
                'paths' => [],
                'mysql' => $stats,
                'default' => true,
            ];
        } catch (\Throwable $e) {
        }
    }
    foreach (glob(SE_CONTENT.'/database/*.sqlite3') ?: [] as $file) {
        $name = basename($file);
        $items['db:'.$name] = ['label' => $name, 'paths' => [$file], 'default' => true];
    }
    $groups['core_db'] = ['label' => pc_lang('group_core_db'), 'hint' => pc_lang('hint_group_core_db'), 'items' => $items];

    /* plugin data */
    $items = [];
    foreach (glob(SE_PLUGINS.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $slug = basename($dir);
        if ($slug === 'parachute') {
            continue;
        }
        $paths = array_values(array_filter([$dir.'/data', $dir.'/uploads'], 'is_dir'));
        if (!$paths) {
            continue;
        }
        $info = is_file($dir.'/info.json') ? json_decode((string) file_get_contents($dir.'/info.json'), true) : null;
        $name = is_array($info) ? (string) ($info['addon']['name'] ?? $slug) : $slug;
        $items['plugin:'.$slug] = ['label' => $name, 'paths' => $paths, 'default' => true];
    }
    $groups['plugins'] = ['label' => pc_lang('group_plugins'), 'hint' => pc_lang('hint_group_plugins'), 'items' => $items];

    /* uploads */
    $items = [];
    $upload_dirs = [
        'images' => $img_path ?? 'assets/images',
        'images_tmb' => $img_tmb_path ?? 'assets/images_tmb',
        'files' => $files_path ?? 'assets/files',
        'galleries' => 'assets/galleries',
        'avatars' => 'assets/avatars',
        'branding' => $se_branding_path ?? 'assets/branding',
    ];
    foreach ($upload_dirs as $key => $rel) {
        $dir = SE_PUBLIC.'/'.trim((string) $rel, '/');
        if (is_dir($dir)) {
            $items['upload:'.$key] = ['label' => trim((string) $rel, '/'), 'paths' => [$dir], 'default' => true];
        }
    }
    $groups['uploads'] = ['label' => pc_lang('group_uploads'), 'hint' => pc_lang('hint_group_uploads'), 'items' => $items];

    /* configuration */
    $items = [];
    $config_files = [
        'config' => [SE_CONTENT.'/config.php', ''],
        'database' => [SE_ROOT.'config_database.php', pc_lang('hint_config_database')],
        'smtp' => [SE_CONTENT.'/config_smtp.php', pc_lang('hint_config_smtp')],
        'includes' => [SE_CONTENT.'/includes', pc_lang('hint_config_includes')],
        'htaccess' => [SE_PUBLIC.'/.htaccess', pc_lang('hint_config_htaccess')],
    ];
    foreach ($config_files as $key => [$path, $hint]) {
        if (file_exists($path)) {
            $items['config:'.$key] = ['label' => pc_rel_path($path), 'hint' => $hint, 'paths' => [$path], 'default' => true];
        }
    }
    $groups['config'] = ['label' => pc_lang('group_config'), 'hint' => pc_lang('hint_group_config'), 'items' => $items];

    /* themes */
    $items = [];
    foreach (glob(SE_THEMES.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = basename($dir);
        if (in_array($name, ['administration', 'default'], true)) {
            continue;
        }
        $items['theme:'.$name] = ['label' => $name, 'paths' => [$dir], 'default' => false];
    }
    $groups['themes'] = ['label' => pc_lang('group_themes'), 'hint' => pc_lang('hint_group_themes'), 'items' => $items];

    return array_filter($groups, fn($g) => !empty($g['items']));
}

/**
 * @return array<string, array> source id => item
 */
function pc_flat_sources(): array {
    $flat = [];
    foreach (pc_sources() as $group) {
        foreach ($group['items'] as $id => $item) {
            $flat[$id] = $item;
        }
    }
    return $flat;
}

/** Path relative to the installation root, always with forward slashes. */
function pc_rel_path(string $abs): string {
    $root = rtrim(str_replace('\\', '/', SE_ROOT), '/').'/';
    $abs = str_replace('\\', '/', $abs);
    return str_starts_with($abs, $root) ? substr($abs, strlen($root)) : ltrim($abs, '/');
}

/**
 * Never worth backing up: dependency/VCS folders inside themes, OS junk,
 * SQLite side files (the snapshot already contains their content) and
 * unfinished files.
 */
function pc_is_excluded(string $name, bool $is_dir): bool {
    if ($is_dir) {
        return in_array($name, ['node_modules', '.git', '.svn', '.idea', '.cache'], true);
    }
    if (in_array($name, ['.DS_Store', 'Thumbs.db', 'desktop.ini'], true)) {
        return true;
    }
    foreach (['-wal', '-shm', '-journal', '.part'] as $suffix) {
        if (str_ends_with($name, $suffix)) {
            return true;
        }
    }
    return false;
}

/**
 * All files below $path (or $path itself). Symlinks are skipped - they
 * could point anywhere on the server.
 */
function pc_walk(string $path): \Generator {
    if (is_link($path)) {
        return;
    }
    if (is_file($path)) {
        yield $path;
        return;
    }
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            fn(SplFileInfo $f) => !$f->isLink() && !pc_is_excluded($f->getFilename(), $f->isDir())
        ),
        RecursiveIteratorIterator::LEAVES_ONLY,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->isReadable()) {
            yield $file->getPathname();
        }
    }
}

/**
 * @return array<string, string> ZIP entry name (relative path) => absolute path
 */
function pc_collect_files(array $ids, array $catalog): array {
    $files = [];
    foreach ($ids as $id) {
        foreach ($catalog[$id]['paths'] ?? [] as $path) {
            foreach (pc_walk($path) as $abs) {
                $files[pc_rel_path($abs)] = $abs;
            }
        }
    }
    ksort($files);
    return $files;
}

function pc_paths_size(array $paths): array {
    $size = 0;
    $count = 0;
    foreach ($paths as $path) {
        foreach (pc_walk($path) as $abs) {
            $size += (int) @filesize($abs);
            $count++;
        }
    }
    return ['size' => $size, 'count' => $count];
}

/** The ids pre-checked in the form: last selection, else the defaults. */
function pc_selected_ids(array $catalog): array {
    $last = json_decode((string) (pc_get_settings()['last_selection'] ?? ''), true);
    if (is_array($last) && $last) {
        return array_values(array_intersect($last, array_keys($catalog)));
    }
    return array_keys(array_filter($catalog, fn($item) => !empty($item['default'])));
}


/* -------------------------------------------------------------------
 * 2b. MySQL
 *
 * With a config_database.php in the installation root, the core tables
 * (se_pages, se_user, ...) live in MySQL instead of data/database/*.sqlite3
 * (see app/database.php). Plugins keep their own SQLite files either way.
 * The dump is written in pure PHP over the core's existing PDO connection -
 * mysqldump needs exec(), which shared hosting usually disables.
 * ---------------------------------------------------------------- */

/** The core's MySQL connection, or null on SQLite installations. */
function pc_mysql_pdo(): ?PDO {
    global $db_type, $db_content;
    if (($db_type ?? '') !== 'mysql' || !isset($db_content) || !($db_content instanceof Medoo)) {
        return null;
    }
    return $db_content->pdo;
}

/**
 * Only the tables that belong to this installation - a MySQL database is
 * often shared with other applications. With a prefix: every table that
 * starts with it, without one: the core's "se_" tables.
 */
function pc_mysql_tables(PDO $pdo): array {
    $prefix = defined('DB_PREFIX') && DB_PREFIX !== '' ? (string) DB_PREFIX : 'se_';
    $tables = [];
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $row) {
        if (str_starts_with((string) $row[0], $prefix)) {
            $tables[] = (string) $row[0];
        }
    }
    sort($tables);
    return $tables;
}

/** Database name, table pattern, table count and estimated size. */
function pc_mysql_stats(PDO $pdo): array {
    $tables = pc_mysql_tables($pdo);
    $size = 0;
    if ($tables) {
        $in = implode(',', array_map([$pdo, 'quote'], $tables));
        $size = (int) $pdo->query("SELECT SUM(DATA_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)")->fetchColumn();
    }
    $prefix = defined('DB_PREFIX') && DB_PREFIX !== '' ? (string) DB_PREFIX : 'se_';
    return [
        'database' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'pattern' => $prefix.'*',
        'tables' => count($tables),
        'size' => $size,
    ];
}

function pc_sql_ident(string $name): string {
    return '`'.str_replace('`', '``', $name).'`';
}

function pc_sql_value(mixed $value): string {
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    $value = (string) $value;
    if ($value === '') {
        return "''";
    }
    // binary data (BLOBs) as hex literal - survives any charset
    if (!mb_check_encoding($value, 'UTF-8')) {
        return '0x'.bin2hex($value);
    }
    return "'".strtr($value, [
        '\\' => '\\\\',
        "\0" => '\\0',
        "\n" => '\\n',
        "\r" => '\\r',
        "'" => "\\'",
        '"' => '\\"',
        "\x1a" => '\\Z',
    ])."'";
}

/**
 * Write a .sql dump of the installation's tables to $dest. Runs inside a
 * consistent snapshot (same as mysqldump --single-transaction), so InnoDB
 * tables are dumped as one point in time even while the site is in use.
 *
 * @return int number of dumped tables
 */
function pc_mysql_dump(PDO $pdo, string $dest): int {
    $fh = fopen($dest, 'wb');
    if (!$fh) {
        throw new RuntimeException('Could not create the SQL dump file.');
    }

    $buffered_attr = defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
        ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
        : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;

    $tables = pc_mysql_tables($pdo);
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

    fwrite($fh, "-- SwiftyEdit Parachute SQL dump\n"
        ."-- Database: ".$database."\n"
        ."-- Server version: ".$version."\n"
        ."-- Created: ".date('c')."\n\n"
        ."SET NAMES utf8mb4;\n"
        ."SET FOREIGN_KEY_CHECKS = 0;\n"
        ."SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
        ."SET time_zone = '+00:00';\n\n");

    // The core's connection is not dump-friendly: Medoo switches it to
    // sql_mode ANSI_QUOTES (SHOW CREATE TABLE would quote with "..." instead
    // of backticks - not importable in default mode) and SwiftyEdit connects
    // with charset utf8 (= utf8mb3, emoji would come back as "?"). Switch
    // the session for the dump and restore it afterwards.
    $session = $pdo->query('SELECT @@SESSION.sql_mode, @@SESSION.time_zone, @@SESSION.character_set_client, @@SESSION.character_set_results, @@SESSION.collation_connection')->fetch(PDO::FETCH_NUM);
    $pdo->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
    $pdo->exec('SET NAMES utf8mb4');
    $pdo->exec("SET SESSION time_zone = '+00:00'");
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

    try {
        foreach ($tables as $table) {
            $create = $pdo->query('SHOW CREATE TABLE '.pc_sql_ident($table))->fetch(PDO::FETCH_NUM)[1];
            fwrite($fh, "-- Table ".$table."\n"
                ."DROP TABLE IF EXISTS ".pc_sql_ident($table).";\n"
                .$create.";\n\n");

            // unbuffered: rows are streamed instead of loading the whole
            // table into PHP memory
            $pdo->setAttribute($buffered_attr, false);
            try {
                $stmt = $pdo->query('SELECT * FROM '.pc_sql_ident($table));
                $head = null;
                $batch = [];
                $batch_bytes = 0;

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($head === null) {
                        $head = 'INSERT INTO '.pc_sql_ident($table).' ('.implode(', ', array_map('pc_sql_ident', array_keys($row))).") VALUES\n";
                    }
                    $line = '('.implode(', ', array_map('pc_sql_value', $row)).')';
                    $batch[] = $line;
                    $batch_bytes += strlen($line);

                    // keep single statements well below max_allowed_packet
                    if (count($batch) >= 500 || $batch_bytes > 1024 * 1024) {
                        fwrite($fh, $head.implode(",\n", $batch).";\n");
                        $batch = [];
                        $batch_bytes = 0;
                    }
                }
                if ($batch) {
                    fwrite($fh, $head.implode(",\n", $batch).";\n");
                }
                $stmt->closeCursor();
            } finally {
                $pdo->setAttribute($buffered_attr, true);
            }
            fwrite($fh, "\n");
        }
    } finally {
        $pdo->exec('COMMIT');
        $restore = $pdo->prepare('SET SESSION sql_mode = ?, SESSION time_zone = ?, SESSION character_set_client = ?, SESSION character_set_results = ?, SESSION collation_connection = ?');
        $restore->execute([(string) $session[0], (string) $session[1], (string) $session[2], $session[3],(string) $session[4]]);
    }

    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
    if (!fclose($fh)) {
        throw new RuntimeException('Could not write the SQL dump file.');
    }

    return count($tables);
}


/* -------------------------------------------------------------------
 * 3. Creating a backup
 * ---------------------------------------------------------------- */

/** Already compressed - stored as-is in the ZIP, saves a lot of CPU time. */
const PC_STORED_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar',
    'mp3', 'mp4', 'm4a', 'm4v', 'mov', 'avi', 'mpg', 'ogg', 'webm', 'woff', 'woff2', 'pdf', 'docx', 'xlsx', 'pptx', 'epub', 'mmdb'];

function pc_is_sqlite(string $path): bool {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, ['sqlite3', 'sqlite', 'db', 'db3'], true)) {
        return false;
    }
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return false;
    }
    $header = fread($fh, 16);
    fclose($fh);
    return $header === "SQLite format 3\0";
}

/**
 * Consistent copy of a live SQLite database. A plain file copy can catch
 * the database in the middle of a write (or miss data still in the -wal
 * file) and produce a broken copy. VACUUM INTO (SQLite 3.27+) writes a
 * clean, compacted snapshot of the committed state instead.
 */
function pc_snapshot_sqlite(string $src, string $dest): bool {
    try {
        $pdo = new PDO('sqlite:'.$src, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 15,
        ]);
        $pdo->exec('VACUUM INTO '.$pdo->quote($dest));
        $pdo = null;
        return is_file($dest);
    } catch (\Throwable $e) {
        @unlink($dest);
        return false;
    }
}

function pc_zip_entry_options(ZipArchive $zip, string $name, string $password): void {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, PC_STORED_EXT, true)) {
        $zip->setCompressionName($name, ZipArchive::CM_STORE);
    }
    if ($password !== '') {
        $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password);
    }
}

function pc_new_filename(): string {
    $host = strtolower(preg_replace('/[^a-z0-9.-]+/i', '-', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    $host = trim($host, '.-') ?: 'site';
    return 'parachute-'.$host.'-'.date('Y-m-d-His').'-'.bin2hex(random_bytes(3)).'.zip';
}

function pc_restore_text(): string {
    return "SwiftyEdit - Parachute backup\n"
        ."=============================\n\n"
        ."All paths in this archive are relative to the SwiftyEdit installation\n"
        ."root (the folder that contains config.php, data/, plugins/ and public/).\n\n"
        ."  data/database/...      core databases (SQLite)\n"
        ."  data/config*.php       configuration\n"
        ."  plugins/<name>/data/   plugin databases and files\n"
        ."  public/assets/...      uploads and themes\n\n"
        ."MySQL installations: database-dump/<name>.sql is NOT a file to copy\n"
        ."back - import it into the (empty or existing) database, e.g. with\n"
        ."phpMyAdmin > Import or `mysql -u USER -p DBNAME < file.sql`. It drops\n"
        ."and recreates every table it contains.\n\n"
        ."To restore a file, copy it from this archive to the same path in the\n"
        ."installation. Make a fresh backup of the current state first and\n"
        ."restore to a matching SwiftyEdit version (see parachute-manifest.json).\n\n"
        ."parachute-manifest.json lists every file with its SHA-256 checksum.\n"
        ."Encrypted archives (AES-256) can be opened with 7-Zip, Keka, The\n"
        ."Unarchiver or `7z x` - not with the built-in macOS/Windows unzip.\n";
}

/**
 * Create a backup of the given source ids.
 *
 * @return array{ok: bool, message?: string, backup_id?: int, size?: int, file_count?: int, warnings?: string[]}
 */
function pc_create_backup(array $ids, string $password, string $note): array {
    $db = pc_db();
    if ($db === null || !pc_zip_available()) {
        return ['ok' => false, 'message' => pc_lang('msg_zip_missing')];
    }

    pc_ensure_dirs();
    @set_time_limit(0);
    // closing the browser tab must not leave a half-written archive
    ignore_user_abort(true);

    $lock = fopen(PC_DATA.'backup.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['ok' => false, 'message' => pc_lang('msg_busy')];
    }

    $backup_id = null;
    $tmp_dir = null;
    $part = null;
    $final = null;
    $user = pc_user();

    try {
        $catalog = pc_flat_sources();
        $ids = array_values(array_unique(array_filter($ids, fn($id) => is_string($id) && isset($catalog[$id]))));
        if (!$ids) {
            return ['ok' => false, 'message' => pc_lang('msg_nothing_selected')];
        }

        $files = pc_collect_files($ids, $catalog);
        $mysql = in_array('mysql:dump', $ids, true) ? ($catalog['mysql:dump']['mysql'] ?? null) : null;
        if (!$files && $mysql === null) {
            return ['ok' => false, 'message' => pc_lang('msg_no_files')];
        }

        if ($password !== '' && !pc_aes_supported()) {
            return ['ok' => false, 'message' => pc_lang('msg_aes_unsupported')];
        }

        // Worst case the ZIP is as large as the sources, plus the temporary
        // SQLite snapshots - keep a safety margin.
        $total = (int) ($mysql['size'] ?? 0);
        foreach ($files as $abs) {
            $total += (int) @filesize($abs);
        }
        $free = @disk_free_space(PC_BACKUPS);
        if ($free !== false && $free < $total * 1.1 + 20 * 1024 * 1024) {
            return ['ok' => false, 'message' => sprintf(pc_lang('msg_disk_space'), pc_format_bytes($total), pc_format_bytes($free))];
        }

        $started = time();
        $filename = pc_new_filename();
        $db->insert('backups', [
            'created_at' => $started,
            'user_id' => $user['id'],
            'user_nick' => $user['nick'],
            'filename' => $filename,
            'sources' => json_encode($ids),
            'encrypted' => $password !== '' ? 1 : 0,
            'note' => $note,
            'status' => 'running',
            'se_version' => pc_se_version(),
        ]);
        $backup_id = (int) $db->id();

        $tmp_dir = PC_TMP.'run-'.$backup_id.'-'.bin2hex(random_bytes(4)).'/';
        mkdir($tmp_dir, 0755, true);

        // MySQL: dump first, then it is zipped like any other file
        if ($mysql !== null) {
            $dump = $tmp_dir.'dump.sql';
            pc_mysql_dump(pc_mysql_pdo(), $dump);
            $dump_name = preg_replace('/[^a-z0-9_-]+/i', '-', (string) $mysql['database']) ?: 'database';
            $files = ['database-dump/'.$dump_name.'.sql' => $dump] + $files;
        }

        $part = PC_BACKUPS.$filename.'.part';
        $zip = new ZipArchive();
        if ($zip->open($part, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Could not create the ZIP file.');
        }

        $manifest_files = [];
        $warnings = [];
        $n = 0;
        foreach ($files as $rel => $abs) {
            $source = $abs;

            if (pc_is_sqlite($abs)) {
                $snapshot = $tmp_dir.($n++).'.sqlite';
                if (pc_snapshot_sqlite($abs, $snapshot)) {
                    $source = $snapshot;
                } else {
                    // fall back to a plain copy, but say so
                    $warnings[] = $rel;
                    if (@copy($abs, $snapshot)) {
                        $source = $snapshot;
                    }
                }
            }

            if (!$zip->addFile($source, $rel)) {
                throw new RuntimeException('Could not add '.$rel.' to the ZIP file.');
            }
            pc_zip_entry_options($zip, $rel, $password);

            $manifest_files[] = [
                'path' => $rel,
                'size' => (int) filesize($source),
                'sha256' => hash_file('sha256', $source),
            ];
        }

        $addon_info = json_decode((string) file_get_contents(SE_ROOT.'plugins/parachute/info.json'), true);
        $manifest = [
            'generator' => 'SwiftyEdit Parachute '.($addon_info['addon']['version'] ?? ''),
            'created_at' => date('c', $started),
            'site' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
            'swiftyedit' => pc_se_version(),
            'php' => PHP_VERSION,
            'user' => $user['nick'],
            'note' => $note,
            'encrypted' => $password !== '',
            'sources' => $ids,
            'sqlite_plain_copies' => $warnings,
            'files' => $manifest_files,
        ];
        $zip->addFromString('parachute-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        pc_zip_entry_options($zip, 'parachute-manifest.json', $password);
        // left unencrypted on purpose - tells whoever opens the archive
        // what it is and how to open/restore it
        $zip->addFromString('parachute-restore.txt', pc_restore_text());

        $expected_entries = $zip->numFiles;

        if (!$zip->close()) {
            throw new RuntimeException('Could not write the ZIP file: '.$zip->getStatusString());
        }

        $final = PC_BACKUPS.$filename;
        if (!rename($part, $final)) {
            throw new RuntimeException('Could not rename the ZIP file.');
        }
        $part = null;

        // re-open with a consistency check - catches truncated archives
        // (full disk, killed process) before anyone relies on them
        $check = new ZipArchive();
        $result = $check->open($final, ZipArchive::CHECKCONS);
        if ($result !== true || $check->numFiles !== $expected_entries) {
            throw new RuntimeException('The ZIP file failed the consistency check.');
        }
        $check->close();

        $size = (int) filesize($final);
        $db->update('backups', [
            'finished_at' => time(),
            'size' => $size,
            'file_count' => count($files),
            'status' => 'ok',
            'message' => $warnings ? 'Plain copy (no snapshot): '.implode(', ', $warnings) : null,
        ], ['id' => $backup_id]);

        pc_log_event($backup_id, 'create', $filename.' ('.pc_format_bytes($size).')');
        if (function_exists('record_log')) {
            record_log($user['nick'], 'Parachute: backup created ('.$filename.')', '5');
        }

        pc_save_setting('last_selection', json_encode($ids));
        pc_apply_retention();

        return ['ok' => true, 'backup_id' => $backup_id, 'size' => $size, 'file_count' => count($files), 'warnings' => $warnings];

    } catch (\Throwable $e) {
        if ($part !== null && is_file($part)) {
            @unlink($part);
        }
        if ($final !== null && is_file($final)) {
            @unlink($final);
        }
        if ($backup_id !== null) {
            $db->update('backups', [
                'finished_at' => time(),
                'status' => 'error',
                'message' => $e->getMessage(),
            ], ['id' => $backup_id]);
            pc_log_event($backup_id, 'error', $e->getMessage());
        }
        return ['ok' => false, 'message' => $e->getMessage()];

    } finally {
        if ($tmp_dir !== null) {
            pc_rmdir($tmp_dir);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function pc_rmdir(string $dir): void {
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir.'/'.$entry;
        is_dir($path) && !is_link($path) ? pc_rmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

/**
 * Leftovers of a run that was killed (PHP timeout, server restart): only
 * cleaned up while no backup is running, i.e. the lock is free.
 */
function pc_cleanup_stale(): void {
    $db = pc_db();
    if ($db === null || !is_dir(PC_DATA)) {
        return;
    }

    $lock = @fopen(PC_DATA.'backup.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return;
    }

    $db->update('backups', [
        'status' => 'error',
        'message' => 'Aborted (the PHP process was terminated).',
    ], ['status' => 'running']);

    foreach (glob(PC_TMP.'run-*', GLOB_ONLYDIR) ?: [] as $dir) {
        pc_rmdir($dir);
    }
    foreach (glob(PC_BACKUPS.'*.part') ?: [] as $file) {
        @unlink($file);
    }

    flock($lock, LOCK_UN);
    fclose($lock);
}


/* -------------------------------------------------------------------
 * 4. Backup files, retention, audit log
 * ---------------------------------------------------------------- */

function pc_backup_file(array $row): string {
    return PC_BACKUPS.basename((string) $row['filename']);
}

function pc_log_event(?int $backup_id, string $action, string $message = ''): void {
    $db = pc_db();
    if ($db === null) {
        return;
    }
    $user = pc_user();
    $db->insert('events', [
        'ts' => time(),
        'backup_id' => $backup_id,
        'action' => $action,
        'user_id' => $user['id'],
        'user_nick' => $user['nick'],
        'message' => $message,
    ]);
}

/**
 * Delete a backup's ZIP. The database row stays (marked deleted) - the
 * history must survive the file.
 */
function pc_delete_backup(array $row, string $deleted_by, string $action): void {
    $file = pc_backup_file($row);
    if (is_file($file)) {
        @unlink($file);
    }
    pc_db()->update('backups', [
        'deleted_at' => time(),
        'deleted_by' => $deleted_by,
    ], ['id' => (int) $row['id']]);

    pc_log_event((int) $row['id'], $action, (string) $row['filename']);
}

/** Keep the newest N successful backups (setting keep_count, 0 = all). */
function pc_apply_retention(): void {
    $keep = (int) (pc_get_settings()['keep_count'] ?? 5);
    if ($keep <= 0) {
        return;
    }

    $rows = pc_db()->select('backups', ['id', 'filename'], [
        'status' => 'ok',
        'deleted_at' => null,
        'ORDER' => ['created_at' => 'DESC', 'id' => 'DESC'],
    ]);

    foreach (array_slice($rows, $keep) as $row) {
        pc_delete_backup($row, 'retention', 'auto_delete');
    }
}

/** Human-readable labels for stored source ids. */
function pc_source_labels(array $ids): array {
    $catalog = pc_flat_sources();
    $labels = [];
    foreach ($ids as $id) {
        $type = strstr((string) $id, ':', true) ?: '';
        $labels[] = [
            'type' => $type,
            'label' => $catalog[$id]['label'] ?? substr((string) $id, strlen($type) + 1),
        ];
    }
    return $labels;
}
