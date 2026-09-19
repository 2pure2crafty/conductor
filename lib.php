<?php
declare(strict_types=1);

// Path to the deployment config file. Everything server-specific lives there,
// not in this repo. Override with the CONDUCTOR_CONFIG env var if you keep it
// somewhere other than the default.
define('CONDUCTOR_CONFIG_FILE', getenv('CONDUCTOR_CONFIG') ?: '/etc/default/conductor');

// Repo-relative paths (these ship with the code and are the same on any server).
define('REGISTRY_PATH', __DIR__ . '/registry.json');
define('WRAPUP_SKILL_SRC', __DIR__ . '/skills/wrap-up/SKILL.md');
define('SPAWN_FINISH_SCRIPT', __DIR__ . '/spawn-finish.sh');
define('SWITCH_TERMINAL_SCRIPT', __DIR__ . '/switch-terminal-finish.sh');

/**
 * Parse KEY=value lines from the deployment config file (CONDUCTOR_CONFIG_FILE).
 * Cached per request. Values may be quoted. Missing file returns [].
 */
function conductor_config(): array {
    static $config = null;
    if ($config !== null) return $config;
    $config = [];
    if (is_readable(CONDUCTOR_CONFIG_FILE)) {
        foreach (file(CONDUCTOR_CONFIG_FILE, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/', $line, $m)) {
                $config[$m[1]] = trim($m[2], "\"'");
            }
        }
    }
    return $config;
}

/** Read a config value, falling back to $default if unset. */
function conductor_config_get(string $key, string $default = ''): string {
    $config = conductor_config();
    return $config[$key] ?? $default;
}

/**
 * Base directory new projects are created under. Server-specific, so it comes
 * from the config file; falls back to a sensible default for a fresh install.
 */
function conductor_base_dir(): string {
    return rtrim(conductor_config_get('CONDUCTOR_BASE_DIR', '/var/www/agents'), '/');
}

/**
 * Glob that scaffolded agents get Read access to (their settings.json allow
 * list). Defaults to the base dir so agents can read across sibling projects;
 * set CONDUCTOR_READ_SCOPE in the config file to widen or narrow it.
 */
function conductor_read_scope(): string {
    return conductor_config_get('CONDUCTOR_READ_SCOPE', conductor_base_dir() . '/**');
}

/** tmux session name that ttyd attaches to (used for the "Open terminal" deep-link). */
function conductor_ttyd_session(): string {
    return conductor_config_get('CONDUCTOR_TTYD_SESSION', 'hds-remote');
}

/** Prefix for spawned agents' tmux session names, e.g. "HDS" -> "HDS-project-agent". */
function conductor_tmux_prefix(): string {
    return conductor_config_get('CONDUCTOR_TMUX_PREFIX', 'HDS');
}

function require_auth(): void {
    $user = conductor_config_get('CONDUCTOR_USER');
    $pass = conductor_config_get('CONDUCTOR_PASS');
    $givenUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $givenPass = $_SERVER['PHP_AUTH_PW'] ?? '';
    $ok = $user !== '' && $pass !== ''
        && hash_equals($user, $givenUser)
        && hash_equals($pass, $givenPass);
    if (!$ok) {
        header('WWW-Authenticate: Basic realm="Conductor"');
        http_response_code(401);
        echo "Auth required.";
        exit;
    }
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Lowercase slug, [a-z0-9-] only, non-empty. Returns null if input can't produce a safe slug. */
function slugify(string $s): ?string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    if ($s === '' || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $s)) {
        return null;
    }
    return $s;
}

/** Ensure $path is inside $base (both resolved via realpath). */
function path_is_within(string $path, string $base): bool {
    $realBase = realpath($base);
    $realPath = realpath($path);
    if ($realBase === false || $realPath === false) return false;
    return $realPath === $realBase || str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR);
}

function load_registry(): array {
    if (!file_exists(REGISTRY_PATH)) {
        return ['projects' => []];
    }
    $fh = fopen(REGISTRY_PATH, 'r');
    flock($fh, LOCK_SH);
    $data = json_decode(stream_get_contents($fh), true);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $data ?? ['projects' => []];
}

/** Read-modify-write under an exclusive lock. $mutator receives and returns the registry array. */
function update_registry(callable $mutator): array {
    $fh = fopen(REGISTRY_PATH, 'c+');
    flock($fh, LOCK_EX);
    $current = json_decode(stream_get_contents($fh), true) ?? ['projects' => []];
    $updated = $mutator($current);
    rewind($fh);
    ftruncate($fh, 0);
    fwrite($fh, json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $updated;
}

function agent_dir(array $project, array $agent): string {
    if ($agent['path'] === '.') return $project['path'];
    return rtrim($project['path'], '/') . '/' . $agent['path'];
}

/** Run a command with argv-array (no shell interpolation). Returns [exitCode, stdout, stderr]. */
function run_cmd(array $argv, ?string $cwd = null): array {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($argv, $descriptors, $pipes, $cwd);
    if (!is_resource($proc)) return [1, '', 'failed to start process'];
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return [$exit, $stdout, $stderr];
}

function tmux_running_sessions(): array {
    [$exit, $stdout] = run_cmd(['tmux', 'list-sessions', '-F', '#{session_name}']);
    if ($exit !== 0) return [];
    return array_filter(explode("\n", trim($stdout)));
}

function tmux_session_exists(string $name): bool {
    [$exit] = run_cmd(['tmux', 'has-session', '-t', $name]);
    return $exit === 0;
}

/** Detect a pending Claude Code permission-confirmation dialog in a tmux pane. */
function detect_pending_prompt(string $tmuxName): ?array {
    [$exit, $stdout] = run_cmd(['tmux', 'capture-pane', '-t', $tmuxName, '-p', '-S', '-40']);
    if ($exit !== 0) return null;
    $lines = explode("\n", $stdout);

    $escIdx = null;
    foreach ($lines as $i => $line) {
        if (str_contains($line, 'Esc to cancel')) $escIdx = $i;
    }
    if ($escIdx === null) return null;

    $i = $escIdx - 1;
    while ($i >= 0 && trim($lines[$i]) === '') $i--;

    $options = [];
    while ($i >= 0 && preg_match('/^\s*(?:\x{276f}\s*)?(\d+)\.\s*(.+?)\s*$/u', $lines[$i], $m)) {
        array_unshift($options, trim($m[2]));
        $i--;
    }
    if (empty($options)) return null;

    while ($i >= 0 && trim($lines[$i]) === '') $i--;
    $question = $i >= 0 ? trim($lines[$i]) : 'Confirmation required';

    return ['question' => $question, 'options' => $options];
}

/** Scan every live agent in the registry for a pending permission prompt. */
function find_pending_prompts(array $registry, array $running): array {
    $found = [];
    foreach ($registry['projects'] as $pSlug => $project) {
        foreach ($project['agents'] as $aSlug => $agent) {
            if (!in_array($agent['tmux'], $running, true)) continue;
            $prompt = detect_pending_prompt($agent['tmux']);
            if ($prompt === null) continue;
            $found[] = [
                'project' => $pSlug,
                'agent' => $aSlug,
                'projectLabel' => $project['label'],
                'agentLabel' => $agent['label'],
                'tmux' => $agent['tmux'],
                'prompt' => $prompt,
            ];
        }
    }
    return $found;
}

function render_header(string $title): void {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' - Conductor</title><style>'
        . 'body{font-family:system-ui,sans-serif;max-width:640px;margin:0 auto;padding:16px;background:#111;color:#eee}'
        . 'a{color:#7ab8ff}'
        . 'h1{font-size:1.4rem}h2{font-size:1.1rem;margin-top:1.5em}'
        . '.card{background:#1c1c1c;border:1px solid #333;border-radius:8px;padding:14px;margin:10px 0}'
        . '.card a{text-decoration:none;color:inherit;display:block}'
        . '.card .desc{color:#aaa;font-size:0.9rem;margin-top:4px}'
        . '.btn{display:inline-block;background:#2563eb;color:#fff;padding:12px 18px;border-radius:6px;'
        . 'text-decoration:none;font-size:1rem;border:none;cursor:pointer;margin:4px 4px 4px 0}'
        . '.btn.stop{background:#b91c1c}'
        . '.status{display:inline-block;padding:2px 8px;border-radius:10px;font-size:0.8rem;margin-left:6px}'
        . '.status.live{background:#14532d;color:#bbf7d0}'
        . '.status.stopped{background:#3f3f46;color:#d4d4d8}'
        . 'label{display:block;margin-top:14px;font-size:0.9rem;color:#ccc}'
        . 'input[type=text],select,textarea{width:100%;padding:10px;margin-top:4px;border-radius:6px;'
        . 'border:1px solid #444;background:#1c1c1c;color:#eee;font-size:1rem;box-sizing:border-box}'
        . 'textarea{min-height:100px}'
        . '.back{display:inline-block;margin-bottom:10px;color:#888;text-decoration:none}'
        . '</style></head><body>';
}

function render_footer(): void {
    echo '</body></html>';
}

function build_claude_md(string $agentLabel, string $projectDescription, string $instructions): string {
    $md = "# {$agentLabel}\n\n"
        . "## Session handoff\n\n"
        . "Before doing anything else, check whether `SESSION.md` exists in this directory.\n"
        . "If it does, read it first, it has the state from your last session with Patch.\n"
        . "Treat it as a briefing to get oriented quickly, not a script to follow blindly.\n\n"
        . "When Patch runs `/wrap-up`, write a fresh handoff to `SESSION.md` following the\n"
        . "instructions in that skill.\n\n"
        . "---\n\n";
    if (trim($projectDescription) !== '') {
        $md .= "## Project context\n\n" . trim($projectDescription) . "\n\n";
    }
    if (trim($instructions) !== '') {
        $md .= "## Role\n\n" . trim($instructions) . "\n";
    }
    return $md;
}

function build_settings_json(string $agentDirAbs): string {
    $data = [
        'permissions' => [
            'allow' => [
                'Read(' . conductor_read_scope() . ')',
                'Write(' . $agentDirAbs . '/**)',
                'Bash(git *)',
                'Bash(find *)',
                'Bash(grep *)',
                'Bash(ls *)',
                'Bash(cat *)',
                'Bash(head *)',
                'Bash(tail *)',
                'Bash(mkdir *)',
                'Bash(php *)',
            ],
            'deny' => [],
        ],
    ];
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

/** Create the standard agent scaffold: dir, CLAUDE.md, wrap-up skill, settings.json. */
function scaffold_new_agent(string $agentDirAbs, string $agentLabel, string $projectDescription, string $instructions): void {
    mkdir($agentDirAbs, 0775, true);
    file_put_contents($agentDirAbs . '/CLAUDE.md', build_claude_md($agentLabel, $projectDescription, $instructions));
    mkdir($agentDirAbs . '/.claude/skills/wrap-up', 0775, true);
    copy(WRAPUP_SKILL_SRC, $agentDirAbs . '/.claude/skills/wrap-up/SKILL.md');
    file_put_contents($agentDirAbs . '/.claude/settings.json', build_settings_json($agentDirAbs));
}

const VALID_PERMISSION_MODES = ['', 'acceptEdits', 'auto'];

/**
 * $model is '' for CLI default, or one of haiku/sonnet/opus/fable (already validated by caller).
 * $permissionMode is '' for interactive default, or one of VALID_PERMISSION_MODES (already validated by caller).
 */
function spawn_tmux_agent(string $tmuxName, string $agentDirAbs, string $model, string $permissionMode = ''): void {
    if (tmux_session_exists($tmuxName)) {
        run_cmd(['tmux', 'kill-session', '-t', $tmuxName]);
    }
    run_cmd(['tmux', 'new-session', '-d', '-s', $tmuxName, '-c', $agentDirAbs]);
    $windowName = $tmuxName . '-' . date('Y-m-d');
    run_cmd(['tmux', 'rename-window', '-t', $tmuxName . ':0', $windowName]);

    $claudeCmd = 'claude';
    if ($model !== '') $claudeCmd .= ' --model ' . $model;
    if ($permissionMode !== '') $claudeCmd .= ' --permission-mode ' . $permissionMode;
    run_cmd(['tmux', 'send-keys', '-t', $tmuxName, $claudeCmd, 'Enter']);

    exec('nohup bash ' . escapeshellarg(SPAWN_FINISH_SCRIPT) . ' ' . escapeshellarg($tmuxName)
        . ' > /dev/null 2>&1 &');
}

function render_spawn_confirmation(string $tmuxName, string $backHref): void {
    render_header('Spinning up');
    echo '<h1>Spinning up ' . h($tmuxName) . '</h1>';
    echo '<p>Give it about a minute to initialise and pair with /remote-control, then open the Claude app.</p>';
    echo '<a class="back" href="' . h($backHref) . '">&larr; Back to project</a>';
    render_footer();
}

function error_page(string $message, string $backHref = 'index.php'): void {
    render_header('Error');
    echo '<p style="color:#f87171">' . h($message) . '</p>';
    echo '<a class="back" href="' . h($backHref) . '">&larr; Back</a>';
    render_footer();
    exit;
}
