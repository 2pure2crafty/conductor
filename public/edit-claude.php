<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
require_auth();

$slug = $_REQUEST['project'] ?? '';
$agentSlug = $_REQUEST['agent'] ?? '';
$registry = load_registry();
$project = $registry['projects'][$slug] ?? null;
if ($project === null) error_page('No such project.');
$agent = $project['agents'][$agentSlug] ?? null;
if ($agent === null) error_page('No such agent.', 'manage.php?slug=' . $slug);

$agentDir = agent_dir($project, $agent);
$claudePath = $agentDir . '/CLAUDE.md';
$back = 'manage.php?slug=' . rawurlencode($slug);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (tmux_session_exists($agent['tmux'])) {
        error_page('Stop the agent before editing its CLAUDE.md (avoid editing under a running session).', $back);
    }
    if (!path_is_within($claudePath, $agentDir)) error_page('Path check failed.');
    file_put_contents($claudePath, $_POST['content'] ?? '');
    audit_log('edit_claude', "$slug/$agentSlug");
    header('Location: ' . $back);
    exit;
}

$current = is_file($claudePath) ? (string) file_get_contents($claudePath) : '';

render_header('Edit CLAUDE.md');
echo '<a class="back" href="' . h($back) . '">&larr; Back to manage</a>';
echo '<h1>' . h($agent['label']) . ' &middot; CLAUDE.md</h1>';
if (tmux_session_exists($agent['tmux'])) {
    echo '<p style="color:#f59e0b">This agent is running. Wrap it down before editing, saving is blocked while live.</p>';
}
echo '<form method="post" action="edit-claude.php">'
    . '<input type="hidden" name="project" value="' . h($slug) . '">'
    . '<input type="hidden" name="agent" value="' . h($agentSlug) . '">'
    . '<textarea name="content" style="min-height:420px;font-family:monospace">' . h($current) . '</textarea>'
    . '<button class="btn" type="submit" style="margin-top:8px">Save</button></form>';
render_footer();
