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
| `agent-prompt.md` | read from the main clone on every run (`--append-system-prompt-file`) | This agent's role (client-facing: propose, wait for a yes, then `BUILD`), how to report, rules |
| `settings.json` | read from the main clone on every run (`--settings`) | Claude Code permission allow/deny lists; with `--permission-mode dontAsk` anything not allowed is refused |
| `preview-setup.sh` | run from the main clone inside each worktree | Makes a worktree servable: copies the preview config in, creates `www/logs` and `www/cache` |
| `agent.env.example` | `~bronxconservatory/agents/portal.bronxconservatory.org/agent.env` | Secrets and knobs: auth, Discord token, model, preview prefix, limits |
| `config.local.preview.php.example` | `~bronxconservatory/agents/portal.bronxconservatory.org/config.local.php` | The portal config the hook copies into every worktree (preview DB, dev strip, email redirect) |
| `swe-portal.bronxconservatory.org.service` | `/etc/systemd/system/` | Runs the machine-wide bridge as `bronxconservatory` with this agent's environment |
| `sudoers.d/bronxconservatory` | `/etc/sudoers.d/` | Optional: full passwordless sudo for the account (which is also the dev site's); the model's sudo is limited by `settings.json` |

The bridge itself, the preview-host scheme and the server helpers live in
the `dreamhost.dev.agent` repository (`bridge/bridge.py`, `docs/`,
`server/`), together with templates for new agents. Because the prompt,
permissions and hook are read from the main clone, changing this agent is an
ordinary change to this repository: edit here, push to `main` (or have the
agent do it through its own approve flow), and the next thread uses it.

`www/bin/migrate.php` (a CLI for the same `MigrationRunner` as Admin >
Maintenance > Migrations) was added alongside so the agent can apply its own
migrations to the preview database without a browser session.
