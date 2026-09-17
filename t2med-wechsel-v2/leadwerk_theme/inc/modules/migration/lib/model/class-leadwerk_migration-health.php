<?php
/**
 * Runtime health checks for Leadwerk Migration.
 *
 * @package Leadwerk_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Leadwerk_Migration_Health {

	/**
	 * Transient used to avoid repeating filesystem and database checks.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'leadwerk_migration_health_cache';

	/**
	 * Request-local copy of the report.
	 *
	 * @var array|null
	 */
	private static $cached_report = null;

	/**
	 * Get the current migration health report.
	 *
	 * @param bool $force Regenerate the report instead of using the cache.
	 * @return array
	 */
	public static function get_report( $force = false ) {
		if ( ! $force && self::is_report( self::$cached_report ) ) {
			return self::$cached_report;
		}

		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( self::is_report( $cached ) ) {
				self::$cached_report = $cached;

				return $cached;
			}
		}

		$checks = array(
			self::check_php_version(),
			self::check_wordpress_version(),
			self::check_directory(
				'storage_writable',
				__( 'Storage directory', 'leadwerk-migration' ),
				defined( 'LEADWERK_MIGRATION_STORAGE_PATH' ) ? LEADWERK_MIGRATION_STORAGE_PATH : null
			),
			self::check_directory(
				'backups_writable',
				__( 'Backups directory', 'leadwerk-migration' ),
				defined( 'LEADWERK_MIGRATION_BACKUPS_PATH' ) ? LEADWERK_MIGRATION_BACKUPS_PATH : null
			),
			self::check_private_path(
				'storage_private',
				__( 'Storage privacy', 'leadwerk-migration' ),
				defined( 'LEADWERK_MIGRATION_STORAGE_PATH' ) ? LEADWERK_MIGRATION_STORAGE_PATH : null,
				false
			),
			self::check_private_path(
				'backups_private',
				__( 'Backup privacy', 'leadwerk-migration' ),
				defined( 'LEADWERK_MIGRATION_BACKUPS_PATH' ) ? LEADWERK_MIGRATION_BACKUPS_PATH : null,
				true
			),
			self::check_disk_space(),
			self::check_memory_limit(),
			self::check_execution_time(),
			self::check_upload_size(),
			self::check_https(),
			self::check_openssl(),
			self::check_wp_cron(),
			self::check_database(),
		);

		/**
		 * Filter the individual health checks before the score is calculated.
		 *
		 * Each check must contain id, label, status, message, and value. The
		 * status must be one of: ok, warning, or critical.
		 *
		 * @param array $checks Health checks.
		 */
		$checks = apply_filters( 'leadwerk_migration_health_checks', $checks );
		$checks = self::normalize_checks( $checks );
		$counts = self::count_statuses( $checks );

		$score = 100;
		if ( $counts['total'] > 0 ) {
			$score = (int) round(
				( ( $counts['ok'] + ( $counts['warning'] * 0.5 ) ) / $counts['total'] ) * 100
			);
		}

		$report = array(
			'checks'       => $checks,
			'counts'       => $counts,
			'score'        => max( 0, min( 100, $score ) ),
			'generated_at' => gmdate( DATE_ATOM ),
		);

		self::$cached_report = $report;

		$default_ttl = defined( 'MINUTE_IN_SECONDS' ) ? 5 * MINUTE_IN_SECONDS : 300;
		$cache_ttl   = (int) apply_filters( 'leadwerk_migration_health_cache_ttl', $default_ttl );
		set_transient( self::CACHE_KEY, $report, max( 1, $cache_ttl ) );

		return $report;
	}

	/**
	 * Clear both the request-local and persistent health caches.
	 *
	 * @return bool Whether the persistent transient was deleted.
	 */
	public static function clear_cache() {
		self::$cached_report = null;

		return delete_transient( self::CACHE_KEY );
	}

	/**
	 * Convert an INI-style byte value (for example, 256M) to bytes.
	 *
	 * A value of -1 is preserved to represent an unlimited setting.
	 *
	 * @param mixed $value   Value to parse.
	 * @param mixed $default Value returned when parsing fails.
	 * @return int|mixed
	 */
	public static function parse_bytes( $value, $default = 0 ) {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_float( $value ) ) {
			return self::clamp_bytes( $value );
		}

		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return $default;
		}

		$value = trim( (string) $value );
		if ( $value === '' ) {
			return $default;
		}

		if ( $value === '-1' ) {
			return -1;
		}

		if ( ! preg_match( '/^([+]?[0-9]+(?:\.[0-9]+)?)\s*([kmgtpe]?)(?:i?b)?$/i', $value, $matches ) ) {
			return $default;
		}

		$units = array(
			''  => 0,
			'k' => 1,
			'm' => 2,
			'g' => 3,
			't' => 4,
			'p' => 5,
			'e' => 6,
		);
		$unit  = strtolower( $matches[2] );
		$bytes = (float) $matches[1] * pow( 1024, $units[ $unit ] );

		return self::clamp_bytes( $bytes );
	}

	/**
	 * Read an INI setting and return its byte value.
	 *
	 * @param string $option  INI option name.
	 * @param mixed  $default Value returned when the option is unavailable.
	 * @return int|mixed
	 */
	public static function ini_bytes( $option, $default = null ) {
		$value = self::read_ini_value( $option );
		if ( $value === false || $value === '' ) {
			return $default;
		}

		return self::parse_bytes( $value, $default );
	}

	/**
	 * Alias with a more explicit name for consumers of the model.
	 *
	 * @param string $option  INI option name.
	 * @param mixed  $default Value returned when the option is unavailable.
	 * @return int|mixed
	 */
	public static function get_ini_bytes( $option, $default = null ) {
		return self::ini_bytes( $option, $default );
	}

	/**
	 * Format a byte count for health-check messages.
	 *
	 * @param int|float $bytes Byte count.
	 * @param int       $decimals Maximum number of decimals.
	 * @return string
	 */
	public static function format_bytes( $bytes, $decimals = 1 ) {
		if ( (float) $bytes < 0 ) {
			return 'Unlimited';
		}

		$bytes = (float) $bytes;
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB' );
		$unit  = 0;

		while ( $bytes >= 1024 && $unit < count( $units ) - 1 ) {
			$bytes /= 1024;
			++$unit;
		}

		$formatted = number_format( $bytes, max( 0, (int) $decimals ), '.', '' );
		$formatted = rtrim( rtrim( $formatted, '0' ), '.' );

		return $formatted . ' ' . $units[ $unit ];
	}

	/**
	 * Check the PHP runtime version.
	 *
	 * @return array
	 */
	private static function check_php_version() {
		$minimum = (string) apply_filters( 'leadwerk_migration_health_minimum_php_version', '7.4' );
		$status  = version_compare( PHP_VERSION, $minimum, '>=' ) ? 'ok' : 'critical';

		if ( $status === 'ok' ) {
			$message = sprintf(
				/* translators: 1: current PHP version, 2: minimum PHP version. */
				__( 'PHP %1$s meets the minimum requirement of %2$s.', 'leadwerk-migration' ),
				PHP_VERSION,
				$minimum
			);
		} else {
			$message = sprintf(
				/* translators: 1: current PHP version, 2: minimum PHP version. */
				__( 'PHP %1$s is below the required version %2$s.', 'leadwerk-migration' ),
				PHP_VERSION,
				$minimum
			);
		}

		return self::make_check( 'php_version', __( 'PHP version', 'leadwerk-migration' ), $status, $message, PHP_VERSION );
	}

	/**
	 * Check the WordPress runtime version.
	 *
	 * @return array
	 */
	private static function check_wordpress_version() {
		global $wp_version;

		$current = is_string( $wp_version ) ? $wp_version : '';
		if ( $current === '' && function_exists( 'get_bloginfo' ) ) {
			$current = (string) get_bloginfo( 'version' );
		}

		$minimum = (string) apply_filters( 'leadwerk_migration_health_minimum_wordpress_version', '6.2' );
		$status  = $current !== '' && version_compare( $current, $minimum, '>=' ) ? 'ok' : 'critical';

		if ( $current === '' ) {
			$message = __( 'The WordPress version could not be detected.', 'leadwerk-migration' );
		} elseif ( $status === 'ok' ) {
			$message = sprintf(
				/* translators: 1: current WordPress version, 2: minimum WordPress version. */
				__( 'WordPress %1$s meets the minimum requirement of %2$s.', 'leadwerk-migration' ),
				$current,
				$minimum
			);
		} else {
			$message = sprintf(
				/* translators: 1: current WordPress version, 2: minimum WordPress version. */
				__( 'WordPress %1$s is below the required version %2$s.', 'leadwerk-migration' ),
				$current,
				$minimum
			);
		}

		return self::make_check( 'wordpress_version', __( 'WordPress version', 'leadwerk-migration' ), $status, $message, $current );
	}

	/**
	 * Check that a configured directory supports the complete migration I/O cycle.
	 *
	 * @param string      $id    Check ID.
	 * @param string      $label Human-readable label.
	 * @param string|null $path  Directory path.
	 * @return array
	 */
	private static function check_directory( $id, $label, $path ) {
		$exists   = is_string( $path ) && $path !== '' && @is_dir( $path );
		$readable = false;
		$writable = false;
		$probe    = array(
			'created' => false,
			'written' => false,
			'read'    => false,
			'deleted' => false,
		);

		if ( $exists ) {
			try {
				$readable = @is_readable( $path );
				$writable = function_exists( 'wp_is_writable' ) ? @wp_is_writable( $path ) : @is_writable( $path );
			} catch ( Throwable $exception ) {
				$readable = false;
				$writable = false;
			}

			if ( $readable && $writable ) {
				$probe = self::probe_directory( $path );
			}
		}

		$value = array(
			'path'     => $path,
			'exists'   => $exists,
			'readable' => (bool) $readable,
			'writable' => (bool) $writable,
			'probe'    => $probe,
		);

		if ( ! is_string( $path ) || $path === '' ) {
			return self::make_check(
				$id,
				$label,
				'critical',
				sprintf( __( '%s is not configured.', 'leadwerk-migration' ), $label ),
				$value
			);
		}

		if ( ! $exists ) {
			return self::make_check(
				$id,
				$label,
				'critical',
				sprintf( __( '%s does not exist.', 'leadwerk-migration' ), $label ),
				$value
			);
		}

		if ( ! $readable ) {
			return self::make_check(
				$id,
				$label,
				'critical',
				sprintf( __( '%s is not readable.', 'leadwerk-migration' ), $label ),
				$value
			);
		}

		if ( ! $writable ) {
			return self::make_check(
				$id,
				$label,
				'critical',
				sprintf( __( '%s is not writable.', 'leadwerk-migration' ), $label ),
				$value
			);
		}

		if ( ! $probe['created'] || ! $probe['written'] || ! $probe['read'] || ! $probe['deleted'] ) {
			return self::make_check(
				$id,
				$label,
				'critical',
				sprintf( __( '%s failed the create, write, read, and delete verification.', 'leadwerk-migration' ), $label ),
				$value
			);
		}

		return self::make_check(
			$id,
			$label,
			'ok',
			sprintf( __( '%s passed the create, write, read, and delete verification.', 'leadwerk-migration' ), $label ),
			$value
		);
	}

	/**
	 * Exercise the filesystem operations required by migration jobs.
	 *
	 * The probe filename is generated locally, opened with exclusive-create mode,
	 * and removed in a finally block so a failed write or read cannot skip cleanup.
	 *
	 * @param string $path Existing directory path.
	 * @return array
	 */
	private static function probe_directory( $path ) {
		$result = array(
			'created' => false,
			'written' => false,
			'read'    => false,
			'deleted' => false,
		);
		$handle = null;
		$file   = null;

		try {
			try {
				$token = function_exists( 'random_bytes' ) ? bin2hex( random_bytes( 12 ) ) : '';
			} catch ( Throwable $exception ) {
				$token = '';
			}

			if ( $token === '' ) {
				$token = str_replace( '.', '', uniqid( '', true ) );
			}

			$file   = rtrim( $path, '/\\' ) . DIRECTORY_SEPARATOR . '.leadwerk-health-' . $token . '.tmp';
			$handle = @fopen( $file, 'x+b' );
			if ( ! is_resource( $handle ) ) {
				return $result;
			}

			$result['created'] = true;
			$payload           = 'leadwerk-health:' . $token;
			$written           = @fwrite( $handle, $payload );
			$result['written'] = $written === strlen( $payload ) && @fflush( $handle );

			if ( $result['written'] && @rewind( $handle ) ) {
				$result['read'] = @stream_get_contents( $handle ) === $payload;
			}
		} catch ( Throwable $exception ) {
			// The result flags identify the operation that failed.
		} finally {
			if ( is_resource( $handle ) ) {
				@fclose( $handle );
			}

			if ( $result['created'] && is_string( $file ) && $file !== '' && ( @file_exists( $file ) || @is_link( $file ) ) ) {
				@unlink( $file );
			}

			if ( $result['created'] && is_string( $file ) && $file !== '' ) {
				clearstatcache( true, $file );
				$result['deleted'] = ! @file_exists( $file ) && ! @is_link( $file );
			}
		}

		return $result;
	}

	/**
	 * Check that sensitive files are outside the web roots. Persistent backups
	 * in the operating-system temp directory are safe from HTTP access but may
	 * be cleaned by the host, so the report recommends a custom private path.
	 *
	 * @param string      $id Persistent check identifier.
	 * @param string      $label Human-readable label.
	 * @param string|null $path Candidate path.
	 * @param boolean     $persistent Whether the path stores persistent backups.
	 * @return array
	 */
	private static function check_private_path( $id, $label, $path, $persistent ) {
		$value = array(
			'path'       => $path,
			'private'    => Leadwerk_Migration_Security::path_is_private( $path ),
			'temporary'  => false,
		);

		if ( ! $value['private'] ) {
			return self::make_check( $id, $label, 'critical', __( 'The configured path is inside a web-served directory. Move it outside every document root before running a migration.', 'leadwerk-migration' ), $value );
		}

		$temp_root = realpath( sys_get_temp_dir() );
		$real_path = is_string( $path ) ? realpath( $path ) : false;
		if ( $temp_root !== false && $real_path !== false ) {
			$temp_root          = rtrim( str_replace( '\\', '/', $temp_root ), '/' );
			$real_path          = rtrim( str_replace( '\\', '/', $real_path ), '/' );
			$value['temporary'] = $real_path === $temp_root || strpos( $real_path . '/', $temp_root . '/' ) === 0;
		}

		if ( $persistent && $value['temporary'] ) {
			return self::make_check( $id, $label, 'warning', __( 'Backups are private but stored in the operating-system temporary directory, which the host may clean. Configure LEADWERK_MIGRATION_DEFAULT_BACKUPS_PATH to a persistent private location.', 'leadwerk-migration' ), $value );
		}

		return self::make_check( $id, $label, 'ok', __( 'The configured path is outside the detected web roots.', 'leadwerk-migration' ), $value );
	}

	/**
	 * Check the most constrained volume used for backups or runtime storage.
	 *
	 * @return array
	 */
	private static function check_disk_space() {
		$critical = self::byte_threshold( 'leadwerk_migration_health_disk_critical_bytes', 512 * 1024 * 1024 );
		$warning  = max( $critical, self::byte_threshold( 'leadwerk_migration_health_disk_warning_bytes', 2 * 1024 * 1024 * 1024 ) );
		$volumes  = array();

		foreach ( self::disk_check_paths() as $target ) {
			$volume_key = self::disk_volume_key( $target['path'] );
			if ( isset( $volumes[ $volume_key ] ) ) {
				$volumes[ $volume_key ]['roles'][] = $target['role'];
				continue;
			}

			$bytes = null;
			if ( function_exists( 'disk_free_space' ) ) {
				try {
					$detected = @disk_free_space( $target['path'] );
					if ( $detected !== false && is_numeric( $detected ) ) {
						$bytes = self::clamp_bytes( (float) $detected );
					}
				} catch ( Throwable $exception ) {
					$bytes = null;
				}
			}

			if ( $bytes === null ) {
				$status = 'warning';
			} elseif ( $bytes < $critical ) {
				$status = 'critical';
			} elseif ( $bytes < $warning ) {
				$status = 'warning';
			} else {
				$status = 'ok';
			}

			$volumes[ $volume_key ] = array(
				'roles'  => array( $target['role'] ),
				'path'   => $target['path'],
				'bytes'  => $bytes,
				'human'  => $bytes === null ? __( 'Unknown', 'leadwerk-migration' ) : self::format_bytes( $bytes ),
				'status' => $status,
			);
		}

		if ( empty( $volumes ) ) {
			$volumes['unavailable'] = array(
				'roles'  => array(),
				'path'   => null,
				'bytes'  => null,
				'human'  => __( 'Unknown', 'leadwerk-migration' ),
				'status' => 'warning',
			);
		}

		$volumes = array_values( $volumes );
		$worst   = null;
		$ranks   = array(
			'ok'       => 0,
			'warning'  => 1,
			'critical' => 2,
		);

		foreach ( $volumes as $volume ) {
			if ( $worst === null
				|| $ranks[ $volume['status'] ] > $ranks[ $worst['status'] ]
				|| ( $ranks[ $volume['status'] ] === $ranks[ $worst['status'] ] && self::disk_volume_is_worse( $volume, $worst ) )
			) {
				$worst = $volume;
			}
		}

		$path   = $worst['path'];
		$bytes  = $worst['bytes'];
		$status = $worst['status'];
		$value  = array(
			'path'    => $path,
			'bytes'   => $bytes,
			'human'   => $worst['human'],
			'volumes' => $volumes,
		);

		if ( $bytes === null ) {
			$message = count( $volumes ) > 1
				? __( 'Free disk space could not be determined for one or more backup or runtime volumes.', 'leadwerk-migration' )
				: __( 'Free disk space could not be determined.', 'leadwerk-migration' );

			return self::make_check( 'disk_free', __( 'Free disk space', 'leadwerk-migration' ), $status, $message, $value );
		}

		if ( $bytes < $critical ) {
			$message = count( $volumes ) > 1
				? sprintf( __( 'Only %s is available on the most constrained backup or runtime volume.', 'leadwerk-migration' ), self::format_bytes( $bytes ) )
				: sprintf( __( 'Only %s of disk space is available.', 'leadwerk-migration' ), self::format_bytes( $bytes ) );
		} elseif ( $bytes < $warning ) {
			$message = count( $volumes ) > 1
				? sprintf( __( '%s is available on the most constrained backup or runtime volume; large migrations may fail.', 'leadwerk-migration' ), self::format_bytes( $bytes ) )
				: sprintf( __( '%s of disk space is available; large migrations may fail.', 'leadwerk-migration' ), self::format_bytes( $bytes ) );
		} else {
			$message = count( $volumes ) > 1
				? sprintf( __( '%s is available on the most constrained backup or runtime volume.', 'leadwerk-migration' ), self::format_bytes( $bytes ) )
				: sprintf( __( '%s of disk space is available.', 'leadwerk-migration' ), self::format_bytes( $bytes ) );
		}

		return self::make_check( 'disk_free', __( 'Free disk space', 'leadwerk-migration' ), $status, $message, $value );
	}

	/**
	 * Check the PHP memory limit.
	 *
	 * @return array
	 */
	private static function check_memory_limit() {
		$raw   = self::read_ini_value( 'memory_limit' );
		$bytes = self::ini_bytes( 'memory_limit', null );
		$value = array(
			'raw'   => $raw === false ? null : $raw,
			'bytes' => $bytes,
			'human' => $bytes === null ? __( 'Unknown', 'leadwerk-migration' ) : self::format_bytes( $bytes ),
		);

		if ( $bytes === null ) {
			return self::make_check( 'memory_limit', __( 'PHP memory limit', 'leadwerk-migration' ), 'warning', __( 'The PHP memory limit could not be determined.', 'leadwerk-migration' ), $value );
		}

		if ( $bytes < 0 ) {
			return self::make_check( 'memory_limit', __( 'PHP memory limit', 'leadwerk-migration' ), 'ok', __( 'The PHP memory limit is unlimited.', 'leadwerk-migration' ), $value );
		}

		$critical = self::byte_threshold( 'leadwerk_migration_health_memory_critical_bytes', 128 * 1024 * 1024 );
		$warning  = max( $critical, self::byte_threshold( 'leadwerk_migration_health_memory_warning_bytes', 256 * 1024 * 1024 ) );

		if ( $bytes < $critical ) {
			$status  = 'critical';
			$message = sprintf( __( 'The PHP memory limit is only %s.', 'leadwerk-migration' ), self::format_bytes( $bytes ) );
		} elseif ( $bytes < $warning ) {
			$status  = 'warning';
			$message = sprintf( __( 'The PHP memory limit is %s; more memory is recommended for large sites.', 'leadwerk-migration' ), self::format_bytes( $bytes ) );
		} else {
			$status  = 'ok';
			$message = sprintf( __( 'The PHP memory limit is %s.', 'leadwerk-migration' ), self::format_bytes( $bytes ) );
		}

		return self::make_check( 'memory_limit', __( 'PHP memory limit', 'leadwerk-migration' ), $status, $message, $value );
	}

	/**
	 * Check PHP's maximum execution time.
	 *
	 * @return array
	 */
	private static function check_execution_time() {
		$raw = self::read_ini_value( 'max_execution_time' );

		if ( $raw === false || ! preg_match( '/^-?[0-9]+$/', trim( (string) $raw ) ) ) {
			return self::make_check( 'execution_time', __( 'Execution time', 'leadwerk-migration' ), 'warning', __( 'The maximum execution time could not be determined.', 'leadwerk-migration' ), null );
		}

		$seconds = (int) $raw;
		if ( $seconds <= 0 ) {
			return self::make_check( 'execution_time', __( 'Execution time', 'leadwerk-migration' ), 'ok', __( 'The PHP execution time is unlimited.', 'leadwerk-migration' ), $seconds );
		}

		$critical = max( 1, (int) apply_filters( 'leadwerk_migration_health_execution_critical_seconds', 30 ) );
		$warning  = max( $critical, (int) apply_filters( 'leadwerk_migration_health_execution_warning_seconds', 120 ) );

		if ( $seconds < $critical ) {
			$status  = 'critical';
			$message = sprintf( __( 'The PHP execution time is limited to %d seconds.', 'leadwerk-migration' ), $seconds );
		} elseif ( $seconds < $warning ) {
			$status  = 'warning';
			$message = sprintf( __( 'The PHP execution time is %d seconds; longer operations may be interrupted.', 'leadwerk-migration' ), $seconds );
		} else {
			$status  = 'ok';
			$message = sprintf( __( 'The PHP execution time is %d seconds.', 'leadwerk-migration' ), $seconds );
		}

		return self::make_check( 'execution_time', __( 'Execution time', 'leadwerk-migration' ), $status, $message, $seconds );
	}

	/**
	 * Check the effective HTTP upload size.
	 *
	 * @return array
	 */
	private static function check_upload_size() {
		$upload_raw = self::read_ini_value( 'upload_max_filesize' );
		$post_raw   = self::read_ini_value( 'post_max_size' );
		$upload     = self::ini_bytes( 'upload_max_filesize', null );
		$post       = self::ini_bytes( 'post_max_size', null );
		$limits     = array();

		if ( $upload !== null && $upload > 0 ) {
			$limits[] = $upload;
		}
		if ( $post !== null && $post > 0 ) {
			$limits[] = $post;
		}

		$bytes = empty( $limits ) ? null : min( $limits );
		if ( $bytes === null && ( $upload !== null || $post !== null ) ) {
			$bytes = -1;
		}

		if ( function_exists( 'wp_max_upload_size' ) ) {
			$wp_bytes = (int) wp_max_upload_size();
			if ( $wp_bytes > 0 && ( $bytes === null || $bytes < 0 || $wp_bytes < $bytes ) ) {
				$bytes = $wp_bytes;
			}
		}

		$value = array(
			'bytes'               => $bytes,
			'human'               => $bytes === null ? __( 'Unknown', 'leadwerk-migration' ) : self::format_bytes( $bytes ),
			'upload_max_filesize' => $upload_raw === false ? null : $upload_raw,
			'post_max_size'       => $post_raw === false ? null : $post_raw,
		);

		if ( $bytes === null ) {
			return self::make_check( 'upload_size', __( 'Upload size', 'leadwerk-migration' ), 'warning', __( 'The effective upload size could not be determined.', 'leadwerk-migration' ), $value );
		}

		if ( $bytes < 0 ) {
			return self::make_check( 'upload_size', __( 'Upload size', 'leadwerk-migration' ), 'ok', __( 'The HTTP upload size is unlimited.', 'leadwerk-migration' ), $value );
		}

		$critical = self::byte_threshold( 'leadwerk_migration_health_upload_critical_bytes', 64 * 1024 * 1024 );
		$warning  = max( $critical, self::byte_threshold( 'leadwerk_migration_health_upload_warning_bytes', 256 * 1024 * 1024 ) );

		if ( $bytes < $critical ) {
			$status  = 'critical';
			$message = sprintf( __( 'The effective upload size is only %s.', 'leadwerk-migration' ), self::format_bytes( $bytes ) );
		} elseif ( $bytes < $warning ) {
			$status  = 'warning';
			$message = sprintf( __( 'The effective upload size is %s; larger archives will require chunked import.', 'leadwerk-migration' ), self::format_bytes( $bytes ) );
		} else {
			$status  = 'ok';
			$message = sprintf( __( 'The effective upload size is %s.', 'leadwerk-migration' ), self::format_bytes( $bytes ) );
		}

		return self::make_check( 'upload_size', __( 'Upload size', 'leadwerk-migration' ), $status, $message, $value );
	}

	/**
	 * Check whether the site is configured to use HTTPS.
	 *
	 * @return array
	 */
	private static function check_https() {
		$scheme = '';
		if ( function_exists( 'home_url' ) ) {
			$scheme = (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME );
		}

		$enabled = ( function_exists( 'is_ssl' ) && is_ssl() ) || strtolower( $scheme ) === 'https';
		$value   = array(
			'enabled' => $enabled,
			'scheme'  => $scheme,
		);

		if ( $enabled ) {
			return self::make_check( 'https', __( 'HTTPS', 'leadwerk-migration' ), 'ok', __( 'The site is configured to use HTTPS.', 'leadwerk-migration' ), $value );
		}

		return self::make_check( 'https', __( 'HTTPS', 'leadwerk-migration' ), 'warning', __( 'The site is not configured to use HTTPS.', 'leadwerk-migration' ), $value );
	}

	/**
	 * Check OpenSSL availability for encrypted backups and secure requests.
	 *
	 * @return array
	 */
	private static function check_openssl() {
		$can_encrypt = false;
		$can_decrypt = false;

		try {
			$can_encrypt = function_exists( 'leadwerk_migration_can_encrypt' ) && leadwerk_migration_can_encrypt();
			$can_decrypt = function_exists( 'leadwerk_migration_can_decrypt' ) && leadwerk_migration_can_decrypt();
		} catch ( Throwable $exception ) {
			$can_encrypt = false;
			$can_decrypt = false;
		}

		$available = $can_encrypt && $can_decrypt;
		$value     = array(
			'available'   => $available,
			'can_encrypt' => $can_encrypt,
			'can_decrypt' => $can_decrypt,
			'version'     => defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : null,
		);

		if ( $available ) {
			return self::make_check( 'openssl', __( 'OpenSSL', 'leadwerk-migration' ), 'ok', __( 'OpenSSL supports creating and reading Leadwerk encrypted backups.', 'leadwerk-migration' ), $value );
		}

		if ( $can_decrypt ) {
			$message = __( 'Encrypted backups can be read, but this server cannot create new authenticated encrypted backups.', 'leadwerk-migration' );
		} elseif ( $can_encrypt ) {
			$message = __( 'Authenticated encrypted backups can be created, but the required decryption and legacy-cipher support is incomplete.', 'leadwerk-migration' );
		} else {
			$message = __( 'The OpenSSL functions, random source, key derivation, or required AES ciphers are unavailable; encrypted backups cannot be processed.', 'leadwerk-migration' );
		}

		return self::make_check( 'openssl', __( 'OpenSSL', 'leadwerk-migration' ), 'warning', $message, $value );
	}

	/**
	 * Check WordPress cron configuration and the plugin cleanup event.
	 *
	 * @return array
	 */
	private static function check_wp_cron() {
		$disabled  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$alternate = defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON;
		$next_run  = null;

		if ( function_exists( 'wp_next_scheduled' ) ) {
			$scheduled = wp_next_scheduled( 'leadwerk_migration_storage_cleanup' );
			if ( $scheduled !== false && ! is_wp_error( $scheduled ) ) {
				$next_run = (int) $scheduled;
			}
		}

		$value = array(
			'disabled'  => (bool) $disabled,
			'alternate' => (bool) $alternate,
			'next_run'  => $next_run,
		);

		if ( $disabled ) {
			return self::make_check( 'wp_cron', __( 'WP-Cron', 'leadwerk-migration' ), 'warning', __( 'WP-Cron is disabled; a working system cron must call wp-cron.php.', 'leadwerk-migration' ), $value );
		}

		if ( $next_run === null ) {
			return self::make_check( 'wp_cron', __( 'WP-Cron', 'leadwerk-migration' ), 'warning', __( 'The Leadwerk cleanup event is not scheduled.', 'leadwerk-migration' ), $value );
		}

		$overdue_tolerance = defined( 'MINUTE_IN_SECONDS' ) ? 15 * MINUTE_IN_SECONDS : 900;
		if ( $next_run < time() - $overdue_tolerance ) {
			return self::make_check( 'wp_cron', __( 'WP-Cron', 'leadwerk-migration' ), 'warning', __( 'The Leadwerk cleanup event is overdue; WP-Cron may not be running.', 'leadwerk-migration' ), $value );
		}

		return self::make_check( 'wp_cron', __( 'WP-Cron', 'leadwerk-migration' ), 'ok', __( 'WP-Cron is enabled and the Leadwerk cleanup event is scheduled.', 'leadwerk-migration' ), $value );
	}

	/**
	 * Verify the existing WordPress database connection with a read-only query.
	 *
	 * @return array
	 */
	private static function check_database() {
		global $wpdb;

		$connected         = false;
		$previous_suppress = null;
		$restore_suppress  = false;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return self::make_check( 'database', __( 'Database connection', 'leadwerk-migration' ), 'critical', __( 'The WordPress database client is unavailable.', 'leadwerk-migration' ), array( 'connected' => false ) );
		}

		try {
			if ( method_exists( $wpdb, 'suppress_errors' ) ) {
				$previous_suppress = $wpdb->suppress_errors( true );
				$restore_suppress  = true;
			}

			$result    = $wpdb->get_var( 'SELECT 1' );
			$has_error = isset( $wpdb->last_error ) && (string) $wpdb->last_error !== '';
			$connected = ! $has_error && (string) $result === '1';
		} catch ( Throwable $exception ) {
			$connected = false;
		} finally {
			if ( $restore_suppress ) {
				try {
					$wpdb->suppress_errors( $previous_suppress );
				} catch ( Throwable $exception ) {
					// The connection result remains valid even if error display state cannot be restored.
				}
			}
		}

		$value = array( 'connected' => $connected );
		if ( $connected ) {
			return self::make_check( 'database', __( 'Database connection', 'leadwerk-migration' ), 'ok', __( 'The WordPress database connection is working.', 'leadwerk-migration' ), $value );
		}

		return self::make_check( 'database', __( 'Database connection', 'leadwerk-migration' ), 'critical', __( 'The WordPress database connection check failed.', 'leadwerk-migration' ), $value );
	}

	/**
	 * Find an existing directory on the first migration volume.
	 *
	 * @return string|null
	 */
	private static function disk_check_path() {
		$paths = self::disk_check_paths();

		return isset( $paths[0]['path'] ) ? $paths[0]['path'] : null;
	}

	/**
	 * Resolve the configured backup and runtime directories to probeable paths.
	 *
	 * @return array
	 */
	private static function disk_check_paths() {
		$candidates = array();
		if ( defined( 'LEADWERK_MIGRATION_BACKUPS_PATH' ) ) {
			$candidates[] = array( 'role' => 'backups', 'path' => LEADWERK_MIGRATION_BACKUPS_PATH );
		}
		if ( defined( 'LEADWERK_MIGRATION_STORAGE_PATH' ) ) {
			$candidates[] = array( 'role' => 'runtime', 'path' => LEADWERK_MIGRATION_STORAGE_PATH );
		}

		if ( empty( $candidates ) && defined( 'WP_CONTENT_DIR' ) ) {
			$candidates[] = array( 'role' => 'fallback', 'path' => WP_CONTENT_DIR );
		}
		if ( empty( $candidates ) && defined( 'ABSPATH' ) ) {
			$candidates[] = array( 'role' => 'fallback', 'path' => ABSPATH );
		}

		$paths = array();
		foreach ( $candidates as $candidate ) {
			$current = $candidate['path'];
			while ( is_string( $current ) && $current !== '' && ! @is_dir( $current ) ) {
				$parent = dirname( $current );
				if ( $parent === $current ) {
					$current = '';
					break;
				}
				$current = $parent;
			}

			if ( is_string( $current ) && $current !== '' && @is_dir( $current ) ) {
				$real = @realpath( $current );
				$paths[] = array(
					'role' => $candidate['role'],
					'path' => $real !== false ? $real : $current,
				);
			}
		}

		return $paths;
	}

	/**
	 * Get a stable identifier for the filesystem containing a path.
	 *
	 * @param string $path Existing directory path.
	 * @return string
	 */
	private static function disk_volume_key( $path ) {
		$normalized = str_replace( '\\', '/', (string) $path );
		if ( preg_match( '/\A([A-Za-z]):\//', $normalized, $matches ) ) {
			return 'drive:' . strtoupper( $matches[1] );
		}
		if ( preg_match( '#\A//([^/]+/[^/]+)#', $normalized, $matches ) ) {
			return 'share:' . strtolower( $matches[1] );
		}

		try {
			$stat = @stat( $path );
			if ( is_array( $stat ) && array_key_exists( 'dev', $stat ) ) {
				return 'device:' . (string) $stat['dev'];
			}
		} catch ( Throwable $exception ) {
			// Fall back to the canonical path if the device ID is unavailable.
		}

		$real = @realpath( $path );
		$real = $real !== false ? $real : $path;

		return 'path:' . strtolower( str_replace( '\\', '/', (string) $real ) );
	}

	/**
	 * Break a same-status tie in favor of unknown or lower free space.
	 *
	 * @param array $candidate Candidate volume result.
	 * @param array $current Current worst volume result.
	 * @return bool
	 */
	private static function disk_volume_is_worse( $candidate, $current ) {
		if ( $candidate['bytes'] === null ) {
			return $current['bytes'] !== null;
		}
		if ( $current['bytes'] === null ) {
			return false;
		}

		return $candidate['bytes'] < $current['bytes'];
	}

	/**
	 * Normalize filtered checks to the public report contract.
	 *
	 * @param mixed $checks Check collection.
	 * @return array
	 */
	private static function normalize_checks( $checks ) {
		if ( ! is_array( $checks ) ) {
			return array();
		}

		$normalized = array();
		foreach ( array_values( $checks ) as $index => $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}

			$status = isset( $check['status'] ) ? (string) $check['status'] : 'warning';
			if ( ! in_array( $status, array( 'ok', 'warning', 'critical' ), true ) ) {
				$status = 'warning';
			}

			$id = isset( $check['id'] ) ? (string) $check['id'] : 'check_' . $index;
			$id = preg_replace( '/[^a-z0-9_\-]/', '_', strtolower( $id ) );

			$normalized[] = array(
				'id'      => $id,
				'label'   => isset( $check['label'] ) ? (string) $check['label'] : $id,
				'status'  => $status,
				'message' => isset( $check['message'] ) ? (string) $check['message'] : '',
				'value'   => array_key_exists( 'value', $check ) ? $check['value'] : null,
			);
		}

		return $normalized;
	}

	/**
	 * Count checks by status.
	 *
	 * @param array $checks Normalized checks.
	 * @return array
	 */
	private static function count_statuses( $checks ) {
		$counts = array(
			'total'    => count( $checks ),
			'ok'       => 0,
			'warning'  => 0,
			'critical' => 0,
		);

		foreach ( $checks as $check ) {
			++$counts[ $check['status'] ];
		}

		return $counts;
	}

	/**
	 * Build one check in the stable public format.
	 *
	 * @param string $id      Check ID.
	 * @param string $label   Human-readable label.
	 * @param string $status  ok, warning, or critical.
	 * @param string $message Human-readable result.
	 * @param mixed  $value   Machine-readable result.
	 * @return array
	 */
	private static function make_check( $id, $label, $status, $message, $value ) {
		return array(
			'id'      => $id,
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
			'value'   => $value,
		);
	}

	/**
	 * Validate a cached report before returning it.
	 *
	 * @param mixed $report Candidate report.
	 * @return bool
	 */
	private static function is_report( $report ) {
		return is_array( $report )
			&& isset( $report['checks'], $report['counts'], $report['score'], $report['generated_at'] )
			&& is_array( $report['checks'] )
			&& is_array( $report['counts'] )
			&& is_numeric( $report['score'] )
			&& is_string( $report['generated_at'] );
	}

	/**
	 * Get a filterable byte threshold.
	 *
	 * @param string $filter  Filter name.
	 * @param int    $default Default threshold.
	 * @return int
	 */
	private static function byte_threshold( $filter, $default ) {
		$value = apply_filters( $filter, $default );
		$value = self::parse_bytes( $value, $default );

		return max( 0, (int) $value );
	}

	/**
	 * Read an INI value without allowing a disabled function to break a report.
	 *
	 * @param string $option INI option name.
	 * @return string|false
	 */
	private static function read_ini_value( $option ) {
		if ( ! function_exists( 'ini_get' ) ) {
			return false;
		}

		try {
			return ini_get( $option );
		} catch ( Throwable $exception ) {
			return false;
		}
	}

	/**
	 * Keep floating-point byte values inside the current platform's int range.
	 *
	 * @param float $bytes Byte value.
	 * @return int
	 */
	private static function clamp_bytes( $bytes ) {
		if ( $bytes >= PHP_INT_MAX ) {
			return PHP_INT_MAX;
		}

		if ( $bytes <= PHP_INT_MIN ) {
			return PHP_INT_MIN;
		}

		return (int) floor( $bytes );
	}
}
