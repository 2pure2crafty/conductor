# Build review: what shipped, what surfaced, what's next

Written at the end of the roadmap build-out. For Patch to review.

## What shipped (all verified, committed, pushed)

1. **Append-only memory + reading ladder** (#11 step 1). `/wrap-up` now writes
   both `SESSION.md` (latest) and `memory/HISTORY.md` (append-only, newest
   first). Scaffolded CLAUDE.md documents the SESSION -> DIGEST -> HISTORY ->
   archive reading ladder.
2. **One-shot Haiku helper** (#10). `daemon_reason()`: headless
   `claude --model haiku -p`, pipes content in, returns text, daemon does file
   I/O. Reused for digests and version summaries.
3. **Digest roll-up** (#11 step 2). Daemon regenerates `memory/DIGEST.md` from
   the whole `HISTORY.md` (compress-from-source, never digest-of-digest), once
   history passes a threshold and grew by a delta since last. Amortized.
4. **Push notifications** (#1). Rising-edge ntfy alert when an agent hits a
   permission prompt. Off by default.
5. **Project-page UI** (#2/#4/#9). Status badges (working/idle/attention/
   stopped), live context size, lifetime token totals + estimated cost,
   SESSION/DIGEST previews.
6. **Peek, nudge, session link, PWA** (#5/#6/#8). Read-only pane peek, quick
   one-off message, "Open in Claude app" link (scraped from scrollback),
   installable web app manifest.
7. **Registry management, version summaries, audit log** (#7/#11 step 3).
   Manage page: toggle auto-wrap-down, delete agent/project (files kept), edit
   CLAUDE.md, generate a version summary via Haiku. Audit log + viewer.

The daemon runs live under systemd in **dry-run** (observe only) with **nothing
opted in**, so it is inert but ready. Nothing auto-kills until you arm it.

## Things that surfaced during the build (worth a decision)

- **Existing agents predate tiered memory.** overseer / ideas / planning /
  test-wrapup have the OLD overwrite-only wrap-up skill and hand-written
  CLAUDE.mds without the reading ladder. New agents get the new behavior
  automatically; existing ones need a small migration (re-copy
  `skills/wrap-up/SKILL.md` into each, add the ladder to their CLAUDE.md).
- **Dashboard token totals were deferred for performance.** Summing every
  agent's full transcript on each dashboard load would be slow (My Cards alone
  has a large transcript). Totals live on the project page instead. If you want
  them on the dashboard, cache them (write a per-agent total on wrap-down, or a
  short-TTL cache file) rather than reading transcripts inline.
- **Digest reads the whole HISTORY each roll-up.** Correct for fidelity
  (compress from source), and cheap on Haiku, but the per-roll-up cost grows
  with total history over a very long-lived project. If that ever bites, add
  HISTORY archive rotation plus a periodic full-rebuild (usually incremental,
  occasionally rebuild from the archived source to reset any drift).
- **Session-URL scraping** relies on the `/remote-control` URL still being in
  the last ~400 lines of scrollback. On a very long session it can scroll off.
  More robust: have `spawn-finish.sh` capture the URL once and stash it in the
  registry. Deferred.
- **Nudge was not live-fired** to avoid injecting a message into your active My
  Cards session. The handler is a near-copy of the proven keystroke path and
  lints clean; worth one real test when convenient.
- **Cost estimate uses the agent's registry model** for all historical turns.
  Transcripts record the model per turn, so a session that switched models is
  slightly off. Fine as an estimate; sum per-model from transcripts if you want
  precision.
- **Audit log dir.** `conductor.service` (web) has no `LogsDirectory`; audit
  writes rely on the daemon's `/var/log/conductor` existing. On a web-only
  deploy, audit silently no-ops. Add `LogsDirectory=conductor` to
  `conductor.service` too, or point `CONDUCTOR_AUDIT_LOG` somewhere writable.
- **Rename** of projects/agents is not implemented (it cascades to slug, tmux
  name, and paths). Delete + recreate for now.

## Recommended next steps (for you to pick from)

1. **Migrate the existing agents to tiered memory** (skill + CLAUDE.md ladder),
   so overseer/ideas/planning get the append-only history and digests too.
2. **Choose the push channel** (self-hosted ntfy vs an unguessable ntfy.sh
   topic + token) and set `CONDUCTOR_PUSH_URL` / `CONDUCTOR_DASHBOARD_URL`.
3. **Observe the daemon**, then when you trust it: flip `CONDUCTOR_DAEMON_DRYRUN=0`
   and opt specific agents into `auto_wrapdown` from the Manage page. (Heads up:
   My Cards sits at ~107k, over the 100k gate, so it would wrap on the next idle
   once armed.)
4. **Dashboard token totals with caching**, if you want spend visible at a
   glance.
5. **HISTORY archive rotation**, only if a project's history gets very large.
6. **Retire the always-on HDS-overseer** (the original goal): kill it and let
   Conductor spin it up on demand, now that wrap-down + tiered memory preserve
   its context cheaply.
