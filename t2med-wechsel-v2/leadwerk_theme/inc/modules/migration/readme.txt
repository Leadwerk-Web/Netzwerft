=== Leadwerk Migration & Backup ===
Contributors: leadwerk
Tags: migration, backup, restore, automation, wp-cli
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.5.13
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Secure WordPress migrations with verified private backups, automation, retention, environment health checks, REST, and WP-CLI.

== Description ==

Leadwerk Migration & Backup packages a WordPress site into a portable `.wpress` archive and imports compatible archives through a guided workflow.

Leadwerk adds an operations layer around the proven migration pipeline:

* Migration health dashboard with PHP, WordPress, filesystem privacy/writability, disk, memory, upload, HTTPS, OpenSSL, cron, and database checks.
* Randomized backup and runtime working directories outside detected web roots, with authenticated download streaming and defense-in-depth deny rules.
* SHA-256 integrity manifests and on-demand verification.
* Optional AES-256-GCM v3 archive encryption with PBKDF2-SHA256 key derivation, path- and position-bound chunk authentication, and a final keyed whole-archive manifest.
* Hourly, daily, weekly, or monthly local automation.
* Count-based retention limited to backups created by the scheduler.
* Optional success and failure email notifications.
* Atomic backup locking with a progress heartbeat and a bounded activity history.
* Capability, exact-scope and action-nonce checks for administrative actions, plus job-bound signed continuation tickets.
* Job-scoped, expiring tokens for REST import polling; the server secret is never returned by REST.
* Real WP-CLI backup, list, verify, health, and schedule commands.
* Fail-closed import guard requiring the backup and destination to use the exact same WordPress core version.
* Hub-coordinated Site Sync between local, staging and live environments using job-scoped authenticated peer-to-peer transfer only; Hub never stores WordPress archives.
* Zone Zero Site Family identity, unique per-environment credentials, event-driven workers, one-minute active-job watchdog and visible online/offline state.
* Fail-closed sync preflight requiring exact WordPress/plugin versions and the same PHP major.minor branch before a backup or destructive action begins.
* Automatic destination safety backup, resumable SHA-256-verified direct transfer and no per-job dual-environment approval.
* Automatic source sync-backup deletion only after the destination import is reported complete; failed/canceled job copies are retained.
* Identity-preserving staging-to-live promotion when an existing installation receives its customer domain, with a manual promotion control for custom staging hostnames.
* Explicit administrator-controlled Site Family creation plus automatic registration of signed Family-less installations as Hub-visible available targets.
* Hub-admin assignment of an available target to a source Family inside the same atomic operation that creates the peer-to-peer sync job.
* Direction-independent full-site sync: any family member can start local, staging or live transfers between any two active sibling environments.
* Site-Family-scoped transfer and runtime job history with cancel/retry controls for both job types.
* Publisher-signed automatic Migration release channel plus family-scoped WordPress upgrades to the highest sibling version.

Leadwerk Migration is an independent GPLv3 fork. It is not affiliated with or endorsed by ServMask. See `NOTICE.md` for upstream and third-party attribution.

== Installation ==

1. Copy the `leadwerk-migration` directory into `/wp-content/plugins/` or upload the release ZIP from Plugins → Add New.
2. Activate **Leadwerk Migration & Backup**.
3. Open Leadwerk Migration → Overview and run the health checks.
4. Create a backup before importing into a production site.

Plugin activation, heartbeat and export never create a Site Family. Open Leadwerk Migration → Site Sync and use **Create Family ID for this site** only on the project origin. A Family-less installation instead registers as an **Available target** after its publisher-signed plugin integrity is verified. Available records are throttled, capped, hidden from ordinary family panels and removed after seven offline days. On the central Hub panel, enable **List WordPress environments without a Family ID** to select one as a destination. Hub atomically assigns that destination to the source Family while creating the peer-to-peer job; a failed job-creation transaction leaves it unassigned.

Sibling environments normally inherit the Site Family identity through a trusted full WordPress restore or clone. Their private physical-installation marker is not copied, so each sibling automatically receives a unique environment credential while retaining the shared family. From any sibling panel, an administrator can choose any two active family environments and start local → staging, staging → live, live → staging or another direction.

A full-site sync transfers the WordPress database, uploads/media, plugins, themes and must-use plugins. The destination takes a safety backup first and imports only after the received archive passes SHA-256 verification. Archive bytes move directly between compatible family environments: local sources push outward to public targets, while local targets pull from public sources. Hub issues short-lived job capabilities and stores progress metadata only. If the peers cannot connect, the job fails closed and no relay copy is created. WordPress core, the server PHP runtime, `wp-config.php`, DNS, SSL certificates and hosting configuration are not transferred. WordPress and Leadwerk Migration must match exactly; PHP patch releases may differ when both environments use the same major.minor branch (for example, 8.4.2 and 8.4.67).

When the destination is detected or explicitly marked as live, the post-import finalizer always sets WordPress search-engine visibility to public (`blog_public=1`). This prevents a local/staging “Discourage search engines” setting from making the live site noindex.

Migration plugin reconciliation is automatic. The highest publisher-signed version reported by an online environment uploads itself to Hub; Hub verifies its Ed25519 signature and SHA-256 hash, updates itself, then queues the same signed release for every older environment in every Site Family. After a target has the current Migration worker, Hub compares WordPress versions only inside that target’s Site Family and upgrades lower siblings to the family’s highest reported version through the official WordPress Core Upgrader. WordPress downgrades are never attempted. PHP is not changed automatically and remains a sync compatibility gate. An existing environment must run version 1.3.0 or later once to have the secure command worker; after that bootstrap, future Migration and family WordPress upgrades are zero-touch.

Every environment sends a heartbeat to the Hub through WP-Cron every minute and is shown as offline after fifteen minutes. Actionable jobs and commands wake public workers immediately with a signed event; partial batches schedule their own immediate continuation. A one-minute watchdog recovers consumed or overlapping worker events and handles `.local` environments that cannot accept inbound callbacks. WordPress cron requires site traffic, so configure a real server cron to call `wp-cron.php` every minute for reliable active-job processing. Hostnames ending in `.local` are detected as local, and any hostname containing `myrdbx.io` is detected as staging.

By default, Leadwerk prefers a writable directory beside the WordPress root only when it is outside the detected document root. If that location is unavailable or web-served, it falls back to the operating-system temporary directory. It does not publish a runtime URL, administrative downloads pass through protected PHP endpoints, and migrations fail closed for paths detected below a web root. The Overview screen reports privacy, writability, and the temporary-backup fallback. For durable backups, define `LEADWERK_MIGRATION_PRIVATE_ROOT` in `wp-config.php` as a writable persistent path outside every document root.

== Frequently Asked Questions ==

= Where are backups stored? =

Backups and temporary migration files use separate randomized directories. Leadwerk prefers a writable parent outside the detected document root and otherwise uses the operating-system temporary directory. The health report warns when durable backups need a persistent custom `LEADWERK_MIGRATION_PRIVATE_ROOT`. The plugin installs defense-in-depth deny rules, publishes no runtime-storage URL, and sends downloads through capability-checked, nonce-protected PHP streams. Administrators must still verify that custom aliases, CDNs, and web-server configuration do not expose either directory.

= Can I automate backups without a cloud account? =

Yes. Leadwerk uses WP-Cron for local backup schedules. Low-traffic sites should configure a system cron to call `wp-cron.php` regularly.

= How does integrity verification work? =

Every Leadwerk-created backup receives a SHA-256 manifest and sidecar. Verification hashes the current file and uses a timing-safe comparison against the stored baseline.

= How are password-protected exports encrypted? =

New encrypted exports use the Leadwerk AES-256-GCM v3 format. Each chunk is authenticated with additional data that binds the archive identifier, normalized path, file size, byte offset, and chunk length. A final password-authenticated manifest covers every preceding archive header and payload byte, including the ordered file set, and is verified before destructive extraction. Keys are derived with PBKDF2-SHA256 and a random salt. Use a unique password of at least 12 characters and store it outside WordPress.

Legacy archives, including older AES-CBC archives, do not contain the v3 keyed whole-archive manifest. They remain importable for compatibility, but Leadwerk displays a distinct unauthenticated-archive warning and requires explicit confirmation before the destructive import stage. Continue only when the source is trusted and an independent checksum has been verified.

= Does the plugin support one-click restore from the backup list? =

Restore is intentionally routed through the Import screen so the destructive confirmation and compatibility checks remain visible. Download the backup, open Import, and select the `.wpress` file.

= Which WP-CLI commands are available? =

Run `wp help leadwerk-migration`. Commands include `backup`, `export`, `list`, `verify`, `health`, and `schedule`.

== Security ==

Imports overwrite site data. Use HTTPS, restrict administrator access, verify archive provenance, and maintain an independent recovery copy. Report suspected vulnerabilities privately to the site owner or package maintainer; do not include secrets or archive contents in public reports.

== Changelog ==

= 1.5.13 =
* Store WP Mail SMTP settings in a backup sidecar and restore them after import so encrypted passwords survive prefix and URL replacement.

= 1.5.12 =
* Keep WP Mail SMTP settings and the `wp_mail_smtp_mail_key` encryption key when a backup is imported, including across different table prefixes.

= 1.5.11 =
* Makes Site Family creation an explicit Site Sync administrator action; activation, workers and exports never create one.
* Registers signed Family-less installations as bounded, expiring Hub available targets and lets Hub admins assign them atomically when starting P2P sync.
* Forces WordPress search-engine visibility on after importing into a live target.

= 1.5.10 =
* Includes importing/finalizing targets in the active worker watchdog.
* Persists secrets-free, job-bound import continuation state and safely re-dispatches a dropped loopback after five minutes.
* Restarts a legacy stalled import once from its already verified local archive when no continuation state exists.

= 1.5.9 =
* Continues direct-push source batches after Hub advances an active job to `receiving`, removing the 64 MiB stall.
* Releases the Site Sync worker lock at shutdown when an import pipeline terminates the request before `finally` can run.
* Treats a verified final-chunk progress callback as idempotent when the target importer has already advanced the Hub job.

= 1.5.8 =
* Prevents empty WordPress restore targets from creating or registering unrelated Site Families.
* Creates new family identities automatically only for content-bearing origin sites, while preserving an explicit wp-config override for legitimate configuration-only origins.
* Keeps empty targets ready to adopt the trusted source family and a new per-installation environment identity during full-site restore.
* Discards local-only provisional identities created by older versions on still-empty, never-enrolled targets without touching approved or inherited families.

= 1.5.7 =
* Removes the Preflight and target protection explanation from the Site Sync interface while retaining every server-side safety gate.

= 1.5.6 =
* Removes the redundant Zero-touch Hub connection panel and moves Site Family identity into a content-sized four-card metrics row.
* Keeps staging promotion available as a separate conditional control.

= 1.5.5 =
* Ensures compact source and target controls stack at the WordPress mobile breakpoint without horizontal overflow.

= 1.5.4 =
* Moves WP-Cron guidance and transfer preflight/target-protection details into compact disclosures, keeping safety information available without extending the page by default.

= 1.5.3 =
* Arranges Site Family identity, Zero-touch Hub connection, environments and full-site synchronization in compact equal two-column panels on desktop.
* Keeps source and target selectors side by side on desktop and returns all compact grids to one column on narrow screens.
* Collapses automatic update history by default, opens it for active work and bounds long job tables with internal scrolling.

= 1.5.2 =
* Shows the Site Sync settings panel only on the configured Hub WordPress installation.
* Rejects direct settings submissions from local, staging and ordinary live family environments.
* Keeps Hub settings recoverable on the configured Hub URL if Hub Mode is temporarily disabled.

= 1.5.1 =
* Starts full-site synchronization through authenticated AJAX without reloading or changing the administrator's scroll position.
* Shows inline starting, success and failure feedback and immediately refreshes the synchronization job table.
* Keeps the existing nonce-protected admin-post route as a no-JavaScript fallback.

= 1.5.0 =
* Removes Hub backup relay routes and fallback behavior; full-site archive bytes are peer-to-peer only.
* Fails and cleans legacy relay jobs during the Hub schema upgrade.
* Requires a fresh HMAC enrollment proof, global/per-IP throttling, a 5,000-family Hub ceiling and a 16-environment per-family ceiling.
* Restricts browser and REST exports/backups to administrators with `manage_options`.
* Incorporates upstream All-in-One WP Migration changes through 7.109, including the 7.108 multisite import capability hardening.

= 1.4.7 =
* Marks Hub REST responses noindex, nofollow and noarchive through X-Robots-Tag.
* Adds Hub REST paths to a valid WordPress robots.txt user-agent group while keeping normal Leadwerk website pages indexable.
* Prevents Hub API responses from being stored by intermediary caches.

= 1.4.4 =
* Refreshes active Site Sync and runtime tables every second through AJAX.
* Reports archive verification, private workspace preparation, WordPress import steps and finalization to Hub.
* Keeps canceled synchronization jobs visible for one hour, then deletes their Hub rows and local partial state.
* Releases the worker lock before the asynchronous importer takes over, preventing stale transfer locks.

= 1.4.3 =

* Scope failed automatic-release cooldowns to the exact attempted version, so a failed older publish cannot delay a newer signed release.
* Align Site Sync panel heartbeat and watchdog guidance with the one-minute recovery schedule.

= 1.4.2 =

* Increase direct family chunks from 2 MB to 8 MB and process up to eight chunks per worker batch.
* Requeue a worker event when simultaneous workers contend for the same lock.
* Add a one-minute active-job watchdog so a consumed loopback event cannot leave transfers waiting for five minutes.
* Continue verified Site Sync imports asynchronously after the archive download instead of stopping after the first import stage.

= 1.4.1 =

* Detect the highest WordPress core version independently inside every Site Family.
* Queue lower sibling environments for an automatic exact-version upgrade after their Migration worker is current.
* Use the official WordPress Core Upgrader with rollback support, never downgrade, and verify the resulting version on the next heartbeat.

= 1.4.0 =

* Transfer full-site archives directly between Site Family environments using short-lived job-scoped capabilities, with automatic private Hub fallback when peers cannot connect.
* Prepare the target recovery backup before peer bytes move and preserve SHA-256 verification, resumability and source-backup cleanup guarantees.
* Publish the newest Migration plugin to Hub automatically and distribute it across all families after Ed25519 publisher-signature and SHA-256 verification.
* Remove the manual runtime reconciliation panel; WordPress and PHP remain explicit compatibility gates and are not globally auto-updated.

= 1.3.5 =

* Refresh synchronization jobs, upload/download percentages, runtime stages, worker messages and environment heartbeat states through authenticated AJAX without reloading the page.
* Use one family-scoped Hub status snapshot per poll and adapt the interval to active or idle work; polling pauses in hidden browser tabs.
* Track target relay-download bytes independently from source upload bytes and show both transfer phases accurately.
* Refresh runtime/source/target environment selectors and Hub registry tables when their underlying records change.

= 1.3.4 =

* Wake public source and target workers as soon as a Hub job or runtime command becomes actionable.
* Continue partial upload, download and runtime-update batches immediately without waiting for the periodic heartbeat.
* Authenticate wake events with environment-specific HMAC signatures, timestamps, nonces and replay protection.
* Keep the five-minute worker schedule as a fallback for unreachable local environments and missed event delivery.

= 1.3.3 =

* Delete the source-generated Site Sync backup, integrity sidecar, manifest and label after the destination import reaches `complete`.
* Retain source backups for failed or canceled jobs and retry cleanup safely when filesystem deletion fails.
* Bind cleanup to the completed job's local source state and require the archive to be tracked with `source=sync`.

= 1.3.2 =

* Resolve runtime update downloads through the first verified private writable location among the existing work, private-root, backup and operating-system temporary paths.
* Probe actual write access before downloading, preserving fail-closed web-root protection while supporting restricted hosting layouts.

= 1.3.1 =

* Permit Site Sync between different PHP patch releases in the same major.minor branch.
* Continue blocking cross-branch PHP combinations such as 8.3.x and 8.1.x.
* Apply the same branch rule at local preflight, Hub create/retry and destination pre-import verification.

= 1.3.0 =

* Add family-wide runtime reconciliation jobs in plugin → WordPress → PHP order.
* Publish the Hub's current Leadwerk Migration package through authenticated chunk reads with SHA-256 verification before installation.
* Add exact WordPress core upgrade requests and an optional HMAC-signed hosting PHP webhook/provider connector.
* Show each Site Family its own complete transfer/update history and add cancel/retry management for transfer and runtime jobs.
* Prevent runtime mutation and full-site transfer from running in the same worker request.
* Require a one-time manual 1.3.0 bootstrap for targets that predate the secure command worker.

= 1.2.0 =

* Create Site Family identity automatically without requiring a Zone Zero backup.
* Automatically enroll and activate valid families/environments through heartbeat secret proofs; remove registration and Hub approval steps.
* Allow any family environment to initiate a full-site transfer between any two active sibling environments.
* Remove per-transfer source/target and typed live confirmation steps while retaining clear destructive target labeling and automatic target safety backups.
* Allow a fresh auto-initialized WordPress to adopt a trusted manually restored Site Family while rotating its environment credential.

= 1.1.1 =

* Preserve the environment ID when the same physical staging installation receives a customer domain and becomes live.
* Keep clone detection fail-closed through a private installation marker that is not included in WordPress backups.
* Add a manual `PROMOTE LIVE` action for staging sites whose custom hostname cannot be classified automatically.

= 1.1.0 =

* Added Zone Zero identity, central Hub enrollment and full local/staging/live Site Sync.
* Added five-minute outbound heartbeat monitoring with a fifteen-minute offline threshold.
* At release 1.1.0, require exact WordPress, PHP and plugin versions before synchronization and again before import; version 1.3.1 later relaxed only PHP patch-level equality.
* Removed per-job approval from both environments; retained explicit confirmation for a live target.
* Added resumable private relay transfer, SHA-256 verification and destination safety backups.

= 1.0.2 =

* Block imports before any destructive stage when the backup and destination WordPress versions differ.
* Block imports when the backup does not contain valid WordPress version metadata.
* Keep the pipeline completion state bound to signed continuation tickets.

= 1.0.1 =

* Preserve WP Mail SMTP option names and encryption keys when source and destination database table prefixes differ.

= 1.0.0 =

* Independent Leadwerk namespace and plugin identity.
* Added health dashboard, verified backups, scheduler, retention, activity log, and notifications.
* Added authenticated streaming downloads plus separate randomized backup and runtime working directories outside detected web roots, with a safe temporary fallback.
* Added AES-256-GCM v3 archive encryption with chunk AAD, a final keyed whole-archive manifest, and explicitly confirmed legacy archive read compatibility.
* Added scoped REST polling tokens, signed job-bound pipeline continuation tickets, and complete WP-CLI operational commands.
* Removed upstream advertising, updater wiring, and placeholder premium screens from the active interface.
* Preserved the GPL migration/import pipeline and upstream notices.
