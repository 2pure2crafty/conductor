<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
require_auth();

$path = conductor_audit_log();
$lines = is_file($path) ? array_slice(array_filter(explode("\n", (string) file_get_contents($path))), -200) : [];

render_header('Audit log');
echo '<a class="back" href="index.php">&larr; Dashboard</a>';
echo '<h1>Audit log</h1>';
if (empty($lines)) {
    echo '<p class="meta">No entries yet (' . h($path) . ').</p>';
} else {
    echo '<p class="meta">Most recent ' . count($lines) . ' entries, newest at the bottom.</p>';
    echo '<pre>' . h(implode("\n", $lines)) . '</pre>';
}
render_footer();
