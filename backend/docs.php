<?php
// Same docs-tab pattern as plugins/deepl/backend/docs.php: sidebar
// (docs_nav) + content pane (docs_content), both hx-get'd from
// backend/reader.php against plugins/parachute/docs/<lang>/*.md.
// Deliberately not permission-gated - the docs contain nothing sensitive.

echo '<div class="row">';
echo '<div class="col-md-3">';

echo '<div hx-get="/admin-xhr/addons/plugin/parachute/read/?show=docs_nav" hx-trigger="load">Loading data ...</div>';

echo '</div>';
echo '<div class="col-md-9">';

echo '<div id="docsContent" hx-get="/admin-xhr/addons/plugin/parachute/read/?show=docs_content" hx-trigger="load">Loading data ...</div>';

echo '</div>';
echo '</div>';
