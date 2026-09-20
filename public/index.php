<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
require_auth();

$registry = load_registry();
$running = tmux_running_sessions();
$pending = find_pending_prompts($registry, $running);

render_header('Dashboard');

if (!empty($pending)) {
    echo '<h1>Needs attention</h1>';
    foreach ($pending as $p) {
        echo '<div class="card" style="border-color:#b45309">';
        echo '<strong>' . h($p['projectLabel'] . ' - ' . $p['agentLabel']) . '</strong>';
        echo '<div class="desc">' . h($p['prompt']['question']) . '</div>';
        echo '<div class="desc">' . implode(' / ', array_map('h', $p['prompt']['options'])) . '</div>';
        echo '<form method="post" action="respond.php" style="margin-top:8px">';
        echo '<input type="hidden" name="project" value="' . h($p['project']) . '">';
        echo '<input type="hidden" name="agent" value="' . h($p['agent']) . '">';
        echo '<input type="hidden" name="option_count" value="' . count($p['prompt']['options']) . '">';
        echo '<button class="btn" type="submit" name="action" value="approve">Approve</button>';
        echo '<button class="btn stop" type="submit" name="action" value="deny">Deny</button>';
        echo '</form>';
        echo '<a class="btn" style="background:#444" href="switch-terminal.php?project=' . h($p['project'])
            . '&amp;agent=' . h($p['agent']) . '">Open terminal</a>';
        echo '</div>';
    }
}

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
echo '<a class="btn" style="background:#444" href="audit.php">Audit log</a>';
render_footer();
