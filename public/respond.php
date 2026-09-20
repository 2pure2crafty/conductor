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
$action = $_POST['action'] ?? '';

$registry = load_registry();
$project = $registry['projects'][$projectSlug] ?? null;
if ($project === null) error_page('No such project.');
$agent = $project['agents'][$agentSlug] ?? null;
if ($agent === null) error_page('No such agent.', 'index.php');

if (!tmux_session_exists($agent['tmux'])) {
    error_page('Session ' . $agent['tmux'] . ' is not running.', 'index.php');
}

if ($action === 'approve') {
    // Default-highlighted option (usually "Yes") - plain Enter selects it.
    run_cmd(['tmux', 'send-keys', '-t', $agent['tmux'], 'Enter']);
} elseif ($action === 'deny') {
    // "No" is reliably the last item in Claude Code's permission menu; walk down to it.
    // Keystrokes need spacing out - sent back-to-back, the TUI's redraw can drop one.
    $optionCount = max(1, (int)($_POST['option_count'] ?? 3));
    for ($i = 0; $i < $optionCount - 1; $i++) {
        run_cmd(['tmux', 'send-keys', '-t', $agent['tmux'], 'Down']);
        usleep(300000);
    }
    run_cmd(['tmux', 'send-keys', '-t', $agent['tmux'], 'Enter']);
} else {
    error_page('Unknown action.', 'index.php');
}
audit_log('respond', $agent['tmux'] . ' ' . $action);

header('Location: index.php');
exit;
