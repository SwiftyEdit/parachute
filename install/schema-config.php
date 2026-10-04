<?php
// Pure table schema definitions - edit here to add/modify columns.
// install/updater.php adds missing tables/columns on every version bump.

return [
    'settings' => [
        'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'key' => 'TEXT NOT NULL UNIQUE',
        'value' => 'TEXT',
    ],

    // One row per backup run - the row stays after the ZIP is deleted
    // (deleted_at/deleted_by), so the history of what was saved, when and
    // by whom is never lost.
    'backups' => [
        'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'created_at' => 'INTEGER NOT NULL',
        'finished_at' => 'INTEGER NULL',
        'user_id' => 'INTEGER NULL',
        'user_nick' => 'VARCHAR(100) NULL',
        'filename' => 'VARCHAR(255) NOT NULL',
        'size' => 'INTEGER DEFAULT 0',
        'file_count' => 'INTEGER DEFAULT 0',
        'sources' => 'TEXT NULL',                 // JSON list of source ids (see pc_sources())
        'encrypted' => 'INTEGER DEFAULT 0',
        'note' => 'TEXT NULL',
        'status' => 'VARCHAR(10) NOT NULL',       // running | ok | error
        'message' => 'TEXT NULL',
        'se_version' => 'VARCHAR(40) NULL',
        'deleted_at' => 'INTEGER NULL',
        'deleted_by' => 'VARCHAR(100) NULL',      // user nick or "retention"
    ],

    // Audit trail - every create, download and delete, by whom.
    'events' => [
        'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'ts' => 'INTEGER NOT NULL',
        'backup_id' => 'INTEGER NULL',
        'action' => 'VARCHAR(20) NOT NULL',       // create | download | delete | auto_delete | error
        'user_id' => 'INTEGER NULL',
        'user_nick' => 'VARCHAR(100) NULL',
        'message' => 'TEXT NULL',
    ],
];
