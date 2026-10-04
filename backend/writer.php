<?php

require __DIR__.'/../global/bootstrap.php';

// acp/core/access.php has already checked "administrator" and the CSRF
// token - this adds the plugin's own, stricter permission.
if (!$pc_access || pc_db() === null) {
    http_response_code(403);
    echo '<div class="alert alert-danger">'.$addon_lang['msg_no_permission'].'</div>';
    exit;
}

/* ---------------------------------------------------------------
 * Create a backup
 * -------------------------------------------------------------- */
if (isset($_POST['create_backup'])) {

    $sources = array_map('strval', (array) ($_POST['sources'] ?? []));
    $password = (string) ($_POST['password'] ?? '');
    $note = mb_substr(trim(strip_tags((string) ($_POST['note'] ?? ''))), 0, 200);

    if ($password !== '') {
        if ($password !== (string) ($_POST['password_repeat'] ?? '')) {
            echo '<div class="alert alert-danger mb-0">'.$addon_lang['msg_password_mismatch'].'</div>';
            exit;
        }
        if (mb_strlen($password) < 8) {
            echo '<div class="alert alert-danger mb-0">'.$addon_lang['msg_password_short'].'</div>';
            exit;
        }
    }

    $result = pc_create_backup($sources, $password, $note);

    if (!$result['ok']) {
        header('HX-Trigger: pc_backups_changed');
        echo '<div class="alert alert-danger mb-0">'.$addon_lang['msg_create_failed'].'<br><small>'.htmlspecialchars((string) ($result['message'] ?? '')).'</small></div>';
        exit;
    }

    header('HX-Trigger: pc_backups_changed');

    echo '<div class="alert alert-success mb-0">';
    echo sprintf($addon_lang['msg_created'], pc_format_bytes((int) $result['size']), number_format((int) $result['file_count'], 0, ',', '.'));
    echo '<div class="mt-2"><a class="btn btn-sm btn-success" href="/xhr/plugins/parachute/?download='.(int) $result['backup_id'].'"><i class="bi bi-download"></i> '.$addon_lang['btn_download'].'</a></div>';
    if (!empty($result['warnings'])) {
        echo '<div class="small text-warning-emphasis mt-2">'.$addon_lang['msg_plain_copy'].'<br>'.htmlspecialchars(implode(', ', $result['warnings'])).'</div>';
    }
    if ($password !== '') {
        echo '<div class="small mt-2"><i class="bi bi-lock-fill"></i> '.$addon_lang['msg_password_reminder'].'</div>';
    }
    echo '</div>';
    exit;
}

/* ---------------------------------------------------------------
 * Delete a backup (the history row stays)
 * -------------------------------------------------------------- */
if (isset($_POST['delete_backup'])) {

    $row = pc_db()->get('backups', '*', ['id' => (int) $_POST['delete_backup'], 'deleted_at' => null]);

    if ($row && $row['status'] !== 'running') {
        pc_delete_backup($row, pc_user()['nick'], 'delete');
        if (function_exists('record_log')) {
            record_log(pc_user()['nick'], 'Parachute: backup deleted ('.$row['filename'].')', '5');
        }
    }

    header('HX-Trigger: pc_backups_changed');
    exit;
}

/* ---------------------------------------------------------------
 * Settings
 * -------------------------------------------------------------- */
if (isset($_POST['save_settings'])) {

    $keep = max(0, min(100, (int) ($_POST['keep_count'] ?? 5)));
    pc_save_setting('keep_count', $keep);

    echo '<div class="alert alert-success">'.$addon_lang['msg_saved'].'</div>';
    exit;
}

exit;
