<?php
/**
 * @var string $pc_db_file from bootstrap.php
 * @var array $addon_info
 */
use Medoo\Medoo;
include_once __DIR__.'/schema.php';


/* INSTALL */

if(!is_file("$pc_db_file")) {

    pc_ensure_dirs();

    $parachute_db = new Medoo([
        'type' => 'sqlite',
        'database' => $pc_db_file
    ]);

    foreach (ParachuteSchema::getTables() as $table_name => $columns) {
        $col_definitions = [];
        foreach ($columns as $col_name => $col_type) {
            $col_definitions[] = "$col_name $col_type";
        }

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (" .
            implode(', ', $col_definitions) . ")";

        $parachute_db->query($sql)->execute();
    }

    $parachute_db->query("CREATE INDEX IF NOT EXISTS idx_backups_created ON backups (created_at)")->execute();
    $parachute_db->query("CREATE INDEX IF NOT EXISTS idx_events_ts ON events (ts)")->execute();

    $parachute_db->insert('settings', [
        'key' => 'version',
        'value' => $addon_info['addon']['version']
    ]);

    foreach (pc_get_default_settings() as $key => $value) {
        if (!$parachute_db->has('settings', ['key' => $key])) {
            $parachute_db->insert('settings', [
                'key' => $key,
                'value' => (string) $value,
            ]);
        }
    }

    echo '<p class="alert alert-info">Generated SQLite File: '.basename($pc_db_file).'</p>';
}
