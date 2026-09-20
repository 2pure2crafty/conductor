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

render_header('Manage: ' . $project['label']);
echo '<a class="back" href="project.php?slug=' . h($slug) . '">&larr; Back to project</a>';
echo '<h1>Manage ' . h($project['label']) . '</h1>';

function hidden(string $slug, string $agentSlug, string $action): string {
    return '<input type="hidden" name="project" value="' . h($slug) . '">'
        . '<input type="hidden" name="agent" value="' . h($agentSlug) . '">'
        . '<input type="hidden" name="action" value="' . h($action) . '">';
}

foreach ($project['agents'] as $agentSlug => $agent) {
    $armed = !empty($agent['auto_wrapdown']);
    echo '<div class="card"><strong>' . h($agent['label']) . '</strong>';
    echo '<div class="meta">auto-wrap-down: ' . ($armed ? 'ON' : 'off') . '</div>';

    echo '<form method="post" action="manage-action.php" style="display:inline">' . hidden($slug, $agentSlug, 'toggle_wrapdown')
        . '<button class="btn" type="submit">' . ($armed ? 'Disable' : 'Enable') . ' auto-wrap-down</button></form>';

    echo '<a class="btn" style="background:#444" href="edit-claude.php?project=' . h($slug)
        . '&amp;agent=' . h($agentSlug) . '">Edit CLAUDE.md</a>';

    echo '<form method="post" action="manage-action.php" style="margin-top:8px">' . hidden($slug, $agentSlug, 'version_summary')
        . '<input type="text" name="version" placeholder="version label, e.g. 1.0">'
        . '<button class="btn" type="submit" style="margin-top:6px">Write version summary</button></form>';

    echo '<form method="post" action="manage-action.php" onsubmit="return confirm(\'Remove this agent from the registry? Files stay on disk.\');" style="margin-top:8px">'
        . hidden($slug, $agentSlug, 'delete_agent')
        . '<button class="btn stop" type="submit">Delete agent</button></form>';
    echo '</div>';
}

echo '<form method="post" action="manage-action.php" onsubmit="return confirm(\'Remove this whole project from the registry? Files stay on disk.\');">'
    . '<input type="hidden" name="project" value="' . h($slug) . '">'
    . '<input type="hidden" name="action" value="delete_project">'
    . '<button class="btn stop" type="submit">Delete project</button></form>';

render_footer();
