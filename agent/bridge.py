#!/usr/bin/env python3
"""
Discord bridge for the Bronx Conservatory engineer agent ("SWE - bronxconservatory").

One Discord thread = one request = one git worktree on its own branch, served
at its own preview hostname:  https://<PREVIEW_PREFIX>-<slug>.<PREVIEW_DOMAIN>/
The worktree lives at ~/previews/<PREVIEW_PREFIX>-<slug>/ and the account's
preview vhost (docs/preview-hosts.md) serves it the moment it exists, so
creating a preview is a plain `git worktree add` with no privileges.

The bridge relays messages between the thread and Claude Code (running as the
`bcmagent` account inside that worktree) and performs every git mutation
itself, on a human's word. The model never commits, merges or pushes.

Words the bridge understands, typed in a thread by a builder or shipper:
  status    list in-flight requests and their preview links (also at top level)
  discard   delete this thread's worktree and branch
  approve   commit, fast-forward main, push  ->  production deploy (shipper only)
Anything else is a message to the agent ("go" included). A new top-level post
in the channel opens a new thread and worktree.

Setup, environment variables and the server layout: docs/engineer-agent.md.
"""
import asyncio
import json
import os
import random
import re
import shutil
import subprocess
from pathlib import Path

import discord

HOME = Path.home()
BASE = HOME / "agents" / "bcm"
REPO = BASE / "repo"                 # main clone: always on main, never edited, never served
PREVIEWS = HOME / "previews"         # one worktree per in-flight thread; served by the vhost
CONFIG_SRC = BASE / "config.local.php"   # preview app config, copied into each tree
DIFFS = BASE / "diffs"
STATE = BASE / "threads.json"
PROMPT_FILE = REPO / "agent" / "agent-prompt.md"
SETTINGS_FILE = REPO / "agent" / "settings.json"

CHANNEL = int(os.environ["DISCORD_CHANNEL_ID"])
ROLE_BUILDER = os.environ.get("ROLE_BUILDER", "builder")
ROLE_SHIPPER = os.environ.get("ROLE_SHIPPER", "shipper")
MODEL = os.environ.get("CODE_MODEL", "claude-fable-5-1")
PREVIEW_DOMAIN = os.environ["PREVIEW_DOMAIN"]                 # preview1.rosenthaltechnologies.com
PREVIEW_PREFIX = os.environ.get("PREVIEW_PREFIX", os.environ.get("USER", "bcmagent"))
MAX_WORKTREES = int(os.environ.get("MAX_WORKTREES", "3"))
MAX_BUDGET_USD = os.environ.get("MAX_BUDGET_USD", "15")
CLAUDE_TIMEOUT = int(os.environ.get("CLAUDE_TIMEOUT_SECONDS", "3600"))

WORDS = ("amber birch cedar coral delta ember grove heron iris juniper kestrel "
         "lumen maple nova opal pine quartz river sage tide umber vale willow").split()

state: dict = json.loads(STATE.read_text()) if STATE.exists() else {}
lock = asyncio.Lock()   # one git/Claude operation at a time


def save() -> None:
    STATE.write_text(json.dumps(state, indent=1))


def log(msg: str) -> None:
    print(msg, flush=True)


def sh(cmd, cwd, timeout: int = 900):
    """Run a command; return (exit code, combined output)."""
    r = subprocess.run(cmd, cwd=str(cwd), capture_output=True, text=True, timeout=timeout)
    return r.returncode, (r.stdout + r.stderr).strip()


def has_role(member, name: str) -> bool:
    return any(r.name == name for r in getattr(member, "roles", []))


def label(slug: str) -> str:
    return f"{PREVIEW_PREFIX}-{slug}"


def tree(slug: str) -> Path:
    return PREVIEWS / label(slug)


def preview_url(slug: str) -> str:
    return f"https://{label(slug)}.{PREVIEW_DOMAIN}/"


def new_slug() -> str:
    while True:
        s = f"{random.choice(WORDS)}-{random.choice(WORDS)}"
        if not tree(s).exists() and s not in {r["slug"] for r in state.values()}:
            return s


# ---- git plumbing: the bridge's job, never the model's -----------------------------------------

def sync_main() -> str | None:
    """Bring the main clone up to date with origin/main. Returns an error message or None."""
    for cmd in (["git", "fetch", "--prune", "origin"],
                ["git", "merge", "--ff-only", "origin/main"]):
        rc, out = sh(cmd, REPO)
        if rc:
            return f"`{' '.join(cmd)}` failed in the main clone:\n```{out[-1200:]}```"
    return None


def install_config(wt: Path) -> None:
    if CONFIG_SRC.exists():
        shutil.copy(CONFIG_SRC, wt / "www" / "config.local.php")
        os.chmod(wt / "www" / "config.local.php", 0o600)
    (wt / "www" / "logs").mkdir(exist_ok=True)
    (wt / "www" / "cache").mkdir(exist_ok=True)


def create_worktree(slug: str) -> str | None:
    err = sync_main()
    if err:
        return err
    PREVIEWS.mkdir(exist_ok=True)
    rc, out = sh(["git", "worktree", "add", "-b", f"feature/{slug}", str(tree(slug)), "main"], REPO)
    if rc:
        return f"`git worktree add` failed:\n```{out[-1200:]}```"
    install_config(tree(slug))
    return None


def remove_worktree(slug: str) -> None:
    sh(["git", "worktree", "remove", "--force", str(tree(slug))], REPO)
    sh(["git", "branch", "-D", f"feature/{slug}"], REPO)
    sh(["git", "worktree", "prune"], REPO)
    if tree(slug).exists():
        shutil.rmtree(tree(slug), ignore_errors=True)


def tree_is_dirty(wt: Path) -> bool:
    return sh(["git", "status", "--porcelain"], wt)[1] != ""


def branch_ahead_of_main(wt: Path) -> bool:
    return sh(["git", "rev-list", "--count", "main..HEAD"], wt)[1] not in ("", "0")


def conflicted_files(wt: Path) -> list[str]:
    out = sh(["git", "diff", "--name-only", "--diff-filter=U"], wt)[1]
    return [f for f in out.splitlines() if f]


# ---- Claude -----------------------------------------------------------------------------------

def run_claude(wt: Path, prompt: str, session: str | None):
    cmd = ["claude", "-p", prompt,
           "--output-format", "json",
           "--model", MODEL,
           "--permission-mode", "dontAsk",             # anything not allow-listed is refused
           "--settings", str(SETTINGS_FILE),           # agent/settings.json from the main clone
           "--append-system-prompt-file", str(PROMPT_FILE),
           "--max-budget-usd", MAX_BUDGET_USD]
    if session:
        cmd += ["--resume", session]
    try:
        r = subprocess.run(cmd, cwd=str(wt), capture_output=True, text=True, timeout=CLAUDE_TIMEOUT)
    except subprocess.TimeoutExpired:
        return f"(agent timed out after {CLAUDE_TIMEOUT}s)", session
    try:
        j = json.loads(r.stdout)
        result = j.get("result") or "(empty reply)"
        if j.get("is_error"):
            result = f"(agent error) {result}"
        return result, j.get("session_id", session)
    except Exception:
        tail = (r.stderr or r.stdout)[-1500:]
        return f"(agent error)\n```{tail}```", session


def extract_line(reply: str, label_text: str) -> str:
    """The text after `label_text` on the line that starts with it (tolerating **bold** or `code`)."""
    m = re.search(rf"^[ \t`*]*{re.escape(label_text)}[`*]*[ \t]*(.*)$", reply, re.M)
    return m.group(1).strip().strip("`*").strip() if m else ""


def extract_block(reply: str, label_text: str) -> str:
    """Everything from the line that starts with `label_text` to the end of the reply (the prompt puts it last)."""
    m = re.search(rf"^[ \t`*]*{re.escape(label_text)}", reply, re.M)
    return reply[m.start():].strip() if m else ""


async def send(thread, text: str) -> None:
    while text:
        cut = text.rfind("\n", 0, 1900) if len(text) > 1900 else len(text)
        if cut <= 0:
            cut = 1900
        await thread.send(text[:cut])
        text = text[cut:].lstrip("\n")


async def post_diff(thread, wt: Path, slug: str) -> None:
    _, stat = sh(["git", "diff", "HEAD", "--stat"], wt)
    _, diff = sh(["git", "diff", "HEAD"], wt)
    _, new = sh(["git", "ls-files", "--others", "--exclude-standard"], wt)
    if not diff and not new:
        return                                        # plan-only turn: nothing to attach
    DIFFS.mkdir(exist_ok=True)
    p = DIFFS / f"{slug}.diff"
    p.write_text(diff + ("\n\n# New (untracked) files:\n" + new if new else ""))
    await thread.send(
        content=f"```{(stat or new)[:1600]}```\nPreview: {preview_url(slug)}\n"
                f"**approve** = merge to main and deploy to PRODUCTION · **discard** = throw away · "
                f"or keep talking to revise.",
        file=discord.File(p, filename="changes.diff"))


# ---- approve / discard ------------------------------------------------------------------------

async def approve(thread, rec: dict, approver) -> bool:
    slug = rec["slug"]
    wt = tree(slug)
    if not tree_is_dirty(wt) and not branch_ahead_of_main(wt):
        await thread.send("Nothing to approve: this thread's tree has no changes.")
        return False
    unresolved = conflicted_files(wt)
    if unresolved:
        await thread.send("Not approving: conflict markers are still unresolved in:\n```"
                          + "\n".join(unresolved) + "```\nAsk the agent to resolve them first.")
        return False

    if tree_is_dirty(wt):
        subject = rec.get("commit") or rec.get("title") or f"Change {slug} approved via Discord"
        body = (f"Requested in Discord by {rec.get('author', 'unknown')}; "
                f"approved by {approver.display_name}.")
        for cmd in (["git", "add", "-A"], ["git", "commit", "-m", subject, "-m", body]):
            rc, out = sh(cmd, wt)
            if rc:
                await thread.send(f"`{' '.join(cmd)}` failed:\n```{out[-1500:]}```")
                return False

    err = sync_main()
    if err:
        await thread.send(err)
        return False

    # Make sure the branch contains main, so the merge below is a fast-forward.
    rc, _ = sh(["git", "merge-base", "--is-ancestor", "main", "HEAD"], wt)
    if rc:
        rc, _ = sh(["git", "rebase", "main"], wt)
        if rc:
            sh(["git", "rebase", "--abort"], wt)
            rc, out = sh(["git", "merge", "--no-edit", "main"], wt)
            if rc:
                files = "\n".join(conflicted_files(wt)) or out[-800:]
                await thread.send(
                    "`main` changed since this work started and the changes conflict. The conflict "
                    "markers are now in this thread's tree, in:\n```" + files + "```\n"
                    "Tell the agent to resolve them (it edits the files), then **approve** again.")
                return False

    rc, out = sh(["git", "merge", "--ff-only", f"feature/{slug}"], REPO)
    if rc:
        await thread.send(f"Couldn't fast-forward main:\n```{out[-1200:]}```\nTry **approve** again.")
        return False
    rc, out = sh(["git", "push", "origin", "main"], REPO)
    if rc:
        sh(["git", "reset", "--hard", "origin/main"], REPO)   # undo the local merge; branch keeps the commit
        await thread.send(f"Push failed (main clone rolled back; **approve** again once fixed):\n```{out[-1500:]}```")
        return False

    _, sha = sh(["git", "rev-parse", "--short", "HEAD"], REPO)
    text = (f"Merged `{sha}` into `main` and pushed. The GitHub webhooks are deploying it to "
            f"production and to the dev site. Preview {preview_url(slug)} removed.")
    if rec.get("post_deploy"):
        text += "\n\n**Production needs this now (from the agent's last report):**\n" + rec["post_deploy"]
    remove_worktree(slug)
    await send(thread, text)
    return True


def status_text() -> str:
    if not state:
        return "No requests in flight."
    lines = [f"- <#{tid}> {preview_url(r['slug'])} {r.get('title', '')[:60]}" for tid, r in state.items()]
    return f"In flight ({len(state)}/{MAX_WORKTREES}):\n" + "\n".join(lines)


# ---- Discord ----------------------------------------------------------------------------------

intents = discord.Intents.default()
intents.message_content = True
intents.members = True
client = discord.Client(intents=intents)


@client.event
async def on_ready():
    log(f"bridge ready as {client.user}; channel {CHANNEL}; model {MODEL}; previews {PREVIEW_PREFIX}-*.{PREVIEW_DOMAIN}")


@client.event
async def on_message(m: discord.Message):
    if m.author.bot:
        return
    in_thread = isinstance(m.channel, discord.Thread)
    if (m.channel.parent_id if in_thread else m.channel.id) != CHANNEL:
        return
    if not (has_role(m.author, ROLE_BUILDER) or has_role(m.author, ROLE_SHIPPER)):
        return
    text = m.content.strip()
    word = text.lower().strip("*_` .!")

    if word == "status":
        await m.reply(status_text())
        return
    if word == "approve" and not has_role(m.author, ROLE_SHIPPER):
        await m.reply("**approve** deploys to production, so only a shipper can say it.")
        return

    if in_thread:
        thread = m.channel
        tid = str(thread.id)
        if tid not in state:
            await m.reply("This thread is finished (approved or discarded). Start a new request in the channel.")
            return
    else:
        if word in ("approve", "discard", "go"):
            await m.reply("Say that inside a request's thread.")
            return
        if len(state) >= MAX_WORKTREES:
            await m.reply(f"Already {MAX_WORKTREES} requests in flight; **approve** or **discard** one first.\n" + status_text())
            return
        thread = await m.create_thread(name=text[:80] or "request")
        tid = str(thread.id)

    async with lock:
        rec = state.get(tid)
        if rec is None:                                   # new request: new branch + worktree + hostname
            slug = new_slug()
            err = create_worktree(slug)
            if err:
                await thread.send(f"Couldn't create a working tree:\n{err}")
                return
            rec = state[tid] = {"slug": slug, "session": None, "title": text[:80],
                                "author": m.author.display_name}
            save()
            await thread.send(f"Working on branch `feature/{slug}`. Preview for this request: {preview_url(slug)}")
            text = (f"[Bridge: this thread's working tree is on branch feature/{slug} and is served live at "
                    f"{preview_url(slug)} . Request from {m.author.display_name} follows.]\n\n{text}")
        else:
            text = f"[{m.author.display_name} in Discord]: {text}"
        slug = rec["slug"]
        wt = tree(slug)

        if word == "approve":
            if await approve(thread, rec, m.author):
                state.pop(tid, None)
                save()
            return
        if word == "discard":
            remove_worktree(slug)
            state.pop(tid, None)
            save()
            await thread.send(f"Discarded; branch `feature/{slug}` and {preview_url(slug)} are gone.")
            return

        if m.attachments:
            text += "\n\nAttachments (fetch with curl if useful):\n" + "\n".join(a.url for a in m.attachments)
        async with thread.typing():
            reply, rec["session"] = await asyncio.to_thread(run_claude, wt, text, rec.get("session"))
        c = extract_line(reply, "Commit message:")
        p = extract_block(reply, "Post-deploy:")
        if c:
            rec["commit"] = c[:72]
        if p:
            rec["post_deploy"] = p
        save()
        await send(thread, reply)
        await post_diff(thread, wt, slug)
        await thread.send(f"{m.author.mention} done.")


client.run(os.environ["DISCORD_BOT_TOKEN"])
