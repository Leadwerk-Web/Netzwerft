<?php
/**
 * Copyright (C) 2014-2025 ServMask Inc.
 * Modifications Copyright (C) 2026 Leadwerk.
 * Copyright (C) 2026 Leadwerk.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 * Upstream attribution: This file derives from the All-in-One WP Migration
 * plugin. Leadwerk replaced the upstream extension placeholders with a
 * native, security-conscious WP-CLI interface.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access is not allowed.' );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	class Leadwerk_Migration_WP_CLI_Command extends WP_CLI_Command {

		/**
		 * Create a complete backup using the synchronous export pipeline.
		 *
		 * ## OPTIONS
		 *
		 * [--exclude-media]
		 * : Exclude the media library.
		 *
		 * [--exclude-themes]
		 * : Exclude themes.
		 *
		 * [--exclude-plugins]
		 * : Exclude regular plugins.
		 *
		 * [--exclude-mu-plugins]
		 * : Exclude must-use plugins.
		 *
		 * [--exclude-database]
		 * : Exclude the database.
		 *
		 * [--exclude-spam-comments]
		 * : Exclude spam comments.
		 *
		 * [--exclude-post-revisions]
		 * : Exclude post revisions.
		 *
		 * [--exclude-email-replace]
		 * : Do not replace email domains in the exported database.
		 *
		 * [--exclude=<paths>]
		 * : Comma-separated paths relative to wp-content to exclude. Absolute
		 *   paths and traversal segments are rejected.
		 *
		 * [--exclude-table=<tables>]
		 * : Comma-separated database table names to exclude.
		 *
		 * ## EXAMPLES
		 *
		 *     wp leadwerk-migration export
		 *     wp leadwerk-migration export --exclude-media --exclude=cache,uploads/private
		 *     wp leadwerk-migration export --exclude-table=wp_actionscheduler_logs
		 *
		 * @subcommand export
		 */
		public function export( $args = array(), $assoc_args = array() ) {
			unset( $args );

			$params = array(
				'priority'                         => 5,
				'secret_key'                       => self::server_secret(),
				'leadwerk_migration_internal'      => 1,
				'leadwerk_migration_inline'        => 1,
				'leadwerk_migration_manual_backup' => 1,
				'options'                          => self::export_options( $assoc_args ),
			);

			self::add_file_exclusions( $params, $assoc_args );
			self::add_table_exclusions( $params, $assoc_args );

			WP_CLI::log( __( 'Creating a Leadwerk backup. This may take several minutes...', 'leadwerk-migration' ) );

			try {
				$result = Leadwerk_Migration_Export_Controller::export( $params );
			} catch ( Throwable $exception ) {
				WP_CLI::error(
					sprintf(
						/* translators: %s: exception message. */
						__( 'Backup failed: %s', 'leadwerk-migration' ),
						wp_strip_all_tags( $exception->getMessage() )
					)
				);
				return;
			}

			if ( ! is_array( $result ) || empty( $result['archive'] ) || ! is_string( $result['archive'] ) ) {
				WP_CLI::error( __( 'The export pipeline finished without returning a backup filename.', 'leadwerk-migration' ) );
				return;
			}

			$archive = $result['archive'];
			$path    = Leadwerk_Migration_Integrity::resolve_backup_path( $archive );
			if ( is_wp_error( $path ) || ! is_file( $path ) ) {
				$message = is_wp_error( $path ) ? $path->get_error_message() : __( 'The exported backup file could not be found.', 'leadwerk-migration' );
				WP_CLI::error( $message );
				return;
			}

			// The normal export-done hook records the manifest. Keep CLI exports
			// self-contained if another plugin has removed that hook.
			$manifests = Leadwerk_Migration_Integrity::get_manifests();
			if ( ! isset( $manifests[ $archive ] ) ) {
				$manifest = Leadwerk_Migration_Integrity::record_file( $archive, 'manual' );
				if ( is_wp_error( $manifest ) ) {
					WP_CLI::warning(
						sprintf(
							/* translators: %s: integrity error. */
							__( 'The backup was created, but its integrity manifest could not be recorded: %s', 'leadwerk-migration' ),
							$manifest->get_error_message()
						)
					);
				}
			}

			$bytes = @filesize( $path );
			$size  = $bytes === false ? __( 'unknown size', 'leadwerk-migration' ) : size_format( $bytes, 2 );
			WP_CLI::success(
				sprintf(
					/* translators: 1: backup filename, 2: formatted file size. */
					__( 'Backup created: %1$s (%2$s)', 'leadwerk-migration' ),
					$archive,
					$size
				)
			);
			WP_CLI::line( $path );
		}

		/**
		 * Alias for `export`.
		 *
		 * See `wp help leadwerk-migration export` for available options.
		 *
		 * ## EXAMPLES
		 *
		 *     wp leadwerk-migration backup
		 *     wp leadwerk-migration backup --exclude-media
		 *
		 * @subcommand backup
		 */
		public function backup( $args = array(), $assoc_args = array() ) {
			$this->export( $args, $assoc_args );
		}

		/**
		 * List locally stored backups.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : Output format. Accepts table or json.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - json
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     wp leadwerk-migration list
		 *     wp leadwerk-migration list --format=json
		 *
		 * @subcommand list
		 */
		public function _list( $args = array(), $assoc_args = array() ) {
			unset( $args );

			$format = self::output_format( $assoc_args );
			$rows   = array();
			foreach ( Leadwerk_Migration_Backups::get_files() as $backup ) {
				$filename = isset( $backup['filename'] ) ? (string) $backup['filename'] : '';
				$status   = Leadwerk_Migration_Integrity::get_status( $filename );
				if ( is_wp_error( $status ) ) {
					$status = Leadwerk_Migration_Integrity::STATUS_ERROR;
				}

				$mtime = isset( $backup['mtime'] ) && is_numeric( $backup['mtime'] ) ? (int) $backup['mtime'] : 0;
				$size  = isset( $backup['size'] ) && is_numeric( $backup['size'] ) ? (int) $backup['size'] : null;
				$rows[] = array(
					'filename'  => $filename,
					'size'      => $size,
					'size_human' => $size === null ? '-' : size_format( $size, 2 ),
					'modified'  => $mtime > 0 ? gmdate( 'Y-m-d H:i:s', $mtime ) . ' UTC' : '-',
					'integrity' => $status,
				);
			}

			if ( $format === 'json' ) {
				WP_CLI::line( wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}

			if ( empty( $rows ) ) {
				WP_CLI::log( __( 'No backups found.', 'leadwerk-migration' ) );
				return;
			}

			\WP_CLI\Utils\format_items( 'table', $rows, array( 'filename', 'size_human', 'modified', 'integrity' ) );
		}

		/**
		 * Verify backup SHA-256 integrity.
		 *
		 * ## OPTIONS
		 *
		 * [<filename>]
		 * : Backup filename. Paths are not accepted.
		 *
		 * [--all]
		 * : Verify every local backup.
		 *
		 * [--establish]
		 * : Record a baseline for backups that do not have one. Existing
		 *   baselines are never replaced.
		 *
		 * [--format=<format>]
		 * : Output format. Accepts table or json.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - json
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     wp leadwerk-migration verify example.wpress
		 *     wp leadwerk-migration verify --all
		 *     wp leadwerk-migration verify old-backup.wpress --establish
		 *
		 * @subcommand verify
		 */
		public function verify( $args = array(), $assoc_args = array() ) {
			$verify_all = self::flag_enabled( $assoc_args, 'all' );
			$establish  = self::flag_enabled( $assoc_args, 'establish' );
			$filename   = isset( $args[0] ) ? (string) $args[0] : '';

			if ( $verify_all && $filename !== '' ) {
				WP_CLI::error( __( 'Provide either a filename or --all, not both.', 'leadwerk-migration' ) );
				return;
			}
			if ( ! $verify_all && $filename === '' ) {
				WP_CLI::error( __( 'Provide a backup filename or use --all.', 'leadwerk-migration' ) );
				return;
			}

			$filenames = array();
			if ( $verify_all ) {
				foreach ( Leadwerk_Migration_Backups::get_files() as $backup ) {
					if ( isset( $backup['filename'] ) ) {
						$filenames[] = (string) $backup['filename'];
					}
				}
			} else {
				$filenames[] = $filename;
			}

			if ( empty( $filenames ) ) {
				WP_CLI::log( __( 'No backups found.', 'leadwerk-migration' ) );
				return;
			}

			$rows     = array();
			$failures = 0;
			foreach ( array_values( array_unique( $filenames ) ) as $candidate ) {
				$result = Leadwerk_Migration_Integrity::verify( $candidate, $establish );
				if ( is_wp_error( $result ) ) {
					$data   = $result->get_error_data();
					$status = is_array( $data ) && isset( $data['status'] ) ? (string) $data['status'] : Leadwerk_Migration_Integrity::STATUS_ERROR;
					$rows[] = array(
						'filename' => $candidate,
						'status'   => $status,
						'sha256'   => isset( $data['actual_sha256'] ) ? (string) $data['actual_sha256'] : '-',
						'message'  => $result->get_error_message(),
					);
					++$failures;
					continue;
				}

				$is_baseline = ! isset( $result['status'] );
				$rows[] = array(
					'filename' => $candidate,
					'status'   => $is_baseline ? 'baseline-recorded' : (string) $result['status'],
					'sha256'   => isset( $result['actual_sha256'] ) ? (string) $result['actual_sha256'] : ( isset( $result['sha256'] ) ? (string) $result['sha256'] : '-' ),
					'message'  => $is_baseline ? __( 'Integrity baseline recorded.', 'leadwerk-migration' ) : __( 'Integrity verified.', 'leadwerk-migration' ),
				);
			}

			$format = self::output_format( $assoc_args );
			if ( $format === 'json' ) {
				WP_CLI::line( wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			} else {
				\WP_CLI\Utils\format_items( 'table', $rows, array( 'filename', 'status', 'sha256', 'message' ) );
			}

			if ( $failures > 0 ) {
				WP_CLI::error(
					sprintf(
						/* translators: %d: number of failed integrity checks. */
						_n( '%d backup failed integrity verification.', '%d backups failed integrity verification.', $failures, 'leadwerk-migration' ),
						$failures
					)
				);
				return;
			}

			WP_CLI::success( __( 'Backup integrity verification completed.', 'leadwerk-migration' ) );
		}

		/**
		 * Display migration health checks.
		 *
		 * ## OPTIONS
		 *
		 * [--refresh]
		 * : Ignore the cached health report.
		 *
		 * [--strict]
		 * : Return a non-zero exit code when a critical check exists.
		 *
		 * [--format=<format>]
		 * : Output format. Accepts table or json.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - json
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     wp leadwerk-migration health
		 *     wp leadwerk-migration health --refresh --format=json
		 *     wp leadwerk-migration health --strict
		 *
		 * @subcommand health
		 */
		public function health( $args = array(), $assoc_args = array() ) {
			unset( $args );

			$report = Leadwerk_Migration_Health::get_report( self::flag_enabled( $assoc_args, 'refresh' ) );
			$format = self::output_format( $assoc_args );

			if ( $format === 'json' ) {
				WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			} else {
				$rows = array();
				foreach ( isset( $report['checks'] ) && is_array( $report['checks'] ) ? $report['checks'] : array() as $check ) {
					$rows[] = array(
						'check'   => isset( $check['label'] ) ? (string) $check['label'] : ( isset( $check['id'] ) ? (string) $check['id'] : '-' ),
						'status'  => isset( $check['status'] ) ? (string) $check['status'] : '-',
						'value'   => isset( $check['value'] ) ? self::display_value( $check['value'] ) : '-',
						'message' => isset( $check['message'] ) ? (string) $check['message'] : '-',
					);
				}
				\WP_CLI\Utils\format_items( 'table', $rows, array( 'check', 'status', 'value', 'message' ) );

				$score  = isset( $report['score'] ) ? (int) $report['score'] : 0;
				$counts = isset( $report['counts'] ) && is_array( $report['counts'] ) ? $report['counts'] : array();
				WP_CLI::log(
					sprintf(
						/* translators: 1: health score, 2: OK checks, 3: warning checks, 4: critical checks. */
						__( 'Score: %1$d/100 | OK: %2$d | Warnings: %3$d | Critical: %4$d', 'leadwerk-migration' ),
						$score,
						isset( $counts['ok'] ) ? (int) $counts['ok'] : 0,
						isset( $counts['warning'] ) ? (int) $counts['warning'] : 0,
						isset( $counts['critical'] ) ? (int) $counts['critical'] : 0
					)
				);
			}

			$critical = isset( $report['counts']['critical'] ) ? (int) $report['counts']['critical'] : 0;
			if ( $critical > 0 && self::flag_enabled( $assoc_args, 'strict' ) ) {
				WP_CLI::error(
					sprintf(
						/* translators: %d: number of critical checks. */
						_n( '%d critical migration health check failed.', '%d critical migration health checks failed.', $critical, 'leadwerk-migration' ),
						$critical
					)
				);
			}
		}

		/**
		 * Inspect or run backup automation.
		 *
		 * ## OPTIONS
		 *
		 * <action>
		 * : Action to perform.
		 * ---
		 * options:
		 *   - status
		 *   - run
		 * ---
		 *
		 * [--format=<format>]
		 * : Output format for `status`. Accepts table or json.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - json
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     wp leadwerk-migration schedule status
		 *     wp leadwerk-migration schedule status --format=json
		 *     wp leadwerk-migration schedule run
		 *
		 * @subcommand schedule
		 */
		public function schedule( $args = array(), $assoc_args = array() ) {
			$action = isset( $args[0] ) ? strtolower( (string) $args[0] ) : '';
			if ( ! in_array( $action, array( 'status', 'run' ), true ) ) {
				WP_CLI::error( __( 'Schedule action must be either status or run.', 'leadwerk-migration' ) );
				return;
			}

			if ( ! class_exists( 'Leadwerk_Migration_Scheduler' ) ) {
				WP_CLI::error( __( 'The Leadwerk automation service is not available.', 'leadwerk-migration' ) );
				return;
			}

			if ( $action === 'run' ) {
				WP_CLI::log( __( 'Running an automated backup now...', 'leadwerk-migration' ) );
				$result = Leadwerk_Migration_Scheduler::run( 'manual' );
				if ( is_wp_error( $result ) ) {
					WP_CLI::error( $result->get_error_message() );
					return;
				}

				$archive = is_array( $result ) && isset( $result['archive'] ) ? (string) $result['archive'] : '';
				WP_CLI::success(
					$archive === ''
						? __( 'The scheduled backup run completed.', 'leadwerk-migration' )
						: sprintf(
							/* translators: %s: backup filename. */
							__( 'Scheduled backup created: %s', 'leadwerk-migration' ),
							$archive
						)
				);
				return;
			}

			$settings = Leadwerk_Migration_Scheduler::get_settings();
			$next_run = Leadwerk_Migration_Scheduler::next_run();
			$status   = array(
				'settings'     => $settings,
				'next_run'     => $next_run ? (int) $next_run : null,
				'next_run_utc' => $next_run ? gmdate( 'Y-m-d H:i:s', (int) $next_run ) . ' UTC' : null,
				'running'      => defined( 'LEADWERK_MIGRATION_AUTOMATION_LOCK' ) && (bool) get_option( LEADWERK_MIGRATION_AUTOMATION_LOCK, false ),
			);

			if ( self::output_format( $assoc_args ) === 'json' ) {
				WP_CLI::line( wp_json_encode( $status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}

			$rows = array();
			foreach ( is_array( $settings ) ? $settings : array() as $key => $value ) {
				$rows[] = array( 'setting' => (string) $key, 'value' => self::display_value( $value ) );
			}
			$rows[] = array( 'setting' => 'next_run', 'value' => $status['next_run_utc'] === null ? '-' : $status['next_run_utc'] );
			$rows[] = array( 'setting' => 'running', 'value' => $status['running'] ? 'yes' : 'no' );
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'setting', 'value' ) );
		}

		/**
		 * Importing changes the site and is intentionally unavailable in WP-CLI.
		 *
		 * Use the authenticated Leadwerk Import screen or REST API so validation
		 * and confirmation can be completed interactively.
		 *
		 * @subcommand import
		 */
		public function import( $args = array(), $assoc_args = array() ) {
			unset( $args, $assoc_args );
			self::unsupported_site_write( 'import' );
		}

		/**
		 * Restoring changes the site and is intentionally unavailable in WP-CLI.
		 *
		 * @subcommand restore
		 */
		public function restore( $args = array(), $assoc_args = array() ) {
			unset( $args, $assoc_args );
			self::unsupported_site_write( 'restore' );
		}

		/**
		 * Resetting a site is intentionally unavailable in WP-CLI.
		 *
		 * @subcommand reset
		 */
		public function reset( $args = array(), $assoc_args = array() ) {
			unset( $args, $assoc_args );
			self::unsupported_site_write( 'reset' );
		}

		/**
		 * Build the pipeline's boolean export options from CLI flags.
		 *
		 * @param array $assoc_args Associative CLI arguments.
		 * @return array
		 */
		private static function export_options( $assoc_args ) {
			$options = array();
			$map     = array(
				'exclude-spam-comments'   => 'no_spam_comments',
				'exclude-post-revisions'  => 'no_post_revisions',
				'exclude-media'           => 'no_media',
				'exclude-themes'          => 'no_themes',
				'exclude-mu-plugins'      => 'no_muplugins',
				'exclude-plugins'         => 'no_plugins',
				'exclude-database'        => 'no_database',
				'exclude-email-replace'   => 'no_email_replace',
				'exclude-cache'           => 'no_cache',
				'exclude-inactive-themes' => 'no_inactive_themes',
				'exclude-inactive-plugins' => 'no_inactive_plugins',
			);

			foreach ( $map as $flag => $option ) {
				if ( self::flag_enabled( $assoc_args, $flag ) || self::flag_enabled( $assoc_args, str_replace( 'exclude-', 'no-', $flag ) ) ) {
					$options[ $option ] = 1;
				}
			}

			return $options;
		}

		/**
		 * Validate and add wp-content-relative path exclusions.
		 *
		 * @param array $params     Pipeline parameters, passed by reference.
		 * @param array $assoc_args Associative CLI arguments.
		 * @return void
		 */
		private static function add_file_exclusions( &$params, $assoc_args ) {
			if ( ! isset( $assoc_args['exclude'] ) || $assoc_args['exclude'] === '' ) {
				return;
			}

			$paths = self::comma_list( $assoc_args['exclude'] );
			$clean = array();
			foreach ( $paths as $path ) {
				$normalized = str_replace( '\\', '/', trim( $path ) );
				$segments   = explode( '/', $normalized );
				if (
					$normalized === '' ||
					$normalized[0] === '/' ||
					preg_match( '/^[A-Za-z]:\//', $normalized ) ||
					in_array( '..', $segments, true ) ||
					strpos( $normalized, chr( 0 ) ) !== false ||
					preg_match( '/[\x00-\x1f\x7f]/', $normalized )
				) {
					WP_CLI::error(
						sprintf(
							/* translators: %s: unsafe exclusion path. */
							__( 'Unsafe exclusion path: %s', 'leadwerk-migration' ),
							$path
						)
					);
					return;
				}

				$normalized = preg_replace( '#(^|/)\./#', '$1', $normalized );
				$normalized = trim( preg_replace( '#/+#', '/', $normalized ), '/' );
				if ( $normalized !== '' ) {
					$clean[] = $normalized;
				}
			}

			if ( ! empty( $clean ) ) {
				$params['options']['exclude_files'] = 1;
				$params['excluded_files']           = implode( ',', array_values( array_unique( $clean ) ) );
			}
		}

		/**
		 * Validate and add database table exclusions.
		 *
		 * @param array $params     Pipeline parameters, passed by reference.
		 * @param array $assoc_args Associative CLI arguments.
		 * @return void
		 */
		private static function add_table_exclusions( &$params, $assoc_args ) {
			if ( ! isset( $assoc_args['exclude-table'] ) || $assoc_args['exclude-table'] === '' ) {
				return;
			}

			$tables = self::comma_list( $assoc_args['exclude-table'] );
			$clean  = array();
			foreach ( $tables as $table ) {
				if ( ! preg_match( '/^[A-Za-z0-9_$-]+$/', $table ) ) {
					WP_CLI::error(
						sprintf(
							/* translators: %s: invalid table name. */
							__( 'Invalid database table name: %s', 'leadwerk-migration' ),
							$table
						)
					);
					return;
				}
				$clean[] = $table;
			}

			if ( ! empty( $clean ) ) {
				$params['options']['exclude_db_tables'] = 1;
				$params['excluded_db_tables']           = implode( ',', array_values( array_unique( $clean ) ) );
			}
		}

		/**
		 * Return a valid server secret, creating one when upgrading an old site.
		 *
		 * @return string
		 */
		private static function server_secret() {
			$secret = get_option( LEADWERK_MIGRATION_SECRET_KEY, '' );
			if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
				$secret = Leadwerk_Migration_Security::generate_secret();
				update_option( LEADWERK_MIGRATION_SECRET_KEY, $secret, false );
			}

			return $secret;
		}

		/**
		 * Read a comma-separated argument (or an array supplied by a test runner).
		 *
		 * @param mixed $value CLI argument value.
		 * @return array
		 */
		private static function comma_list( $value ) {
			$values = is_array( $value ) ? $value : array( $value );
			$items  = array();
			foreach ( $values as $entry ) {
				foreach ( explode( ',', (string) $entry ) as $item ) {
					$item = trim( $item );
					if ( $item !== '' ) {
						$items[] = $item;
					}
				}
			}

			return $items;
		}

		/**
		 * Get and validate the requested output format.
		 *
		 * @param array $assoc_args Associative CLI arguments.
		 * @return string
		 */
		private static function output_format( $assoc_args ) {
			$format = isset( $assoc_args['format'] ) ? strtolower( (string) $assoc_args['format'] ) : 'table';
			if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
				WP_CLI::error( __( 'Format must be either table or json.', 'leadwerk-migration' ) );
				return 'table';
			}

			return $format;
		}

		/**
		 * Interpret a WP-CLI flag without treating --flag=false as enabled.
		 *
		 * @param array  $assoc_args Associative CLI arguments.
		 * @param string $key        Flag name.
		 * @return bool
		 */
		private static function flag_enabled( $assoc_args, $key ) {
			if ( ! array_key_exists( $key, $assoc_args ) ) {
				return false;
			}

			$value = $assoc_args[ $key ];
			return ! in_array( $value, array( false, 0, '0', 'false', 'no', 'off' ), true );
		}

		/**
		 * Convert nested values to a readable table cell.
		 *
		 * @param mixed $value Value to display.
		 * @return string
		 */
		private static function display_value( $value ) {
			if ( is_bool( $value ) ) {
				return $value ? 'yes' : 'no';
			}
			if ( $value === null || $value === '' ) {
				return '-';
			}
			if ( is_scalar( $value ) ) {
				return (string) $value;
			}

			return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
		}

		/**
		 * Report intentionally unsupported destructive operations.
		 *
		 * @param string $operation Operation name.
		 * @return void
		 */
		private static function unsupported_site_write( $operation ) {
			if ( 'reset' === $operation ) {
				$message = __( 'Site reset is not provided by Leadwerk Migration WP-CLI. Create and verify a backup first, then use a purpose-built WordPress reset workflow.', 'leadwerk-migration' );
			} elseif ( 'restore' === $operation ) {
				$message = __( 'Restore is not available in WP-CLI because it can overwrite the current site. Use the authenticated Leadwerk Migration Import screen, where the backup is validated before confirmation.', 'leadwerk-migration' );
			} else {
				$message = __( 'Import is not available in WP-CLI because it can overwrite the current site. Use the authenticated Leadwerk Migration Import screen or import REST workflow, where validation and confirmation are explicit.', 'leadwerk-migration' );
			}

			WP_CLI::error(
				$message
			);
		}
	}
}
