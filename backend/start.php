<?php

require __DIR__.'/../global/bootstrap.php';

if (!$pc_access) {
    echo '<div class="alert alert-danger">'.$addon_lang['msg_no_permission'].'</div>';
    return;
}

echo '<h1>'.$addon_lang['title_overview'].'</h1>';

echo '<div hx-get="/admin-xhr/addons/plugin/parachute/read/?show=status" hx-trigger="load, pc_backups_changed from:body"></div>';

echo '<div class="row">';

echo '<div class="col-lg-5">';
echo '<div class="card mb-3">';
echo '<div class="card-header">'.$addon_lang['title_create'].'</div>';
echo '<div class="card-body" hx-get="/admin-xhr/addons/plugin/parachute/read/?show=create_form" hx-trigger="load">LOADING ...</div>';
echo '</div>';
echo '</div>';

echo '<div class="col-lg-7">';
echo '<div class="card mb-3">';
echo '<div class="card-header">'.$addon_lang['title_backups'].'</div>';
echo '<div class="card-body" id="pcBackups" hx-get="/admin-xhr/addons/plugin/parachute/read/?show=backups" hx-trigger="load, pc_backups_changed from:body">LOADING ...</div>';
echo '</div>';

echo '<div class="card mb-3">';
echo '<div class="card-header">'.$addon_lang['title_events'].'</div>';
echo '<div class="card-body" hx-get="/admin-xhr/addons/plugin/parachute/read/?show=events" hx-trigger="load, pc_backups_changed from:body">LOADING ...</div>';
echo '</div>';
echo '</div>';

echo '</div>';
