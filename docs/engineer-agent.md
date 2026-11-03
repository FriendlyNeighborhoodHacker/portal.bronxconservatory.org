# Engineer agent ("SWE - bronxconservatory")

A Claude Code agent that builds features for this portal from Discord. You
describe a change in `#bronxconservatory-features`; the agent plans, builds it
in its own git worktree, shows it at its own preview hostname, and, only when
a shipper says **approve**, the bridge commits to `main`, which the GitHub
webhooks deploy to production and to the dev site. The agent then hands over
whatever production needs by hand (a migration, a config constant).

This is the setup guide: follow it top to bottom on the dev VPS. The files it
installs live in [agent/](../agent/) (see its README for the map). Previews
rely on the machine-wide scheme in [preview-hosts.md](preview-hosts.md), which
is set up once and then serves every account on the box, the two other
engineer agents and student developers included. The design came from the
"Engineer Agent (VS Code handoff)" brainstorm; the differences are listed at
the end.

## The workflow you get

1. **Request.** A builder or shipper posts in `#bronxconservatory-features`:
   *"Add a 'download receipt' button to the invoices page."* The bridge opens
   a thread, syncs the main clone, creates a worktree on `feature/<slug>` at
   `~/previews/bcmagent-<slug>/`, which is live at once as
   `https://bcmagent-<slug>.preview1.rosenthaltechnologies.com`, and hands the
   request to the agent.
2. **Plan.** The agent reads the code and replies with questions or a short
   plan (files, approach, risks, whether a migration is needed). It edits nothing.
3. **Go.** You reply **go** (or discuss for a few rounds).
4. **Build.** The agent implements, runs `php -l` and the PHPUnit suite,
   applies any migration to the preview database, and replies with what
   changed, a `Preview:` link, what to check, a `Commit message:` line and, if
   needed, a `Post-deploy:` block. The bridge attaches the diff.
5. **Review.** Look at the preview. Revisions continue in the thread. Several
   requests can be open at once, each at its own hostname.
6. **Approve** (shipper only). The bridge commits on the branch, fast-forwards
   `main`, pushes, removes the worktree and its hostname, and reposts the
   `Post-deploy:` block so it is not lost. **discard** throws the branch away
   instead. **status** (in a thread or at the top level) lists what is open.

## Layout on the VPS

Machine: the self-managed Debian 13 VPS at 208.113.209.171 (DreamHost), the
one that already hosts `dev-bronxconservatory.lillyrosenthal.org`. Sudo
account `debian`.

| Path (account `bcmagent`) | Purpose |
|---|---|
| `~/.agent.env` | secrets and knobs (template: `agent/agent.env.example`) |
| `~/.my.cnf` | preview DB credentials for the `mysql`/`mariadb` CLI |
| `~/agents/venv/` | Python venv with discord.py |
| `~/agents/bcm/repo/` | **main clone**, always on `main`, never served, never edited; the bridge syncs it, creates worktrees from it, merges and pushes from it, and reads the bridge, prompt and permissions from its `agent/` |
| `~/previews/bcmagent-<slug>/` | one worktree per in-flight Discord thread, branch `feature/<slug>`, served as `https://bcmagent-<slug>.preview1.rosenthaltechnologies.com` |
| `~/agents/bcm/config.local.php` | preview portal config; copied into each worktree's `www/` |
| `~/agents/bcm/threads.json`, `diffs/` | bridge state and attached diffs |

| Host / service | |
|---|---|
| Previews | `bcmagent-*.preview1.rosenthaltechnologies.com`, vhost `preview-bcmagent.conf` created by `preview-account-add` |
| PHP | php-fpm 8.4 pool `bcmagent` (socket `/run/php/php8.4-fpm-bcmagent.sock`), runs as `bcmagent` |
| Database | local MariaDB on the VPS: `bcmportalpreview` (previews) and `bronx_music_conservatory_test` (PHPUnit), user `bcmagent` |
| Bridge | systemd `bcm-bridge.service`, runs `repo/agent/bridge.py` as `bcmagent` |
| Discord | bot **SWE - bronxconservatory**, channel `#bronxconservatory-features`, roles `builder` / `shipper` |

Why a local MariaDB rather than the DreamHost MySQL the dev site uses: the
PHPUnit bootstrap drops and recreates its own test database, which needs
`CREATE DATABASE`, and a shared-hosting MySQL user cannot do that. A local
server also keeps the agent's experiments off the dev site's data. All of the
agent's previews share the one preview database, so two open requests that
both add migrations can collide; the agent's plan says when a migration is
involved, and the first-run checklist exercises it.

Every step names the account. From `debian`, `sudo -iu bcmagent` gives a shell
as the agent account; `exit` returns. Steps marked **BRIAN** need a browser
session or a secret.

## Setup

### 0. Prerequisite: the preview scheme

Do [preview-hosts.md](preview-hosts.md) "One-time machine setup" first if it
has not been done: the Cloudflare move, the wildcard certificate, the fallback
vhost and the `preview-account-add` helper. Check:

```
sudo certbot certificates | grep -A2 preview1
ls -l /usr/local/sbin/preview-account-add /etc/apache2/sites-enabled/preview-zz-fallback.conf
```

### 1. Accounts and packages (as debian)

**1. Account and sudo.** The account gets full passwordless sudo, as decided;
the model's own sudo is limited separately by `agent/settings.json` (an
Apache reload or configtest, `tail`, `journalctl`). The sudoers file is one
line (the commented copy is `agent/sudoers.d/bcmagent`).

```
sudo adduser --disabled-password --gecos "BCM engineer agent" bcmagent
sudo usermod -aG adm bcmagent            # read Apache logs without sudo
echo 'bcmagent ALL=(ALL) NOPASSWD: ALL' | sudo tee /etc/sudoers.d/bcmagent
sudo chmod 440 /etc/sudoers.d/bcmagent
sudo visudo -cf /etc/sudoers.d/bcmagent
```

**2. Preview prefix.** Creates the `bcmagent` php-fpm pool, the vhost for
`bcmagent-*.preview1.rosenthaltechnologies.com` and `~bcmagent/previews`:

```
sudo preview-account-add bcmagent
```

**3. Packages.** PHP 8.4 fpm, Apache, certbot and the MariaDB client are
already on the box. Add the database server and the Python venv module:

```
sudo apt install -y mariadb-server python3-venv
sudo systemctl enable --now mariadb
```

**4. Databases.** One user, two databases. The grant on the test database
name is what lets the PHPUnit bootstrap drop and recreate it.

```
sudo mariadb <<'SQL'
CREATE DATABASE bcmportalpreview CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bcmagent'@'localhost' IDENTIFIED BY '<preview db password>';
GRANT ALL PRIVILEGES ON `bcmportalpreview`.* TO 'bcmagent'@'localhost';
GRANT ALL PRIVILEGES ON `bronx_music_conservatory_test`.* TO 'bcmagent'@'localhost';
FLUSH PRIVILEGES;
SQL
```

### 2. The agent account (as bcmagent: `sudo -iu bcmagent`)

**5. Claude Code.** Fable needs Claude Code 2.1.251 or newer; the installer
fetches the current release.

```
curl -fsSL https://claude.ai/install.sh | bash
exit; sudo -iu bcmagent                  # new login so ~/.local/bin is on PATH
claude --version
```

**6. Deploy key and clone.** The key needs **write** access because the
bridge pushes `main`.

```
ssh-keygen -t ed25519 -N "" -f ~/.ssh/bcm_deploy -C "bcmagent@dev-vps"
cat ~/.ssh/bcm_deploy.pub
```

**BRIAN:** GitHub repo → Settings → Deploy keys → Add, title `bcmagent (dev
VPS)`, tick **Allow write access**. Then:

```
printf 'Host github.com\n  IdentityFile ~/.ssh/bcm_deploy\n  IdentitiesOnly yes\n' >> ~/.ssh/config
chmod 600 ~/.ssh/config
mkdir -p ~/agents/bcm/diffs ~/var/sessions
chmod 700 ~/var/sessions
git clone git@github.com:FriendlyNeighborhoodHacker/portal.bronxconservatory.org ~/agents/bcm/repo
git -C ~/agents/bcm/repo config user.name "BCM Engineer Agent"
git -C ~/agents/bcm/repo config user.email "bcmagent@dev-bronxconservatory.lillyrosenthal.org"
```

**7. Preview config, DB credentials, schema.**

```
cp ~/agents/bcm/repo/agent/config.local.preview.php.example ~/agents/bcm/config.local.php
chmod 600 ~/agents/bcm/config.local.php
vim ~/agents/bcm/config.local.php        # DB_PASS, REMEMBER_TOKEN_KEY, SMTP_* (copy from the dev site's secrets)

cat > ~/.my.cnf <<'EOT'
[client]
user=bcmagent
password=<preview db password>
[mysql]
database=bcmportalpreview
[mariadb]
database=bcmportalpreview
EOT
chmod 600 ~/.my.cnf
mariadb -e 'SELECT 1'
mariadb < ~/agents/bcm/repo/schema.sql
```

`schema.sql` seeds the developer accounts (`brian.rosenthal@gmail.com` /
`lilly`), so previews can be signed into right away.

**8. Smoke-test a preview by hand**, exactly the way the bridge makes one:

```
cd ~/agents/bcm/repo
git worktree add -b feature/smoke ~/previews/bcmagent-smoke main
cp ~/agents/bcm/config.local.php ~/previews/bcmagent-smoke/www/config.local.php
chmod 600 ~/previews/bcmagent-smoke/www/config.local.php
mkdir -p ~/previews/bcmagent-smoke/www/cache
curl -sI https://bcmagent-smoke.preview1.rosenthaltechnologies.com/login.php | head -1    # HTTP/2 200
php ~/previews/bcmagent-smoke/www/bin/migrate.php                                         # all applied; "Up to date."
```

Browser check at `https://bcmagent-smoke.preview1.rosenthaltechnologies.com/`:
the login page with the yellow dev strip; sign in as the developer account;
Admin > Maintenance > Migrations lists everything as applied; Admin > Get
Started > **Reset & Load All Sample Data** fills the preview database with the
sample roster (it stays loaded for every later preview, since they share the
database). A 500 with "Permission denied" on `config.local.php` means PHP ran
in the wrong pool (preview-hosts.md pitfalls). Then clean up:

```
git worktree remove --force ~/previews/bcmagent-smoke
git branch -D feature/smoke
```

**9. Secrets.** Fill in what you have now; the Discord token and channel ID
come in step 12.

```
cp ~/agents/bcm/repo/agent/agent.env.example ~/.agent.env
chmod 600 ~/.agent.env
vim ~/.agent.env
```

**BRIAN:** `ANTHROPIC_API_KEY` is a key from the Console org with the credits,
named `bcmagent-portal`. Then prove the key, the version and the model work,
and that the permission file is honoured (the second command must report that
the commit was refused):

```
set -a; source ~/.agent.env; set +a
cd ~/agents/bcm/repo
claude -p "Reply with the single word ok." --model "$CODE_MODEL" --output-format text
claude -p "Run exactly: git commit --allow-empty -m test. Report whether it ran." --model "$CODE_MODEL" \
  --permission-mode dontAsk --settings agent/settings.json --append-system-prompt-file agent/agent-prompt.md
```

**10. PHPUnit**, in the main clone. The suite must pass here before the agent
is asked to rely on it:

```
cd ~/agents/bcm/repo && php unit-tests/tools/phpunit.phar -c unit-tests/phpunit.xml
```

**11. Python venv.**

```
python3 -m venv ~/agents/venv
~/agents/venv/bin/pip install -U pip discord.py
```

### 3. Discord and the service

**12. Discord (BRIAN, about 10 minutes).** In the `Rosenthal Ops` server,
`#bronxconservatory-features` exists under PROJECTS and the roles `builder`,
`shipper` and `client-bronxconservatory` exist. Check the channel permissions:
`client-bronxconservatory` and `builder` have View Channel, Send Messages,
Send Messages in Threads, Create Public Threads, Read Message History, Attach
Files; `@everyone` has View Channel off.

New bot application at discord.com/developers/applications: **New
Application** → name `SWE - bronxconservatory` → **Bot** → Reset Token (copy
it: `DISCORD_BOT_TOKEN`), Public Bot off, **both** Privileged Gateway Intents
on (Server Members, Message Content) → **OAuth2 → URL Generator**: scope
`bot`; permissions View Channels, Send Messages, Send Messages in Threads,
Create Public Threads, Read Message History, Attach Files, Add Reactions →
open the URL and authorize it into `Rosenthal Ops`. Give the bot's role access
to `#bronxconservatory-features`. Right-click the channel → Copy Channel ID
(`DISCORD_CHANNEL_ID`). Put both values in `~bcmagent/.agent.env`.

You keep `builder` + `shipper`. A client contact gets
`client-bronxconservatory` + `builder`: they can request, discuss, say **go**,
review the preview and ask for revisions, but not **approve**, because approve
is a production deploy. Give someone `shipper` to change that.

**13. Service (as debian).**

```
sudo cp /home/bcmagent/agents/bcm/repo/agent/bcm-bridge.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now bcm-bridge
journalctl -fu bcm-bridge          # "bridge ready as SWE - bronxconservatory#…; channel …; previews bcmagent-*.…"
```

Optional insurance, as `bcmagent` (`crontab -e`): a nightly dump of the
preview database.

```
30 2 * * * mariadb-dump bcmportalpreview | gzip > /home/bcmagent/agents/bcm/backups/preview-$(date +\%F).sql.gz
```

(`mkdir ~/agents/bcm/backups` first; prune by hand now and then.)

### 4. First run: a trivial, safe change

Do the whole loop once with something harmless, from your own account.

- [ ] Post in `#bronxconservatory-features`: *Add an HTML comment `<!-- agent test -->` at the top of the login page.* The bot opens a thread, posts the branch and a `bcmagent-<slug>` preview link that already loads, and replies with a plan or a question. **No diff is attached** (it has not edited).
- [ ] Reply **go**. The reply has bullets, a `Preview:` link that shows the comment in the page source, a `Commit message:` line, and `changes.diff` attached.
- [ ] Reply **status**: one request in flight, with its link.
- [ ] Reply **discard**: the preview link now returns 404; `git -C ~bcmagent/agents/bcm/repo worktree list` shows only the main clone.
- [ ] Repeat the request, **go**, then **approve**. The bridge posts the sha; the commit appears on GitHub `main`; the dev site and production show the comment (view source on the login page). Then request its removal the same way.
- [ ] From a `builder`-only account: **approve** is refused with the "only a shipper" message.
- [ ] Ask the agent in-thread to *commit this for me*: it declines and explains that approve does it.
- [ ] Two top-level requests at once: two threads, two branches, two hostnames that both load, `worktree list` shows both, **discard** in one removes only that one.
- [ ] A request that needs a migration (e.g. a nullable column on `settings`): the plan says so; after **go** the preview's Admin > Migrations shows it applied, `schema.sql` is in the diff, and the report ends with a `Post-deploy:` block naming the file. After **approve**, apply it on production and on the dev site from Admin > Maintenance > Migrations, as the block says.

## Day to day

- **Change the agent.** The bridge, prompt and permissions are read from the
  main clone's `agent/` directory. Prompt and permission changes apply on the
  next agent turn once they are on `main` and the clone has synced (it syncs
  on every new request and approve). Bridge changes need
  `sudo systemctl restart bcm-bridge`. Changes to `agent/` can go through the
  agent's own approve flow; the prompt tells it not to touch that directory
  unless asked.
- **Logs.** `journalctl -fu bcm-bridge` for the bridge; Admin > Maintenance >
  Server Logs on any preview for Apache (one log pair for all of the
  account's previews, hostname first on each line); `www/logs/` in the
  worktree for the app. Claude Code transcripts are under
  `~bcmagent/.claude/projects/`.
- **Reset the preview database.** As `bcmagent`:
  `mariadb -e 'DROP DATABASE bcmportalpreview; CREATE DATABASE bcmportalpreview CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'`
  then `mariadb < ~/agents/bcm/repo/schema.sql`, then reload sample data from
  Get Started. Do it when a discarded migration left the schema ahead of `main`.
- **Spending.** `MAX_BUDGET_USD` in `.agent.env` caps one agent turn; a turn
  that hits it stops and reports, and the next message resumes the session.
- **Stale state** after a manual git operation in the clone or a deleted
  worktree: `git -C ~bcmagent/agents/bcm/repo worktree prune`, remove that
  thread's entry from `~bcmagent/agents/bcm/threads.json`, and
  `sudo systemctl restart bcm-bridge`.
- **Another agent for another application** (`lillyrosenthal.org`,
  `brianrosenthal.org`): repeat this document with its own account, prefix,
  database, Discord bot and channel, and a copy of `agent/` in that
  repository with its prompt rewritten for that codebase. The preview scheme
  needs nothing new.

## Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Bridge exits with `PrivilegedIntentsRequired` | Server Members or Message Content intent off | Developer Portal → Bot → enable both; restart |
| `API Error: 400 ... does not support this model` | Claude Code older than 2.1.251 | as `bcmagent`: `claude update`, then restart the bridge |
| The agent edits before **go**, or ignores the report format | prompt file not found (the clone's `agent/agent-prompt.md` missing or the clone behind) | `git -C ~bcmagent/agents/bcm/repo log -1 -- agent/`; the bridge posts the `claude` command's stderr in the thread as `(agent error)` |
| Every tool call refused | `agent/settings.json` missing or invalid JSON | `python3 -m json.tool ~bcmagent/agents/bcm/repo/agent/settings.json` |
| New preview link is 404 "No such preview" | `preview-account-add bcmagent` not run, or `PREVIEW_PREFIX` in `.agent.env` differs from the provisioned prefix | step 2; `grep PREVIEW_PREFIX ~bcmagent/.agent.env` |
| Preview 403 / 500 | see preview-hosts.md pitfalls | |
| Tests fail with `Access denied ... CREATE DATABASE` | the grant on `bronx_music_conservatory_test` is missing | step 4 |
| **approve** says main changed and left conflict markers | someone else pushed to `main` touching the same files | tell the agent to resolve the markers, then **approve** again |
| `git push` rejected | deploy key read-only, or branch protection | GitHub → Deploy keys → Allow write access; or add the key to the protection allowlist |
| Production does not show the change after **approve** | production webhook did not fire | GitHub → Settings → Webhooks → recent deliveries; Admin > Maintenance > Server Logs > deploy-webhook on production |
| A migration broke the preview database | expected: that is what the preview DB is for | reset it (Day to day) |

## Decisions, and where this differs from the handoff

- **Per-request previews, shared by every account.** The handoff's
  `<slug>.preview1.rosenthaltechnologies.com` idea is kept, generalised so the
  same wildcard record and certificate serve any account on the machine
  (`<prefix>-<slug>`), with a one-time `preview-account-add` per account
  instead of a hand-written vhost. `MAX_WORKTREES` (3) keeps the number of
  open requests from getting out of hand; approve and discard remove them.
- **The account has sudo.** The handoff said no sudo; you asked for it. The
  account has full passwordless sudo, but what the model may run with it is
  the short list in `agent/settings.json`, and `dontAsk` mode refuses
  everything else. The bridge itself never uses sudo.
- **Fable, with budget caps.** `CODE_MODEL=claude-fable-5-1`. Claude Code no
  longer has `--max-turns`, so the bridge caps a turn with `--max-budget-usd`
  and a wall-clock timeout instead.
- **Local MariaDB, not the dev database.** See "Layout". The dev site keeps
  its DreamHost database untouched.
- **The agent's files live in the repo** (`agent/`), and the bridge reads the
  prompt and permissions from the main clone on every run, so changing the
  agent is a normal reviewed change rather than an edit on the box.
- **Approve is production.** There is no staging gate: preview → `main` →
  production in one step. Fine for small changes; revisit for anything
  touching payments or authentication (a `staging` branch the webhook deploys
  first would be the next step), and decide after the first week whether a
  client contact should get `shipper`.
