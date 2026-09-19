<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
require_auth();

$slug = $_GET['slug'] ?? '';
$registry = load_registry();
$project = $registry['projects'][$slug] ?? null;

if ($project === null) {
    http_response_code(404);
    render_header('Not found');
    echo '<p>No such project.</p><a class="back" href="index.php">&larr; Dashboard</a>';
    render_footer();
    exit;
}

$running = tmux_running_sessions();

render_header($project['label']);
echo '<a class="back" href="index.php">&larr; Dashboard</a>';
echo '<h1>' . h($project['label']) . '</h1>';
echo '<p>' . nl2br(h($project['description'])) . '</p>';
if (!empty($project['repo'])) {
    echo '<p><a href="' . h($project['repo']) . '">' . h($project['repo']) . '</a></p>';
}

echo '<h2>Agents</h2>';
foreach ($project['agents'] as $agentSlug => $agent) {
    $isLive = in_array($agent['tmux'], $running, true);
    echo '<div class="card"><strong>' . h($agent['label']) . '</strong>'
        . '<span class="status ' . ($isLive ? 'live' : 'stopped') . '">' . ($isLive ? 'live' : 'stopped') . '</span>';
    echo '<div class="desc">tmux: ' . h($agent['tmux']) . ' &middot; model: ' . h($agent['model']) . '</div>';
    if ($isLive) {
        echo '<form method="post" action="wrapdown.php" onsubmit="return confirm(\'Send /wrap-up and stop this session?\');">'
            . '<input type="hidden" name="project" value="' . h($slug) . '">'
            . '<input type="hidden" name="agent" value="' . h($agentSlug) . '">'
            . '<button class="btn stop" type="submit">Wrap up &amp; stop</button></form>';
    }
    echo '</div>';
}

echo '<a class="btn" href="spawn-form.php?project=' . h($slug) . '">Spin up agent</a>';
render_footer();
