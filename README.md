# HDS Router

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

| Repo (shipped, generic)            | Per-server (you create, not tracked)        |
| ---------------------------------- | ------------------------------------------- |
| `lib.php`, `public/*.php`          | `/etc/default/hds-router` (config + secrets)|
| `skills/wrap-up/SKILL.md`          | `registry.json` (this server's projects)    |
| `registry.example.json` (template) |                                             |
| `hds-router.env.example` (template)|                                             |
| `hds-router.service.example`       |                                             |
| `spawn-finish.sh`, `switch-terminal-finish.sh` |                                 |

`registry.json` is `.gitignore`d on purpose: it's deployed state (real paths,
tmux names), not code.

## Deploy to a new server

1. **Clone the repo** somewhere the service user can read, e.g. `/opt/hds-router`.

2. **Config file.** Copy and fill in the template:
   ```bash
   sudo cp hds-router.env.example /etc/default/hds-router
   sudo nano /etc/default/hds-router        # set ROUTER_USER/PASS, ROUTER_BASE_DIR, etc.
   sudo chown <service-user>:<service-user> /etc/default/hds-router
   sudo chmod 600 /etc/default/hds-router
   ```
   Generate a strong password with `openssl rand -hex 8`.

   Config keys (all read at request time, no restart needed to change them):
   - `ROUTER_USER` / `ROUTER_PASS` — HTTP basic-auth for the UI.
   - `ROUTER_BASE_DIR` — where new projects get created.
   - `ROUTER_READ_SCOPE` — Read glob baked into new agents' settings.json
     (defaults to `ROUTER_BASE_DIR/**`).
   - `ROUTER_TTYD_SESSION` — tmux session ttyd attaches to (for the
     "Open terminal" deep-link).
   - `ROUTER_TMUX_PREFIX` — prefix for spawned tmux session names.

3. **Live registry.** Seed it from the template (or start empty, the app copes
   with a missing file and you add projects through the UI):
   ```bash
   cp registry.example.json registry.json
   nano registry.json                       # edit or clear out the example entry
   ```

4. **systemd service.** Copy the template, edit `User`/`Group`/paths/port, then:
   ```bash
   sudo cp hds-router.service.example /etc/systemd/system/hds-router.service
   sudo nano /etc/systemd/system/hds-router.service
   sudo systemctl daemon-reload
   sudo systemctl enable --now hds-router.service
   ```
   Run it as an unprivileged user that owns the agent directories and can drive
   `tmux`, `claude`, `git`, and `gh`. Not root.

5. **Private exposure.** The service binds to `127.0.0.1` only. Put it behind
   Tailscale (or another private reverse proxy). With Tailscale serve, alongside
   an existing ttyd mapping:
   ```bash
   sudo tailscale serve --bg --set-path /router 7682
   ```

## Requirements on the target server

`php` (8.x, CLI), `tmux`, `claude` (Claude Code CLI), `git`, and `gh` (only if
you want the "create GitHub repo" option to work; `gh auth status` must show a
token with `repo` scope).
