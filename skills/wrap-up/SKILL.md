---
name: wrap-up
description: Write a session handoff before ending the session (SESSION.md + append to memory/HISTORY.md)
disable-model-invocation: true
---

Wrapping up writes the handoff to two places: the latest-only `SESSION.md`, and
an append-only log at `memory/HISTORY.md`. Do both.

## 1. Write SESSION.md (overwrite)

Write (overwrite, don't append) `SESSION.md` in the project root: a handoff note
for whoever opens the next session here, written so they can pick up cold with no
other context.

Include, only where it applies:

- What was being worked on and why
- Decisions made this session and the reasoning behind them (the "why" is what
  saves the next session from re-litigating it)
- Current state of any in-progress work: what's done, what's half-done, what's
  untouched
- Open questions or anything blocked on Patch
- The concrete next step, stated plainly

Keep it tight: a few short paragraphs or a bulleted list, not a transcript.
Write for someone who wasn't in the room: no "as discussed above," no
unexplained shorthand. Overwrite the whole file each time; SESSION.md reflects
only the most recent handoff.

## 2. Append to memory/HISTORY.md (never overwrite)

Then append this same handoff to `memory/HISTORY.md` as a new dated entry, so the
project keeps a lossless record of every session instead of losing older context
to each overwrite. Create `memory/` and the file if they don't exist.

- **Prepend** the new entry at the TOP of the file (newest first), under a
  heading like `## <YYYY-MM-DD HH:MM> — <one-line title>`.
- Never edit or delete existing entries. This log is append-only and is the
  source of truth that condensed summaries are derived from.
- The entry can be the same content as SESSION.md, or slightly fuller if there is
  durable detail worth keeping that SESSION.md omits for brevity.

Do not touch `memory/DIGEST.md` or `memory/archive/` here; those are maintained
separately (by the Conductor daemon) from this log.

## 3. Close out

After writing both, tell Patch the session is ready to close.
