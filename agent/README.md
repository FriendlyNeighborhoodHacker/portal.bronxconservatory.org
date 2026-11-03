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
| `bridge.py` | `/usr/local/lib/swe-bridge/` once per machine (docs/swe-bridge.md) | **swe-bridge**, the generic Discord <-> Claude Code relay every agent on the machine runs; owns the git worktrees (one per request, each at its own preview hostname) and every commit/merge/push |
| `agent-prompt.md` | read from the main clone on every run (`--append-system-prompt-file`) | This agent's role, workflow (plan, go, build, report, approve) and rules |
| `settings.json` | read from the main clone on every run (`--settings`) | Claude Code permission allow/deny lists; with `--permission-mode dontAsk` anything not allowed is refused |
| `preview-setup.sh` | run from the main clone inside each worktree | Makes a worktree servable: copies the preview config in, creates `www/logs` and `www/cache` |
| `agent.env.example` | `~bronxconservatory/.agent.env` | Secrets and knobs: API key, Discord token, model, preview prefix, limits |
| `config.local.preview.php.example` | `~bronxconservatory/agent/config.local.php` | The portal config the hook copies into every worktree (preview DB, dev strip, email redirect) |
| `swe-bronxconservatory.service` | `/etc/systemd/system/` | Runs the machine-wide bridge as `bronxconservatory` with this agent's environment |
| `server/preview-account-add` | `/usr/local/sbin/` | Machine-wide helper: gives any Linux account its own preview prefix (pool + vhost), once per account; see docs/preview-hosts.md |
| `server/preview-zz-fallback.conf` | `/etc/apache2/sites-available/` | http→https redirect and the "no such preview" fallback for `*.preview1.rosenthaltechnologies.com` |
| `sudoers.d/bronxconservatory` | `/etc/sudoers.d/` | Optional: full passwordless sudo for the account (which is also the dev site's); the model's sudo is limited by `settings.json` |

Because the prompt, permissions and hook are read from the main clone,
changing the agent is an ordinary change to this repository: edit here, push
to `main` (or have the agent do it through its own approve flow), and the next
agent turn uses it. The bridge itself is shared by every agent on the machine
and is updated by copying `bridge.py` into place (docs/swe-bridge.md).

`www/bin/migrate.php` (a CLI for the same `MigrationRunner` as Admin >
Maintenance > Migrations) was added alongside so the agent can apply its own
migrations to the preview database without a browser session.
