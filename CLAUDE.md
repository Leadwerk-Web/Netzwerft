# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

**Read `AGENTS.md` first.** It is the source contract for pages that ship to WordPress (canonical files, how to add a public page, `project.json` registration, `data-lw-*` annotations, forbidden actions). This file does not repeat it.

## What this is

Static marketing site for "die netzwerft GmbH" (medical-practice IT, T2med partner, Karlsruhe). Plain HTML + CSS + vanilla JS in the repo root — no bundler, no package.json, no templating. All copy is German (`lang="de"`); code comments are German too.

The same root HTML files feed two different targets:

| Target | Home page | Mechanism |
| --- | --- | --- |
| WordPress (live) | `t2med-wechsel-v2.html` → `/` | External "Global Theme Distribution" repo (not in this repo) compiles a signed theme ZIP from root files registered in its `projects/netzwerft/project.json`. Validate there with `node tooling/bin/gtd.mjs validate --project projects/netzwerft/project.json`. |
| GitHub Pages (preview) | `index-v2.html` → `/Netzwerft/` | `.github/workflows/pages.yml` on push to `main` |

`index.html` in the repo is only a redirect to `t2med-wechsel-v2.html`; do not build content there.

`t2med-wechsel-v2/` is the **old** embedded WordPress theme/plugin suite (PHP, own README). Per `AGENTS.md` it is not source for pages — don't edit it to ship HTML/CSS, and don't count its copies of `source_assets/*.html` or `assets/` when changing a page. `Archiv/` holds retired page versions.

## Commands

No build, lint or test step for the static site. Serve the repo root with any static server (root-relative URLs need a server, not `file://`).

Reproduce the GitHub Pages build locally (writes `_site/`, don't commit it):

```bash
node .github/scripts/build-pages-preview.mjs
```

It copies only `css/ js/ fonts/ Fotos/ svg/` plus root `*.html`, forces `noindex, nofollow`, rewrites root-relative `/…` URLs to `/Netzwerft/…`, emits `danke/ impressum/ datenschutz/` as directory routes, and **fails if a root-relative URL escapes the rewrite** or if `index-v2.html` is missing. Assets outside those five folders won't be published.

Old WordPress suite only (`t2med-wechsel-v2/`): `php tests/validate-package.php`, or `composer test` (validate + PHPUnit + PHPCS/WPCS).

## Architecture

**Shared chrome is duplicated, not included.** Header/nav, footer, mobile sticky CTA and the "Erstgespräch" funnel modal (`#funnel-modal` / `#funnel-form`) are inlined in every page. A nav/footer/funnel change must be applied to every root `*.html` (grep for the markup).

**CSS layering (v2 is current):** `montserrat-local.css` (local font, no Google Fonts) → `styles-v2.css` (tokens, header, footer) → `landing-t2med-v2.css` (landing blocks, used by nearly every page) → page-specific sheet (`landing-<page>-v2.css`, `wissen-v2.css`, `special-pages.css` for 404/danke/legal/update-check). Landing pages stack several page sheets (e.g. `praxissoftware-wechsel.html` loads the praxisgruendung and praxisuebernahme sheets too), so a change in one landing sheet can affect other pages. `styles.css`, `styles-v3.css`, `hero.css`, `landing-t2med(-v3).css` are legacy — only `Archiv/` and the `design-handout*` pages use them.

**JS:** every page loads `lenis.min.js` + `scroll-smooth.js` + `main.js`. `main.js` is one feature-detected file for all pages: header scroll state, mobile menu, FAQ accordion, service tabs, sticky CTA/back-to-top, scroll reveals, T2med image overhang, privacy-friendly video lightbox, funnel modal (opened by any `[data-conversion="appointment_start"]`, chip groups `[data-funnel-group]`, conditional steps `[data-funnel-conditional]`), and the Wissen hub filter/search (`#hash` category, `wissen.html?q=` prefill). Page extras: `hero-particles.js`, `references.js` (partner logos), `hero-v5.js` (index-v2 hero), `t2med-page.js`, and — only for the archived hero variants — `carousel.js`, `hero-slider.js`, `hero-neon-slider.js`, `hero-v6.js`, `hero-v7.js`. Static forms have no submit handler/backend; on WordPress the form is WPForms.

**Cache busting:** some pages reference assets with `?v=YYYYMMDD<letter>` (notably `index-v2.html`). Bump the query on every page that links a changed file.

**Page groups:**
- Service landings: `t2med-wechsel-v2`, `praxisgruendung-it`, `praxisuebernahme-it`, `praxissoftware-wechsel`, `ueber-uns`, `karriere`.
- Wissen: `wissen.html` hub + `wissen-*.html` articles (long-form ones have images in `Fotos/wissen/`, FAQ and cross-links); OG images live in `Fotos/og/`.
- `index-v2.html` hero: the customer chose the former `hero-v5` variant — a horizontal image accordion (Gründung, Übergabe, Softwarewechsel) synced with the headline words. Its assets keep the V5 names: `css/hero-v5.css` (replaces `css/index-v2.css` completely), `js/hero-v5.js`, `Fotos/Hero-v5/`.
- `Archiv/hero-varianten/`: the rejected hero variants `hero-v2`–`v4`, `hero-v6`, `hero-v7` and the previous carousel home (`index-v2-karussell.html`). Their CSS/JS/images stay in `css/`, `js/`, `Fotos/`; asset paths inside the archived HTML are not adjusted to the subfolder.
- `design-handout*.html`: internal design references, not site pages.

## Conventions

- Git commit messages are short German summaries (see `git log`).
