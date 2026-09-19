---
name: wrap-up
description: Write a session handoff to SESSION.md before ending the session
disable-model-invocation: true
---

Write (overwrite, don't append) `SESSION.md` in the project root: a handoff
note for whoever opens the next session in this directory, written so they
can pick up cold with no other context.

Include, only where it applies:

- What was being worked on and why
- Decisions made in this session and the reasoning behind them (not just
  the decision itself — the "why" is what saves the next session from
  re-litigating it)
- Current state of any in-progress work: what's done, what's half-done,
  what's untouched
- Open questions or anything blocked on Patch
- The concrete next step, stated plainly

Keep it tight. A few short paragraphs or a bulleted list, not a transcript
and not a play-by-play of every tool call. Write for someone who wasn't in
the room: no "as discussed above," no unexplained shorthand.

Overwrite the whole file each time. SESSION.md reflects only the most recent
handoff, not a running log.

After writing it, tell Patch the session is ready to close.
