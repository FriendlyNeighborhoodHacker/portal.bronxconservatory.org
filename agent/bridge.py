#!/usr/bin/env python3
"""
swe-bridge: a Discord <-> Claude Code bridge for one "software engineer" agent.

One Discord thread = one request = one git worktree on its own branch, served
at its own preview hostname  https://<PREVIEW_PREFIX>-<slug>.<PREVIEW_DOMAIN>/
(docs/preview-hosts.md in the portal.bronxconservatory.org repository). The
bridge relays messages between the thread and Claude Code, running as the
agent's Linux account inside that worktree, and performs every git mutation
itself, on a human's word. The model never commits, merges or pushes.

The same bridge serves any repository. A repository opts in with an `agent/`
directory holding:
  agent-prompt.md      the agent's role, workflow and rules (appended to the system prompt)
  settings.json        Claude Code permission allow/deny lists (with --permission-mode dontAsk,
                       anything not allowed is refused)
  preview-setup.sh     optional hook, run inside the worktree after it is created and after
                       every agent turn, with PREVIEW_ROOT set to the directory the preview
                       vhost serves. It copies config, builds, whatever makes the tree servable.

Layout, under the agent account's home:
  $AGENT_DIR/repo/            main clone: always on main, never served, never edited;
                              the prompt, settings and hook are read from its agent/ on every run
  $AGENT_DIR/worktrees/<slug> one worktree per in-flight thread, branch feature/<slug>
  $AGENT_DIR/threads.json, diffs/
  ~/previews/<prefix>-<slug>  what the vhost serves (<label>/www is the web root):
                              PREVIEW_MODE=tree   a symlink to the worktree (the repo's www/ is the web root)
                              PREVIEW_MODE=build  a real directory the hook fills

A thread has two phases. It starts in the CONVERSATION phase: no worktree, no
branch, no preview; the agent runs read-only (Claude Code plan mode) in the
main clone and talks until it understands the change and the person has
clearly said yes. It signals that by starting a reply with the line `BUILD`,
followed by the agreed plan. Only then does the bridge create the worktree,
branch and preview and start the BUILD phase, where the agent implements,
the preview follows the tree, and revisions continue until approve or discard.

Nobody has to tidy up. At most MAX_WORKTREES threads have a live worktree; when
another build needs one, the least recently active build is PARKED: its
uncommitted work is committed on its branch, the worktree and preview are
removed, and the next message in that thread rebuilds the worktree from the
branch and carries on.

Words the bridge understands, typed in a thread by a builder or shipper:
  status    list open threads, their phase and preview links (also at top level)
  discard   close the thread; in the build phase also delete worktree, branch and preview
  approve   commit, fast-forward main, push (shipper only); whatever deploys main does the rest
Anything else is a message to the agent. A new top-level post in the channel
opens a new thread (and nothing else).

Environment (from $AGENT_DIR/agent.env via the unit): DISCORD_BOT_TOKEN, DISCORD_CHANNEL_ID,
PREVIEW_DOMAIN, PREVIEW_PREFIX, and optionally AGENT_DIR, PREVIEW_MODE, CODE_MODEL,
FALLBACK_MODEL, MAX_BUDGET_USD, CLAUDE_TIMEOUT_SECONDS, MAX_WORKTREES, MAIN_BRANCH, ROLE_BUILDER, ROLE_SHIPPER.
"""
import asyncio
import json
import sys
import time
import traceback
import os
import random
import re
import shutil
import subprocess
from pathlib import Path

import discord

HOME = Path.home()
AGENT_DIR = Path(os.environ.get("AGENT_DIR", str(HOME / "agent")))
REPO = AGENT_DIR / "repo"
WORKTREES = AGENT_DIR / "worktrees"
DIFFS = AGENT_DIR / "diffs"
STATE = AGENT_DIR / "threads.json"
PREVIEWS = HOME / "previews"
PROMPT_FILE = REPO / "agent" / "agent-prompt.md"
SETTINGS_FILE = REPO / "agent" / "settings.json"
SETUP_HOOK = REPO / "agent" / "preview-setup.sh"

CHANNEL = int(os.environ["DISCORD_CHANNEL_ID"])
ROLE_BUILDER = os.environ.get("ROLE_BUILDER", "builder")
ROLE_SHIPPER = os.environ.get("ROLE_SHIPPER", "shipper")
MODEL = os.environ.get("CODE_MODEL", "claude-fable-5-1")
FALLBACK = os.environ.get("FALLBACK_MODEL", "")          # e.g. "opus,sonnet": tried in order when MODEL is overloaded or unavailable
PREVIEW_DOMAIN = os.environ["PREVIEW_DOMAIN"]                 # preview1.rosenthaltechnologies.com
PREVIEW_PREFIX = os.environ.get("PREVIEW_PREFIX") or os.environ.get("USER") or HOME.name
PREVIEW_MODE = os.environ.get("PREVIEW_MODE", "tree")         # tree | build
MAIN = os.environ.get("MAIN_BRANCH", "main")
MAX_WORKTREES = int(os.environ.get("MAX_WORKTREES", "3"))
MAX_BUDGET_USD = os.environ.get("MAX_BUDGET_USD", "15")
CLAUDE_TIMEOUT = int(os.environ.get("CLAUDE_TIMEOUT_SECONDS", "3600"))
HOOK_TIMEOUT = int(os.environ.get("HOOK_TIMEOUT_SECONDS", "900"))

WORDS = ("amber birch cedar coral delta ember grove heron iris juniper kestrel "
         "lumen maple nova opal pine quartz river sage tide umber vale willow").split()

state: dict = json.loads(STATE.read_text()) if STATE.exists() else {}
lock = asyncio.Lock()   # one git/Claude operation at a time


def save() -> None:
    STATE.write_text(json.dumps(state, indent=1))


def log(msg: str) -> None:
    print(msg, flush=True)


def sh(cmd, cwd, timeout: int = 900, env: dict | None = None):
    """Run a command; return (exit code, combined output)."""
    r = subprocess.run(cmd, cwd=str(cwd), capture_output=True, text=True, timeout=timeout,
                       env={**os.environ, **(env or {})})
    return r.returncode, (r.stdout + r.stderr).strip()


def has_role(member, name: str) -> bool:
    return any(r.name == name for r in getattr(member, "roles", []))


def label(slug: str) -> str:
    return f"{PREVIEW_PREFIX}-{slug}"


def tree(slug: str) -> Path:
    return WORKTREES / slug


def preview_dir(slug: str) -> Path:
    return PREVIEWS / label(slug)


def preview_url(slug: str) -> str:
    return f"https://{label(slug)}.{PREVIEW_DOMAIN}/"


def new_slug() -> str:
    while True:
        s = f"{random.choice(WORDS)}-{random.choice(WORDS)}"
        if not tree(s).exists() and not preview_dir(s).exists() and s not in {r.get("slug") for r in state.values()}:
            return s


# ---- git plumbing: the bridge's job, never the model's -----------------------------------------

def sync_main() -> str | None:
    """Bring the main clone up to date with origin. Returns an error message or None."""
    for cmd in (["git", "fetch", "--prune", "origin"],
                ["git", "merge", "--ff-only", f"origin/{MAIN}"]):
        rc, out = sh(cmd, REPO)
        if rc:
            return f"`{' '.join(cmd)}` failed in the main clone:\n```{out[-1200:]}```"
    return None


def run_hook(slug: str) -> tuple[int, str]:
    """Run the repository's agent/preview-setup.sh inside the worktree, if it has one."""
    if not SETUP_HOOK.exists():
        return 0, ""
    env = {"PREVIEW_ROOT": str(preview_dir(slug) / "www"), "PREVIEW_URL": preview_url(slug),
           "PREVIEW_LABEL": label(slug), "AGENT_DIR": str(AGENT_DIR), "SLUG": slug}
    try:
        return sh(["bash", str(SETUP_HOOK)], tree(slug), timeout=HOOK_TIMEOUT, env=env)
    except subprocess.TimeoutExpired:
        return 1, f"preview-setup.sh timed out after {HOOK_TIMEOUT}s"


def create_worktree(slug: str) -> str | None:
    err = sync_main()
    if err:
        return err
    WORKTREES.mkdir(parents=True, exist_ok=True)
    PREVIEWS.mkdir(exist_ok=True)
    rc, out = sh(["git", "worktree", "add", "-b", f"feature/{slug}", str(tree(slug)), MAIN], REPO)
    if rc:
        return f"`git worktree add` failed:\n```{out[-1200:]}```"
    if PREVIEW_MODE == "tree":
        preview_dir(slug).symlink_to(tree(slug))
    else:
        (preview_dir(slug) / "www").mkdir(parents=True, exist_ok=True)
    rc, out = run_hook(slug)
    if rc:
        return f"`agent/preview-setup.sh` failed:\n```{out[-1500:]}```"
    return None


def remove_worktree(slug: str) -> None:
    p = preview_dir(slug)
    if p.is_symlink():
        p.unlink()
    elif p.exists():
        shutil.rmtree(p, ignore_errors=True)
    sh(["git", "worktree", "remove", "--force", str(tree(slug))], REPO)
    sh(["git", "branch", "-D", f"feature/{slug}"], REPO)
    sh(["git", "worktree", "prune"], REPO)
    if tree(slug).exists():
        shutil.rmtree(tree(slug), ignore_errors=True)


def park_worktree(rec: dict) -> None:
    """Free a slot without losing anything: WIP goes onto the branch, worktree and preview go away."""
    slug = rec["slug"]
    wt = tree(slug)
    if wt.exists():
        if sh(["git", "status", "--porcelain"], wt)[1]:
            sh(["git", "add", "-A"], wt)
            sh(["git", "commit", "-q", "-m", "WIP (parked by the bridge)"], wt)
    p = preview_dir(slug)
    if p.is_symlink():
        p.unlink()
    elif p.exists():
        shutil.rmtree(p, ignore_errors=True)
    sh(["git", "worktree", "remove", "--force", str(wt)], REPO)
    sh(["git", "worktree", "prune"], REPO)
    rec["parked"] = True
    log(f"parked slug {slug} (branch feature/{slug} keeps the work)")


def unpark_worktree(rec: dict) -> str | None:
    """Recreate a parked thread's worktree from its branch, with the WIP uncommitted again."""
    slug = rec["slug"]
    wt = tree(slug)
    WORKTREES.mkdir(parents=True, exist_ok=True)
    rc, out = sh(["git", "worktree", "add", str(wt), f"feature/{slug}"], REPO)
    if rc:
        return f"`git worktree add` failed:\n```{out[-1200:]}```"
    _, base = sh(["git", "merge-base", MAIN, "HEAD"], wt)
    if base:
        sh(["git", "reset", "-q", "--mixed", base], wt)       # branch back to its base; WIP is plain edits again
    if PREVIEW_MODE == "tree":
        preview_dir(slug).symlink_to(wt)
    else:
        (preview_dir(slug) / "www").mkdir(parents=True, exist_ok=True)
    rc, out = run_hook(slug)
    if rc:
        return f"`agent/preview-setup.sh` failed:\n```{out[-1500:]}```"
    rec["parked"] = False
    log(f"unparked slug {slug}")
    return None


def make_room() -> None:
    """If every slot is taken, park the build thread that has been quiet longest."""
    live = [r for r in state.values() if r.get("phase") == "build" and not r.get("parked")]
    while len(live) >= MAX_WORKTREES:
        victim = min(live, key=lambda r: r.get("last", 0))
        park_worktree(victim)
        live.remove(victim)
    save()


def tree_is_dirty(wt: Path) -> bool:
    return sh(["git", "status", "--porcelain"], wt)[1] != ""


def branch_ahead_of_main(wt: Path) -> bool:
    return sh(["git", "rev-list", "--count", f"{MAIN}..HEAD"], wt)[1] not in ("", "0")


def conflicted_files(wt: Path) -> list[str]:
    out = sh(["git", "diff", "--name-only", "--diff-filter=U"], wt)[1]
    return [f for f in out.splitlines() if f]


# ---- Claude -----------------------------------------------------------------------------------

def run_claude(cwd: Path, prompt: str, session: str | None, phase: str = "build"):
    # phase "plan": Claude Code plan mode (read-only) in the main clone.
    # phase "build": dontAsk in the worktree; anything not allow-listed is refused.
    cmd = ["claude", "-p", prompt,
           "--output-format", "json",
           "--model", MODEL,
           "--permission-mode", "plan" if phase == "plan" else "dontAsk",
           "--settings", str(SETTINGS_FILE),           # agent/settings.json from the main clone
           "--append-system-prompt-file", str(PROMPT_FILE),
           "--max-budget-usd", MAX_BUDGET_USD]
    if FALLBACK:
        cmd += ["--fallback-model", FALLBACK]
    if session:
        cmd += ["--resume", session]
    try:
        r = subprocess.run(cmd, cwd=str(cwd), capture_output=True, text=True, timeout=CLAUDE_TIMEOUT,
                           stdin=subprocess.DEVNULL)      # claude waits 3 s for stdin otherwise
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
    DIFFS.mkdir(parents=True, exist_ok=True)
    p = DIFFS / f"{slug}.diff"
    p.write_text(diff + ("\n\n# New (untracked) files:\n" + new if new else ""))
    await thread.send(content=f"```{(stat or new)[:1700]}```",
                      file=discord.File(p, filename="changes.diff"), suppress_embeds=True)


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
    rc, _ = sh(["git", "merge-base", "--is-ancestor", MAIN, "HEAD"], wt)
    if rc:
        rc, _ = sh(["git", "rebase", MAIN], wt)
        if rc:
            sh(["git", "rebase", "--abort"], wt)
            rc, out = sh(["git", "merge", "--no-edit", MAIN], wt)
            if rc:
                files = "\n".join(conflicted_files(wt)) or out[-800:]
                await thread.send(
                    f"`{MAIN}` changed since this work started and the changes conflict. The conflict "
                    "markers are now in this thread's tree, in:\n```" + files + "```\n"
                    "Tell the agent to resolve them (it edits the files), then **approve** again.")
                return False

    rc, out = sh(["git", "merge", "--ff-only", f"feature/{slug}"], REPO)
    if rc:
        await thread.send(f"Couldn't fast-forward {MAIN}:\n```{out[-1200:]}```\nTry **approve** again.")
        return False
    rc, out = sh(["git", "push", "origin", MAIN], REPO)
    if rc:
        sh(["git", "reset", "--hard", f"origin/{MAIN}"], REPO)   # undo the local merge; branch keeps the commit
        await thread.send(f"Push failed (main clone rolled back; **approve** again once fixed):\n```{out[-1500:]}```")
        return False

    _, sha = sh(["git", "rev-parse", "--short", "HEAD"], REPO)
    text = (f"Merged `{sha}` into `{MAIN}` and pushed; whatever deploys `{MAIN}` is now running. "
            f"Preview {preview_url(slug)} removed.")
    if rec.get("post_deploy"):
        text += "\n\n**Still to do by hand (from the agent's last report):**\n" + rec["post_deploy"]
    remove_worktree(slug)
    await send(thread, text)
    return True


def build_marker(reply: str) -> str | None:
    # If the agent's reply starts with the BUILD line, return the plan that follows it.
    lines = reply.strip().splitlines()
    if lines and lines[0].strip().strip("*`#:").upper() == "BUILD":
        return "\n".join(lines[1:]).strip()
    return None


def building() -> int:
    return sum(1 for r in state.values() if r.get("phase") == "build" and not r.get("parked"))


def status_text() -> str:
    if not state:
        return "No threads open."
    lines = []
    for tid, r in state.items():
        if r.get("phase") == "build" and r.get("parked"):
            lines.append(f"- <#{tid}> parked (work kept on `feature/{r['slug']}`): {r.get('title', '')[:60]}")
        elif r.get("phase") == "build":
            lines.append(f"- <#{tid}> building: {preview_url(r['slug'])} {r.get('title', '')[:60]}")
        else:
            lines.append(f"- <#{tid}> talking: {r.get('title', '')[:60]}")
    return f"Open threads ({building()}/{MAX_WORKTREES} building):\n" + "\n".join(lines)


# ---- Discord ----------------------------------------------------------------------------------

intents = discord.Intents.default()
intents.message_content = True
intents.members = True
client = discord.Client(intents=intents)


@client.event
async def on_ready():
    log(f"bridge ready as {client.user}; channel {CHANNEL}; model {MODEL}; "
        f"previews {PREVIEW_PREFIX}-*.{PREVIEW_DOMAIN} ({PREVIEW_MODE}); repo {REPO}")


@client.event
async def on_message(m: discord.Message):
    try:
        await handle_message(m)
    except Exception:
        log(f"ERROR handling a message from {m.author}:\n{traceback.format_exc()}")
        try:
            where = m.channel if isinstance(m.channel, discord.Thread) else m
            await where.send(f"Something went wrong on my side ({type(sys.exc_info()[1]).__name__}: "
                             f"{sys.exc_info()[1]}). The details are in the bridge log; say that again "
                             f"once it is fixed.")
        except Exception:
            pass


async def handle_message(m: discord.Message):
    if m.author.bot:
        return
    in_thread = isinstance(m.channel, discord.Thread)
    if (m.channel.parent_id if in_thread else m.channel.id) != CHANNEL:
        return
    where = f"thread {m.channel.id}" if in_thread else "channel"
    if not (has_role(m.author, ROLE_BUILDER) or has_role(m.author, ROLE_SHIPPER)):
        log(f"[{where}] ignored message from {m.author} (no {ROLE_BUILDER}/{ROLE_SHIPPER} role): {m.content[:120]!r}")
        return
    text = m.content.strip()
    word = text.lower().strip("*_` .!")
    log(f"[{where}] {m.author}: {text[:300]!r}")

    if word == "status":
        await m.reply(status_text(), suppress_embeds=True)
        return
    if word == "approve" and not has_role(m.author, ROLE_SHIPPER):
        await m.reply("**approve** ships to production, so only a shipper can say it.")
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
        thread = await m.create_thread(name=text[:80] or "request")
        tid = str(thread.id)

    async with lock:
        rec = state.get(tid)
        if rec is not None:
            rec["last"] = time.time()
        if rec is None:                                   # new thread: conversation only, nothing created
            rec = state[tid] = {"phase": "plan", "plan_session": None, "session": None,
                                "title": text[:80], "author": m.author.display_name, "last": time.time()}
            save()
            log(f"[thread {tid}] new thread (conversation phase)")
            err = await asyncio.to_thread(sync_main)
            if err:
                log(f"[thread {tid}] main clone sync failed: {err[:200]!r}")
            text = (f"[Bridge: CONVERSATION phase, read-only in the main clone. Nothing has been built. "
                    f"Message from {m.author.display_name}:]\n\n{text}")
        elif rec.get("phase") == "plan":
            text = f"[Bridge: CONVERSATION phase. {m.author.display_name} in Discord:]\n\n{text}"
        else:
            text = f"[{m.author.display_name} in Discord]: {text}"
        if m.attachments:
            text += "\n\nAttachments (fetch with curl if useful):\n" + "\n".join(a.url for a in m.attachments)

        # ---- conversation phase: talk in the main clone until the agent says BUILD ----
        if rec.get("phase") == "plan":
            if word == "approve":
                await thread.send("Nothing has been built in this thread yet, so there is nothing to approve.")
                return
            if word == "discard":
                log(f"[thread {tid}] discard (conversation phase) by {m.author}")
                state.pop(tid, None)
                save()
                await thread.send("Closed; nothing was built.")
                return
            async with thread.typing():
                log(f"[thread {tid}] claude ({MODEL}) planning in {REPO}" + (" (resume)" if rec.get("plan_session") else " (new session)"))
                reply, rec["plan_session"] = await asyncio.to_thread(run_claude, REPO, text, rec.get("plan_session"), "plan")
                save()
                log(f"[thread {tid}] claude done, {len(reply)} chars")
            plan = build_marker(reply)
            if plan is None:
                await send(thread, reply)
                return
            # The agent is starting a change: make room if needed, create the worktree, build.
            slug = new_slug()
            await send(thread, plan)
            async with thread.typing():
                await asyncio.to_thread(make_room)
                err = await asyncio.to_thread(create_worktree, slug)
            if err:
                remove_worktree(slug)
                await thread.send(f"Couldn't create a working tree:\n{err}")
                return
            rec.update(phase="build", slug=slug, plan=plan, session=None)
            save()
            log(f"[thread {tid}] BUILD -> slug {slug}, branch feature/{slug}, {preview_url(slug)}")
            await thread.send(f"Building on branch `feature/{slug}`. Preview for this change: {preview_url(slug)}")
            text = (f"[Bridge: BUILD phase. This is your own worktree on branch feature/{slug}, served live at "
                    f"{preview_url(slug)} (the bridge refreshes it after each of your turns). The plan you "
                    f"agreed with {rec['author']} in Discord:]\n\n{plan}\n\n[Implement it now, run the "
                    f"checks, then report as your instructions say.]")

        # ---- build phase: the worktree ----
        slug = rec["slug"]
        wt = tree(slug)
        if rec.get("parked") and word != "discard":
            async with thread.typing():
                await asyncio.to_thread(make_room)
                err = await asyncio.to_thread(unpark_worktree, rec)
            save()
            if err:
                await thread.send(f"Couldn't bring this thread's tree back:\n{err}")
                return
        if word == "approve":
            log(f"[thread {tid}] approve by {m.author} for slug {slug}")
            if await approve(thread, rec, m.author):
                state.pop(tid, None)
                save()
            return
        if word == "discard":
            log(f"[thread {tid}] discard by {m.author} for slug {slug}")
            remove_worktree(slug)
            state.pop(tid, None)
            save()
            await thread.send(f"Discarded; branch `feature/{slug}` and {preview_url(slug)} are gone.")
            return

        async with thread.typing():
            log(f"[thread {tid}] claude ({MODEL}) building in {wt}" + (" (resume)" if rec.get("session") else " (new session)"))
            reply, rec["session"] = await asyncio.to_thread(run_claude, wt, text, rec.get("session"))
            log(f"[thread {tid}] claude done, {len(reply)} chars; session {rec.get('session')}")
            # Make the preview reflect the tree (copy config, build, ...). One automatic retry
            # through the agent if the hook fails, then the humans hear about it.
            rc, out = await asyncio.to_thread(run_hook, slug)
            log(f"[thread {tid}] preview-setup.sh " + ("ok" if rc == 0 else f"FAILED rc={rc}: {out[-300:]!r}"))
            if rc:
                fix = (f"[Bridge: after your changes, agent/preview-setup.sh failed while refreshing the "
                       f"preview. Fix the cause and report again.]\n```{out[-3000:]}```")
                more, rec["session"] = await asyncio.to_thread(run_claude, wt, fix, rec.get("session"))
                reply += "\n\n" + more
                rc, out = await asyncio.to_thread(run_hook, slug)
                if rc:
                    reply += f"\n\n**The preview could not be refreshed** (preview-setup.sh):\n```{out[-1200:]}```"
        c = extract_line(reply, "Commit message:")
        p = extract_block(reply, "Post-deploy:")
        if c:
            rec["commit"] = c[:72]
        if p:
            rec["post_deploy"] = p
        save()
        await send(thread, reply)
        await post_diff(thread, wt, slug)
        log(f"[thread {tid}] replied" + (f"; commit message {rec['commit']!r}" if rec.get("commit") else ""))


def selftest() -> None:
    """Exercise the logic that does not need Discord or git, against mixed thread records."""
    global state
    state = {
        "1": {"phase": "plan", "title": "What is 2+2?", "last": 1},
        "2": {"phase": "build", "slug": "amber-pine", "title": "reorder lessons", "last": 2},
        "3": {"phase": "build", "slug": "tide-quartz", "title": "old one", "last": 0, "parked": True},
    }
    assert new_slug() not in ("amber-pine", "tide-quartz")          # must tolerate records without a slug
    assert building() == 1, building()
    st = status_text()
    assert "talking" in st and "building" in st and "parked" in st, st
    assert build_marker("BUILD\nplan line") == "plan line"
    assert build_marker("**BUILD**\n\nplan") == "plan"
    assert build_marker("I would BUILD this") is None
    assert build_marker("4") is None
    assert extract_line("x\n`Commit message: Reorder lessons`\n", "Commit message:") == "Reorder lessons"
    assert extract_block("a\n**Post-deploy:**\n- step `x.sql`\n", "Post-deploy:").endswith("`x.sql`")
    live = [r for r in state.values() if r.get("phase") == "build" and not r.get("parked")]
    assert min(live, key=lambda r: r.get("last", 0))["slug"] == "amber-pine"
    print("selftest ok")


if __name__ == "__main__":
    if "--selftest" in sys.argv:
        selftest()
    else:
        client.run(os.environ["DISCORD_BOT_TOKEN"])
