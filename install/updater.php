<?php
/**
 * @var \Medoo\Medoo $parachute_db from bootstrap.php
 * @var array $addon_info
 */
include_once __DIR__.'/schema.php';

echo '<p class="alert alert-info">We try to update version: '.$addon_info['addon']['version'].'</p>';

// 1. Update/Create all tables with current schema
foreach (ParachuteSchema::getTables() as $table_name => $columns) {
    pc_updateOrCreateTable($table_name, $columns);
}

// 2. Backfill settings introduced after the initial install, without
// touching settings the admin already changed.
foreach (pc_get_default_settings() as $key => $value) {
    if (!$parachute_db->has('settings', ['key' => $key])) {
        $parachute_db->insert('settings', [
            'key' => $key,
            'value' => (string) $value,
        ]);
    }
}

// 3. Protection files for data/ (in case they went missing)
pc_ensure_dirs();

// 4. Update version
pc_save_setting('version', $addon_info['addon']['version']);

echo '<p class="alert alert-info">Updated to version: '.$addon_info['addon']['version'].'</p>';
