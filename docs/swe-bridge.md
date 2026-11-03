# swe-bridge: the Discord engineer-agent bridge

One Python program, installed once per machine, that turns a repository into
a Discord-driven software engineer: a request in a channel becomes a thread,
a git worktree on its own branch and a live preview hostname; Claude Code
plans and builds inside that worktree; a human says **approve** and the
bridge commits, fast-forwards `main` and pushes, which triggers whatever
already deploys `main` for that repository. The model never runs git.

The source is [agent/bridge.py](../agent/bridge.py) in this repository.

The model to hold in mind: a **Linux account is an engineer**, and an
engineer can take on several **sites**. The account owns one Claude Code
install, one SSH identity for GitHub, one preview prefix
(`<account>-*.preview1...`) and one transcript history. Each site the
engineer works on is a repository with an `agent/` directory (the contract
below) and, on the machine, its own `~/agents/<site>/` holding that site's
main clone, worktrees, state and `agent.env`, run by its own systemd unit
`swe-<site>`. Adding a site to an engineer is adding a directory and a
unit; adding an engineer is adding an account (preview-hosts.md).

| Engineer (account) | Site (repository) | Unit | Discord bot | Guide |
|---|---|---|---|---|
| `brianrosenthal` | brianjrosenthal/brianrosenthal.org | `swe-brianrosenthal.org` | SWE - brianrosenthal.org | that repo's `docs/engineer-agent.md` |
| `bronxconservatory` | this repository | `swe-portal.bronxconservatory.org` | SWE - bronxconservatory | [engineer-agent.md](engineer-agent.md) |
| `lillyrosenthal` | (planned) | | | |

Discord side: one bot per site is the simplest (one token, one channel, one
unit). An engineer can instead present as one bot across its sites: create
one bot application, give its token to every one of that engineer's units,
and point each unit at its own channel. Each unit ignores every channel but
its own, so the processes do not interfere; Discord allows a bot several
gateway sessions.

Previews come from the machine-wide scheme in [preview-hosts.md](preview-hosts.md):
each account owns `<prefix>-*.preview1.rosenthaltechnologies.com`, and a
preview exists as soon as `~/previews/<prefix>-<slug>/www` does.

## What a thread looks like

| Who says | What happens |
|---|---|
| anyone with `builder` or `shipper`, top level | a thread, a branch `feature/<slug>`, a worktree, a preview URL, and the agent plans |
| **go** (or any discussion) | passed to the agent; it implements, the bridge refreshes the preview and attaches the diff |
| **status** | lists in-flight requests and their links (top level or in a thread) |
| **discard** | worktree, branch and preview removed |
| **approve** (`shipper` only) | commit on the branch, fast-forward `main`, push, preview removed, any `Post-deploy:` block reposted |

If `main` moved under a thread and the merge conflicts, the bridge leaves the
conflict markers in the tree and asks for the agent to resolve them (by
editing files), then **approve** again. `MAX_WORKTREES` (3) caps what is in
flight. The bridge serializes git and agent work, so two threads never run at
once; previews themselves are served concurrently.

## The `agent/` contract

| File in the repository | Required | Used how |
|---|---|---|
| `agent/agent-prompt.md` | yes | appended to Claude Code's system prompt on every turn: role, workflow (plan first, wait for go, implement, report with `Commit message:` and an optional `Post-deploy:` block last), rules |
| `agent/settings.json` | yes | Claude Code permissions, passed with `--settings`; with `--permission-mode dontAsk` anything not in `allow` is refused, so the `allow` list is the whole surface. `deny` git mutations regardless |
| `agent/preview-setup.sh` | no | run with `bash` inside the worktree right after it is created and after every agent turn, with `PREVIEW_ROOT` (the directory the vhost serves), `PREVIEW_URL`, `PREVIEW_LABEL`, `SLUG`, `AGENT_DIR` in the environment. Copy config, build assets, anything that makes the tree servable. Must be idempotent. If it fails after an agent turn the bridge hands the error to the agent once, then reports it |
| `agent/agent.env.example` | convention | template for `~/agents/<site>/agent.env` |
| `agent/swe-<site>.service` | convention | the systemd unit for this site's agent (the bridge path is the same for all; `User=`, `WorkingDirectory=` and `EnvironmentFile=` name the engineer and the site) |

All three are read from the agent's **main clone** on every run, so changing
them is a normal change to the repository, which can itself go through the
agent's approve flow.

Two preview modes, chosen by `PREVIEW_MODE` in `agent.env`:

- **`tree`**: the repository's `www/` is already a servable web root.
  `~/previews/<label>` is a symlink to the worktree; `PREVIEW_ROOT` is
  `<worktree>/www`. (The portal.)
- **`build`**: the web root must be assembled. `~/previews/<label>/www` is a
  real directory that `preview-setup.sh` fills from the worktree, and the
  worktree itself is never served. (brianrosenthal.org: `www/` copied,
  `astro/math` built to `math/`.)

## Layout under the engineer account

Per site, `AGENT_DIR` = `~/agents/<site>/` (the site's primary hostname,
e.g. `~/agents/brianrosenthal.org/`, `~/agents/portal.bronxconservatory.org/`):

| Path | Purpose |
|---|---|
| `~/agents/<site>/agent.env` | this site's secrets and knobs, `chmod 600`; sets `AGENT_DIR` itself |
| `~/agents/<site>/repo/` | main clone, always on `main`; synced before each new request and each approve; never edited, never served |
| `~/agents/<site>/worktrees/<slug>/` | one worktree per in-flight thread |
| `~/agents/<site>/threads.json`, `diffs/` | state, attached diffs |
| `~/previews/<prefix>-<slug>` | the preview (symlink or built directory); `PREVIEW_PREFIX` starts with the account's provisioned prefix and may add a site tag (`brianrosenthal-org`) so an engineer's sites stay apart |
| `~/.claude/projects/` | Claude Code transcripts, resumable per thread (shared by the account's sites) |

## Environment variables (`~/agents/<site>/agent.env`)

| Variable | Required | Meaning |
|---|---|---|
| `DISCORD_BOT_TOKEN`, `DISCORD_CHANNEL_ID` | yes | the bot and the one channel it watches |
| `ANTHROPIC_API_KEY` | yes | read by Claude Code |
| `PREVIEW_DOMAIN`, `PREVIEW_PREFIX` | yes | `preview1.rosenthaltechnologies.com` and the account's provisioned prefix |
| `PREVIEW_MODE` | no | `tree` (default) or `build` |
| `CODE_MODEL` | no | default `claude-fable-5-1` (needs Claude Code ≥ 2.1.251) |
| `MAX_BUDGET_USD` | no | spend cap per agent turn, default 15 |
| `CLAUDE_TIMEOUT_SECONDS`, `HOOK_TIMEOUT_SECONDS` | no | 3600 and 900 |
| `MAX_WORKTREES` | no | requests in flight at once, default 3 |
| `MAIN_BRANCH` | no | default `main` |
| `ROLE_BUILDER`, `ROLE_SHIPPER` | no | Discord role names, default `builder` / `shipper` |
| `AGENT_DIR` | yes in practice | `~/agents/<site>`; the template sets it (the bridge's fallback is `~/agent`) |

## Install the bridge (once per machine, as debian)

```
SRC=/home/bronxconservatory/deploy/dev-bronxconservatory.lillyrosenthal.org/agent   # any checkout of this repo
sudo apt install -y python3-venv
sudo install -d /usr/local/lib/swe-bridge
sudo install -m 644 $SRC/bridge.py /usr/local/lib/swe-bridge/bridge.py
sudo python3 -m venv /usr/local/lib/swe-bridge/venv
sudo /usr/local/lib/swe-bridge/venv/bin/pip install -U pip discord.py
/usr/local/lib/swe-bridge/venv/bin/python -c 'import discord; print(discord.__version__)'
```

To update the bridge later: copy the new `bridge.py` over the installed one
and `sudo systemctl restart 'swe-*'`.

## Add an agent (per repository and account)

The repository's own `docs/engineer-agent.md` fills in the specifics; the
shape is always:

1. **Account and preview prefix** (as debian): the account exists;
   `sudo preview-account-add <account>` (preview-hosts.md).
2. **Toolchain** (as the account, once per engineer): whatever the hooks
   need to build (Deno, Node, PHP), then Claude Code:
   `curl -fsSL https://claude.ai/install.sh | bash`, re-login, `claude --version`.
3. **Deploy key with write access** on the repository (the account's one
   SSH key, added to each of its sites' repositories), and the main clone:
   `git clone <ssh url> ~/agents/<site>/repo`, plus `git config user.name/email` in it.
4. **Secrets and config** the hook needs, under `~/agents/<site>/`.
5. **Smoke test by hand**: `git -C ~/agents/<site>/repo worktree add -b feature/smoke ~/agents/<site>/worktrees/smoke main`,
   make the preview the way the bridge would (symlink or run the hook with
   `PREVIEW_ROOT`), `curl` the preview URL, then remove the worktree, branch
   and preview.
6. **`~/agents/<site>/agent.env`** from the repo's template, then prove
   Claude Code works with the model and refuses a denied command.
7. **Discord** (BRIAN): a bot application per agent, both privileged intents
   on, invited with View Channels, Send Messages, Send Messages in Threads,
   Create Public Threads, Read Message History, Attach Files, Add Reactions;
   a channel the bot's role can see; its ID in `agent.env`.
8. **Service** (as debian): copy the repo's `agent/swe-<site>.service`
   into `/etc/systemd/system/`, `daemon-reload`, `enable --now`,
   `journalctl -fu swe-<site>` shows "bridge ready".
9. **First run** with a trivial change: plan, **go**, preview, **discard**;
   again with **approve**; a builder-only account is refused **approve**.

## Pitfalls common to every agent

| Symptom | Cause | Fix |
|---|---|---|
| unit exits with `PrivilegedIntentsRequired` | Server Members or Message Content intent off | Developer Portal → Bot → enable both; restart |
| `API Error: 400 ... does not support this model` | Claude Code older than 2.1.251 | as the account: `claude update`; restart the unit |
| `(agent error)` with `claude: not found` in the thread | `~/.local/bin` not on the unit's `PATH` | the `Environment=PATH=` line in the unit |
| the agent edits before **go**, or ignores the report format | `agent/agent-prompt.md` missing from the main clone, or the clone behind | `git -C ~/agents/<site>/repo log -1 -- agent/`; the clone syncs on each new request |
| every tool call refused | `agent/settings.json` missing or invalid | `python3 -m json.tool ~/agents/<site>/repo/agent/settings.json` |
| "Couldn't create a working tree" with a hook error | `preview-setup.sh` failed (missing config, missing toolchain) | run it by hand in a worktree with `PREVIEW_ROOT` set and read the output |
| preview link 404 "No such preview" | `preview-account-add` not run for this account, or `PREVIEW_PREFIX` differs from the provisioned prefix | preview-hosts.md |
| `git push` rejected | deploy key read-only, or branch protection | GitHub → Deploy keys → Allow write access |
| `threads.json` stale after manual git work | a worktree was removed or committed by hand | `git -C ~/agents/<site>/repo worktree prune`; delete the thread's entry; restart the unit |
