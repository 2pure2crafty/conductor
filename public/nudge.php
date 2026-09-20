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
$message = trim($_POST['message'] ?? '');

$registry = load_registry();
$project = $registry['projects'][$projectSlug] ?? null;
if ($project === null) error_page('No such project.');
$agent = $project['agents'][$agentSlug] ?? null;
if ($agent === null) error_page('No such agent.', 'project.php?slug=' . $projectSlug);
if (!tmux_session_exists($agent['tmux'])) {
    error_page('Session is not running.', 'project.php?slug=' . $projectSlug);
}

// Strip control characters (keep it a single-line message); cap length.
$message = preg_replace('/[\x00-\x1f\x7f]/', ' ', $message);
$message = mb_substr($message, 0, 2000);
if ($message === '') error_page('Empty message.', 'project.php?slug=' . $projectSlug);

// send-keys with a single argv string is shell-injection safe (no shell). Type
// the text, pause, then Enter, so the submit isn't swallowed by a redraw.
run_cmd(['tmux', 'send-keys', '-t', $agent['tmux'], $message]);
usleep(400000);
run_cmd(['tmux', 'send-keys', '-t', $agent['tmux'], 'Enter']);
audit_log('nudge', $agent['tmux'] . ': ' . mb_substr($message, 0, 80));

header('Location: project.php?slug=' . rawurlencode($projectSlug));
exit;
