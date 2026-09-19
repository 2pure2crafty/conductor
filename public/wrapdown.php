<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}

$projectSlug = $_POST['project'] ?? '';
$agentSlug = $_POST['agent'] ?? '';

$registry = load_registry();
$project = $registry['projects'][$projectSlug] ?? null;
if ($project === null) error_page('No such project.');
$agent = $project['agents'][$agentSlug] ?? null;
if ($agent === null) error_page('No such agent.', 'project.php?slug=' . $projectSlug);

if (!tmux_session_exists($agent['tmux'])) {
    error_page('Session ' . $agent['tmux'] . ' is not running.', 'project.php?slug=' . $projectSlug);
}

$agentDirAbs = agent_dir($project, $agent);
$sessionMdPath = $agentDirAbs . '/SESSION.md';
$beforeMtime = file_exists($sessionMdPath) ? filemtime($sessionMdPath) : null;

run_cmd(['tmux', 'send-keys', '-t', $agent['tmux'], '/wrap-up', 'Enter']);

$updated = false;
$deadline = time() + 90;
while (time() < $deadline) {
    clearstatcache(true, $sessionMdPath);
    if (file_exists($sessionMdPath)) {
        $mtime = filemtime($sessionMdPath);
        if ($beforeMtime === null || $mtime > $beforeMtime) {
            $updated = true;
            break;
        }
    }
    sleep(1);
}

run_cmd(['tmux', 'kill-session', '-t', $agent['tmux']]);

render_header('Wrapped up');
echo '<h1>' . h($agent['label']) . ' stopped</h1>';
if ($updated) {
    echo '<p>SESSION.md was updated and the session has been stopped.</p>';
} else {
    echo '<p style="color:#f59e0b">SESSION.md did not update within 90 seconds, but the session '
        . 'has been stopped anyway. Check ' . h($sessionMdPath) . ' by hand.</p>';
}
echo '<a class="back" href="project.php?slug=' . h($projectSlug) . '">&larr; Back to project</a>';
render_footer();
