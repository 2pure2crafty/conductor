<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}

$model = $_POST['model'] ?? '';
if ($model !== '' && !in_array($model, ['haiku', 'sonnet', 'opus', 'fable'], true)) {
    error_page('Invalid model.');
}

$permissionMode = $_POST['permission_mode'] ?? '';
if (!in_array($permissionMode, VALID_PERMISSION_MODES, true)) {
    error_page('Invalid permission mode.');
}

$isNewProject = ($_POST['new_project'] ?? '') === '1';

if ($isNewProject) {
    $projectName = trim($_POST['project_name'] ?? '');
    $projectDescription = trim($_POST['project_description'] ?? '');
    $createRepo = ($_POST['create_repo'] ?? '') === '1';
    $agentName = trim($_POST['agent_name'] ?? '');
    $instructions = trim($_POST['agent_instructions'] ?? '');

    $projectSlug = slugify($projectName);
    $agentSlug = slugify($agentName);
    if ($projectSlug === null) error_page('Project name did not produce a valid slug.');
    if ($agentSlug === null) error_page('Agent name did not produce a valid slug.', 'spawn-form.php');

    $registry = load_registry();
    if (isset($registry['projects'][$projectSlug])) {
        error_page('A project with slug "' . $projectSlug . '" already exists.', 'spawn-form.php');
    }

    $baseDir = conductor_base_dir();
    $projectPath = $baseDir . '/' . $projectSlug;
    if (is_dir($projectPath)) {
        error_page('Directory already exists at ' . $projectPath . '.', 'spawn-form.php');
    }
    mkdir($projectPath, 0775, true);
    if (!path_is_within($projectPath, $baseDir)) {
        error_page('Refusing to continue: resolved path escaped the base directory.');
    }

    run_cmd(['git', 'init'], $projectPath);

    $repoUrl = null;
    if ($createRepo) {
        run_cmd(['gh', 'repo', 'create', $projectSlug, '--private', '--source=.', '--remote=origin'], $projectPath);
        [$exit, $stdout] = run_cmd(['gh', 'repo', 'view', '--json', 'sshUrl', '-q', '.sshUrl'], $projectPath);
        if ($exit === 0) $repoUrl = trim($stdout);
    }

    $agentDirAbs = $projectPath . '/' . $agentSlug;
    $agentLabel = $agentName;
    scaffold_new_agent($agentDirAbs, $agentLabel, $projectDescription, $instructions);

    $tmuxName = conductor_tmux_prefix() . '-' . $projectSlug . '-' . $agentSlug;
    $registry = update_registry(function (array $reg) use ($projectSlug, $projectName, $projectPath, $repoUrl, $projectDescription, $agentSlug, $agentLabel, $tmuxName, $model, $permissionMode) {
        $reg['projects'][$projectSlug] = [
            'label' => $projectName,
            'path' => $projectPath,
            'repo' => $repoUrl,
            'description' => $projectDescription,
            'agents' => [
                $agentSlug => [
                    'label' => $agentLabel,
                    'path' => $agentSlug,
                    'tmux' => $tmuxName,
                    'model' => $model !== '' ? $model : 'auto',
                    'permission_mode' => $permissionMode,
                ],
            ],
        ];
        return $reg;
    });

    spawn_tmux_agent($tmuxName, $agentDirAbs, $model, $permissionMode);
    render_spawn_confirmation($tmuxName, 'project.php?slug=' . $projectSlug);
    exit;
}

// Existing project
$projectSlug = $_POST['project'] ?? '';
$registry = load_registry();
$project = $registry['projects'][$projectSlug] ?? null;
if ($project === null) error_page('No such project.');

$agentChoice = $_POST['agent_choice'] ?? '';

if ($agentChoice === '__new__') {
    $agentName = trim($_POST['agent_name'] ?? '');
    $instructions = trim($_POST['agent_instructions'] ?? '');
    $agentSlug = slugify($agentName);
    if ($agentSlug === null) error_page('Agent name did not produce a valid slug.', 'spawn-form.php?project=' . $projectSlug);
    if (isset($project['agents'][$agentSlug])) {
        error_page('Agent "' . $agentSlug . '" already exists in this project.', 'spawn-form.php?project=' . $projectSlug);
    }

    $agentDirAbs = rtrim($project['path'], '/') . '/' . $agentSlug;
    if (is_dir($agentDirAbs)) error_page('Directory already exists at ' . $agentDirAbs . '.', 'spawn-form.php?project=' . $projectSlug);

    scaffold_new_agent($agentDirAbs, $agentName, $project['description'], $instructions);
    if (!path_is_within($agentDirAbs, $project['path'])) {
        error_page('Refusing to continue: resolved agent path escaped the project directory.');
    }

    $tmuxName = conductor_tmux_prefix() . '-' . $projectSlug . '-' . $agentSlug;
    $registry = update_registry(function (array $reg) use ($projectSlug, $agentSlug, $agentName, $tmuxName, $model, $permissionMode) {
        $reg['projects'][$projectSlug]['agents'][$agentSlug] = [
            'label' => $agentName,
            'path' => $agentSlug,
            'tmux' => $tmuxName,
            'model' => $model !== '' ? $model : 'auto',
            'permission_mode' => $permissionMode,
        ];
        return $reg;
    });

    spawn_tmux_agent($tmuxName, $agentDirAbs, $model, $permissionMode);
    render_spawn_confirmation($tmuxName, 'project.php?slug=' . $projectSlug);
    exit;
}

// Existing agent: just turn it on, as-is.
$agent = $project['agents'][$agentChoice] ?? null;
if ($agent === null) error_page('No such agent.');

$agentDirAbs = agent_dir($project, $agent);
spawn_tmux_agent($agent['tmux'], $agentDirAbs, $agent['model'] === 'auto' ? '' : $agent['model'], $agent['permission_mode'] ?? '');
render_spawn_confirmation($agent['tmux'], 'project.php?slug=' . $projectSlug);
