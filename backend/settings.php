<?php

require __DIR__.'/../global/bootstrap.php';

if (!$pc_access) {
    echo '<div class="alert alert-danger">'.$addon_lang['msg_no_permission'].'</div>';
    return;
}

echo '<h1>'.$addon_lang['title_settings'].'</h1>';

echo '<div class="row">';

echo '<div class="col-md-6">';
echo '<div class="card mb-3">';
echo '<div class="card-header">'.$addon_lang['title_settings'].'</div>';
echo '<div class="card-body" hx-get="/admin-xhr/addons/plugin/parachute/read/?show=settings_form" hx-trigger="load">LOADING ...</div>';
echo '</div>';
echo '</div>';

echo '<div class="col-md-6">';
echo '<div class="card mb-3">';
echo '<div class="card-header">'.$addon_lang['title_system'].'</div>';
echo '<div class="card-body" hx-get="/admin-xhr/addons/plugin/parachute/read/?show=system" hx-trigger="load">LOADING ...</div>';
echo '</div>';
echo '</div>';

echo '</div>';
