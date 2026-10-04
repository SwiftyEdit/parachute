<?php

require __DIR__.'/../global/bootstrap.php';

$show = (string) ($_GET['show'] ?? '');

/* ---------------------------------------------------------------
 * Docs - readable without the backup permission, nothing sensitive
 * -------------------------------------------------------------- */
if ($show === 'docs_nav') {

    // se_parse_docs_file() needs a global $Parsedown instance
    $Parsedown = new Parsedown();

    $docs_root = SE_ROOT.'plugins/parachute/docs/en';
    if (is_dir(SE_ROOT.'plugins/parachute/docs/'.basename((string) $languagePack))) {
        $docs_root = SE_ROOT.'plugins/parachute/docs/'.basename((string) $languagePack);
    }

    $current_file = basename($_GET['file'] ?? 'index.md');
    $parsed_files = [];

    foreach (glob($docs_root.'/*.md') as $doc) {
        $parsed_file = se_parse_docs_file($doc);
        $parsed_files[] = [
            'title' => $parsed_file['header']['title'],
            'priority' => $parsed_file['header']['priority'],
            'btn' => $parsed_file['header']['btn'],
            'file' => $doc,
        ];
    }

    $sorted_parsed_files = se_array_multisort($parsed_files, 'priority', SORT_ASC);

    $list = '<div class="card mb-3">';
    $list .= '<div class="list-group list-group-flush">';
    foreach ($sorted_parsed_files as $v) {
        $active = basename($v['file']) === $current_file ? ' active' : '';
        $hx_get = '/admin-xhr/addons/plugin/parachute/read/?show=docs_content&file='.basename($v['file']);
        $list .= '<button class="list-group-item list-group-item-action'.$active.'" hx-get="'.$hx_get.'" hx-target="#docsContent"
            hx-on:click="this.closest(\'.list-group\').querySelectorAll(\'.active\').forEach(function(el){ el.classList.remove(\'active\'); }); this.classList.add(\'active\')">';
        $list .= $v['btn'];
        $list .= '</button>';
    }
    $list .= '</div>';
    $list .= '</div>';
    echo $list;
    exit;
}

if ($show === 'docs_content') {

    $Parsedown = new Parsedown();

    $df = basename($_GET['file'] ?? 'index.md');

    $doc_file = SE_ROOT.'plugins/parachute/docs/'.basename((string) $languagePack).'/'.$df;
    if (!is_file($doc_file)) {
        $doc_file = SE_ROOT.'plugins/parachute/docs/en/'.$df;
    }

    if (is_file($doc_file)) {
        $parsed_file = se_parse_docs_file($doc_file);
        echo $parsed_file['content'];
    }
    exit;
}

/* ---------------------------------------------------------------
 * Everything below: administrators with drm_acp_sensitive_files only
 * -------------------------------------------------------------- */
if (!$pc_access || pc_db() === null) {
    http_response_code(403);
    echo '<div class="alert alert-danger mb-0">'.$addon_lang['msg_no_permission'].'</div>';
    exit;
}

$pc_date = fn(?int $ts) => $ts ? date($addon_lang['date_format'] ?? 'Y-m-d H:i', $ts) : '-';
$pc_csrf = htmlspecialchars($_SESSION['token'] ?? '', ENT_QUOTES);

/* ---------------------------------------------------------------
 * Status line on top of the overview
 * -------------------------------------------------------------- */
if ($show === 'status') {

    $last = pc_db()->get('backups', ['created_at'], [
        'status' => 'ok',
        'ORDER' => ['created_at' => 'DESC'],
    ]);

    if (!$last) {
        echo '<div class="alert alert-warning">'.$addon_lang['msg_no_backup_yet'].'</div>';
        exit;
    }

    $days = (int) floor((time() - (int) $last['created_at']) / 86400);
    $text = sprintf($addon_lang['text_last_backup'], $pc_date((int) $last['created_at']), $days);

    echo '<div class="alert '.($days > 30 ? 'alert-warning' : 'alert-light').'">'.$text;
    if ($days > 30) {
        echo ' '.$addon_lang['msg_backup_old'];
    }
    echo '</div>';
    exit;
}

/* ---------------------------------------------------------------
 * Form: new backup
 * -------------------------------------------------------------- */
if ($show === 'create_form') {

    if (!pc_zip_available()) {
        echo '<div class="alert alert-danger mb-0">'.$addon_lang['msg_zip_missing'].'</div>';
        exit;
    }

    $groups = pc_sources();
    $selected = pc_selected_ids(pc_flat_sources());
    $aes = pc_aes_supported();

    echo '<form hx-post="/admin-xhr/addons/plugin/parachute/write/" hx-target="#pcCreateResult" hx-swap="innerHTML" hx-disabled-elt="#pcCreateBtn" hx-indicator="#pcCreateSpinner">';
    echo '<input type="hidden" name="csrf_token" value="'.$pc_csrf.'">';
    echo '<input type="hidden" name="create_backup" value="1">';

    foreach ($groups as $group_key => $group) {
        $group_total = 0;
        $rows = '';
        foreach ($group['items'] as $id => $item) {
            $stats = !empty($item['mysql'])
                ? ['size' => (int) $item['mysql']['size'], 'count' => null, 'tables' => (int) $item['mysql']['tables']]
                : pc_paths_size($item['paths']);
            $group_total += $stats['size'];
            $field_id = 'pc_'.preg_replace('/[^a-z0-9]+/i', '_', $id);
            $checked = in_array($id, $selected, true) ? ' checked' : '';

            $rows .= '<div class="form-check">';
            $rows .= '<input class="form-check-input pc-src-'.$group_key.'" type="checkbox" name="sources[]" value="'.htmlspecialchars($id, ENT_QUOTES).'" id="'.$field_id.'"'.$checked.'>';
            $rows .= '<label class="form-check-label" for="'.$field_id.'">'.htmlspecialchars($item['label']);
            $rows .= ' <small class="text-muted">'.(isset($stats['tables'])
                ? '~'.pc_format_bytes($stats['size']).' · '.sprintf($addon_lang['label_tables'], $stats['tables'])
                : pc_format_bytes($stats['size']).' · '.sprintf($addon_lang['label_files'], number_format($stats['count'], 0, ',', '.'))).'</small>';
            if (!empty($item['hint'])) {
                $rows .= '<br><small class="text-muted">'.htmlspecialchars($item['hint']).'</small>';
            }
            $rows .= '</label>';
            $rows .= '</div>';
        }

        echo '<fieldset class="mb-3">';
        echo '<div class="d-flex align-items-center border-bottom mb-2 pb-1">';
        echo '<strong>'.htmlspecialchars($group['label']).'</strong>';
        echo '<small class="text-muted ms-2">'.pc_format_bytes($group_total).'</small>';
        echo '<button type="button" class="btn btn-sm btn-link ms-auto py-0" hx-on:click="var boxes=this.closest(\'fieldset\').querySelectorAll(\'input[type=checkbox]\'); var all=Array.prototype.every.call(boxes,function(b){return b.checked}); boxes.forEach(function(b){b.checked=!all})">'.$addon_lang['btn_toggle_all'].'</button>';
        echo '</div>';
        if (!empty($group['hint'])) {
            echo '<p class="small text-muted mb-1">'.htmlspecialchars($group['hint']).'</p>';
        }
        echo $rows;
        echo '</fieldset>';
    }

    echo '<fieldset class="mb-3">';
    echo '<div class="border-bottom mb-2 pb-1"><strong>'.$addon_lang['label_encryption'].'</strong></div>';
    if ($aes) {
        echo '<div class="row g-2">';
        echo '<div class="col-sm-6"><input type="password" class="form-control" name="password" autocomplete="new-password" minlength="8" placeholder="'.$addon_lang['placeholder_password'].'"></div>';
        echo '<div class="col-sm-6"><input type="password" class="form-control" name="password_repeat" autocomplete="new-password" placeholder="'.$addon_lang['placeholder_password_repeat'].'"></div>';
        echo '</div>';
        echo '<div class="form-text">'.$addon_lang['hint_password'].'</div>';
    } else {
        echo '<div class="alert alert-info mb-0">'.$addon_lang['msg_aes_unsupported'].'</div>';
    }
    echo '</fieldset>';

    echo '<div class="mb-3">';
    echo '<label class="form-label" for="pcNote">'.$addon_lang['label_note'].'</label>';
    echo '<input type="text" class="form-control" id="pcNote" name="note" maxlength="200" placeholder="'.$addon_lang['placeholder_note'].'">';
    echo '</div>';

    echo '<div class="d-flex align-items-center gap-2">';
    echo '<button type="submit" id="pcCreateBtn" class="btn btn-success"><i class="bi bi-file-earmark-zip"></i> '.$addon_lang['btn_create'].'</button>';
    echo '<span id="pcCreateSpinner" class="htmx-indicator small text-muted"><span class="spinner-border spinner-border-sm"></span> '.$addon_lang['text_running'].'</span>';
    echo '</div>';

    echo '</form>';
    echo '<div id="pcCreateResult" class="mt-3"></div>';
    exit;
}

/* ---------------------------------------------------------------
 * List of backups on the server
 * -------------------------------------------------------------- */
if ($show === 'backups') {

    pc_cleanup_stale();

    $rows = pc_db()->select('backups', '*', [
        'deleted_at' => null,
        'ORDER' => ['created_at' => 'DESC', 'id' => 'DESC'],
    ]);

    if (!$rows) {
        echo '<p class="text-muted mb-0">'.$addon_lang['msg_list_empty'].'</p>';
        exit;
    }

    $type_icons = ['db' => 'bi-database', 'mysql' => 'bi-database', 'plugin' => 'bi-plug', 'upload' => 'bi-images', 'config' => 'bi-gear', 'theme' => 'bi-palette'];
    $used = 0;

    echo '<div class="table-responsive">';
    echo '<table class="table table-sm align-middle mb-0">';
    echo '<thead><tr>';
    echo '<th>'.$addon_lang['th_date'].'</th>';
    echo '<th>'.$addon_lang['th_contents'].'</th>';
    echo '<th class="text-end">'.$addon_lang['th_size'].'</th>';
    echo '<th></th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $file_exists = is_file(pc_backup_file($row));
        if ($row['status'] === 'ok' && $file_exists) {
            $used += (int) $row['size'];
        }

        // contents: one badge per source type, full list in the tooltip
        $by_type = [];
        foreach (pc_source_labels(json_decode((string) $row['sources'], true) ?: []) as $src) {
            $by_type[$src['type']][] = $src['label'];
        }
        $badges = '';
        foreach ($by_type as $type => $labels) {
            $icon = $type_icons[$type] ?? 'bi-box';
            $badges .= '<span class="badge text-bg-light border me-1" title="'.htmlspecialchars(implode(', ', $labels), ENT_QUOTES).'"><i class="bi '.$icon.'"></i> '.count($labels).'</span>';
        }

        echo '<tr>';
        echo '<td class="text-nowrap">'.$pc_date((int) $row['created_at']);
        echo '<br><small class="text-muted">'.htmlspecialchars((string) $row['user_nick']).'</small></td>';

        echo '<td>'.$badges;
        if ((int) $row['encrypted'] === 1) {
            echo '<span class="badge text-bg-dark" title="'.$addon_lang['label_encrypted'].'"><i class="bi bi-lock-fill"></i></span>';
        }
        if ($row['note'] !== null && $row['note'] !== '') {
            echo '<br><small>'.htmlspecialchars((string) $row['note']).'</small>';
        }
        if ($row['status'] === 'error') {
            echo '<br><span class="badge text-bg-danger">'.$addon_lang['status_error'].'</span> <small class="text-danger">'.htmlspecialchars((string) $row['message']).'</small>';
        } elseif ($row['status'] === 'running') {
            echo '<br><span class="badge text-bg-info">'.$addon_lang['status_running'].'</span>';
        } elseif (!$file_exists) {
            echo '<br><span class="badge text-bg-warning">'.$addon_lang['status_file_missing'].'</span>';
        } elseif (!empty($row['message'])) {
            echo '<br><small class="text-warning" title="'.htmlspecialchars((string) $row['message'], ENT_QUOTES).'"><i class="bi bi-exclamation-triangle"></i> '.$addon_lang['label_plain_copy'].'</small>';
        }
        echo '</td>';

        echo '<td class="text-end text-nowrap">';
        if ($row['status'] === 'ok') {
            echo pc_format_bytes((int) $row['size']).'<br><small class="text-muted">'.sprintf($addon_lang['label_files'], number_format((int) $row['file_count'], 0, ',', '.')).'</small>';
        }
        echo '</td>';

        echo '<td class="text-end text-nowrap">';
        if ($row['status'] === 'ok' && $file_exists) {
            echo '<a class="btn btn-sm btn-default" href="/xhr/plugins/parachute/?download='.(int) $row['id'].'" title="'.$addon_lang['btn_download'].'" hx-on:click="setTimeout(function(){ htmx.trigger(document.body, \'pc_backups_changed\') }, 1500)"><i class="bi bi-download"></i></a> ';
        }
        if ($row['status'] !== 'running') {
            $vals = htmlspecialchars(json_encode(['csrf_token' => $_SESSION['token'] ?? '', 'delete_backup' => (int) $row['id']]), ENT_QUOTES);
            echo '<button type="button" class="btn btn-sm btn-default text-danger" title="'.$addon_lang['btn_delete'].'"'
                .' hx-post="/admin-xhr/addons/plugin/parachute/write/" hx-vals="'.$vals.'" hx-swap="none"'
                .' hx-confirm="'.htmlspecialchars($addon_lang['confirm_delete'], ENT_QUOTES).'"><i class="bi bi-trash"></i></button>';
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';

    $keep = (int) ($pc_settings['keep_count'] ?? 5);
    echo '<p class="small text-muted mt-2 mb-0">'.sprintf($addon_lang['text_storage_used'], pc_format_bytes($used));
    echo ' · '.($keep > 0 ? sprintf($addon_lang['text_keep_count'], $keep) : $addon_lang['text_keep_all']).'</p>';
    exit;
}

/* ---------------------------------------------------------------
 * Audit log
 * -------------------------------------------------------------- */
if ($show === 'events') {

    $events = pc_db()->select('events', '*', [
        'ORDER' => ['ts' => 'DESC', 'id' => 'DESC'],
        'LIMIT' => 50,
    ]);

    if (!$events) {
        echo '<p class="text-muted mb-0">'.$addon_lang['msg_events_empty'].'</p>';
        exit;
    }

    $action_class = ['create' => 'text-bg-success', 'download' => 'text-bg-primary', 'delete' => 'text-bg-secondary', 'auto_delete' => 'text-bg-light border', 'error' => 'text-bg-danger'];

    echo '<div class="table-responsive" style="max-height:400px;overflow-y:auto">';
    echo '<table class="table table-sm mb-0">';
    echo '<thead><tr><th>'.$addon_lang['th_date'].'</th><th>'.$addon_lang['th_action'].'</th><th>'.$addon_lang['th_user'].'</th><th>'.$addon_lang['th_details'].'</th></tr></thead><tbody>';
    foreach ($events as $ev) {
        $action = (string) $ev['action'];
        echo '<tr>';
        echo '<td class="text-nowrap">'.$pc_date((int) $ev['ts']).'</td>';
        echo '<td><span class="badge '.($action_class[$action] ?? 'text-bg-light').'">'.htmlspecialchars($addon_lang['action_'.$action] ?? $action).'</span></td>';
        echo '<td>'.($action === 'auto_delete' ? '<em>'.$addon_lang['label_retention'].'</em>' : htmlspecialchars((string) $ev['user_nick'])).'</td>';
        echo '<td class="small text-break">'.htmlspecialchars((string) $ev['message']).'</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
    exit;
}

/* ---------------------------------------------------------------
 * Settings
 * -------------------------------------------------------------- */
if ($show === 'settings_form') {

    echo '<div id="pcSettingsResponse"></div>';
    echo '<form hx-post="/admin-xhr/addons/plugin/parachute/write/" hx-target="#pcSettingsResponse" hx-swap="innerHTML">';
    echo '<input type="hidden" name="csrf_token" value="'.$pc_csrf.'">';
    echo '<input type="hidden" name="save_settings" value="1">';

    echo '<div class="mb-3">';
    echo '<label class="form-label" for="pcKeepCount">'.$addon_lang['label_keep_count'].'</label>';
    echo '<input type="number" class="form-control" id="pcKeepCount" name="keep_count" min="0" max="100" value="'.(int) ($pc_settings['keep_count'] ?? 5).'" style="max-width:120px">';
    echo '<div class="form-text">'.$addon_lang['hint_keep_count'].'</div>';
    echo '</div>';

    echo '<button type="submit" class="btn btn-success">'.$addon_lang['btn_save'].'</button>';
    echo '</form>';
    exit;
}

/* ---------------------------------------------------------------
 * System check
 * -------------------------------------------------------------- */
if ($show === 'system') {

    $sqlite_version = '';
    try {
        $sqlite_version = (string) (new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn();
    } catch (\Throwable $e) {
    }

    $free = @disk_free_space(PC_BACKUPS);
    $max_exec = (int) ini_get('max_execution_time');

    $yes = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> '.$addon_lang['label_yes'].'</span>';
    $no = '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> '.$addon_lang['label_no'].'</span>';

    $checks = [
        $addon_lang['sys_db_type'] => pc_mysql_pdo() !== null
            ? 'MySQL '.htmlspecialchars((string) pc_mysql_pdo()->query('SELECT VERSION()')->fetchColumn())
            : 'SQLite',
        $addon_lang['sys_zip'] => pc_zip_available() ? $yes : $no,
        $addon_lang['sys_aes'] => pc_aes_supported() ? $yes : $no,
        $addon_lang['sys_sqlite'] => htmlspecialchars($sqlite_version ?: '-')
            .(version_compare($sqlite_version ?: '0', '3.27.0', '>=') ? '' : ' <small class="text-danger">'.$addon_lang['sys_sqlite_old'].'</small>'),
        $addon_lang['sys_max_exec'] => ($max_exec === 0 ? '∞' : $max_exec.' s')
            .(function_exists('set_time_limit') ? '' : ' <small class="text-warning">'.$addon_lang['sys_time_limit_disabled'].'</small>'),
        $addon_lang['sys_free_space'] => $free !== false ? pc_format_bytes($free) : '-',
        $addon_lang['sys_storage'] => '<code>'.htmlspecialchars(pc_rel_path(PC_BACKUPS)).'</code>',
        $addon_lang['sys_se_version'] => htmlspecialchars(pc_se_version() ?: '-'),
    ];

    echo '<table class="table table-sm mb-2">';
    foreach ($checks as $label => $value) {
        echo '<tr><th class="fw-normal text-muted">'.$label.'</th><td>'.$value.'</td></tr>';
    }
    echo '</table>';
    echo '<p class="small text-muted mb-0">'.$addon_lang['hint_system'].'</p>';
    exit;
}

exit;
