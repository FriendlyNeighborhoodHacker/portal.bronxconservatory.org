# Deployment

How the portal is installed on a server, and the step-by-step setup of the
dev instance. The same layout is used on production; only the account name,
hostname, and database differ.

## Server layout (every machine)

Each site lives in a dedicated shell account. Nothing is owned by root, and
the git checkout is never the web root.

| Path | Purpose |
|---|---|
| `~/deploy/{site}/` | git checkout |
| `~/deploy/{site}.secrets/config.local.php` | the one secret file (never in git) |
| `~/deploy/{site}.sh` | deploy script: pull, sync `www/` to the live root, copy secrets |
| `~/deploy/logs/` | webhook and cron logs |
| `~/{site}/` | live web root (Apache DocumentRoot) |

`{site}` is the hostname, e.g. `dev-bronxconservatory.lillyrosenthal.org`.

Consequences of this layout:

- `db_migrations/` stays in the checkout, outside the web root. `MIGRATIONS_DIR`
  in config.local.php must therefore be an **absolute path** into the checkout.
  The `dirname(__DIR__)` form used for local development does not work, because
  config.local.php is copied into the live root rather than living beside the
  repo.
- `www/gitwebhook.php` runs whatever `DEPLOY_SCRIPT` names in config.local.php
  and logs to `DEPLOY_LOG`. One webhook file serves every machine; GitHub has
  one webhook entry per machine, each with its own secret.
- `www/uptime.php` is the health check for external monitors. It returns HTTP
  200 with `{"status":"ok"}` when PHP is serving and the database answers a
  read on `users`, and 503 with `{"status":"fail"}` otherwise. Alert on any
  non-200 response. It never starts a session or writes anything.
- PHP must run **as the site account**. On DreamHost shared hosting that is
  automatic. On a self-managed Apache box it means a dedicated php-fpm pool
  per site (see step 11 below); with mod_php or a shared pool, PHP would run
  as some other user and could not read the secrets or run the deploy script.
- Apache itself (`www-data`) still serves static files straight from the live
  root, so the home directory needs `o+x` and static files stay world-readable.
  Only `config.local.php` is owner-only; `.htaccess` keeps it from being served.

## Dev instance

| | |
|---|---|
| Hostname | `dev-bronxconservatory.lillyrosenthal.org` |
| Machine | self-managed Debian/Apache VPS at 208.113.209.171 (also hosts dev.hackleyclubz.org) |
| Sudo account | `debian` |
| Site account | `bronxconservatory` |
| PHP | php-fpm 8.4, pool `bronxconservatory` (socket `/run/php/php8.4-fpm-bronxconservatory.sock`) |
| Database | `bcmportaldev` / user `bcmportaldev` on DreamHost shared MySQL (`mysql.brianrosenthal.org`) |

No extra IP address is needed. Apache picks the vhost by hostname and certbot
issues a separate certificate per hostname over SNI.

Every step below names the account to run it as. From `debian`, get a shell as
the site account with `sudo -iu bronxconservatory`; `exit` returns. The site
account is deliberately **not** a sudoer, so any step marked "as debian" fails
with "not in the sudoers file" if run from the wrong shell. Do not run
site-account steps through `sudo -u ... cmd ~/path`: the shell expands `~`
before sudo runs, so it points at the wrong home directory.

### 1. Preparation

**1. DNS (anywhere).** Add an A record for `dev-bronxconservatory` in the
lillyrosenthal.org zone pointing to 208.113.209.171. Confirm before issuing the
certificate, since Let's Encrypt validates over HTTP:

```
dig +short dev-bronxconservatory.lillyrosenthal.org
```

**2. Database (DreamHost panel).** MySQL Databases > add database
`bcmportaldev`, user `bcmportaldev`, on the MySQL hostname
`mysql.brianrosenthal.org`. Then edit the user and add `208.113.209.171` to its
**allowable hosts**. The default only permits DreamHost's own shared servers;
without this the VPS is refused and the portal shows a connection error.

**3. Account setup (as debian).** The `chmod` lets Apache traverse the home
directory, which Debian locks down by default.

```
sudo adduser bronxconservatory          # if not already created
sudo chmod o+x /home/bronxconservatory
```

**4. PHP and packages (as debian).** PHP 8.2+ (fpm) with `pdo_mysql`,
`mbstring`, `curl`, `xml`; Apache `mod_rewrite` and `proxy_fcgi`; `rsync` for
the deploy script; the MariaDB client only for loading the schema.

```
php -v
sudo apache2ctl -M | grep -E 'proxy_fcgi|rewrite'
sudo apt install php-fpm php-mysql php-mbstring php-curl php-xml mariadb-client rsync
sudo a2enmod rewrite proxy_fcgi
```

**5. Apache log access (as debian, optional).** Lets Admin > Maintenance >
Server Logs read the Apache logs. Skip this and the two `apache-*` entries in
the config if not wanted.

```
sudo usermod -aG adm bronxconservatory
```

(Takes effect on the site account's next login.)

### 2. Checkout, secrets, deploy script (as bronxconservatory)

**6. GitHub key.** Add the public key as a read-only deploy key on the repo.

```
ssh-keygen -t ed25519 -C "bronxconservatory@dev"
cat ~/.ssh/id_ed25519.pub
```

**7. Directories and checkout.**

```
mkdir -p ~/deploy/logs ~/deploy/dev-bronxconservatory.lillyrosenthal.org.secrets ~/dev-bronxconservatory.lillyrosenthal.org
chmod 700 ~/deploy/dev-bronxconservatory.lillyrosenthal.org.secrets
git clone git@github.com:FriendlyNeighborhoodHacker/portal.bronxconservatory.org ~/deploy/dev-bronxconservatory.lillyrosenthal.org
```

**8. Load the schema.** `schema.sql` is complete on its own (no migrations
needed on a fresh install) and seeds the developer accounts.

```
mysql -h mysql.brianrosenthal.org -u bcmportaldev -p --skip-ssl-verify-server-cert -e 'SELECT 1'
mysql -h mysql.brianrosenthal.org -u bcmportaldev -p --skip-ssl-verify-server-cert bcmportaldev < ~/deploy/dev-bronxconservatory.lillyrosenthal.org/schema.sql
```

The `--skip-ssl-verify-server-cert` flag is needed because recent MariaDB
clients verify the server certificate by default and DreamHost's shared MySQL
certificate does not match the hostname ("ERROR 2026 ... certificate is NOT
trusted"). Only the command-line client is affected; PHP's PDO driver does not
verify server certificates unless asked to.

**9. Secrets file.** See `www/config.local.php.example` for commentary on
every setting.

```
cp ~/deploy/dev-bronxconservatory.lillyrosenthal.org/www/config.local.php.example ~/deploy/dev-bronxconservatory.lillyrosenthal.org.secrets/config.local.php
vim ~/deploy/dev-bronxconservatory.lillyrosenthal.org.secrets/config.local.php
```

```php
define('DB_HOST', 'mysql.brianrosenthal.org');
define('DB_NAME', 'bcmportaldev');
define('DB_USER', 'bcmportaldev');
define('DB_PASS', '<strong password>');
define('APP_NAME', 'BCM Portal (DEV)');

// Marks this install as a dev instance: every page shows a thin "Dev site —
// note this is test data" strip, and Get Started offers "Load All Sample
// Data". Never set on production.
define('DEV_SITE', true);

// Leave SUPER_PASSWORD empty on dev.

// SMTP: same settings as production are fine, because of the redirect below.

// Dev-only: every outgoing email lands here, never with a family.
define('EMAIL_REDIRECT_TO', 'brian.rosenthal@gmail.com');
define('EMAIL_REDIRECT_ALLOWLIST', []);

// php -r "echo bin2hex(random_bytes(32));"
define('REMEMBER_TOKEN_KEY', '<random hex>');

// Stripe TEST-mode keys only, or empty to disable checkout. Stripe webhooks
// only reach dev if a second endpoint is added in the Stripe test dashboard.
define('STRIPE_SECRET_KEY', '');
define('STRIPE_PUBLISHABLE_KEY', '');
define('STRIPE_WEBHOOK_SECRET', '');

// Empty on dev so the public forms are testable.
define('RECAPTCHA_SITE_KEY', '');
define('RECAPTCHA_SECRET_KEY', '');

// Deploy webhook. Another random hex string; must match the GitHub webhook.
// No sudo needed: PHP runs as bronxconservatory (its own php-fpm pool).
define('GIT_WEBHOOK_SECRET', '<random hex>');
define('DEPLOY_SCRIPT', '/home/bronxconservatory/deploy/dev-bronxconservatory.lillyrosenthal.org.sh');
define('DEPLOY_LOG',    '/home/bronxconservatory/deploy/logs/dev-bronxconservatory.lillyrosenthal.org.webhook.log');

// Leave SESSION_SAVE_PATH undefined: the pool has its own session dir
// (php-fpm's default is per-pool-readable under /var/lib/php/sessions).
// If "CSRF token mismatch" appears on every POST, set it to
// '/home/bronxconservatory/var/sessions'.

// Absolute paths into the checkout (see "Server layout" above).
define('MIGRATIONS_DIR', '/home/bronxconservatory/deploy/dev-bronxconservatory.lillyrosenthal.org/db_migrations');
// Where the sample-data loader reads from (the checkout, not the web root).
// Only used when DEV_SITE is true.
define('SAMPLE_DATA_DIR', '/home/bronxconservatory/deploy/dev-bronxconservatory.lillyrosenthal.org/sample_data');

define('ADMIN_LOG_FILES', [
    'deploy-webhook' => '/home/bronxconservatory/deploy/logs/dev-bronxconservatory.lillyrosenthal.org.webhook.log',
    'apache-access'  => '/var/log/apache2/dev-bronxconservatory-access.log',
    'apache-error'   => '/var/log/apache2/dev-bronxconservatory-error.log',
]);
```

**10. Deploy script.** Pulls, syncs `www/` into the live root, copies the
secrets. Excluded from the sync and from deletion, because the live root owns
them: the app's `.log` files, the image cache, and the config.

```
cat > ~/deploy/dev-bronxconservatory.lillyrosenthal.org.sh <<'EOS'
#!/bin/bash
set -euo pipefail
SITE=dev-bronxconservatory.lillyrosenthal.org
CHECKOUT=/home/bronxconservatory/deploy/$SITE
SECRETS=/home/bronxconservatory/deploy/$SITE.secrets
LIVE=/home/bronxconservatory/$SITE

cd "$CHECKOUT"
git fetch origin
git reset --hard origin/main

rsync -rlt --delete \
  --exclude 'config.local.php' \
  --exclude 'logs/*.log' \
  --exclude 'cache/' \
  "$CHECKOUT/www/" "$LIVE/"

cp "$SECRETS/config.local.php" "$LIVE/config.local.php"
chmod 600 "$LIVE/config.local.php"
mkdir -p "$LIVE/logs" "$LIVE/cache"

echo "Deployed $SITE at $(git rev-parse --short HEAD)"
EOS
chmod 755 ~/deploy/dev-bronxconservatory.lillyrosenthal.org.sh
~/deploy/dev-bronxconservatory.lillyrosenthal.org.sh      # first deploy
```

To track a branch other than `main` on dev, change the `reset --hard` line.

### 3. PHP pool, Apache, certificate, webhook, cron

**11. php-fpm pool (as debian).** The box has one pool per site; each runs as
that site's account. Copy the existing pool so process settings carry over,
then change the name, user, group, and socket. `listen.owner` and
`listen.group` must match the user Apache runs as (`APACHE_RUN_USER` in
`/etc/apache2/envvars`, normally `www-data`); check with
`ps -o user,args -C apache2`. If they disagree, Apache cannot connect to the
socket and every PHP request is a 503.

```
sudo cp /etc/php/8.4/fpm/pool.d/www.conf /etc/php/8.4/fpm/pool.d/bronxconservatory.conf
sudo sed -i \
  -e 's/^\[www\]/[bronxconservatory]/' \
  -e 's/^user = .*/user = bronxconservatory/' \
  -e 's/^group = .*/group = bronxconservatory/' \
  -e 's|^listen = .*|listen = /run/php/php8.4-fpm-bronxconservatory.sock|' \
  /etc/php/8.4/fpm/pool.d/bronxconservatory.conf
sudo php-fpm8.4 -t
sudo systemctl restart php8.4-fpm
ls -l /run/php/                                  # new socket should appear
ps -o user,args -C php-fpm8.4                    # workers for the new pool run as bronxconservatory
```

Symptom if this step is skipped: HTTP 500 with "require_once(.../config.local.php):
Failed to open stream: Permission denied" in the Apache error log, because
the request ran as another site's pool user.

**12. Vhost (as debian).** Plain HTTP first so certbot can validate. The
`FilesMatch` block routes this vhost's PHP to its own pool. `AllowOverride All`
is required: the repo's `www/.htaccess` is what blocks `logs/` and
`config.local.php` from being served.

There must be **no global PHP handler** in `conf-enabled` (Debian's
`php8.4-fpm.conf`, or a custom one). Each site's vhost declares its own
pool. In particular, a global handler wrapped in `<If ...>` silently wins over
a vhost-level `FilesMatch`, because Apache merges `<If>` sections after every
other kind, so PHP for every site runs in whichever pool the global file
names. The symptom is the step-11 "Permission denied" 500 even though the
vhost config looks right; a one-line probe
(`<?php echo posix_getpwuid(posix_geteuid())["name"];`) in the live root
shows which user is actually running PHP. Keep only the `.phps` / dotfile
deny rules in the global file.

```
sudo tee /etc/apache2/sites-available/dev-bronxconservatory.conf >/dev/null <<'EOS'
<VirtualHost *:80>
    ServerName dev-bronxconservatory.lillyrosenthal.org
    DocumentRoot /home/bronxconservatory/dev-bronxconservatory.lillyrosenthal.org

    <Directory /home/bronxconservatory/dev-bronxconservatory.lillyrosenthal.org>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm-bronxconservatory.sock|fcgi://localhost"
    </FilesMatch>

    ErrorLog  ${APACHE_LOG_DIR}/dev-bronxconservatory-error.log
    CustomLog ${APACHE_LOG_DIR}/dev-bronxconservatory-access.log combined
</VirtualHost>
EOS
sudo a2ensite dev-bronxconservatory
sudo apache2ctl configtest && sudo systemctl reload apache2
```

**13. Certificate (as debian).** HTTPS is required, not cosmetic: the
remember-me cookie is set with the Secure flag, so persistent login silently
fails over HTTP. Certbot copies the `:80` vhost **as it is at that moment**
into `dev-bronxconservatory-le-ssl.conf`. If the `FilesMatch` block was added
after certbot ran, add it to the `-le-ssl.conf` file by hand and reload;
otherwise HTTPS requests keep going to the other site's pool and the
step-11 "Permission denied" 500 persists while plain HTTP would work.

```
sudo apt install certbot python3-certbot-apache   # if not already there
sudo certbot --apache -d dev-bronxconservatory.lillyrosenthal.org --redirect
```

**14. Smoke test (browser).** Sign in with a seeded developer account and
visit Admin > Maintenance. Migrations should list every file as applied; a
test email should arrive with the "[Redirected ...]" subject tag. A database
connection error almost always means the allowable-hosts setting in step 2.
Every page should show the thin yellow "Dev site — note this is test data"
strip at the top (`DEV_SITE`). Then Admin > Get Started
(`/admin/setup/index.php`) > **Reset & Load All Sample Data** clears any
previous load and fills the database with the `sample_data/` roster, both
semesters, the fall schedule and a set of leads in one click.

**15. GitHub webhook (github.com).** Repo Settings > Webhooks > Add webhook:
payload URL `https://dev-bronxconservatory.lillyrosenthal.org/gitwebhook.php`,
content type `application/json`, secret = `GIT_WEBHOOK_SECRET` from step 9,
push events only. Production keeps its own entry with its own secret. Push a
commit and check Admin > Maintenance > Server Logs > deploy-webhook for
"Deploy Successful". The webhook runs the deploy script directly as
`bronxconservatory`, so no sudo rule is needed.

**16. Installment-fee cron (as bronxconservatory).** See `cron.md`. On dev it
is fine to skip this and run the sweep from Admin > Maintenance when testing.

```
crontab -e
```

```
15 2 * * * php /home/bronxconservatory/dev-bronxconservatory.lillyrosenthal.org/bin/apply_installment_fees.php >> /home/bronxconservatory/deploy/logs/dev-bronxconservatory.lillyrosenthal.org.installment_fees.log 2>&1
```

## Day to day

- **Deploy by hand:** as the site account, run `~/deploy/{site}.sh`. Then, if
  the pull brought new files in `db_migrations/`, apply them from
  Admin > Maintenance > Migrations.
- **Change a secret:** edit `~/deploy/{site}.secrets/config.local.php`, then
  run the deploy script (or just copy the file) so the live root picks it up.
- **Clear the image cache:** delete `~/{site}/cache/public`; it is rebuilt on
  demand (see `file-storage-spec.md`).
