# Conductor

Conductor spins Claude Code agent sessions up and down on demand, driven from a
phone browser. A small private PHP app.

> **Note:** this repo is public temporarily. It will be set to private soon.
> See `LICENSE`, no usage rights are granted while it's public.

## The problem it solves

Leaving a Claude Code session running 24/7 so it's there when you want it burns
tokens while it sits idle, and it chains you to the terminal. Conductor replaces
"always-on agents" with "on-demand agents": you open a small private web app from
your phone, spin up the agent you want, and it hands you off to the Claude mobile
app (via `/remote-control`) to do the actual work. When you're done, `/wrap-up`
writes a `SESSION.md` handoff and the session is killed. The next spin-up reads
that handoff and picks up where you left off. Persistent *information*, not
persistent *agents*.

## What it does

- Dashboard of your projects and agents, each with live/stopped status.
- A "Needs attention" section that surfaces any agent stuck on a Claude Code
  permission prompt, with Approve / Deny buttons, plus a deep-link that switches
  your terminal to that session if you'd rather handle it by hand.
- Spin up an existing agent as-is, a new agent inside an existing project, or a
  whole new project (folder, `git init`, optional GitHub repo), choosing the
  model and permission mode per agent.
- On spin-up the agent introduces itself: it states its name and role and, if a
  `SESSION.md` exists, summarizes where things stand and the next step.
- Wrap down: send `/wrap-up`, wait for the `SESSION.md` handoff to be written,
  then kill the tmux session.

## Why it's called Conductor

The layer that runs a single agent (its tool loop, context, and permissions) is
that agent's "harness"; Claude Code is the harness. Conductor sits one level
above the harness: it starts, watches, and stops many harness sessions without
doing the work itself, the way a conductor directs an orchestra rather than
playing an instrument. It was briefly called "HDS Router," but it doesn't route
anything and it's no longer HDS-specific.

## What's in the repo vs. what's per-server

The code here is generic. Everything server-specific lives outside the repo, in
a config file and a live registry that you create at deploy time:

| Repo (shipped, generic)                         | Per-server (you create, not tracked)         |
| ----------------------------------------------- | -------------------------------------------- |
| `lib.php`, `public/*.php`                        | `/etc/default/conductor` (config + secrets)  |
| `skills/wrap-up/SKILL.md`                        | `registry.json` (this server's projects)     |
| `registry.example.json` (template)              |                                              |
| `conductor.env.example` (template)              |                                              |
| `conductor.service.example`                     |                                              |
| `spawn-finish.sh`, `switch-terminal-finish.sh`  |                                              |

`registry.json` is `.gitignore`d on purpose: it's deployed state (real paths,
tmux names), not code.

## Deploy to a new server

1. **Clone the repo** somewhere the service user can read, e.g. `/opt/conductor`.

2. **Config file.** Copy and fill in the template:
   ```bash
   sudo cp conductor.env.example /etc/default/conductor
   sudo nano /etc/default/conductor        # set CONDUCTOR_USER/PASS, CONDUCTOR_BASE_DIR, etc.
   sudo chown <service-user>:<service-user> /etc/default/conductor
   sudo chmod 600 /etc/default/conductor
   ```
   Generate a strong password with `openssl rand -hex 8`.

   Config keys (all read at request time, no restart needed to change them):
   - `CONDUCTOR_USER` / `CONDUCTOR_PASS`: HTTP basic-auth for the UI.
   - `CONDUCTOR_BASE_DIR`: where new projects get created.
   - `CONDUCTOR_READ_SCOPE`: Read glob baked into new agents' settings.json
     (defaults to `CONDUCTOR_BASE_DIR/**`).
   - `CONDUCTOR_TTYD_SESSION`: tmux session ttyd attaches to (for the
     "Open terminal" deep-link).
   - `CONDUCTOR_TMUX_PREFIX`: prefix for spawned tmux session names.

3. **Live registry.** Seed it from the template (or start empty, the app copes
   with a missing file and you add projects through the UI):
   ```bash
   cp registry.example.json registry.json
   nano registry.json                       # edit or clear out the example entry
   ```

4. **systemd service.** Copy the template, edit `User`/`Group`/paths/port, then:
   ```bash
   sudo cp conductor.service.example /etc/systemd/system/conductor.service
   sudo nano /etc/systemd/system/conductor.service
   sudo systemctl daemon-reload
   sudo systemctl enable --now conductor.service
   ```
   Run it as an unprivileged user that owns the agent directories and can drive
   `tmux`, `claude`, `git`, and `gh`. Not root.

5. **Private exposure.** The service binds to `127.0.0.1` only. Put it behind
   Tailscale (or another private reverse proxy). Give it its own HTTPS port at
   the root, so its relative links work cleanly:
   ```bash
   sudo tailscale serve --bg --https=8443 http://127.0.0.1:7682
   # -> https://<node>.<tailnet>.ts.net:8443/
   ```
   Do **not** use `tailscale serve --set-path /conductor`: that registers an
   exact path, not a subtree, so only the bare `/conductor` URL resolves and
   every sub-page (`/conductor/project.php`, etc.) 404s. If you must mount under
   a sub-path, use a reverse proxy that forwards the whole subtree (nginx
   `location /conductor/ { proxy_pass ...; }`) and set `CONDUCTOR_BASE_PATH`.

## Requirements on the target server

`php` (8.x, CLI), `tmux`, `claude` (Claude Code CLI), `git`, and `gh` (only if
you want the "create GitHub repo" option to work; `gh auth status` must show a
token with `repo` scope).
