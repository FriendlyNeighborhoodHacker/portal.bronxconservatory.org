# agent/ — the Discord engineer agent

Everything that makes up "SWE - bronxconservatory", the Claude Code agent that
builds features for this portal from a Discord thread, shows each one at its
own preview hostname, and ships it to `main` (= production) on an explicit
**approve**.

The step-by-step setup, the server layout, the day-to-day commands and the
pitfalls are in [docs/engineer-agent.md](../docs/engineer-agent.md). This
directory holds the pieces that document installs:

| File | Installed where | Purpose |
|---|---|---|
| `bridge.py` | runs from the agent's main clone via systemd | Discord <-> Claude Code relay; owns the git worktrees (one per request, each at its own preview hostname) and every commit/merge/push |
| `agent-prompt.md` | read from the main clone on every run (`--append-system-prompt-file`) | The agent's role, workflow (plan, go, build, report, approve) and rules |
| `settings.json` | read from the main clone on every run (`--settings`) | Claude Code permission allow/deny lists; with `--permission-mode dontAsk` anything not allowed is refused |
| `agent.env.example` | `~bcmagent/.agent.env` | Secrets and knobs: API key, Discord token, model, preview URL, limits |
| `config.local.preview.php.example` | `~bcmagent/agents/bcm/config.local.php` | The portal config the bridge copies into every worktree (preview DB, dev strip, email redirect) |
| `bcm-bridge.service` | `/etc/systemd/system/` | Runs the bridge as `bcmagent` |
| `server/preview-account-add` | `/usr/local/sbin/` | Machine-wide helper: gives any Linux account its own preview prefix (pool + vhost), once per account; see docs/preview-hosts.md |
| `server/preview-zz-fallback.conf` | `/etc/apache2/sites-available/` | http→https redirect and the "no such preview" fallback for `*.preview1.rosenthaltechnologies.com` |
| `sudoers.d/bcmagent` | `/etc/sudoers.d/` | Full passwordless sudo for the account; the model's sudo is limited by `settings.json` |

Because the bridge, the prompt and the permissions are read from the main
clone, changing the agent is an ordinary change to this repository: edit here,
push to `main` (or have the agent do it through its own approve flow), then
`sudo systemctl restart bcm-bridge`. The prompt and permissions take effect on
the next agent turn without a restart.

`www/bin/migrate.php` (a CLI for the same `MigrationRunner` as Admin >
Maintenance > Migrations) was added alongside so the agent can apply its own
migrations to the preview database without a browser session.
