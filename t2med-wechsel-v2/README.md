# Leadwerk T2med WordPress package

This folder contains four installable WordPress packages:

- `leadwerk-fields`
- `leadwerk-wpml-clone`
- `leadwerk_importer`
- `leadwerk_theme`

## Requirements

- WordPress 6.9+
- PHP 8.1+
- WPForms Lite or Pro 1.10.2+

## Installation order

1. Copy the three plugin folders to `wp-content/plugins/`.
2. Copy `leadwerk_theme` to `wp-content/themes/`.
3. Activate Leadwerk Fields and Leadwerk WPML Clone.
4. Activate WPForms and the Leadwerk T2med theme.
5. Activate Leadwerk Importer.
6. Open **Tools → T2med Import**, run a dry-run, then run the live import.
7. Open **Leadwerk Optionen** and complete the legal review checklist.

The live import is resumable and journaled. It sets the T2med page as the front
page only after media, pages, fields, WPForms, links and SEO complete.

## Editing

All T2med page content is edited in the page’s Leadwerk Fields metabox. Global
logo, contact, navigation and WPForms values are edited under **Leadwerk
Optionen**. Internal links are stored as page references plus optional anchors.

English is disabled by default. If it is enabled later under **Settings →
Leadwerk Sprachen**, English clones are created as drafts and must be translated
and published manually.

## Validation

Run:

```bash
php tests/validate-package.php
```

When Composer development dependencies are installed, `composer test` also runs
PHPUnit and WordPress Coding Standards.

