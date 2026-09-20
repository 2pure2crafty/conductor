<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}

$slug = $_POST['project'] ?? '';
$agentSlug = $_POST['agent'] ?? '';
$action = $_POST['action'] ?? '';
$back = 'manage.php?slug=' . rawurlencode($slug);

$registry = load_registry();
$project = $registry['projects'][$slug] ?? null;
if ($project === null) error_page('No such project.');

switch ($action) {
    case 'toggle_wrapdown':
        if (!isset($project['agents'][$agentSlug])) error_page('No such agent.', $back);
        $new = empty($project['agents'][$agentSlug]['auto_wrapdown']);
        update_registry(function ($r) use ($slug, $agentSlug, $new) {
            $r['projects'][$slug]['agents'][$agentSlug]['auto_wrapdown'] = $new;
            return $r;
        });
        audit_log('toggle_wrapdown', "$slug/$agentSlug -> " . ($new ? 'on' : 'off'));
        break;

    case 'delete_agent':
        if (!isset($project['agents'][$agentSlug])) error_page('No such agent.', $back);
        $tmux = $project['agents'][$agentSlug]['tmux'];
        if (tmux_session_exists($tmux)) run_cmd(['tmux', 'kill-session', '-t', $tmux]);
        update_registry(function ($r) use ($slug, $agentSlug) {
            unset($r['projects'][$slug]['agents'][$agentSlug]);
            return $r;
        });
        audit_log('delete_agent', "$slug/$agentSlug (files kept on disk)");
        break;

    case 'delete_project':
        foreach ($project['agents'] as $a) {
            if (tmux_session_exists($a['tmux'])) run_cmd(['tmux', 'kill-session', '-t', $a['tmux']]);
        }
        update_registry(function ($r) use ($slug) {
            unset($r['projects'][$slug]);
            return $r;
        });
        audit_log('delete_project', "$slug (files kept on disk)");
        header('Location: index.php');
        exit;

    case 'version_summary':
        if (!isset($project['agents'][$agentSlug])) error_page('No such agent.', $back);
        $agent = $project['agents'][$agentSlug];
        $label = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($_POST['version'] ?? ''));
        if ($label === '') error_page('Version label required.', $back);
        $mem = agent_memory_dir($project, $agent);
        $src = '';
        foreach (['DIGEST.md', 'HISTORY.md'] as $f) {
            if (is_file("$mem/$f")) $src .= "\n\n===== $f =====\n" . file_get_contents("$mem/$f");
        }
        if (trim($src) === '') error_page('No memory to summarize yet.', $back);
        $summary = daemon_reason(
            "Write a concise summary of this project version for the archive: what it is, "
            . "the key decisions and their reasons, and the state at this milestone. Markdown, no preamble.",
            $src
        );
        if ($summary === null) error_page('Summary generation failed (Haiku).', $back);
        @mkdir("$mem/archive", 0775, true);
        file_put_contents("$mem/archive/v{$label}-summary.md", $summary . "\n");
        audit_log('version_summary', "$slug/$agentSlug v$label");
        break;

    default:
        error_page('Unknown action.', $back);
}

header('Location: ' . $back);
exit;
