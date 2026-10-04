<?php
/**
 * Routed via /xhr/plugins/parachute/ (see app/handlers/xhr-routes.php).
 *
 * Only the ZIP download lives here: a file download needs a clean,
 * non-HTMX response, which /admin-xhr/ can't give (it rejects plain,
 * non-XHR requests). Same reasoning as former's download_file and
 * paperboy's export branch - but with the plugin's own, stricter
 * permission check instead of "any administrator".
 */

require_once __DIR__.'/bootstrap.php';

if (empty($pc_access) || pc_db() === null) {
    http_response_code(403);
    exit;
}

if (isset($_GET['download'])) {
    $row = pc_db()->get('backups', '*', ['id' => (int) $_GET['download']]);

    if (!$row || $row['status'] !== 'ok' || !empty($row['deleted_at'])) {
        http_response_code(404);
        exit;
    }

    $file = pc_backup_file($row);
    if (!is_file($file)) {
        http_response_code(404);
        exit;
    }

    pc_log_event((int) $row['id'], 'download', (string) $row['filename']);
    if (function_exists('record_log')) {
        record_log(pc_user()['nick'], 'Parachute: backup downloaded ('.$row['filename'].')', '5');
    }

    @set_time_limit(0);
    // anything buffered so far (frontend bootstrap) would corrupt the ZIP
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.basename($file).'"');
    header('Content-Length: '.filesize($file));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

http_response_code(404);
exit;
