<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
require_auth();

$registry = load_registry();
$running = tmux_running_sessions();

render_header('Dashboard');
echo '<h1>Projects</h1>';

foreach ($registry['projects'] as $slug => $project) {
    $agentCount = count($project['agents']);
    $liveCount = 0;
    foreach ($project['agents'] as $agent) {
        if (in_array($agent['tmux'], $running, true)) $liveCount++;
    }
    echo '<div class="card"><a href="project.php?slug=' . h($slug) . '">'
        . '<strong>' . h($project['label']) . '</strong>';
    if ($liveCount > 0) {
        echo '<span class="status live">' . $liveCount . ' live</span>';
    }
    echo '<div class="desc">' . h(mb_strimwidth($project['description'], 0, 140, '...')) . '</div>'
        . '<div class="desc">' . $agentCount . ' agent' . ($agentCount === 1 ? '' : 's') . '</div>'
        . '</a></div>';
}

echo '<a class="btn" href="spawn-form.php">+ New project</a>';
render_footer();
