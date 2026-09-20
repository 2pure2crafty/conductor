# Conductor roadmap

Specs for the next round of features. Each entry: what it does, how it fits the
current code, rough effort, and dependencies. Ordered by value, not build order.

Current state (shipped): dashboard, project profile pages, spin up
(existing agent / new agent / new project), per-agent model + permission mode,
"Needs attention" permission-prompt handling (approve / deny / open-terminal),
wrap-down (`/wrap-up` then kill), self-introducing spin-up.

The four highest-value additions are, in order: push notifications (#1),
SESSION.md preview (#2), auto-wrap-down on idle (#3), and live status
badges (#4). Token tracking (#9) is a strong candidate too, since the same
transcript data it reads also drives the auto-wrap-down refinement.

---

## 1. Push notifications on "needs attention"

**Problem.** The "Needs attention" section only helps if Patch happens to open
the dashboard. An agent can sit blocked on a permission prompt for hours
unseen. Conductor is currently pull-only; for a phone-first tool it should push.

**What.** When an agent transitions into a pending-prompt state (or finishes and
goes idle), send a push to Patch's phone with the agent name and the question,
and a tap-through to the dashboard.

**How.** A small poller, separate from the web app, since the PHP app only runs
during a request. Add `conductor-watch.php` (or a bash loop) run by a systemd
timer every ~30s, or a `conductor-watch.service` that loops with a sleep. It
calls `find_pending_prompts()` (already in `lib.php`), diffs against the last
seen set (a small state file, e.g. `/run/conductor/notified.json`), and on a
newly-pending agent POSTs to a push service. Only notify on the rising edge, so
a prompt that stays pending doesn't re-ping every cycle.

Delivery channel (pick one, config key `CONDUCTOR_PUSH_URL` + token):
- **ntfy.sh**: self-hostable or free tier, a single `curl -d` to a topic URL,
  no account needed on the server side. Simplest.
- **Pushover**: paid one-time, very reliable, richer phone UI.
- **Claude app**: not a general push channel; skip for this.

Recommend ntfy: cheapest to wire, self-hostable behind the same trust boundary.

**Effort.** Medium. The detection already exists; the new parts are the poller,
the dedupe state file, and the curl-to-ntfy. ~1 evening.

**Dependencies.** None in-repo. A phone with the ntfy (or Pushover) app installed.

---

## 2. SESSION.md preview on the dashboard / project page

**Problem.** The whole model is "persistent information, not persistent agents,"
but right now the only way to read that information is to spin the agent back
up, which spends the tokens the wrap-down was meant to save. The handoff should
be readable for free.

**What.** On each project page (and optionally the dashboard card), show each
agent's last SESSION.md: a collapsed snippet with a "last active" timestamp
(the file mtime), tap to expand the full handoff. Read-only.

**How.** In `project.php`, for each agent resolve its dir via `agent_dir()`,
read `<dir>/SESSION.md` if present, show `filemtime()` as "last active" and the
first ~200 chars as a snippet with a toggle for the rest. All server-side, no
new endpoint. Escape with `h()`. Guard with `path_is_within()` as defense in
depth. If no SESSION.md, show "no handoff yet."

**Effort.** Low. Pure read + render in an existing page. ~1-2 hours.

**Dependencies.** None. Best paired with #3, since auto-wrap-down is what keeps
these handoffs fresh.

---

## 3. Auto-wrap-down on idle (the cache play)

**Problem / goal.** Keep token spend down by not carrying large, stale sessions,
and by wrapping down before the prompt cache expires anyway. Patch's target:
**4 minutes of idle**, then auto-`/wrap-up` and kill.

### Why 4 minutes: how the cache actually works

Accurate numbers (from the Anthropic prompt-caching reference):

- The prompt cache has a **5-minute TTL by default**. It is a **sliding
  window**: every request that hits the cached prefix refreshes the 5 minutes.
  After 5 minutes with no request, the entry expires. (There is also a 1-hour
  TTL option at higher write cost, not relevant here.)
- **Cache read** costs about **0.1x** the base input token price.
- **Cache write** costs about **1.25x** base input (5-minute TTL).
- So within a warm window, each turn re-reads the whole accumulated context at
  0.1x: cheap. Once the cache expires, the next turn pays ~1.25x to rewrite the
  entire (by-now-large) context from cold: expensive.

The nuance worth being precise about: a truly idle session spends nothing while
it sits there (no request in flight). The cost is not "idling burns tokens" per
turn; the cost is **(a)** context that keeps growing across a long session, so
every turn re-reads more, and **(b)** paying the full cold cache-write to
resurrect a big stale context after the 5-minute window lapses.

**So 4 minutes is well chosen.** It fires just under the 5-minute TTL, i.e. just
before the cache would die on its own. Wrapping down then:
- doesn't throw away a still-warm cache (you were about to lose it anyway),
- converts an expensive-to-resume large context into a compact SESSION.md,
- means the next spin-up starts with a tiny prompt (just the handoff), so its
  first turn is cheap instead of paying to rebuild yesterday's context.

Net: you trade a large cold cache-write later for a small SESSION.md write now
plus a small cold start next time. For intermittent phone-driven use, that is
the cheaper path, and it is the behavior Conductor was built around.

### The size gate: wrap-down is a safety valve, not a routine timer

Idle time alone is the wrong trigger. Wrapping down is not free: it spends a
summary turn (reads the whole context once, at ~0.1x while the cache is still
warm, plus a few hundred output tokens to write SESSION.md), and the next
spin-up pays a small fresh read of the handoff. The savings from avoiding a
future cold resume scale with context size, roughly **1.15x times the context**
(the 1.25x cold write you'd have paid, minus the 0.1x warm read the wrap-up
turn costs).

So the size of the context decides whether it is worth it:

- **Small context** (spun up, did two or three small things, on the order of
  10-20k tokens): the absolute saving is tiny, and you have paid a lossy summary
  turn and thrown away the live conversation for almost nothing. Better to leave
  it: let the cache lapse and eat a cheap small cold resume if Patch returns, or
  just let the session sit. Continuity preserved, near-zero cost either way.
- **Large / unwieldy context** (a long working session, hundreds of k of
  tokens): a cold resume is genuinely expensive, so snapshotting to a compact
  SESSION.md and resetting is a real win.

**Therefore auto-wrap-down gates on BOTH idle time AND context size.** It fires
only when the agent has been idle past the timeout AND its context has grown past
a threshold. It is a safety stop for when the context becomes unwieldy, not a
punishment for pausing four minutes on a small session.

Measuring live context size is exact, not estimated: Claude Code logs every turn
to `~/.claude/projects/<encoded-cwd>/*.jsonl`, and the latest assistant turn's
`input_tokens + cache_read_input_tokens + cache_creation_input_tokens` is the
current context size. The watcher reads the transcript tail to get it. (This is
the same transcript data feature #9 uses for token totals, so build them
together.)

### What

Auto-wrap-down fires when **both** conditions hold, per agent:
- idle past `CONDUCTOR_IDLE_TIMEOUT` (default 240s, `0` = disabled), AND
- current context size past `CONDUCTOR_WRAPDOWN_MIN_CONTEXT` (default ~100k
  tokens, `0` = no size gate).

Then run the existing wrap-down: `/wrap-up`, wait for SESSION.md, kill. Below the
size threshold, an idle agent is left alone (optionally just killed without a
wrap-up if Patch prefers, but the safe default is leave-running).

The 100k default is a starting point to tune: at Sonnet input pricing a cold
resume of 100k costs on the order of a few tens of cents, which is roughly where
"snapshot and reset" starts clearly beating "resume." Lower it if you want to
reclaim sooner, raise it if you value continuity more.

### How

Fold this into the same poller as #1 (one watcher process). Track per-agent
"idle since" timestamps in the state file. Each cycle:
- if an agent is working or has a pending prompt, clear its idle timer;
- if idle past `CONDUCTOR_IDLE_TIMEOUT`, read its context size from the
  transcript tail (see #9); if it also exceeds `CONDUCTOR_WRAPDOWN_MIN_CONTEXT`,
  trigger wrap-down (reuse the exact logic in `wrapdown.php`, factored into a
  `lib.php` helper so both the web handler and the watcher call the same code).

Guardrails:
- **Never auto-kill an agent with a pending permission prompt.** That would
  destroy the in-progress write. Only wrap down clean-idle agents.
- **Respect the size gate.** Do not wrap down a small-context agent on idle
  alone; the token math does not justify it (see the size-gate section above).
- Detecting "idle" must not itself count as activity; capture-pane and reading
  the transcript are both read-only, so they are safe.
- Make it opt-in per agent (a registry flag, e.g. `"auto_wrapdown": true`), so a
  long-running job Patch wants left alone can set it false.
- Log every auto-wrap-down (see #7 audit log) and optionally push a note (#1).

**Effort.** Medium. Refactor `wrapdown.php` into a shared helper, add the timer
bookkeeping and the transcript-size read to the watcher. ~half a day, most of it
testing the "don't kill a busy/prompting/small-context agent" edge cases.

**Dependencies.** The watcher (#1), idle detection (#4), and the transcript
reader (#9). Build those first; this rides on them.

---

## 4. Live status badges

**Problem.** No at-a-glance view of what each agent is doing.

**What.** Per-agent badge: **working** / **idle** / **needs attention** /
**stopped**, on the dashboard and project pages.

**How.** Extends detection already in `lib.php`. For a live tmux session,
capture-pane once and classify:
- pane shows the permission dialog ("Esc to cancel") -> needs attention
  (reuse `detect_pending_prompt()`);
- pane shows "esc to interrupt" -> working;
- pane shows "for agents" (the idle status bar, present in every permission
  mode) -> idle;
- no tmux session -> stopped.

Add a `agent_status(tmux)` helper returning one of those four; render as a
colored `.status` badge (the CSS classes already exist, add `working` /
`attention` variants). One capture-pane per live agent per page load; fine at
this scale.

**Effort.** Low. One helper plus a bit of CSS and markup. ~2 hours.

**Dependencies.** None. Feeds #3 (idle detection) and #1 (what to notify on).

---

## 5. Read-only pane peek

**What.** On an agent's page, show the last ~20 lines of its live tmux pane,
read-only, so Patch can check progress from the phone without attaching.

**How.** New `peek.php?project=&agent=`, auth + registry lookup, then
`tmux capture-pane -p -S -20`, output inside a `<pre>` (escaped). Optionally a
meta-refresh every few seconds for a near-live view. Never send keys from here;
strictly read-only.

**Effort.** Low. ~1-2 hours.

**Dependencies.** None. Nice complement to #4 (tap a "working" badge to peek).

---

## 6. Quick-nudge box

**What.** A one-line text field on the agent page that sends a single message
into the session (e.g. "keep going", "focus on the tests") without opening the
full Claude app.

**How.** New `nudge.php` POST handler: auth, registry lookup, confirm the
session exists, then `tmux send-keys -t <tmux> "<message>"` then `Enter`, with
the same keystroke-spacing care learned in `respond.php` / `spawn-finish.sh`
(type text, pause, Enter). Slugify/escape is not enough here since it is free
text going to send-keys, but send-keys with a single argv string is safe from
shell injection (no shell); still cap length and strip control characters.

**Effort.** Low-medium. ~2-3 hours, mostly getting submission timing reliable
(same class of issue already solved twice).

**Dependencies.** None.

---

## 7. Manage the registry from the UI

**What.** Rename / archive / delete projects and agents, and edit an existing
agent's CLAUDE.md, from the web UI. Today creation works but any later change
means hand-editing `registry.json` or using the terminal. Also: an audit log of
spin-ups / wrap-downs / auto-kills with timestamps.

**How.**
- Edit/rename/delete: new handlers using `update_registry()` (already
  lock-safe). Deleting an agent should confirm, kill any live session, and
  optionally leave the on-disk dir in place (safer default: registry entry
  removed, files kept).
- Edit CLAUDE.md: a textarea pre-filled from `<agent-dir>/CLAUDE.md`, written
  back via a guarded write (`path_is_within()`), only when the session is not
  live (avoid editing under a running agent).
- Audit log: append a line to `<repo>/../conductor-state/audit.log` (outside the
  repo) on each spin-up / wrap-down / auto-kill, shown on a simple log page.

**Effort.** Medium. Several small handlers plus confirm dialogs. ~half a day.

**Dependencies.** None, but destructive actions want the confirm-UI pattern.

---

## 8. Tappable session link + "Add to Home Screen"

**What.** Two small phone-experience wins:
- Surface the `/remote-control` session URL as a tap-through link on the
  spawn-confirmation page (and the agent page), so Patch opens the exact session
  in the Claude app in one tap instead of hunting for it.
- A PWA manifest so Conductor installs to the home screen and opens
  full-screen like a native app.

**How.**
- Session link: after spin-up, `/remote-control` prints a
  `https://claude.ai/code/session_...` URL in the pane. The spawn-finish script
  (or the watcher) can capture-pane, regex out that URL, and stash it in the
  registry under the agent (`"session_url"`). `render_spawn_confirmation()` and
  the agent page then render it as a link. Refresh it on each spin-up.
- PWA: add `manifest.webmanifest` (name, icons, `display: standalone`,
  `start_url`) and a `<link rel="manifest">` in `render_header()`. Optionally a
  tiny service worker, not required for "add to home screen."

**Effort.** Low. ~2-3 hours total.

**Dependencies.** None. The session-link half pairs naturally with the watcher.

---

## 9. Token tracking (per agent / per project)

**Problem.** During a live prompt you see a token readout, but there is no way to
go back and see tokens spent per project over time, or to see a running total at
the project level.

**What.** Both a historical read and a live-ish readout:
- **Historical:** total tokens (and an estimated cost) per agent and per project,
  across all past sessions. Shown on the project page and summed on the
  dashboard.
- **Live:** the current session's running total and current context size, shown
  on the agent page while it is up.

**This is confirmed feasible, not hypothetical.** Claude Code logs every turn to
`~/.claude/projects/<encoded-cwd>/*.jsonl`, where `<encoded-cwd>` is the agent's
working directory with `/` replaced by `-` (e.g. `/var/www/my-cards` ->
`-var-www-my-cards`). Each assistant record carries a `usage` object with
`input_tokens`, `output_tokens`, `cache_read_input_tokens`,
`cache_creation_input_tokens`, and the `model`. Verified present on this server.

**How.**
- Add `agent_token_usage(project, agent)` to `lib.php`: resolve the agent's cwd,
  map it to the encoded transcript dir under a configurable transcripts root
  (`CONDUCTOR_TRANSCRIPTS_DIR`, default `/home/patch/.claude/projects`), read
  every `*.jsonl`, and sum the four usage fields (grouped by model, since
  pricing differs).
- `project_token_usage(project)` sums across the project's agents.
- Cost estimate: multiply by per-model input/output rates from a small config
  map (cache-read billed ~0.1x input, cache-write ~1.25x input). Keep the rate
  table in one place so it is easy to update; label the figure "estimated."
- **Current context size** for the live/auto-wrap-down use: the last assistant
  record's `input + cache_read + cache_creation`. Expose as
  `agent_context_size(project, agent)`; the watcher (#3) reuses it.
- Rendering: a compact "tokens: X in / Y out (~$Z)" line per agent on the
  project page, a project total on the dashboard, and current context size on
  the agent page.

Caveats to note in the UI: totals cover only transcripts still on disk (Claude
Code retention), and cost is an estimate from a local rate table, not billing
truth. Reading transcripts is read-only and safe.

**Effort.** Medium. The parse-and-sum is straightforward; the fiddly parts are
the cwd->encoded-dir mapping (handle agents whose `path` is `.` vs a subdir) and
keeping the pricing table current. ~half a day.

**Dependencies.** None to read; shares the transcript-parsing code with #3's
context-size gate, so build the transcript reader once and use it for both.

---

## Build-order suggestion

1. **Transcript reader** (#9 core): parse `~/.claude/projects/*/*.jsonl` for
   usage totals and current context size. Shared by #9 and #3.
2. **Watcher process** (shared infra for #1 and #3) + **status detection** (#4).
   One `conductor-watch` service that classifies every live agent each cycle.
3. **Push on needs-attention** (#1) on top of the watcher.
4. **Token tracking UI** (#9) + **SESSION.md preview** (#2) + **status badges**
   (#4 render) in the pages.
5. **Auto-wrap-down** (#3): refactor `wrapdown.php` into a shared helper; gate on
   idle time AND context size (from the #9 reader); "never kill a
   prompting/working/small-context agent" guardrails; per-agent opt-in.
6. The smaller UI wins (#5 peek, #6 nudge, #8 links + PWA) as time allows.
7. **Registry management + audit log** (#7) last; most surface area.

## Repo discipline (keep the pushed code generic)

The repo ships generic code and `*.example` templates only. Everything
server-specific stays out of git:

- `registry.json` (live projects/agents) is gitignored.
- Secrets and real config live in `/etc/default/conductor`, outside the repo.
- The systemd unit lives in `/etc/systemd/system/`, outside the repo; the repo
  carries `conductor.service.example`.
- Agent working dirs are created under `CONDUCTOR_BASE_DIR`
  (`/var/www/hdp/agents` here), siblings of the repo, never inside it.

New per-deployment state introduced by these features (watcher dedupe file,
audit log, idle timers) must follow the same rule: write it **outside** the repo
(e.g. `/run/conductor/` for transient state, `/var/lib/conductor/` or a
`conductor-state/` sibling dir for the audit log), never inside the working
tree. The hardened `.gitignore` is a backstop (`conductor.env`, `.env`,
`*.local`, `SESSION.md`, `agent-*.jsonl`), not the primary mechanism; the
primary mechanism is keeping state out of the tree in the first place. Ship a
`*.example` template for any new config file.
