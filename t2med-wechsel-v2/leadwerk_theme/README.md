# Leadwerk T2med Theme Suite

Version 3.0.8 includes the former Leadwerk plugins directly as theme modules:

- Leadwerk Fields 2.0.2
- Leadwerk Importer 3.1.5
- Leadwerk WPML Clone 4.3.3
- Leadwerk Migration 1.5.11

All existing post meta, options, Site Family identity, backup manifests and sync
state keep their original keys. No content or settings migration is required.

## Admin navigation

The modules are available below **Leadwerk Optionen**:

- Leadwerk Optionen (global fields)
- T2med Import
- Übersetzungen (translation dashboard)
- Sprachen
- Übersetzung: Diagnose
- Migration: Overview
- Migration: Export
- Migration: Import
- Migration: Backups
- Migration: Automation
- Migration: Site Sync

When English is enabled, logged-in editors also see `Sprache: DE/EN` in the
WordPress admin bar. It links to the counterpart editor or offers creation of a
missing EN draft. The translation dashboard shows every page pair and review
state; it can create all missing EN drafts in one nonce-protected action.

WPML Clone 4.3.3 provides the ACM-grade segment workflow in a T2med-specific form:
structured field and HTML-segment editing, exact-match translation memory,
source-change review flags without overwriting EN text, localized global
header/footer option strings, list-table language filters, published-pair-only
routes, switcher links, canonicals, hreflang and sitemap integration. Media and
attachments remain shared; page-reference fields map to a published EN pair or
fall back safely to DE.

Version 4.3.3 uses the ACM browser-extension DeepL workflow instead of a paid API:
single/section/bulk translation with adaptive short waits, progress, cancellation and a
dashboard queue that translates and saves every untranslated or review-required page plus pending
global header/footer strings, editable theme
and WPForms runtime strings, translated media alt/title/caption data, Yoast SEO
segments, an optional automatic frontend switcher, and conservative identity /
public-slug diagnostics. The English front-page counterpart uses the complete
landing-page template and form while remaining unavailable until published.

The accessible floating language control is enabled by default on every frontend
page. It stays globe-only until hover, keyboard focus or touch; its compact menu
links only to published counterparts and marks unavailable translations clearly.

DeepL output that is intentionally identical to the source (for example names,
brands or text already written in English) is accepted and retained. Failed icon
or translation attempts restore the previous EN value without clearing the field.

## Upgrade from separate plugins

1. Install and activate this theme.
2. Deactivate `leadwerk-fields`, `leadwerk_importer`, `leadwerk-wpml-clone` and
   `leadwerk-migration` together.
3. Confirm that the module versions appear under Leadwerk Optionen.
4. Delete the four inactive plugin directories.

Do not deactivate WPForms; it remains an external dependency of the T2med form.

## Migration release updates

The embedded Migration core remains publisher-signed. Hub can continue using
its signed release channel. A newer verified release is staged, verified again,
atomically promoted into the active theme and removed from the temporary plugin
location, so WordPress does not retain a separate Migration plugin.
