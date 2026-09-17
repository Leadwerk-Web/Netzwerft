# die netzwerft · T2med — static source contract

Canonical pages are the HTML files in **this repository root**, not
`t2med-wechsel-v2/leadwerk_theme`. Global Theme Distribution compiles a signed
theme ZIP. WordPress never reads GitHub at request time.

Follow this file when adding or changing T2med pages, CSS or JavaScript. Full
platform contract: `Global Theme Distribution/AGENTS.md`.

## Canonical files

| Role | Path |
| --- | --- |
| Home | `t2med-wechsel-v2.html` → `/` (`nw-t2med-home-v2`) |
| Thanks | `danke.html` |
| Legal | `impressum.html`, `datenschutz.html` |
| 404 | `404.html` |
| CSS | `css/styles-v2.css`, `css/landing-t2med-v2.css`, `css/special-pages.css`, `css/montserrat-local.css` |
| Fonts | `fonts/` |
| JS | `js/` |
| Images | `Fotos/`, `svg/` |
| GTD profile | `Global Theme Distribution/projects/netzwerft/project.json` |

Ignore for source: `Global Theme Distribution/**`, `t2med-wechsel-v2/**` (old
WordPress theme/plugins), `acm-output/**`, ZIPs.

## New public page

1. Add `<slug>.html` in the repo root (same pattern as `danke.html`).
   `lang="de"`, one `<main>`, one `<h1>`, title, meta description.
2. Link local CSS (do not invent a parallel design system):

   ```html
   <link rel="stylesheet" href="css/montserrat-local.css">
   <link rel="stylesheet" href="css/styles-v2.css">
   <link rel="stylesheet" href="css/landing-t2med-v2.css">
   <link rel="stylesheet" href="css/special-pages.css">
   ```

   Page-specific rules go in `css/special-pages.css` or a new `css/<slug>.css`
   that the HTML actually links. JS goes in `js/` and must be progressive
   enhancement (no localhost, no secrets, no `javascript:` URLs).
3. Register the file in
   `Global Theme Distribution/projects/netzwerft/project.json`:
   - `source.pagePatterns` (explicit list; `**/*.html` is **not** used)
   - `source.pageOverrides` with a new stable `sourceKey` (`nw-<slug>-v1`),
     `route` (`/<slug>/`), `template` (`home` / `special` / `legal`), `status`
4. Annotate editable nodes (`data-lw-section`, `data-lw-field`, `data-lw-type`).
   This project uses `editableContentPolicy: require`.
5. Internal links are project paths (`danke.html`, `/impressum/`). No empty
   `href`, no production host in new canonical tags.
6. Add nav/footer links on existing pages if the page is public.
7. Validate from `Global Theme Distribution/`:

   ```bash
   node tooling/bin/gtd.mjs validate --project projects/netzwerft/project.json
   node tooling/bin/gtd.mjs build --project projects/netzwerft/project.json --dry-run
   ```

Do not change a `sourceKey` after first release. Do not edit
`t2med-wechsel-v2/leadwerk_theme` to ship page HTML/CSS; that folder is the old
embedded suite, not the static source.

## Updates (Theme Center)

CSS, JavaScript and PHP arrive with the signed theme ZIP. After a successful
install a wp-admin popup imports only HTML, fields, sections and content media.
Unchanged pages stay as they are; removed pages are retired (draft + noindex).
The importer screen is recovery, not the update UI.

A live site on version 1 must not jump to version 10. Theme Center installs the
next published ZIP, then the popup checks the server for the following version.
First install on an empty site may take the chosen catalog version directly.
Rollback is a snapshot restore, not a skip.

## CSS and JavaScript

- Shared look: `css/styles-v2.css` (tokens, header, footer).
- Landing blocks: `css/landing-t2med-v2.css`.
- Legal/thanks/404: `css/special-pages.css`.
- Fonts stay local (`css/montserrat-local.css` + `fonts/`). No Google Fonts.
- Only files referenced from HTML (or `assetRoots`) enter the theme ZIP.

## Forbidden

- Putting new pages under `t2med-wechsel-v2/` or `Global Theme Distribution/`
- Copying ACM overlay/CSS into this site
- Weakening the GTD validator
- Silent WordPress auto-update
- Skipping published theme versions on the live WordPress site
