<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
require_auth();

$registry = load_registry();
$projectSlug = $_GET['project'] ?? '';
$project = $registry['projects'][$projectSlug] ?? null;
$isNewProject = ($project === null);

render_header($isNewProject ? 'New project' : 'Spin up agent');
echo '<a class="back" href="' . ($isNewProject ? 'index.php' : 'project.php?slug=' . h($projectSlug)) . '">&larr; Back</a>';
echo '<h1>' . ($isNewProject ? 'New project' : h('Spin up agent - ' . $project['label'])) . '</h1>';

echo '<form method="post" action="spawn.php">';

if ($isNewProject) {
    echo '<input type="hidden" name="new_project" value="1">';
    echo '<label>Project name<input type="text" name="project_name" required></label>';
    echo '<label>Project description<textarea name="project_description" '
        . 'placeholder="What is this project, for the agent\'s CLAUDE.md and the dashboard"></textarea></label>';
    echo '<label><input type="checkbox" name="create_repo" value="1" style="width:auto;display:inline-block;vertical-align:middle"> '
        . 'Create a GitHub repo for this project</label>';
} else {
    echo '<input type="hidden" name="project" value="' . h($projectSlug) . '">';
    echo '<label>Agent<select name="agent_choice" id="agent_choice" onchange="toggleNewAgent()">';
    foreach ($project['agents'] as $agentSlug => $agent) {
        echo '<option value="' . h($agentSlug) . '">' . h($agent['label']) . '</option>';
    }
    echo '<option value="__new__">+ New agent</option>';
    echo '</select></label>';
}

$newAgentDisplay = $isNewProject ? '' : 'style="display:none"';
echo '<div id="new_agent_fields" ' . $newAgentDisplay . '>';
echo '<label>Agent name<input type="text" name="agent_name"' . ($isNewProject ? ' required' : '') . '></label>';
echo '<label>Role / instructions for CLAUDE.md<textarea name="agent_instructions" '
    . 'placeholder="What is this agent responsible for? Any project context it needs."></textarea></label>';
echo '<label>Model<select name="model">'
    . '<option value="">auto (CLI default)</option>'
    . '<option value="haiku">haiku</option>'
    . '<option value="sonnet" selected>sonnet</option>'
    . '<option value="opus">opus</option>'
    . '<option value="fable">fable</option>'
    . '</select></label>';
echo '<label>Permission mode<select name="permission_mode">'
    . '<option value="">default (prompts for everything - you approve each tool call live)</option>'
    . '<option value="acceptEdits" selected>acceptEdits (file edits auto-approved, e.g. /wrap-up; other tools still prompt)</option>'
    . '<option value="auto">auto (fully autonomous within this agent\'s settings.json allow-list - same as the pipeline agents)</option>'
    . '</select></label>';
echo '</div>';

echo '<button class="btn" type="submit">Spin up</button>';
echo '</form>';

if (!$isNewProject) {
    echo '<script>
function toggleNewAgent() {
    var v = document.getElementById("agent_choice").value;
    document.getElementById("new_agent_fields").style.display = (v === "__new__") ? "block" : "none";
}
</script>';
}

render_footer();
