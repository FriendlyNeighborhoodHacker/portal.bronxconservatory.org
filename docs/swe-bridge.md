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
| `lillyrosenthal` | FriendlyNeighborhoodHacker/lillyrosenthal.com | `swe-lillyrosenthal.org` | SWE - lillyrosenthal.org | that repo's `docs/engineer-agent.md` |

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

A thread is a conversation first. A top-level post by anyone with `builder`
or `shipper` opens a thread, and that is all: no branch, no worktree, no
preview. The agent answers from the main clone in Claude Code's plan mode,
which is read-only (file writes are refused by the tool; only the
allow-listed read-only commands run). A question gets an answer; a request
gets built or gets a question back, as the repository's prompt decides.
The brianrosenthal.org agent builds as soon as a request is specific; the
portal's agent proposes and waits for a yes, because its users are the client.

When the agent is going to change files it starts its reply with a line that
says `BUILD`, followed by what it will do. The bridge posts that, creates the
worktree on `feature/<slug>`, runs the repository's `preview-setup.sh`, posts
the preview link, and hands the agent the plan to implement in the worktree
(a fresh Claude Code session there; the conversation's session stays in the
main clone). From then on every message in the thread reaches the agent in
its worktree: it revises, the bridge refreshes the preview and attaches the
diff. The preview link therefore never appears before there is something to
look at.

Nobody has to tidy up. `MAX_WORKTREES` (3) is how many threads may have a
live worktree at once; when another build needs a slot the bridge **parks**
the build that has been quiet longest: it commits that thread's uncommitted
work on its branch as "WIP", removes the worktree and preview, and the next
message in that thread rebuilds the worktree from the branch with the work
uncommitted again, as if nothing happened. Conversations are never limited.

| Who says | Conversation phase | Build phase |
|---|---|---|
| anything else | answered read-only from the main clone | passed to the agent in its worktree; preview refreshed, diff attached |
| **status** | lists open threads: talking, building or parked, with preview links (top level or in a thread) | same |
| **discard** | thread closed, nothing to remove | worktree, branch and preview removed (optional; parking covers the rest) |
| **approve** (`shipper` only) | refused: nothing built yet | commit on the branch, fast-forward `main`, push, preview removed, any `Post-deploy:` block reposted |

If `main` moved under a thread and the merge conflicts, the bridge leaves the
conflict markers in the tree and asks for the agent to resolve them (by
editing files), then **approve** again. The bridge serializes git and agent
work, so two threads never run at once; previews themselves are served
concurrently.

## The `agent/` contract

| File in the repository | Required | Used how |
|---|---|---|
| `agent/agent-prompt.md` | yes | appended to Claude Code's system prompt on every turn: who the agent talks to and how much to check before building, the `BUILD` line that starts a change (the bridge labels each message `CONVERSATION phase` or `BUILD phase`), how to report (`Commit message:` line, optional `Post-deploy:` block last), rules. Write it the way you would brief a colleague, not as a form |
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
| `~/previews/<prefix>-<slug>` | the preview (symlink or built directory); `PREVIEW_PREFIX` is the account's provisioned prefix. Do not add a site tag like `-org`: Chrome treats `brianrosenthal-org-x` as a lookalike of brianrosenthal.org and shows a warning page instead of the preview |
| `~/.claude/projects/` | Claude Code transcripts, resumable per thread (shared by the account's sites) |

## Environment variables (`~/agents/<site>/agent.env`)

| Variable | Required | Meaning |
|---|---|---|
| `DISCORD_BOT_TOKEN`, `DISCORD_CHANNEL_ID` | yes | the bot and the one channel it watches |
| `ANTHROPIC_API_KEY` or `CLAUDE_CODE_OAUTH_TOKEN` | one of them | read by Claude Code. The API key meters usage separately on the Console org. The OAuth token (`claude setup-token` on a machine where you are logged in; it prints a `sk-ant-oat01-...` value once) runs the agent on your Claude subscription, sharing its limits with your own sessions. If both are set the API key wins, so remove it to use the subscription |
| `PREVIEW_DOMAIN`, `PREVIEW_PREFIX` | yes | `preview1.rosenthaltechnologies.com` and the account's provisioned prefix |
| `PREVIEW_MODE` | no | `tree` (default) or `build` |
| `CODE_MODEL` | no | default `claude-fable-5-1` (needs Claude Code ≥ 2.1.251) |
| `FALLBACK_MODEL` | no | models to try, in order, when `CODE_MODEL` is overloaded or not available to the account, e.g. `opus,sonnet` (Claude Code's `--fallback-model`); unset means fail the turn instead |
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

To update the bridge later, run its self-test with the installed venv first,
then copy it over the installed one and restart every agent:

```
DISCORD_CHANNEL_ID=1 PREVIEW_DOMAIN=x PREVIEW_PREFIX=x /usr/local/lib/swe-bridge/venv/bin/python /path/to/new/bridge.py --selftest
sudo install -m 644 /path/to/new/bridge.py /usr/local/lib/swe-bridge/bridge.py
sudo systemctl restart 'swe-*'
```

The self-test covers the bridge's own logic (slugs, status, the `BUILD`
marker, the report parsers, parking). The git and Discord plumbing is only
proven by a real thread, so after a bridge change run the first few items of
an agent's first-run checklist. If a message ever hits an unexpected error,
the bridge says so in the thread and the traceback is in `journalctl`.

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
9. **First run**: a question (answered, nothing created), then a trivial
   change (built, preview link, diff), **discard**; again with **approve**;
   a builder-only account is refused **approve**.

## Pitfalls common to every agent

| Symptom | Cause | Fix |
|---|---|---|
| unit exits with `PrivilegedIntentsRequired` | Server Members or Message Content intent off | Developer Portal → Bot → enable both; restart |
| `API Error: 400 ... does not support this model` | Claude Code older than 2.1.251 | as the account: `claude update`; restart the unit |
| `(agent error)` with `claude: not found` in the thread | `~/.local/bin` not on the unit's `PATH` | the `Environment=PATH=` line in the unit |
| the agent ignores the report format, or never starts a change (every reply is read-only) | `agent/agent-prompt.md` missing from the main clone or behind, or it lacks the `BUILD` line protocol | `git -C ~/agents/<site>/repo log -1 -- agent/`; the clone syncs on each new thread |
| the agent says it cannot write files | the thread is still in the conversation phase (no `BUILD` line was given) | tell it to go ahead; it replies `BUILD` and the bridge takes it from there |
| a thread's preview link is 404 and `status` says parked | the slot was given to a newer build | post anything in the thread; the worktree comes back from the branch |
| every tool call refused | `agent/settings.json` missing or invalid | `python3 -m json.tool ~/agents/<site>/repo/agent/settings.json` |
| "Couldn't create a working tree" with a hook error | `preview-setup.sh` failed (missing config, missing toolchain) | run it by hand in a worktree with `PREVIEW_ROOT` set and read the output |
| preview link 404 "No such preview" | `preview-account-add` not run for this account, or `PREVIEW_PREFIX` differs from the provisioned prefix | preview-hosts.md |
| `git push` rejected | deploy key read-only, or branch protection | GitHub → Deploy keys → Allow write access |
| `threads.json` stale after manual git work | a worktree was removed or committed by hand | `git -C ~/agents/<site>/repo worktree prune`; delete the thread's entry; restart the unit |
