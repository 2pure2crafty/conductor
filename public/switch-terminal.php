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
if ($agent === null) error_page('No such agent.', 'index.php');

if (!tmux_session_exists($agent['tmux'])) {
    error_page('Session ' . $agent['tmux'] . ' is not running.', 'index.php');
}
if (!tmux_session_exists(TTYD_TMUX_SESSION)) {
    error_page('Terminal session (' . TTYD_TMUX_SESSION . ') is not running.', 'index.php');
}

exec('nohup bash ' . escapeshellarg(SWITCH_TERMINAL_SCRIPT) . ' ' . escapeshellarg(TTYD_TMUX_SESSION)
    . ' ' . escapeshellarg($agent['tmux']) . ' > /dev/null 2>&1 &');

header('Location: /');
exit;
