<?php
/**
 * Backup integrity manifest management.
 *
 * @package Leadwerk_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Leadwerk_Migration_Integrity {

	const STATUS_VERIFIED  = 'verified';
	const STATUS_MODIFIED  = 'modified';
	const STATUS_MISSING   = 'missing';
	const STATUS_UNTRACKED = 'untracked';
	const STATUS_ERROR     = 'error';

	/**
	 * Record the backup produced by an export pipeline.
	 *
	 * @param array $params Export parameters.
	 * @return array|WP_Error
	 */
	public static function record_export( $params ) {
		if ( ! is_array( $params ) || empty( $params['archive'] ) || ! is_string( $params['archive'] ) ) {
			return new WP_Error(
				'leadwerk_migration_integrity_missing_archive',
				__( 'The exported backup filename is missing.', 'leadwerk-migration' )
			);
		}

		$source = 'export';

		if ( ! empty( $params['leadwerk_migration_manual_backup'] ) || ! empty( $params['leadwerk_migration_manual_export'] ) ) {
			$source = 'manual';
		} elseif ( function_exists( 'leadwerk_migration_is_scheduled_backup' ) && leadwerk_migration_is_scheduled_backup() ) {
			$source = 'scheduled';
		}

		return self::record_file( $params['archive'], $source );
	}

	/**
	 * Establish or replace the SHA-256 baseline for a backup.
	 *
	 * @param string $filename Backup filename, without a path.
	 * @param string $source   Origin of the backup.
	 * @return array|WP_Error
	 */
	public static function record_file( $filename, $source = 'manual' ) {
		$path = self::resolve_backup_path( $filename );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$snapshot = self::snapshot( $path );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$source = self::normalize_source( $source );
		$now    = gmdate( 'c' );

		$manifest = array(
			'filename'    => $filename,
			'algorithm'   => 'sha256',
			'sha256'      => $snapshot['sha256'],
			'size'        => $snapshot['size'],
			'mtime'       => $snapshot['mtime'],
			'source'      => $source,
			'recorded_at' => $now,
		);

		$sidecar = self::write_sidecar( $path, $manifest );
		if ( is_wp_error( $sidecar ) ) {
			return $sidecar;
		}

		$manifests              = self::get_manifests();
		$previous_manifest      = isset( $manifests[ $filename ] ) ? $manifests[ $filename ] : null;
		$manifests[ $filename ] = $manifest;

		$saved = self::save_manifests( $manifests );
		if ( is_wp_error( $saved ) ) {
			// Keep the option and sidecar in agreement if database persistence fails.
			if ( is_array( $previous_manifest ) ) {
				self::write_sidecar( $path, $previous_manifest );
			} else {
				@unlink( self::sidecar_path( $path ) );
			}

			return $saved;
		}

		return $manifest;
	}

	/**
	 * Verify a backup against its stored SHA-256 baseline.
	 *
	 * When $establish is true, an untracked file is recorded as a new baseline.
	 * Existing baselines are never silently replaced.
	 *
	 * @param string  $filename  Backup filename, without a path.
	 * @param boolean $establish Establish a missing baseline.
	 * @return array|WP_Error
	 */
	public static function verify( $filename, $establish = false ) {
		$path = self::resolve_backup_path( $filename );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		if ( ! is_file( $path ) ) {
			return new WP_Error(
				'leadwerk_migration_integrity_backup_missing',
				__( 'The backup file could not be found.', 'leadwerk-migration' ),
				array(
					'filename' => $filename,
					'status'   => self::STATUS_MISSING,
				)
			);
		}

		$manifests = self::get_manifests();
		if ( ! isset( $manifests[ $filename ] ) || ! is_array( $manifests[ $filename ] ) ) {
			if ( $establish ) {
				return self::record_file( $filename, 'baseline' );
			}

			return new WP_Error(
				'leadwerk_migration_integrity_untracked',
				__( 'No integrity baseline exists for this backup.', 'leadwerk-migration' ),
				array(
					'filename' => $filename,
					'status'   => self::STATUS_UNTRACKED,
				)
			);
		}

		$manifest = $manifests[ $filename ];
		if (
			empty( $manifest['sha256'] ) ||
			! is_string( $manifest['sha256'] ) ||
			! preg_match( '/\A[a-f0-9]{64}\z/i', $manifest['sha256'] )
		) {
			return new WP_Error(
				'leadwerk_migration_integrity_invalid_manifest',
				__( 'The stored integrity manifest is invalid.', 'leadwerk-migration' ),
				array(
					'filename' => $filename,
					'status'   => self::STATUS_ERROR,
				)
			);
		}

		$snapshot = self::snapshot( $path );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$expected_hash = strtolower( $manifest['sha256'] );
		$actual_hash   = strtolower( $snapshot['sha256'] );
		$verified      = hash_equals( $expected_hash, $actual_hash );

		if ( ! $verified ) {
			return new WP_Error(
				'leadwerk_migration_integrity_mismatch',
				__( 'The backup integrity check failed. The file has changed since its baseline was recorded.', 'leadwerk-migration' ),
				array(
					'filename'        => $filename,
					'status'          => self::STATUS_MODIFIED,
					'expected_sha256' => $expected_hash,
					'actual_sha256'   => $actual_hash,
					'expected_size'   => isset( $manifest['size'] ) ? $manifest['size'] : null,
					'actual_size'     => $snapshot['size'],
				)
			);
		}

		return array(
			'filename'        => $filename,
			'status'          => self::STATUS_VERIFIED,
			'verified'        => true,
			'expected_sha256' => $expected_hash,
			'actual_sha256'   => $actual_hash,
			'expected_size'   => isset( $manifest['size'] ) ? $manifest['size'] : null,
			'actual_size'     => $snapshot['size'],
			'mtime'           => $snapshot['mtime'],
			'verified_at'     => gmdate( 'c' ),
		);
	}

	/**
	 * Get the current integrity state of a backup.
	 *
	 * @param string  $filename Backup filename, without a path.
	 * @param boolean $deep     Hash the complete file instead of comparing metadata.
	 * @return string|WP_Error
	 */
	public static function get_status( $filename, $deep = true ) {
		$path = self::resolve_backup_path( $filename );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		if ( ! is_file( $path ) ) {
			return self::STATUS_MISSING;
		}

		$manifests = self::get_manifests();
		if ( ! isset( $manifests[ $filename ] ) ) {
			return self::STATUS_UNTRACKED;
		}

		// Dashboard lists must not hash several multi-gigabyte archives on every
		// page load. A shallow status is conservative: any metadata change is
		// marked modified until the explicit Verify action performs a full hash.
		if ( ! $deep ) {
			$manifest = $manifests[ $filename ];
			if ( ! is_array( $manifest ) || ! isset( $manifest['size'], $manifest['mtime'] ) ) {
				return self::STATUS_ERROR;
			}

			$size  = @filesize( $path );
			$mtime = @filemtime( $path );
			if ( false === $size || false === $mtime ) {
				return self::STATUS_ERROR;
			}

			return (int) $manifest['size'] === (int) $size && (int) $manifest['mtime'] === (int) $mtime
				? self::STATUS_VERIFIED
				: self::STATUS_MODIFIED;
		}

		$result = self::verify( $filename );
		if ( ! is_wp_error( $result ) ) {
			return self::STATUS_VERIFIED;
		}

		$data = $result->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			return $data['status'];
		}

		return self::STATUS_ERROR;
	}

	/**
	 * Return all recorded manifests, keyed by backup filename.
	 *
	 * @return array
	 */
	public static function get_manifests() {
		$manifests = get_option( LEADWERK_MIGRATION_BACKUP_MANIFESTS, array() );

		return is_array( $manifests ) ? $manifests : array();
	}

	/**
	 * Remove a backup baseline and its sidecar.
	 *
	 * @param string $filename Backup filename, without a path.
	 * @return boolean|WP_Error
	 */
	public static function remove( $filename ) {
		$path = self::resolve_backup_path( $filename );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$manifests = self::get_manifests();
		if ( isset( $manifests[ $filename ] ) ) {
			unset( $manifests[ $filename ] );

			$saved = self::save_manifests( $manifests );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		$sidecar = self::sidecar_path( $path );
		if ( ( file_exists( $sidecar ) || is_link( $sidecar ) ) && ! @unlink( $sidecar ) ) {
			return new WP_Error(
				'leadwerk_migration_integrity_sidecar_remove_failed',
				__( 'The backup integrity sidecar could not be removed.', 'leadwerk-migration' ),
				array( 'filename' => $filename )
			);
		}

		return true;
	}

	/**
	 * Resolve a filename to a direct child of the backups directory.
	 *
	 * Paths, traversal segments, unsupported extensions and symlinks escaping
	 * the backups directory are rejected.
	 *
	 * @param string $filename Backup filename, without a path.
	 * @return string|WP_Error
	 */
	public static function resolve_backup_path( $filename ) {
		$valid = self::validate_filename( $filename );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( ! defined( 'LEADWERK_MIGRATION_BACKUPS_PATH' ) ) {
			return new WP_Error(
				'leadwerk_migration_integrity_backups_path_missing',
				__( 'The backups directory is not configured.', 'leadwerk-migration' )
			);
		}

		$base = rtrim( (string) LEADWERK_MIGRATION_BACKUPS_PATH, "/\\" );
		if ( $base === '' || strpos( $base, chr( 0 ) ) !== false || ! Leadwerk_Migration_Security::path_is_private( $base ) ) {
			return new WP_Error(
				'leadwerk_migration_integrity_backups_path_invalid',
				__( 'The backups directory is invalid.', 'leadwerk-migration' )
			);
		}

		$path      = $base . DIRECTORY_SEPARATOR . $filename;
		$base_real = realpath( $base );

		if ( $base_real === false || ! is_dir( $base_real ) || is_link( $base ) ) {
			return new WP_Error(
				'leadwerk_migration_integrity_backups_path_unresolved',
				__( 'The backups directory could not be resolved safely.', 'leadwerk-migration' )
			);
		}

		if ( file_exists( $path ) || is_link( $path ) ) {
			$path_real = realpath( $path );
			if ( $path_real === false || $base_real === false || ! self::is_path_inside( $path_real, $base_real ) ) {
				return new WP_Error(
					'leadwerk_migration_integrity_unsafe_path',
					__( 'The backup path is outside the configured backups directory.', 'leadwerk-migration' ),
					array( 'filename' => $filename )
				);
			}
		}

		return $base_real . DIRECTORY_SEPARATOR . $filename;
	}

	/**
	 * Validate a backup filename without normalizing unsafe input.
	 *
	 * @param mixed $filename Candidate filename.
	 * @return true|WP_Error
	 */
	private static function validate_filename( $filename ) {
		if ( ! is_string( $filename ) || $filename === '' ) {
			return new WP_Error(
				'leadwerk_migration_integrity_invalid_filename',
				__( 'A valid backup filename is required.', 'leadwerk-migration' )
			);
		}

		$forward_slashes = str_replace( '\\', '/', $filename );
		if (
			$forward_slashes !== basename( $forward_slashes ) ||
			$filename !== basename( $filename ) ||
			preg_match( '/[\x00-\x1f\x7f<>:"|?*]/', $filename )
		) {
			return new WP_Error(
				'leadwerk_migration_integrity_unsafe_filename',
				__( 'Backup paths and unsafe filename characters are not allowed.', 'leadwerk-migration' ),
				array( 'filename' => $filename )
			);
		}

		if ( strlen( $filename ) <= 7 || substr( $filename, -7 ) !== '.wpress' ) {
			return new WP_Error(
				'leadwerk_migration_integrity_invalid_extension',
				__( 'Only .wpress backup files can have integrity manifests.', 'leadwerk-migration' ),
				array( 'filename' => $filename )
			);
		}

		return true;
	}

	/**
	 * Calculate a stable file snapshot.
	 *
	 * @param string $path Absolute backup path.
	 * @return array|WP_Error
	 */
	private static function snapshot( $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error(
				'leadwerk_migration_integrity_backup_unreadable',
				__( 'The backup file does not exist or is not readable.', 'leadwerk-migration' ),
				array( 'filename' => basename( $path ) )
			);
		}

		clearstatcache( true, $path );
		$size_before  = @filesize( $path );
		$mtime_before = @filemtime( $path );
		$sha256       = @hash_file( 'sha256', $path );
		clearstatcache( true, $path );
		$size_after  = @filesize( $path );
		$mtime_after = @filemtime( $path );

		if ( $sha256 === false || ! is_string( $sha256 ) || ! preg_match( '/\A[a-f0-9]{64}\z/i', $sha256 ) ) {
			return new WP_Error(
				'leadwerk_migration_integrity_hash_failed',
				__( 'The SHA-256 checksum for the backup could not be calculated.', 'leadwerk-migration' ),
				array( 'filename' => basename( $path ) )
			);
		}

		if ( $size_before === false || $mtime_before === false || $size_after === false || $mtime_after === false ) {
			return new WP_Error(
				'leadwerk_migration_integrity_stat_failed',
				__( 'The backup file metadata could not be read.', 'leadwerk-migration' ),
				array( 'filename' => basename( $path ) )
			);
		}

		if ( $size_before !== $size_after || $mtime_before !== $mtime_after ) {
			return new WP_Error(
				'leadwerk_migration_integrity_file_changed',
				__( 'The backup changed while its integrity checksum was being calculated. Please try again.', 'leadwerk-migration' ),
				array( 'filename' => basename( $path ) )
			);
		}

		return array(
			'sha256' => strtolower( $sha256 ),
			'size'   => $size_after,
			'mtime'  => $mtime_after,
		);
	}

	/**
	 * Atomically write a manifest next to its backup.
	 *
	 * @param string $backup_path Absolute backup path.
	 * @param array  $manifest    Manifest data.
	 * @return true|WP_Error
	 */
	private static function write_sidecar( $backup_path, $manifest ) {
		$sidecar = self::sidecar_path( $backup_path );
		$flags   = 0;

		if ( defined( 'JSON_PRETTY_PRINT' ) ) {
			$flags |= JSON_PRETTY_PRINT;
		}

		if ( defined( 'JSON_UNESCAPED_SLASHES' ) ) {
			$flags |= JSON_UNESCAPED_SLASHES;
		}

		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $manifest, $flags ) : json_encode( $manifest, $flags );
		if ( ! is_string( $json ) ) {
			return new WP_Error(
				'leadwerk_migration_integrity_json_failed',
				__( 'The backup integrity manifest could not be encoded.', 'leadwerk-migration' ),
				array( 'filename' => basename( $backup_path ) )
			);
		}

		$json .= "\n";
		$dir   = dirname( $sidecar );

		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return new WP_Error(
				'leadwerk_migration_integrity_sidecar_directory_unwritable',
				__( 'The backups directory is not writable, so the integrity sidecar could not be saved.', 'leadwerk-migration' ),
				array( 'filename' => basename( $backup_path ) )
			);
		}

		$temp = @tempnam( $dir, '.leadwerk-integrity-' );
		if ( $temp === false || realpath( dirname( $temp ) ) !== realpath( $dir ) ) {
			if ( is_string( $temp ) && file_exists( $temp ) ) {
				@unlink( $temp );
			}

			return new WP_Error(
				'leadwerk_migration_integrity_temp_failed',
				__( 'A temporary integrity manifest could not be created.', 'leadwerk-migration' ),
				array( 'filename' => basename( $backup_path ) )
			);
		}

		$handle = @fopen( $temp, 'wb' );
		if ( $handle === false ) {
			@unlink( $temp );

			return new WP_Error(
				'leadwerk_migration_integrity_temp_open_failed',
				__( 'The temporary integrity manifest could not be opened.', 'leadwerk-migration' ),
				array( 'filename' => basename( $backup_path ) )
			);
		}

		$written = 0;
		$length  = strlen( $json );
		$locked  = @flock( $handle, LOCK_EX );

		while ( $locked && $written < $length ) {
			$bytes = @fwrite( $handle, substr( $json, $written ) );
			if ( $bytes === false || $bytes === 0 ) {
				break;
			}

			$written += $bytes;
		}

		$flushed = $written === $length && @fflush( $handle );
		if ( $locked ) {
			@flock( $handle, LOCK_UN );
		}
		@fclose( $handle );

		if ( ! $locked || ! $flushed ) {
			@unlink( $temp );

			return new WP_Error(
				'leadwerk_migration_integrity_sidecar_write_failed',
				__( 'The integrity sidecar could not be written completely.', 'leadwerk-migration' ),
				array( 'filename' => basename( $backup_path ) )
			);
		}

		@chmod( $temp, 0600 );
		if ( ! @rename( $temp, $sidecar ) ) {
			@unlink( $temp );

			return new WP_Error(
				'leadwerk_migration_integrity_sidecar_replace_failed',
				__( 'The integrity sidecar could not be replaced atomically.', 'leadwerk-migration' ),
				array( 'filename' => basename( $backup_path ) )
			);
		}

		return true;
	}

	/**
	 * Persist the manifest index without autoloading it on every request.
	 *
	 * @param array $manifests Filename-keyed manifest map.
	 * @return true|WP_Error
	 */
	private static function save_manifests( $manifests ) {
		$updated = update_option( LEADWERK_MIGRATION_BACKUP_MANIFESTS, $manifests, false );
		if ( $updated || get_option( LEADWERK_MIGRATION_BACKUP_MANIFESTS, array() ) === $manifests ) {
			return true;
		}

		return new WP_Error(
			'leadwerk_migration_integrity_manifest_save_failed',
			__( 'The backup integrity manifest could not be saved to the database.', 'leadwerk-migration' )
		);
	}

	/**
	 * Normalize a source label for safe storage and display.
	 *
	 * @param mixed $source Source label.
	 * @return string
	 */
	private static function normalize_source( $source ) {
		if ( ! is_scalar( $source ) ) {
			return 'manual';
		}

		$source = strtolower( trim( (string) $source ) );
		$source = preg_replace( '/[^a-z0-9_-]+/', '-', $source );
		$source = trim( $source, '-' );

		return $source === '' ? 'manual' : substr( $source, 0, 64 );
	}

	/**
	 * Build the sidecar path for a backup.
	 *
	 * @param string $backup_path Absolute backup path.
	 * @return string
	 */
	private static function sidecar_path( $backup_path ) {
		return $backup_path . '.leadwerk.json';
	}

	/**
	 * Check whether a canonical path is a child of a canonical directory.
	 *
	 * @param string $path Canonical file path.
	 * @param string $base Canonical base directory.
	 * @return boolean
	 */
	private static function is_path_inside( $path, $base ) {
		$path = str_replace( '\\', '/', $path );
		$base = rtrim( str_replace( '\\', '/', $base ), '/' ) . '/';

		if ( DIRECTORY_SEPARATOR === '\\' ) {
			$path = strtolower( $path );
			$base = strtolower( $base );
		}

		return strpos( $path, $base ) === 0;
	}
}
