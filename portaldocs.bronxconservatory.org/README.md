# BCM Portal Docs

Documentation site for portal.bronxconservatory.org. Interim domain:
**bcmdocs.lillyrosenthal.org** (long-term home: docs.bronxconservatory.org —
update `site` in `astro.config.mjs` when it moves).

Built with [Astro](https://astro.build) running on [Deno](https://deno.com),
modeled on `hackley-clubz/docs.hackleyclubz.org`. Deno is used because npm
lifecycle scripts are off by default and `deno.lock` pins exact bytes with
`--frozen` installs.

## Local development

```bash
deno install --allow-scripts=npm:esbuild,npm:sharp,npm:fsevents
deno task dev      # http://localhost:4321
deno task build    # static build into dist/
```

Notes:
- `deno task <name>` reads the `scripts` block of `package.json`; there is no
  `deno.json`.
- `sharp` is declared explicitly because Deno's node_modules layout doesn't
  hoist Astro's optional dep (the build fails with `MissingSharp` otherwise).
- `deno.lock` is the committed lockfile (no `package-lock.json`).

## Content structure

- Pages are plain `.md` files under `src/pages/` (file-based routing). Each
  page sets `layout: ../layouts/DocsLayout.astro` plus `title` and
  `description` in front matter.
- The sidebar nav is hand-written in `src/lib/nav.ts` — add new pages there.
- `/sample-data` and its CSV downloads are generated at build time straight
  from the repo's `sample_data/` directory (`src/lib/sample-data.ts` + the
  `src/pages/sample-data/` endpoint) — no copies to keep in sync.
- Design tokens (BCM navy/gold/blue palette) live in `src/styles/global.css`.
- `schema.sql` and `docs/app_spec.md` at the repo root are the sources of
  truth the Data Model and experience pages summarize — update the docs when
  those change.

## Deployment (not yet wired up)

Modeled on the docs.hackleyclubz.org setup (push-to-deploy webhook → build on
the VPS → Apache serves static files):

1. **DNS** — an A record for `bcmdocs.lillyrosenthal.org` pointing at the VPS.
2. **Build on the server** (in the deploy script, after the main site):
   ```bash
   cd <checkout>/portaldocs.bronxconservatory.org
   deno install --frozen --allow-scripts=npm:esbuild,npm:sharp
   deno task build
   rsync -a --delete dist/ ~/bcmdocs.lillyrosenthal.org/
   ```
   Guard on deno being installed (`$HOME/.deno/bin/deno`) so a missing deno
   warns rather than failing the whole deploy.
3. **Apache vhost** for the webroot (`Options -Indexes`, `AllowOverride
   None`), then `a2ensite` it.
4. **TLS** — `sudo certbot --apache -d bcmdocs.lillyrosenthal.org` with the
   redirect option.
