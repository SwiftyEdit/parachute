<?php
/**
 * Backend bootstrap (admin nav tabs + admin-xhr reader/writer + the
 * download endpoint in global/xhr.php).
 *
 * Sets $pc_access - every entry point MUST check it before doing anything:
 * backups contain the user database (password hashes, personal data) and
 * config files (SMTP password), so the plugin is restricted to
 * administrators with the highest permission level
 * (drm_acp_sensitive_files), not just any administrator.
 */
global $addon_lang, $parachute_db, $pc_access, $pc_settings;
use Medoo\Medoo;

$mod_root = SE_ROOT.'plugins/parachute/';
$pc_db_file = $mod_root.'data/parachute.sqlite3';

// $addon_info is only pre-set when this bootstrap is reached via the admin
// nav-tab router (acp/core/addons/edit-plugin.php).
if(!isset($addon_info)) {
    $addon_info = json_decode(file_get_contents($mod_root.'info.json'), true);
}

require_once __DIR__.'/functions.php';

if(!is_array($addon_lang) && function_exists('se_return_addon_translations')) {
    $addon_lang = se_return_addon_translations('parachute');
}

$pc_access = pc_can_access();

if($pc_access) {
    if(is_file($pc_db_file)) {
        $parachute_db = new Medoo([
            'type' => 'sqlite',
            'database' => $pc_db_file
        ]);
    } elseif(SE_SECTION === 'backend') {
        include __DIR__ . '/../install/installer.php';
    }

    if(isset($parachute_db)) {
        $pc_settings = pc_get_settings();

        if(SE_SECTION === 'backend') {
            if (version_compare($pc_settings['version'] ?? '0', $addon_info['addon']['version'], '<')) {
                include __DIR__ . '/../install/updater.php';
                $pc_settings = pc_get_settings();
            }
        }
    }
}
