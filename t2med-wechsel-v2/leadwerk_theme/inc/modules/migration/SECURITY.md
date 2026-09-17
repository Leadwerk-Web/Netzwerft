# Security policy

Leadwerk Migration performs privileged filesystem and database operations. Only trusted administrators should receive import capability.

## Operational guidance

* Serve WordPress over HTTPS and keep WordPress, PHP, and this plugin updated.
* Run the Overview health checks before a migration.
* Accept archives only from trusted sources and verify their SHA-256 baseline before long-term storage.
* Keep an independent recovery backup outside the destination server.
* Use a real system cron for predictable automation on low-traffic sites.
* Do not expose the randomized backup or runtime working directory through an alias, CDN, or custom web-server rule. Leadwerk rejects paths detected below a web root, but custom aliases cannot always be discovered from PHP.
* If the health report says backups are using the operating-system temporary directory, configure `LEADWERK_MIGRATION_PRIVATE_ROOT` (or the path-specific constants) to a persistent, writable directory outside every document root; hosts may clean temporary files.
* For encrypted exports, use a unique password of at least 12 characters and store it in a password manager outside WordPress. Losing it makes the archive unrecoverable.

## Archive encryption

New password-protected exports use the Leadwerk AES-256-GCM v3 format with PBKDF2-SHA256 key derivation, a random 128-bit salt, and a unique 96-bit nonce per encrypted chunk. Each chunk's additional authenticated data binds its archive identifier, normalized path, total file size, byte offset, and plaintext length. A final password-authenticated manifest covers every preceding archive header and payload byte, including `package.json` and the complete ordered file set. The importer verifies this manifest before destructive extraction and fails closed when v3 metadata or framing is missing, inconsistent, or changed.

Legacy archives, including older AES-CBC archives, lack the v3 keyed whole-archive manifest. Compatibility imports display a distinct warning and require explicit confirmation before destructive processing. Treat these archives as unauthenticated even when they are password-protected: trust their provenance and verify an independent checksum first. Leadwerk never creates new archives in the legacy AES-CBC format.

## Runtime storage

Temporary job data and durable backups use separate randomized directories outside the plugin tree. Leadwerk first chooses a writable WordPress-root parent only when it is outside the detected document root, then falls back to the operating-system temporary directory. Paths resolving below `ABSPATH` or the server document root fail closed. The plugin publishes no URL for runtime data; administrative log and backup downloads are streamed through capability- and nonce-protected handlers. Because PHP cannot discover every reverse-proxy alias, CDN mapping, or custom server rule, operators must still verify the effective deployment layout.

A successful HTTP request records the validating document root with the chosen private path so WP-CLI and system cron reuse the same durable volume. CLI processes without `DOCUMENT_ROOT` may use the temporary directory for that request, but they do not permanently overwrite a web-validated sibling path.

Browser pipeline continuations require a purpose-bound operation credential plus a signed, expiring ticket bound to the job, archive, and restore mode. Local browser restores persist verified-copy markers in the job status so password and confirm prompts keep reading the private workspace after a reload. REST imports of encrypted archives require a password acknowledgement and a separate destructive confirmation. Backup mutations additionally require a logged-in user, an action nonce, and the exact backup credential scope. Read-only status credentials are sent in POST bodies rather than query strings.

## Site Sync and Hub

Local, staging and live environments never expose an inbound Site Sync endpoint. They send signed outbound HTTPS requests to the configured Hub. After initial registration, every request uses an environment-specific secret, a timestamp, a unique nonce, a body hash and HMAC-SHA256; the Hub rejects replayed nonces and clock skew. Environment secrets are encrypted at rest by the Hub with AES-256-GCM and WordPress key material. Site Family and environment secrets are never rendered in wp-admin.

Site Families and environments with valid cryptographic proofs activate automatically during heartbeat enrollment. There is no separate registration, Hub approval or dual-environment transfer approval workflow. The Hub retains explicit emergency revoke/reactivate controls. Before a job is created, both endpoints must be active, online within fifteen minutes, report exactly equal WordPress and Leadwerk Migration versions, and use the same PHP major.minor branch. PHP patch releases may differ within that branch. The target repeats runtime checks from signed archive metadata immediately before destructive import.

Runtime reconciliation commands are restricted to the authenticated Site Family and only the named target may report progress. Hub-built plugin packages are kept in private relay storage, delivered through authenticated bounded chunks and verified against an exact SHA-256 manifest before the WordPress upgrader receives them. Downgrades are refused. WordPress core upgrades use official update offers. PHP changes are never simulated: they require a deployment-specific provider filter or an HTTPS hosting webhook signed with a secret that is stored but never rendered in wp-admin. Runtime updates and full-site transfer cannot execute in the same worker request. Environments older than 1.3.0 require one manual bootstrap because they do not contain this command-verification worker.

Any authenticated environment in a Site Family may initiate a transfer between any two active siblings in that family. Starting the clearly labeled full-site sync action is authorization to replace the selected target's WordPress site data. The target still creates a private safety backup before receiving data, verifies the complete archive hash and repeats compatibility checks before import.

The source-generated transfer backup is deleted only after Hub records successful completion of the destination import. Cleanup is bound to the source environment's local job state, accepts only a direct supported backup filename and requires an integrity manifest whose origin is `sync`. Its archive, integrity sidecar, manifest and label are removed together. A failed or canceled transfer never triggers this automatic source deletion.

Each physical WordPress installation receives a random marker in private storage outside the exported WordPress tree. A hostname change on the same installation therefore preserves its environment credential and can promote staging to live. A copied database or restored backup does not carry that marker; the receiving installation rotates to a new environment ID and requires one-time Hub enrollment. This prevents two active endpoints from silently sharing the same credential.

Relay archives live outside the web root, are addressed only by random job IDs, transfer in bounded resumable chunks, and require a full SHA-256 match before import. The destination makes a local safety backup first. Relay files expire and completed or failed job files are removed. Configure a real five-minute system cron on low-traffic sites because WordPress cron cannot send heartbeats without incoming requests.

## Reporting

Report suspected vulnerabilities privately to the package maintainer. Include the affected version, reproducible steps, and impact. Do not attach live site archives, passwords, tokens, database dumps, or personal information.
