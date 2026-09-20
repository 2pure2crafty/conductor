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

/* --- daemon / token-tracking config --------------------------------------- */

/** Root dir Claude Code writes per-project transcripts to. */
function conductor_transcripts_dir(): string {
    return rtrim(conductor_config_get('CONDUCTOR_TRANSCRIPTS_DIR', '/home/patch/.claude/projects'), '/');
}

/** Seconds an agent must sit idle (while over the size gate) before auto-wrap-down. */
function conductor_idle_timeout(): int {
    return (int) conductor_config_get('CONDUCTOR_IDLE_TIMEOUT', '240');
}

/** Context-size gate: auto-wrap-down only arms once context exceeds this many tokens. 0 = no gate. */
function conductor_wrapdown_min_context(): int {
    return (int) conductor_config_get('CONDUCTOR_WRAPDOWN_MIN_CONTEXT', '100000');
}

/** Seconds between daemon poll cycles. */
function conductor_daemon_interval(): int {
    return max(5, (int) conductor_config_get('CONDUCTOR_DAEMON_INTERVAL', '30'));
}

/** When true (default), the daemon logs decisions but takes no real action (no kills, no token spend). */
function conductor_daemon_dryrun(): bool {
    return conductor_config_get('CONDUCTOR_DAEMON_DRYRUN', '1') !== '0';
}

/** memory/HISTORY.md must reach this est-token size before a digest is generated. */
function conductor_digest_threshold(): int {
    return (int) conductor_config_get('CONDUCTOR_DIGEST_THRESHOLD', '15000');
}

/** Re-generate the digest only after HISTORY.md grows this many est-tokens since the last digest. */
function conductor_digest_regen_delta(): int {
    return (int) conductor_config_get('CONDUCTOR_DIGEST_REGEN_DELTA', '5000');
}

/** ntfy-style push endpoint (e.g. https://ntfy.sh/<topic>). Empty = notifications off. */
function conductor_push_url(): string {
    return conductor_config_get('CONDUCTOR_PUSH_URL', '');
}

/** Optional bearer token for the push endpoint. */
function conductor_push_token(): string {
    return conductor_config_get('CONDUCTOR_PUSH_TOKEN', '');
}

/** Public dashboard URL, used as the tap-through target on notifications. */
function conductor_dashboard_url(): string {
    return conductor_config_get('CONDUCTOR_DASHBOARD_URL', '');
}

/**
 * URL path the app is mounted under, e.g. "/conductor/" when reverse-proxied at
 * a sub-path (Tailscale serve --set-path). Emitted as a <base> tag so the app's
 * relative links resolve correctly regardless of trailing slash. Empty (default)
 * means mounted at the site root, no <base> tag. Always normalized to a leading
 * and trailing slash.
 */
function conductor_base_path(): string {
    $p = trim(conductor_config_get('CONDUCTOR_BASE_PATH', ''));
    if ($p === '' || $p === '/') return '';
    if ($p[0] !== '/') $p = '/' . $p;
    if (substr($p, -1) !== '/') $p .= '/';
    return $p;
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

/**
 * Run a command with argv-array (no shell interpolation). Optionally feed $stdin
 * and enforce a wall-clock $timeout (seconds, 0 = none). Returns
 * [exitCode, stdout, stderr]. On timeout: exit 124, whatever was captured, and a
 * "timeout" note on stderr.
 */
function run_cmd(array $argv, ?string $cwd = null, string $stdin = '', int $timeout = 0): array {
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($argv, $descriptors, $pipes, $cwd);
    if (!is_resource($proc)) return [1, '', 'failed to start process'];

    if ($stdin !== '') fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $deadline = $timeout > 0 ? microtime(true) + $timeout : 0;
    while (true) {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (!$status['running']) break;
        if ($deadline && microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]) . "\n[timeout]";
            fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
            return [124, $stdout, $stderr];
        }
        usleep(50000);
    }
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return [$exit, $stdout, $stderr];
}

/**
 * One-shot reasoning via a headless Haiku (or configured model). Feeds $stdin,
 * runs `claude --model <m> -p <prompt>` non-interactively, returns the trimmed
 * stdout text, or null on failure/timeout. The daemon does the file I/O itself,
 * so this call only reasons: no filesystem access, no permission prompt, no
 * session. Reuses the host's existing Claude Code auth (no API key needed).
 */
function daemon_reason(string $prompt, string $stdin = '', ?int $timeout = null): ?string {
    $model = conductor_config_get('CONDUCTOR_REASON_MODEL', 'haiku');
    $timeout ??= (int) conductor_config_get('CONDUCTOR_REASON_TIMEOUT', '120');
    [$exit, $out, $err] = run_cmd(
        ['claude', '--model', $model, '-p', $prompt],
        null,
        $stdin,
        $timeout
    );
    if ($exit !== 0) return null;
    $out = trim($out);
    return $out === '' ? null : $out;
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

/**
 * Classify a live agent's pane: 'attention' (pending permission prompt),
 * 'working' (mid-response), 'idle' (ready for input), or 'stopped' (no session).
 * All read-only.
 */
function agent_status(string $tmux): string {
    if (!tmux_session_exists($tmux)) return 'stopped';
    [$exit, $out] = run_cmd(['tmux', 'capture-pane', '-t', $tmux, '-p', '-S', '-40']);
    if ($exit !== 0) return 'stopped';
    if (str_contains($out, 'Esc to cancel')) return 'attention';
    if (str_contains($out, 'esc to interrupt')) return 'working';
    if (str_contains($out, 'for agents')) return 'idle';
    return 'working'; // starting up or an unrecognized transient state; not idle
}

/* --- transcript reading (token totals + live context size) ---------------- */

/** Map an absolute working directory to its Claude Code transcript dir name (/ -> -). */
function encode_cwd(string $absPath): string {
    return str_replace('/', '-', $absPath);
}

/** Absolute transcript dir for an agent, or null if it doesn't exist. */
function agent_transcript_dir(array $project, array $agent): ?string {
    $cwd = agent_dir($project, $agent);
    $dir = conductor_transcripts_dir() . '/' . encode_cwd($cwd);
    return is_dir($dir) ? $dir : null;
}

/** Pull the usage object out of one transcript JSONL line, or null. */
function _usage_from_line(string $line): ?array {
    $rec = json_decode($line, true);
    if (!is_array($rec)) return null;
    $msg = $rec['message'] ?? [];
    $u = (is_array($msg) ? ($msg['usage'] ?? null) : null) ?? ($rec['usage'] ?? null);
    return is_array($u) ? $u : null;
}

/**
 * Current context size (tokens) for an agent: the most recent transcript turn's
 * input + cache_read + cache_creation. 0 if no transcript. Reads only the newest
 * transcript file, tail-first, so it's cheap.
 */
function agent_context_size(array $project, array $agent): int {
    $dir = agent_transcript_dir($project, $agent);
    if ($dir === null) return 0;
    $files = glob($dir . '/*.jsonl');
    if (!$files) return 0;
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $lines = @file($files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $u = _usage_from_line($lines[$i]);
        if ($u === null) continue;
        $ctx = (int)($u['input_tokens'] ?? 0)
             + (int)($u['cache_read_input_tokens'] ?? 0)
             + (int)($u['cache_creation_input_tokens'] ?? 0);
        if ($ctx > 0) return $ctx;
    }
    return 0;
}

/**
 * Lifetime token totals for an agent across all its transcripts. Returns
 * ['input'=>, 'output'=>, 'cache_read'=>, 'cache_write'=>, 'sessions'=>].
 */
function agent_token_usage(array $project, array $agent): array {
    $totals = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'sessions' => 0];
    $dir = agent_transcript_dir($project, $agent);
    if ($dir === null) return $totals;
    foreach (glob($dir . '/*.jsonl') as $f) {
        $totals['sessions']++;
        foreach (@file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $u = _usage_from_line($line);
            if ($u === null) continue;
            $totals['input']      += (int)($u['input_tokens'] ?? 0);
            $totals['output']     += (int)($u['output_tokens'] ?? 0);
            $totals['cache_read'] += (int)($u['cache_read_input_tokens'] ?? 0);
            $totals['cache_write']+= (int)($u['cache_creation_input_tokens'] ?? 0);
        }
    }
    return $totals;
}

/**
 * Send a push notification via an ntfy-compatible endpoint (ntfy.sh or a
 * self-hosted ntfy). No-op (returns false) if CONDUCTOR_PUSH_URL is unset.
 * $opts: tags (comma string), priority (1-5), click (URL).
 */
function push_notify(string $title, string $message, array $opts = []): bool {
    $url = conductor_push_url();
    if ($url === '') return false;
    $argv = ['curl', '-s', '-m', '10', '-X', 'POST'];
    $argv[] = '-H'; $argv[] = 'Title: ' . str_replace(["\r", "\n"], ' ', $title);
    if (!empty($opts['tags']))     { $argv[] = '-H'; $argv[] = 'Tags: ' . $opts['tags']; }
    if (!empty($opts['priority'])) { $argv[] = '-H'; $argv[] = 'Priority: ' . $opts['priority']; }
    if (!empty($opts['click']))    { $argv[] = '-H'; $argv[] = 'Click: ' . $opts['click']; }
    $token = conductor_push_token();
    if ($token !== '')             { $argv[] = '-H'; $argv[] = 'Authorization: Bearer ' . $token; }
    $argv[] = '--data-binary'; $argv[] = $message;
    $argv[] = $url;
    [$exit] = run_cmd($argv, null, '', 12);
    return $exit === 0;
}

/* --- tiered memory: digest roll-up ---------------------------------------- */

/** Rough token estimate for a string (~4 chars/token). Good enough for gating. */
function est_tokens(string $s): int {
    return intdiv(strlen($s), 4);
}

function agent_memory_dir(array $project, array $agent): string {
    return agent_dir($project, $agent) . '/memory';
}

/**
 * Regenerate memory/DIGEST.md from the WHOLE memory/HISTORY.md (compress from
 * source, never digest-of-digest, so fidelity doesn't compound). Uses one-shot
 * Haiku for the reasoning; the daemon writes the file. Returns
 * ['ok'=>bool, 'src_tokens'=>int, 'reason'=>string].
 */
function regenerate_digest(array $project, array $agent): array {
    $mem = agent_memory_dir($project, $agent);
    $historyPath = $mem . '/HISTORY.md';
    if (!is_file($historyPath)) {
        return ['ok' => false, 'src_tokens' => 0, 'reason' => 'no history'];
    }
    $history = (string) file_get_contents($historyPath);
    $srcTokens = est_tokens($history);

    $prompt = "You maintain a condensed DIGEST of a project's session history. Below "
        . "(stdin) is the full chronological log of session handoffs, newest first. "
        . "Produce a tight digest that preserves the durable facts, the decisions and "
        . "their reasons, and the overall arc of the project, so someone can get "
        . "oriented without reading the full log. Drop redundant or superseded detail; "
        . "keep what still matters. Group by theme or period as makes sense. Output only "
        . "the digest as markdown, no preamble, no closing remarks.";

    $digest = daemon_reason($prompt, $history);
    if ($digest === null) {
        return ['ok' => false, 'src_tokens' => $srcTokens, 'reason' => 'reason failed'];
    }

    if (!is_dir($mem)) @mkdir($mem, 0775, true);
    $header = "<!-- Auto-generated by the Conductor daemon from HISTORY.md. Do not edit by hand;\n"
        . "     edits are overwritten on the next roll-up. Source of truth is HISTORY.md. -->\n\n";
    file_put_contents($mem . '/DIGEST.md', $header . $digest . "\n");
    return ['ok' => true, 'src_tokens' => $srcTokens, 'reason' => 'regenerated'];
}

/* --- shared wrap-down (used by the web handler and the daemon) ------------- */

/**
 * Send /wrap-up, wait (bounded) for SESSION.md to be (re)written, then kill the
 * session. Returns ['updated'=>bool, 'killed'=>bool, 'reason'=>string].
 */
function perform_wrapdown(array $project, array $agent, int $waitSeconds = 90): array {
    $tmux = $agent['tmux'];
    if (!tmux_session_exists($tmux)) {
        return ['updated' => false, 'killed' => false, 'reason' => 'not running'];
    }
    $sessionMd = agent_dir($project, $agent) . '/SESSION.md';
    $before = file_exists($sessionMd) ? filemtime($sessionMd) : null;

    run_cmd(['tmux', 'send-keys', '-t', $tmux, '/wrap-up', 'Enter']);

    $updated = false;
    $deadline = time() + $waitSeconds;
    while (time() < $deadline) {
        clearstatcache(true, $sessionMd);
        if (file_exists($sessionMd)) {
            $m = filemtime($sessionMd);
            if ($before === null || $m > $before) { $updated = true; break; }
        }
        sleep(1);
    }

    run_cmd(['tmux', 'kill-session', '-t', $tmux]);
    return ['updated' => $updated, 'killed' => true, 'reason' => $updated ? 'wrapped' : 'timeout'];
}

function render_header(string $title): void {
    $basePath = conductor_base_path();
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . ($basePath !== '' ? '<base href="' . h($basePath) . '">' : '')
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
        . '.status.idle{background:#14532d;color:#bbf7d0}'
        . '.status.working{background:#1e3a8a;color:#bfdbfe}'
        . '.status.attention{background:#78350f;color:#fed7aa}'
        . '.status.stopped{background:#3f3f46;color:#d4d4d8}'
        . 'details{margin-top:8px}details summary{cursor:pointer;color:#7ab8ff;font-size:0.9rem}'
        . 'details pre{white-space:pre-wrap;word-wrap:break-word;background:#141414;border:1px solid #333;'
        . 'border-radius:6px;padding:10px;font-size:0.82rem;color:#cfcfcf;max-height:340px;overflow:auto}'
        . '.meta{color:#888;font-size:0.8rem;margin-top:4px}'
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

/** Compact token count: 1234 -> "1.2k", 1500000 -> "1.5M". */
function fmt_tokens(int $n): string {
    if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
    if ($n >= 1000)    return round($n / 1000, 1) . 'k';
    return (string) $n;
}

/** Per-1M-token [input, output] USD rates by model alias. Cache read ~0.1x in, write ~1.25x in. */
function model_rates(string $model): array {
    $t = [
        'opus'   => [5.0, 25.0], 'fable' => [10.0, 50.0],
        'sonnet' => [3.0, 15.0], 'haiku' => [1.0, 5.0],
    ];
    foreach ($t as $k => $r) if (str_contains($model, $k)) return $r;
    return $t['sonnet'];
}

/** Rough USD cost estimate from aggregated usage and the agent's model. */
function estimate_cost(array $usage, string $model): float {
    [$in, $out] = model_rates($model);
    $inTokens = ($usage['input'] ?? 0)
              + ($usage['cache_write'] ?? 0) * 1.25
              + ($usage['cache_read'] ?? 0) * 0.1;
    return $inTokens / 1e6 * $in + ($usage['output'] ?? 0) / 1e6 * $out;
}

/** HTML status badge span for one of working/idle/attention/stopped. */
function status_badge(string $status): string {
    return '<span class="status ' . h($status) . '">' . h($status) . '</span>';
}

function build_claude_md(string $agentLabel, string $projectDescription, string $instructions): string {
    $md = "# {$agentLabel}\n\n"
        . "## Session handoff\n\n"
        . "This project keeps its memory in layers. On startup, read them in order and stop\n"
        . "as soon as you have enough to work, you rarely need all of them:\n\n"
        . "1. `SESSION.md` (project root): the latest handoff, where the last session left\n"
        . "   off and the next step. Always read this first. Usually enough.\n"
        . "2. `memory/DIGEST.md`: a condensed summary of older history. Read it if you need\n"
        . "   more background than SESSION.md gives.\n"
        . "3. `memory/HISTORY.md`: the full append-only log of every past handoff. Grep or\n"
        . "   skim it only when you need a specific detail the digest dropped.\n"
        . "4. `memory/archive/`: superseded versions and rotated-out history. Read only on\n"
        . "   explicit need.\n\n"
        . "Treat these as a briefing to get oriented quickly, not a script to follow blindly.\n\n"
        . "When Patch runs `/wrap-up`, write a fresh handoff to `SESSION.md` AND append it to\n"
        . "`memory/HISTORY.md`, following the instructions in that skill.\n\n"
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
