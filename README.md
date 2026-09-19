# Conductor

A small private PHP app for spinning up and wrapping down Claude Code agent
sessions from a phone browser, instead of leaving them running 24/7. It shows a
dashboard of projects and agents, spins up an agent (existing or brand new) into
a tmux session running `claude`, auto-sends `/remote-control` so you continue in
the Claude mobile app, and wraps agents back down with `/wrap-up` (which writes a
`SESSION.md` handoff) before killing the session. Persistent *information*, not
persistent *agents*.

> **Note:** this repo is public temporarily. It will be set to private soon.
> See `LICENSE`, no usage rights are granted while it's public.

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
   - `CONDUCTOR_USER` / `CONDUCTOR_PASS` — HTTP basic-auth for the UI.
   - `CONDUCTOR_BASE_DIR` — where new projects get created.
   - `CONDUCTOR_READ_SCOPE` — Read glob baked into new agents' settings.json
     (defaults to `CONDUCTOR_BASE_DIR/**`).
   - `CONDUCTOR_TTYD_SESSION` — tmux session ttyd attaches to (for the
     "Open terminal" deep-link).
   - `CONDUCTOR_TMUX_PREFIX` — prefix for spawned tmux session names.

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
