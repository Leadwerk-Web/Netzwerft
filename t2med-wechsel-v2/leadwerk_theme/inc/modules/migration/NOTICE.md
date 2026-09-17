# Notices

## Leadwerk modifications

Copyright © 2026 Leadwerk.

Leadwerk Migration & Backup 1.0.0 is an independent modified distribution released under the GNU General Public License, version 3 or later. Leadwerk is not affiliated with, sponsored by, or endorsed by ServMask.

## Upstream work

This distribution incorporates the upstream changes through **All-in-One WP Migration 7.109**.

Copyright © 2014–2025 ServMask Inc.

The upstream work was distributed under the GNU General Public License, version 3. Its copyright notices and GPL source headers have been retained. The complete GPLv3 text is included in `LICENSE`.

“All-in-One WP Migration” and “ServMask” are names of their respective owners and are used here only to identify the upstream work.

## Third-party component

The bundled Bandar template library is distributed under the MIT License. Its original license text is retained at `lib/vendor/bandar/bandar/LICENSE`.

## Source availability

This package contains the PHP, templates, and JavaScript/CSS files loaded by the plugin. Leadwerk-authored assets are included in editable source form. Some inherited JavaScript/CSS assets remain in the minified form supplied with the upstream 7.109 distribution; this notice does not represent those minified files as the preferred form for modification. Redistributors remain responsible for satisfying the GPL corresponding-source requirements for the form they distribute.

## Runtime data

Temporary migration data and backups use separately randomized private directories rather than a directory inside this plugin. Leadwerk prefers a writable parent beside the WordPress root only when that parent is outside the detected document root; otherwise it falls back to the operating-system temporary directory and reports that persistent backups should be moved. A web-validated private root and its document-root evidence are reused by WP-CLI and system cron so those contexts do not permanently demote durable storage onto a temporary volume. The plugin publishes no runtime-storage URL, streams administrative log and backup downloads through protected handlers, and refuses migration paths that resolve below a detected web root. Generated deny rules are defense in depth, not the primary access control.
