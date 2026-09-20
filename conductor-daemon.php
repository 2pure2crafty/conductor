<?php
declare(strict_types=1);
/*
 * Conductor daemon: an always-on watcher (analogous to underseer) that manages
 * agent sessions without a human in the loop. Run under systemd; see
 * conductor-daemon.service.example.
 *
 * Per cycle, for every registry agent that has opted in ("auto_wrapdown": true):
 *   - classify the pane (working / idle / attention / stopped)
 *   - working / attention / stopped  -> disarm (clear the idle timer). Never
 *     touch an agent mid-response or one waiting on a permission prompt.
 *   - idle: read the live context size from the transcript. Only once context
 *     crosses CONDUCTOR_WRAPDOWN_MIN_CONTEXT does the idle timer arm; if the
 *     agent then stays idle-and-over-threshold for CONDUCTOR_IDLE_TIMEOUT,
 *     wrap it down (/wrap-up -> SESSION.md -> kill). Below the threshold it is
 *     left alone: the token math doesn't justify wrapping a small context.
 *
 * Safety: dry-run is ON by default (CONDUCTOR_DAEMON_DRYRUN != "0"); it logs
 * what it would do and kills nothing. Even with dry-run off, only agents with
 * the per-agent opt-in flag are ever touched.
 */

require __DIR__ . '/lib.php';

/** Transient per-agent timer state (RuntimeDirectory under systemd). */
function state_file(): string {
    return rtrim(conductor_config_get('CONDUCTOR_STATE_DIR', '/run/conductor'), '/') . '/daemon-state.json';
}

/** Daemon log (LogsDirectory under systemd). */
function log_file(): string {
    return rtrim(conductor_config_get('CONDUCTOR_LOG_DIR', '/var/log/conductor'), '/') . '/daemon.log';
}

function daemon_log(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    $f = log_file();
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0755, true);
    @file_put_contents($f, $line, FILE_APPEND);
    echo $line; // also to stdout so `journalctl -u conductor-daemon` shows it
}

function load_state(): array {
    $f = state_file();
    if (!file_exists($f)) return [];
    $d = json_decode((string) file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function save_state(array $state): void {
    $f = state_file();
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0755, true);
    @file_put_contents($f, json_encode($state, JSON_PRETTY_PRINT));
}

function run_cycle(array &$state): void {
    $registry = load_registry();
    $idleTimeout = conductor_idle_timeout();
    $minContext  = conductor_wrapdown_min_context();
    $dryrun      = conductor_daemon_dryrun();
    $now         = time();
    $seen        = [];

    foreach ($registry['projects'] as $pSlug => $project) {
        foreach ($project['agents'] as $aSlug => $agent) {
            if (empty($agent['auto_wrapdown'])) continue; // opt-in only
            $key = $pSlug . '/' . $aSlug;
            $seen[$key] = true;

            $status = agent_status($agent['tmux']);
            if ($status !== 'idle') {
                // working / attention / stopped: disarm and move on.
                if (isset($state[$key])) unset($state[$key]);
                continue;
            }

            $ctx = agent_context_size($project, $agent);
            if ($minContext > 0 && $ctx < $minContext) {
                // Idle but small: not worth wrapping. Keep it disarmed.
                if (isset($state[$key])) unset($state[$key]);
                continue;
            }

            // Idle and over the size gate: arm the timer if not already.
            if (!isset($state[$key]['armed_at'])) {
                $state[$key] = ['armed_at' => $now, 'ctx' => $ctx];
                daemon_log("ARM $key idle, ctx=$ctx >= $minContext; timer started ({$idleTimeout}s)");
                continue;
            }

            $idleFor = $now - (int) $state[$key]['armed_at'];
            if ($idleFor < $idleTimeout) continue; // still counting down

            // Fire.
            if ($dryrun) {
                daemon_log("DRYRUN would wrap-down $key (idle {$idleFor}s, ctx=$ctx)");
                // keep armed so the intent stays visible; don't loop-spam
                continue;
            }
            daemon_log("WRAPDOWN $key (idle {$idleFor}s, ctx=$ctx)");
            $res = perform_wrapdown($project, $agent, 90);
            daemon_log("  result: " . json_encode($res));
            unset($state[$key]);
        }
    }

    // Drop state for agents no longer opted-in / present.
    foreach (array_keys($state) as $key) {
        if (empty($seen[$key])) unset($state[$key]);
    }
}

/* --- main loop ------------------------------------------------------------ */

$interval = conductor_daemon_interval();
daemon_log('conductor-daemon starting'
    . ' (interval=' . $interval . 's'
    . ', idle_timeout=' . conductor_idle_timeout() . 's'
    . ', min_context=' . conductor_wrapdown_min_context()
    . ', dryrun=' . (conductor_daemon_dryrun() ? 'on' : 'off') . ')');

$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
    pcntl_signal(SIGINT,  function () use (&$running) { $running = false; });
}

while ($running) {
    $state = load_state();
    try {
        run_cycle($state);
    } catch (\Throwable $e) {
        daemon_log('ERROR ' . $e->getMessage());
    }
    save_state($state);
    sleep($interval);
}

daemon_log('conductor-daemon stopping');
