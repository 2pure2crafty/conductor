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

$projTotals = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'sessions' => 0];
$projCost = 0.0;

echo '<h2>Agents</h2>';
foreach ($project['agents'] as $agentSlug => $agent) {
    $isLive = in_array($agent['tmux'], $running, true);
    $status = $isLive ? agent_status($agent['tmux']) : 'stopped';

    echo '<div class="card"><strong>' . h($agent['label']) . '</strong>' . status_badge($status);
    echo '<div class="desc">tmux: ' . h($agent['tmux']) . ' &middot; model: ' . h($agent['model']) . '</div>';

    // Live context size
    if ($isLive) {
        $ctx = agent_context_size($project, $agent);
        if ($ctx > 0) echo '<div class="meta">context now: ' . fmt_tokens($ctx) . ' tokens</div>';
    }

    // Lifetime token totals + estimated cost
    $usage = agent_token_usage($project, $agent);
    foreach ($projTotals as $k => $_) $projTotals[$k] += $usage[$k];
    $cost = estimate_cost($usage, $agent['model']);
    $projCost += $cost;
    if ($usage['sessions'] > 0) {
        echo '<div class="meta">tokens: ' . fmt_tokens($usage['input'] + $usage['cache_read'] + $usage['cache_write'])
            . ' in / ' . fmt_tokens($usage['output']) . ' out'
            . ' &middot; ~$' . number_format($cost, 2)
            . ' &middot; ' . $usage['sessions'] . ' session' . ($usage['sessions'] === 1 ? '' : 's') . '</div>';
    }

    // SESSION.md + DIGEST previews (read-only)
    $sessionPath = agent_dir($project, $agent) . '/SESSION.md';
    if (is_file($sessionPath)) {
        echo '<details><summary>SESSION.md &middot; last active ' . h(date('Y-m-d H:i', filemtime($sessionPath))) . '</summary>'
            . '<pre>' . h((string) file_get_contents($sessionPath)) . '</pre></details>';
    }
    $digestPath = agent_memory_dir($project, $agent) . '/DIGEST.md';
    if (is_file($digestPath)) {
        echo '<details><summary>memory/DIGEST.md (condensed history)</summary>'
            . '<pre>' . h((string) file_get_contents($digestPath)) . '</pre></details>';
    }

    if ($isLive) {
        $sessionUrl = agent_session_url($agent['tmux']);
        if ($sessionUrl !== null) {
            echo '<a class="btn" href="' . h($sessionUrl) . '">Open in Claude app</a>';
        }
        echo '<a class="btn" style="background:#444" href="peek.php?project=' . h($slug)
            . '&amp;agent=' . h($agentSlug) . '">Peek</a>';
        // Quick nudge
        echo '<form method="post" action="nudge.php" style="margin-top:8px">'
            . '<input type="hidden" name="project" value="' . h($slug) . '">'
            . '<input type="hidden" name="agent" value="' . h($agentSlug) . '">'
            . '<input type="text" name="message" placeholder="Quick nudge (sends a message)">'
            . '<button class="btn" type="submit" style="margin-top:6px">Send</button></form>';
        echo '<form method="post" action="wrapdown.php" onsubmit="return confirm(\'Send /wrap-up and stop this session?\');" style="margin-top:8px">'
            . '<input type="hidden" name="project" value="' . h($slug) . '">'
            . '<input type="hidden" name="agent" value="' . h($agentSlug) . '">'
            . '<button class="btn stop" type="submit">Wrap up &amp; stop</button></form>';
    }
    echo '</div>';
}

if ($projTotals['sessions'] > 0) {
    echo '<div class="meta">Project total: '
        . fmt_tokens($projTotals['input'] + $projTotals['cache_read'] + $projTotals['cache_write'])
        . ' in / ' . fmt_tokens($projTotals['output']) . ' out &middot; ~$' . number_format($projCost, 2)
        . ' (estimated, from transcripts on disk)</div>';
}

echo '<a class="btn" href="spawn-form.php?project=' . h($slug) . '">Spin up agent</a>';
echo '<a class="btn" style="background:#444" href="manage.php?slug=' . h($slug) . '">Manage</a>';
render_footer();
