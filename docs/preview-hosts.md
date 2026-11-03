# Preview hosts: `<prefix>-<slug>.preview1.rosenthaltechnologies.com`

Any Linux account on the dev VPS can publish a web site at
`https://<prefix>-<slug>.preview1.rosenthaltechnologies.com` by creating one
directory. No DNS record, certificate, Apache change or root step per site.
The engineer agents use it for one preview per request; student developers on
other projects can use it for their own test sites.

Machine: the self-managed Debian 13 VPS at 208.113.209.171 (DreamHost); sudo
account `debian`. `preview1` is this machine's name in the scheme; the next
box gets `preview2`, and DNS picks the more specific record.

## How it works

| Piece | Done | By |
|---|---|---|
| Wildcard DNS `*.preview1.rosenthaltechnologies.com` → the VPS | once per machine | Brian, in Cloudflare |
| Wildcard certificate for `*.preview1...` (Let's Encrypt, DNS challenge, auto-renewing) | once per machine | root |
| Fallback vhost: http→https redirect, "no such preview" page | once per machine | root |
| Per-account vhost + php-fpm pool: `ServerAlias <prefix>-*.preview1...`, document root `~/previews/%1/www`, PHP as that account | once per account (`preview-account-add`) | root |
| A preview | `mkdir -p ~/previews/<prefix>-<slug>/www` | the account, any time |

Why the account's prefix is in the hostname: PHP has to run as the account
that owns the files (so it can read that account's owner-only config and
nobody else's), which means one php-fpm pool per account, and an Apache vhost
can only name one pool. A wildcard alias per account, `brianrosenthal-*`, lets Apache
pick brianrosenthal's vhost, and so brianrosenthal's pool, from the hostname alone. The
`mod_vhost_alias` module then maps the first hostname label to the directory.
Everything after the prefix is the account's to invent.

Naming: the prefix is lowercase letters and digits (it is the account name
unless `preview-account-add` was given another); the slug is lowercase
letters, digits and hyphens. The directory is the **whole label**:
`~/previews/brianrosenthal-mysite/www` serves `brianrosenthal-mysite.preview1...`. The bare
`brianrosenthal.preview1...` serves `~/previews/brianrosenthal/www`, the account's default site.
The web root inside each preview is `www/` by default (this repo's layout and
hackleyclubz's); the helper takes another name as its third argument.

The wildcard certificate covers exactly one label, so hostnames are always
`something.preview1.rosenthaltechnologies.com`, never two labels deep.

## One-time machine setup

**1. Move `rosenthaltechnologies.com` DNS to Cloudflare (BRIAN).** Let's
Encrypt issues wildcards only through the DNS challenge, and certbot's
Cloudflare plugin is the maintained way to answer it automatically at every
renewal. Cloudflare's free plan hosts a whole zone, not a subdomain, so the
zone moves. Only DNS moves: the registration stays at DreamHost, the web site
stays on DreamHost's server, mail stays at Fastmail. Nothing about the
machines changes, so the move is invisible if every record is copied.

What the zone holds today (checked 2026-11-03; TTLs are 60 s, so changes
propagate within minutes; DNSSEC is off, so there is no DS record to remove
first):

| Type | Name | Content | What it is |
|---|---|---|---|
| A | `@` | `173.236.249.0` | the web site, DreamHost shared hosting |
| A | `www` | `173.236.249.0` | same |
| A | `ftp` | `173.236.249.0` | DreamHost's FTP alias |
| MX | `@` | `10 in1.smtp.messagingengine.com` | Fastmail inbound |
| MX | `@` | `20 in2.smtp.messagingengine.com` | Fastmail inbound |
| NS | `@` | `ns1/ns2/ns3.dreamhost.com` | replaced by Cloudflare's |

There is no SPF, DKIM or DMARC record at the moment. They are not needed for
the move, but Cloudflare's import is a good time to add the three Fastmail
asks for (Fastmail → Settings → Domains → the domain lists them: an SPF `TXT`
at `@`, three `CNAME`s `fm1/fm2/fm3._domainkey`, and a `_dmarc` `TXT`);
without them, mail sent from the domain is more likely to be marked spam.

1. **Snapshot the zone** so you can compare afterwards. From any machine:

   ```
   for t in A MX TXT CNAME NS; do echo "== $t"; dig +short $t rosenthaltechnologies.com; done
   dig +short www.rosenthaltechnologies.com; dig +short ftp.rosenthaltechnologies.com
   ```

   Also open DreamHost panel → Websites → Manage Websites → the domain → DNS
   (or Domains → Manage Domains → DNS) and keep that page open; it is the
   authoritative list, including anything the public lookup above missed.

2. **Add the zone to Cloudflare.** dash.cloudflare.com → Add a site →
   `rosenthaltechnologies.com` → Free plan → Continue. Cloudflare scans and
   imports records. Compare the list with the snapshot: you should see the
   three `A` records and the two `MX` records. Add anything missing by hand,
   exactly as DreamHost shows it. Make **every** record **DNS only** (grey
   cloud, "Proxied" off): the proxy would hide client IPs from the sites and
   break per-host certificates. Then Continue through "Quick start" without
   changing anything (leave Always Use HTTPS and the other toggles off).

3. **Add the preview records**, both `A`, both **DNS only**:

   | Type | Name | IPv4 address | Proxy |
   |---|---|---|---|
   | A | `*.preview1` | `208.113.209.171` | DNS only |
   | A | `preview1` | `208.113.209.171` | DNS only |

   The wildcard is per machine: the next dev box gets `*.preview2`.

4. **Switch the nameservers at the registrar.** Cloudflare shows two
   nameservers (`<name>.ns.cloudflare.com`). DreamHost panel → Domains →
   Manage Registrations (the domain is registered at DreamHost) → the domain
   → Nameservers → replace the three `nsN.dreamhost.com` entries with the two
   Cloudflare ones → Save. DreamHost may warn that its DNS for the domain will
   stop being used; that is the point. Do not remove the DreamHost DNS
   records page yet; it is harmless and a backup.

5. **Wait and verify.** Cloudflare emails when the zone is "Active" (usually
   within an hour; up to 24 h for the registry). Then, from any machine:

   ```
   dig +short NS rosenthaltechnologies.com          # the two cloudflare nameservers
   dig +short A  rosenthaltechnologies.com          # 173.236.249.0
   dig +short MX rosenthaltechnologies.com          # both messagingengine hosts
   dig +short A  anything.preview1.rosenthaltechnologies.com   # 208.113.209.171
   ```

   Send an email to an address at the domain and reply from it; both must
   arrive. Fastmail → Settings → Domains should still show the domain as
   working (its MX check is what matters). Open `https://rosenthaltechnologies.com`
   once to confirm the web site is unchanged.

6. **API token for certbot.** Cloudflare → My Profile (top right) → API
   Tokens → Create Token → **Edit zone DNS** template → Permissions: Zone,
   DNS, Edit → Zone Resources: Include, Specific zone,
   `rosenthaltechnologies.com` → Continue to summary → Create Token. Copy it
   now; it is shown once. It can only edit DNS in this one zone, which is all
   the certificate renewal needs.

Rollback at any point before step 4 is nothing (Cloudflare is just holding a
copy); after step 4, put the three DreamHost nameservers back at the registrar
and DreamHost's untouched records take over again within the TTL.

**2. Wildcard certificate (as debian).**

```
sudo apt install -y python3-certbot-dns-cloudflare
sudo install -d -m 700 /root/.secrets
echo 'dns_cloudflare_api_token = <token>' | sudo tee /root/.secrets/cloudflare.ini >/dev/null
sudo chmod 600 /root/.secrets/cloudflare.ini
sudo certbot certonly --dns-cloudflare --dns-cloudflare-credentials /root/.secrets/cloudflare.ini \
  --cert-name preview1.rosenthaltechnologies.com \
  -d preview1.rosenthaltechnologies.com -d '*.preview1.rosenthaltechnologies.com' \
  --deploy-hook 'systemctl reload apache2'
sudo certbot renew --dry-run
sudo certbot certificates                       # lineage preview1.rosenthaltechnologies.com, both names, ~90 days
```

Certbot's systemd timer renews it about 30 days before expiry using the
token, and the deploy hook reloads Apache so every preview vhost picks up the
new certificate. If the token is ever revoked, renewal fails and certbot
emails the account address it asked for at first use.

**3. Fallback vhost and the helper (as debian).** Both files live in this
repository under `agent/server/`. Take them from any checkout, e.g. the dev
site's at `/home/bronxconservatory/deploy/dev-bronxconservatory.lillyrosenthal.org/`.

```
SRC=/home/bronxconservatory/deploy/dev-bronxconservatory.lillyrosenthal.org/agent/server
sudo install -m 755 $SRC/preview-account-add /usr/local/sbin/preview-account-add
sudo install -d /var/www/preview-fallback
echo '<!doctype html><title>No such preview</title><h1>No such preview</h1><p>Nothing is published at this hostname.</p>' | sudo tee /var/www/preview-fallback/index.html >/dev/null
sudo cp $SRC/preview-zz-fallback.conf /etc/apache2/sites-available/
sudo a2enmod vhost_alias ssl rewrite proxy_fcgi
sudo a2ensite preview-zz-fallback
sudo apache2ctl configtest && sudo systemctl reload apache2
curl -sI https://nobody-here.preview1.rosenthaltechnologies.com/ | head -1      # HTTP/1.1 404 (the "No such preview" page)
```

## Per account

**Provision (as debian), once.** The account must exist first; a personal
account gets a normal login, an agent account a locked password.

```
sudo adduser brianrosenthal                 # personal accounts: prompts for a password
sudo adduser lillyrosenthal
sudo preview-account-add brianrosenthal     # prefix "brianrosenthal", web root "www"
sudo preview-account-add lillyrosenthal     # prefix "lillyrosenthal"
sudo preview-account-add bronxconservatory  # the dev site's account; also the portal's engineer agent
```

The accounts on this machine and their prefixes, as of November 2026:

| Account | Prefix | Used for |
|---|---|---|
| `brianrosenthal` | `brianrosenthal-` | Brian's own previews |
| `lillyrosenthal` | `lillyrosenthal-` | Lilly's own previews |
| `bronxconservatory` | `bronxconservatory-` | the dev site's account; the portal's engineer agent makes one preview per request |

It creates the pool and the vhost, makes the home directory traversable, and
prints the hostname pattern. Re-running it is harmless.

**Use (as the account), any time:**

```
mkdir -p ~/previews/brianrosenthal-hello/www
echo '<h1>hello</h1>' > ~/previews/brianrosenthal-hello/www/index.html
curl -s https://brianrosenthal-hello.preview1.rosenthaltechnologies.com/
```

A PHP app works the same way: put the checkout (or a git worktree) at
`~/previews/brianrosenthal-<slug>/` with its web root at `www/`, and its config inside
the tree. PHP runs as `brianrosenthal`, so an owner-only config file is fine. Files
must stay world-readable for Apache's static serving (the default umask does
that). Delete the directory and the preview is gone.

**Let someone else provision accounts** without giving them root: a sudoers
line for one command.

```
echo '%previewadmins ALL=(root) NOPASSWD: /usr/local/sbin/preview-account-add' | sudo tee /etc/sudoers.d/previewadmins
sudo chmod 440 /etc/sudoers.d/previewadmins && sudo visudo -cf /etc/sudoers.d/previewadmins
sudo groupadd previewadmins && sudo usermod -aG previewadmins <who>
```

**Remove an account's previews:** `sudo a2dissite preview-brianrosenthal`, delete
`/etc/php/8.4/fpm/pool.d/brianrosenthal.conf`, reload both services.

## Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Browser warns about the certificate | hostname has two labels before `preview1`, or the cert was not issued | one label only; `sudo certbot certificates` |
| 404 "No such preview" for a hostname that should exist | prefix not provisioned, or the directory name is not the whole label | `ls /etc/apache2/sites-enabled/preview-*`; `ls ~/previews` |
| 404 from the account's own vhost | `www/` (or the configured web root) missing inside the preview directory | create it |
| 403 | Apache cannot traverse the home directory or read the files | `chmod o+x /home/<account>`; files must be `o+r` |
| 500, "config: Permission denied" in the error log | PHP ran in another account's pool | `grep SetHandler /etc/apache2/sites-available/preview-<account>.conf`; make sure no global PHP handler is enabled (deployment.md step 12) |
| The wrong site answers | two accounts share a prefix, or the fallback file sorts before an account file | prefixes are unique; the fallback stays `preview-zz-...` |
| Renewal fails | Cloudflare token revoked or the zone left Cloudflare | `sudo certbot renew --dry-run` and read the error |
