<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
require_auth();

$projectSlug = $_GET['project'] ?? '';
$agentSlug = $_GET['agent'] ?? '';

$registry = load_registry();
$project = $registry['projects'][$projectSlug] ?? null;
if ($project === null) error_page('No such project.');
$agent = $project['agents'][$agentSlug] ?? null;
if ($agent === null) error_page('No such agent.', 'project.php?slug=' . $projectSlug);

$tail = agent_pane_tail($agent['tmux'], 24);

render_header('Peek: ' . $agent['label']);
echo '<a class="back" href="project.php?slug=' . h($projectSlug) . '">&larr; Back to project</a>';
echo '<h1>' . h($agent['label']) . ' &middot; ' . status_badge(agent_status($agent['tmux'])) . '</h1>';
if ($tail === '') {
    echo '<p>Session is not running.</p>';
} else {
    echo '<p class="meta">Last 24 lines (read-only). Reload to refresh.</p>';
    echo '<pre>' . h($tail) . '</pre>';
    echo '<a class="btn" href="peek.php?project=' . h($projectSlug) . '&amp;agent=' . h($agentSlug) . '">Refresh</a>';
}
render_footer();
