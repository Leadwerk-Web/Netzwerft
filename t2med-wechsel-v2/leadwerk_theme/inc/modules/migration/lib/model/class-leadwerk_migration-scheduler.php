<?php
/**
 * Leadwerk Migration backup scheduler.
 *
 * @package Leadwerk_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Leadwerk_Migration_Scheduler {

	/**
	 * A lock is considered abandoned after twelve hours.
	 */
	const LOCK_TTL = 43200;

	/**
	 * Maximum number of activity entries retained.
	 */
	const ACTIVITY_LIMIT = 100;

	/**
	 * Return the default automation settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'enabled'            => false,
			'frequency'          => 'daily',
			'start_hour'         => 2,
			'start_minute'       => 0,
			'retention_count'    => 5,
			'notification_email' => sanitize_email( get_option( 'admin_email', '' ) ),
			'notify_success'     => true,
			'notify_error'       => true,
			'exclusions'         => array_fill_keys( self::allowed_exclusions(), false ),
		);
	}

	/**
	 * Read and normalize the saved automation settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$settings = get_option( LEADWERK_MIGRATION_AUTOMATION_SETTINGS, array() );

		return self::sanitize_settings( is_array( $settings ) ? $settings : array() );
	}

	/**
	 * Sanitize automation settings.
	 *
	 * A few aliases are accepted so older clients can be upgraded without
	 * retaining duplicate keys in the stored option.
	 *
	 * @param mixed $settings Candidate settings.
	 * @return array
	 */
	public static function sanitize_settings( $settings ) {
		$defaults = self::default_settings();
		$settings = is_array( $settings ) ? $settings : array();

		if ( ! isset( $settings['frequency'] ) ) {
			if ( isset( $settings['interval'] ) ) {
				$settings['frequency'] = $settings['interval'];
			} elseif ( isset( $settings['schedule'] ) ) {
				$settings['frequency'] = $settings['schedule'];
			}
		}

		if ( ! isset( $settings['start_hour'] ) && isset( $settings['hour'] ) ) {
			$settings['start_hour'] = $settings['hour'];
		}

		if ( ! isset( $settings['start_minute'] ) && isset( $settings['minute'] ) ) {
			$settings['start_minute'] = $settings['minute'];
		}

		if ( ! isset( $settings['retention_count'] ) && isset( $settings['retention'] ) ) {
			$settings['retention_count'] = $settings['retention'];
		}

		if ( ! isset( $settings['notification_email'] ) && isset( $settings['email'] ) ) {
			$settings['notification_email'] = $settings['email'];
		}

		if ( ! isset( $settings['notify_error'] ) && isset( $settings['notify_failure'] ) ) {
			$settings['notify_error'] = $settings['notify_failure'];
		}

		if ( isset( $settings['notifications'] ) && is_array( $settings['notifications'] ) ) {
			$notifications = $settings['notifications'];

			if ( ! isset( $settings['notification_email'] ) && isset( $notifications['email'] ) ) {
				$settings['notification_email'] = $notifications['email'];
			}

			if ( ! isset( $settings['notify_success'] ) && isset( $notifications['success'] ) ) {
				$settings['notify_success'] = $notifications['success'];
			}

			if ( ! isset( $settings['notify_error'] ) && isset( $notifications['error'] ) ) {
				$settings['notify_error'] = $notifications['error'];
			}
		}

		$frequency = isset( $settings['frequency'] ) ? strtolower( trim( (string) $settings['frequency'] ) ) : $defaults['frequency'];
		if ( ! in_array( $frequency, self::allowed_frequencies(), true ) ) {
			$frequency = $defaults['frequency'];
		}

		$hour      = isset( $settings['start_hour'] ) ? (int) $settings['start_hour'] : $defaults['start_hour'];
		$minute    = isset( $settings['start_minute'] ) ? (int) $settings['start_minute'] : $defaults['start_minute'];
		$retention = isset( $settings['retention_count'] ) ? (int) $settings['retention_count'] : $defaults['retention_count'];

		$hour      = max( 0, min( 23, $hour ) );
		$minute    = max( 0, min( 59, $minute ) );
		$retention = max( 0, min( 100, $retention ) );

		$email = isset( $settings['notification_email'] ) ? sanitize_email( (string) $settings['notification_email'] ) : $defaults['notification_email'];

		return array(
			'enabled'            => isset( $settings['enabled'] ) ? self::to_boolean( $settings['enabled'] ) : $defaults['enabled'],
			'frequency'          => $frequency,
			'start_hour'         => $hour,
			'start_minute'       => $minute,
			'retention_count'    => $retention,
			'notification_email' => $email,
			'notify_success'     => isset( $settings['notify_success'] ) ? self::to_boolean( $settings['notify_success'] ) : $defaults['notify_success'],
			'notify_error'       => isset( $settings['notify_error'] ) ? self::to_boolean( $settings['notify_error'] ) : $defaults['notify_error'],
			'exclusions'         => self::sanitize_exclusions( $settings ),
		);
	}

	/**
	 * Persist settings and synchronize the recurring event.
	 *
	 * @param mixed $settings Candidate settings.
	 * @return array|WP_Error
	 */
	public static function save_settings( $settings ) {
		$settings = self::sanitize_settings( $settings );
		$updated  = update_option( LEADWERK_MIGRATION_AUTOMATION_SETTINGS, $settings, false );

		if ( ! $updated && get_option( LEADWERK_MIGRATION_AUTOMATION_SETTINGS, array() ) !== $settings ) {
			return new WP_Error(
				'leadwerk_migration_automation_settings_save_failed',
				__( 'The backup automation settings could not be saved.', 'leadwerk-migration' )
			);
		}

		$synced = self::sync_schedule( $settings );
		if ( is_wp_error( $synced ) ) {
			return $synced;
		}

		return $settings;
	}

	/**
	 * Add the scheduler's longer recurrence intervals to WordPress cron.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules = is_array( $schedules ) ? $schedules : array();

		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'leadwerk-migration' ),
			);
		}

		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once Every 30 Days', 'leadwerk-migration' ),
			);
		}

		return $schedules;
	}

	/**
	 * Rebuild the recurring cron event to match current settings.
	 *
	 * Queued manual events use a different argument signature and are not
	 * removed when only the recurring schedule changes.
	 *
	 * @param array|null $settings Optional normalized settings.
	 * @return boolean|WP_Error
	 */
	public static function sync_schedule( $settings = null ) {
		$settings = is_array( $settings ) ? self::sanitize_settings( $settings ) : self::get_settings();

		wp_clear_scheduled_hook( LEADWERK_MIGRATION_AUTOMATION_HOOK, array() );

		if ( empty( $settings['enabled'] ) ) {
			return true;
		}

		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );

		$timestamp = self::next_run( $settings );
		$scheduled = wp_schedule_event(
			$timestamp,
			$settings['frequency'],
			LEADWERK_MIGRATION_AUTOMATION_HOOK,
			array()
		);

		if ( ! $scheduled ) {
			return new WP_Error(
				'leadwerk_migration_automation_schedule_failed',
				__( 'WordPress could not schedule the automated backup.', 'leadwerk-migration' )
			);
		}

		return true;
	}

	/**
	 * Calculate the next cron timestamp in the site's timezone.
	 *
	 * Hourly jobs honor start_minute. Twice-daily jobs are anchored to both
	 * start_hour and start_minute. Longer jobs first run at the next selected
	 * site-local time, then follow their WordPress recurrence interval.
	 *
	 * @param array|null $settings Optional settings.
	 * @return integer
	 */
	public static function next_run( $settings = null ) {
		$settings = is_array( $settings ) ? self::sanitize_settings( $settings ) : self::get_settings();
		$timezone = self::site_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );
		$hour     = (int) $settings['start_hour'];
		$minute   = (int) $settings['start_minute'];

		if ( 'hourly' === $settings['frequency'] ) {
			$next = $now->setTime( (int) $now->format( 'G' ), $minute, 0 );
			if ( $next <= $now ) {
				$next = $next->modify( '+1 hour' );
			}
		} elseif ( 'twicedaily' === $settings['frequency'] ) {
			$next = $now->setTime( $hour, $minute, 0 );
			while ( $next <= $now ) {
				$next = $next->modify( '+12 hours' );
			}
		} else {
			$next = $now->setTime( $hour, $minute, 0 );
			if ( $next <= $now ) {
				$next = $next->modify( '+1 day' );
			}
		}

		$timestamp = (int) $next->format( 'U' );

		return (int) apply_filters( 'leadwerk_migration_automation_next_run', $timestamp, $settings );
	}

	/**
	 * Execute a complete inline export and record its integrity baseline.
	 *
	 * @param string $origin Run origin: scheduled or manual.
	 * @return array|WP_Error
	 */
	public static function run( $origin = 'scheduled' ) {
		global $leadwerk_migration_automation_lock_token;

		$origin   = self::sanitize_origin( $origin );
		$settings = self::get_settings();

		if ( 'scheduled' === $origin && empty( $settings['enabled'] ) ) {
			self::log_activity(
				'skipped',
				__( 'Scheduled backup skipped because automation is disabled.', 'leadwerk-migration' ),
				array( 'origin' => $origin )
			);

			return new WP_Error(
				'leadwerk_migration_automation_disabled',
				__( 'Backup automation is disabled.', 'leadwerk-migration' )
			);
		}

		$token = self::acquire_lock( $origin );
		if ( is_wp_error( $token ) ) {
			self::log_activity(
				'skipped',
				__( 'Backup skipped because another automated export is already running.', 'leadwerk-migration' ),
				array( 'origin' => $origin )
			);

			return $token;
		}

		// A shutdown callback is needed because legacy pipeline code may call exit.
		register_shutdown_function(
			function () use ( $token ) {
				Leadwerk_Migration_Scheduler::release_lock( $token );
			}
		);

		$started_at = time();
		$leadwerk_migration_automation_lock_token = $token;
		self::log_activity(
			'started',
			__( 'Backup export started.', 'leadwerk-migration' ),
			array( 'origin' => $origin )
		);

		try {
			$secret = get_option( LEADWERK_MIGRATION_SECRET_KEY, '' );
			if ( ! is_string( $secret ) || $secret === '' ) {
				throw new RuntimeException( __( 'The internal migration secret is not configured.', 'leadwerk-migration' ) );
			}

			$params = array(
				'leadwerk_migration_internal' => 1,
				'leadwerk_migration_inline'   => 1,
				'secret_key'                  => $secret,
				'priority'                    => 5,
				// Site Sync must always move the complete WordPress site dataset,
				// regardless of exclusions configured for routine automation backups.
				'options'                     => 'sync' === $origin ? array() : self::export_options( $settings['exclusions'] ),
			);
			if ( in_array( $origin, array( 'manual', 'sync' ), true ) ) {
				$params['leadwerk_migration_manual_backup'] = 1;
			}

			$params = Leadwerk_Migration_Export_Controller::export( $params );

			if ( ! is_array( $params ) || empty( $params['archive'] ) || ! is_string( $params['archive'] ) ) {
				throw new RuntimeException( __( 'The export pipeline did not return a backup filename.', 'leadwerk-migration' ) );
			}

			if ( ! class_exists( 'Leadwerk_Migration_Integrity' ) ) {
				throw new RuntimeException( __( 'The backup integrity service is unavailable.', 'leadwerk-migration' ) );
			}

			$source    = in_array( $origin, array( 'scheduled', 'sync' ), true ) ? $origin : 'manual';
			$manifests = Leadwerk_Migration_Integrity::get_manifests();
			$manifest  = isset( $manifests[ $params['archive'] ] ) ? $manifests[ $params['archive'] ] : null;
			$path      = Leadwerk_Migration_Integrity::resolve_backup_path( $params['archive'] );
			$reusable  = is_array( $manifest ) && ! is_wp_error( $path )
				&& isset( $manifest['source'], $manifest['size'], $manifest['mtime'] )
				&& $manifest['source'] === $source
				&& (int) $manifest['size'] === (int) @filesize( $path )
				&& (int) $manifest['mtime'] === (int) @filemtime( $path );

			if ( ! $reusable ) {
				$manifest = Leadwerk_Migration_Integrity::record_file( $params['archive'], $source );
				if ( is_wp_error( $manifest ) ) {
					throw new RuntimeException( $manifest->get_error_message() );
				}
			}

			$retention = array(
				'deleted' => array(),
				'errors'  => array(),
			);

			if ( 'scheduled' === $origin ) {
				$retention = self::apply_retention( (int) $settings['retention_count'], $params['archive'] );
			}

			$duration = max( 0, time() - $started_at );
			$result   = array(
				'status'            => 'success',
				'origin'            => $origin,
				'archive'           => $params['archive'],
				'manifest'          => $manifest,
				'retention_deleted' => $retention['deleted'],
				'retention_errors'  => $retention['errors'],
				'duration'          => $duration,
				'finished_at'       => gmdate( 'c' ),
			);

			self::log_activity(
				'success',
				__( 'Backup completed and its SHA-256 baseline was recorded.', 'leadwerk-migration' ),
				array(
					'origin'            => $origin,
					'archive'           => $params['archive'],
					'size'              => isset( $manifest['size'] ) ? $manifest['size'] : null,
					'duration'          => $duration,
					'retention_deleted' => $retention['deleted'],
					'retention_errors'  => $retention['errors'],
				)
			);

			try {
				self::send_notification( true, $settings, $result );
			} catch ( Throwable $notification_error ) {
				self::log_activity(
					'notification_error',
					__( 'The backup completed, but its success email could not be sent.', 'leadwerk-migration' ),
					array( 'archive' => $params['archive'] )
				);
			}

			return $result;
		} catch ( Throwable $error ) {
			$duration = max( 0, time() - $started_at );
			$message  = trim( wp_strip_all_tags( $error->getMessage() ) );

			if ( $message === '' ) {
				$message = __( 'The backup failed for an unknown reason.', 'leadwerk-migration' );
			}

			self::log_activity(
				'error',
				$message,
				array(
					'origin'   => $origin,
					'duration' => $duration,
				)
			);

			try {
				self::send_notification(
					false,
					$settings,
					array(
						'origin'   => $origin,
						'message'  => $message,
						'duration' => $duration,
					)
				);
			} catch ( Throwable $notification_error ) {
				// Notification hooks must never mask the export error.
			}

			return new WP_Error( 'leadwerk_migration_automation_run_failed', $message );
		} finally {
			$leadwerk_migration_automation_lock_token = null;
			self::release_lock( $token );
		}
	}

	/**
	 * Queue a one-off backup without changing the recurring schedule.
	 *
	 * @return integer|WP_Error Unix timestamp of the queued event.
	 */
	public static function queue_manual_run() {
		$args     = array( 'manual' );
		$existing = wp_next_scheduled( LEADWERK_MIGRATION_AUTOMATION_HOOK, $args );

		if ( $existing ) {
			return (int) $existing;
		}

		$timestamp = time() + 5;
		if ( ! wp_schedule_single_event( $timestamp, LEADWERK_MIGRATION_AUTOMATION_HOOK, $args ) ) {
			return new WP_Error(
				'leadwerk_migration_manual_backup_queue_failed',
				__( 'WordPress could not queue the backup.', 'leadwerk-migration' )
			);
		}

		self::log_activity(
			'queued',
			__( 'Manual backup queued.', 'leadwerk-migration' ),
			array(
				'origin'       => 'manual',
				'scheduled_at' => gmdate( 'c', $timestamp ),
			)
		);

		return $timestamp;
	}

	/**
	 * Return recent activity, newest first.
	 *
	 * @param integer $limit Maximum entries to return.
	 * @return array
	 */
	public static function get_activity( $limit = self::ACTIVITY_LIMIT ) {
		$activity = get_option( LEADWERK_MIGRATION_ACTIVITY_LOG, array() );
		$activity = is_array( $activity ) ? array_values( $activity ) : array();
		$limit    = max( 0, min( self::ACTIVITY_LIMIT, (int) $limit ) );

		return array_slice( $activity, 0, $limit );
	}

	/**
	 * Append a sanitized activity entry and retain at most 100 entries.
	 *
	 * @param string $status  Activity status.
	 * @param string $message Human-readable message.
	 * @param array  $context Optional safe context.
	 * @return array
	 */
	public static function log_activity( $status, $message, $context = array() ) {
		$status = strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', (string) $status ) );
		$status = trim( $status, '-' );

		if ( $status === '' ) {
			$status = 'info';
		}

		$message = trim( wp_strip_all_tags( (string) $message ) );
		$entry   = array(
			'id'        => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'leadwerk-', true ),
			'timestamp' => gmdate( 'c' ),
			'status'    => substr( $status, 0, 32 ),
			'message'   => substr( $message, 0, 1000 ),
			'context'   => self::sanitize_context( is_array( $context ) ? $context : array() ),
		);

		$activity = get_option( LEADWERK_MIGRATION_ACTIVITY_LOG, array() );
		$activity = is_array( $activity ) ? array_values( $activity ) : array();
		array_unshift( $activity, $entry );
		$activity = array_slice( $activity, 0, self::ACTIVITY_LIMIT );

		update_option( LEADWERK_MIGRATION_ACTIVITY_LOG, $activity, false );

		return $entry;
	}

	/**
	 * Initialize scheduler storage and synchronize its event.
	 *
	 * @return boolean|WP_Error
	 */
	public static function activate() {
		if ( get_option( LEADWERK_MIGRATION_AUTOMATION_SETTINGS, false ) === false ) {
			add_option( LEADWERK_MIGRATION_AUTOMATION_SETTINGS, self::default_settings(), '', false );
		} else {
			update_option( LEADWERK_MIGRATION_AUTOMATION_SETTINGS, self::get_settings(), false );
		}

		return self::sync_schedule();
	}

	/**
	 * Remove all automation events and any abandoned lock.
	 *
	 * Settings, manifests and activity are intentionally preserved.
	 *
	 * @return boolean
	 */
	public static function deactivate() {
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( LEADWERK_MIGRATION_AUTOMATION_HOOK );
		} else {
			wp_clear_scheduled_hook( LEADWERK_MIGRATION_AUTOMATION_HOOK, array() );
			wp_clear_scheduled_hook( LEADWERK_MIGRATION_AUTOMATION_HOOK, array( 'manual' ) );
		}

		delete_option( LEADWERK_MIGRATION_AUTOMATION_LOCK );

		return true;
	}

	/**
	 * Acquire the option-backed process lock.
	 *
	 * add_option is an atomic insert because option_name is unique. Stale locks
	 * are removed with an exact serialized-value comparison before one retry.
	 *
	 * @param string $origin Run origin.
	 * @return string|WP_Error Lock token or error.
	 */
	private static function acquire_lock( $origin ) {
		$now   = time();
		$token = class_exists( 'Leadwerk_Migration_Security' ) ? Leadwerk_Migration_Security::job_id() : wp_generate_password( 32, false, false );
		$lock  = array(
			'token'       => $token,
			'origin'      => $origin,
			'acquired_at' => $now,
			'heartbeat_at' => $now,
			'expires_at'  => $now + self::LOCK_TTL,
		);

		if ( add_option( LEADWERK_MIGRATION_AUTOMATION_LOCK, $lock, '', false ) ) {
			return $token;
		}

		$existing = get_option( LEADWERK_MIGRATION_AUTOMATION_LOCK, false );
		$expired  = ! is_array( $existing ) || empty( $existing['expires_at'] ) || (int) $existing['expires_at'] <= $now;

		if ( $expired && self::compare_delete_lock( $existing ) ) {
			if ( add_option( LEADWERK_MIGRATION_AUTOMATION_LOCK, $lock, '', false ) ) {
				return $token;
			}
		}

		return new WP_Error(
			'leadwerk_migration_automation_locked',
			__( 'Another automated backup is already running.', 'leadwerk-migration' )
		);
	}

	/**
	 * Release a lock only when this process still owns it.
	 *
	 * Public visibility is required by the registered shutdown callback.
	 *
	 * @param string $token Lock token.
	 * @return boolean
	 */
	public static function release_lock( $token ) {
		$lock = get_option( LEADWERK_MIGRATION_AUTOMATION_LOCK, false );
		if ( ! is_array( $lock ) || empty( $lock['token'] ) || ! is_string( $lock['token'] ) ) {
			return false;
		}

		if ( ! is_string( $token ) || ! hash_equals( $lock['token'], $token ) ) {
			return false;
		}

		return self::compare_delete_lock( $lock );
	}

	/**
	 * Extend a lock while its owner is making pipeline progress.
	 *
	 * @param string $token Lock token.
	 * @return boolean
	 */
	public static function renew_lock( $token ) {
		$lock = get_option( LEADWERK_MIGRATION_AUTOMATION_LOCK, false );
		if ( ! is_array( $lock ) || empty( $lock['token'] ) || ! is_string( $lock['token'] ) || ! is_string( $token ) || ! hash_equals( $lock['token'], $token ) ) {
			return false;
		}

		$now = time();
		if ( ! empty( $lock['expires_at'] ) && (int) $lock['expires_at'] > $now + self::LOCK_TTL - 60 ) {
			return true;
		}

		$renewed                 = $lock;
		$renewed['heartbeat_at'] = $now;
		$renewed['expires_at']   = $renewed['heartbeat_at'] + self::LOCK_TTL;

		return self::compare_update_lock( $lock, $renewed );
	}

	/**
	 * Delete an option lock only if its database value is unchanged.
	 *
	 * @param mixed $expected Expected unserialized option value.
	 * @return boolean
	 */
	private static function compare_delete_lock( $expected ) {
		global $wpdb;

		if ( ! isset( $wpdb->options ) ) {
			return false;
		}

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				LEADWERK_MIGRATION_AUTOMATION_LOCK,
				maybe_serialize( $expected )
			)
		);

		if ( $deleted ) {
			wp_cache_delete( LEADWERK_MIGRATION_AUTOMATION_LOCK, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}

		return (bool) $deleted;
	}

	/**
	 * Update an option lock only when the process still owns the exact value.
	 *
	 * @param mixed $expected Expected unserialized option value.
	 * @param array $replacement Replacement lock value.
	 * @return boolean
	 */
	private static function compare_update_lock( $expected, $replacement ) {
		global $wpdb;

		if ( ! isset( $wpdb->options ) ) {
			return false;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $replacement ),
				LEADWERK_MIGRATION_AUTOMATION_LOCK,
				maybe_serialize( $expected )
			)
		);

		if ( $updated ) {
			wp_cache_delete( LEADWERK_MIGRATION_AUTOMATION_LOCK, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}

		return (bool) $updated;
	}

	/**
	 * Apply count retention exclusively to manifests marked scheduled.
	 *
	 * A retention count of zero disables automatic deletion.
	 *
	 * @param integer $retention_count Number of scheduled backups to retain.
	 * @param string  $current_archive Current run archive, retained on timestamp ties.
	 * @return array
	 */
	private static function apply_retention( $retention_count, $current_archive = '' ) {
		$result = array(
			'deleted' => array(),
			'errors'  => array(),
		);

		if ( $retention_count <= 0 || ! class_exists( 'Leadwerk_Migration_Integrity' ) ) {
			return $result;
		}

		$scheduled = array();
		$manifests = Leadwerk_Migration_Integrity::get_manifests();

		foreach ( $manifests as $filename => $manifest ) {
			if ( ! is_array( $manifest ) || ! isset( $manifest['source'] ) || 'scheduled' !== $manifest['source'] ) {
				continue;
			}

			$path = Leadwerk_Migration_Integrity::resolve_backup_path( $filename );
			if ( is_wp_error( $path ) ) {
				$result['errors'][] = array(
					'archive' => is_scalar( $filename ) ? (string) $filename : '',
					'message' => $path->get_error_message(),
				);
				continue;
			}

			if ( ! is_file( $path ) ) {
				$removed = Leadwerk_Migration_Integrity::remove( $filename );
				if ( is_wp_error( $removed ) ) {
					$result['errors'][] = array(
						'archive' => $filename,
						'message' => $removed->get_error_message(),
					);
				}
				continue;
			}

			$recorded = isset( $manifest['recorded_at'] ) ? strtotime( $manifest['recorded_at'] ) : false;
			if ( false === $recorded ) {
				$recorded = isset( $manifest['mtime'] ) ? (int) $manifest['mtime'] : (int) @filemtime( $path );
			}

			$scheduled[] = array(
				'filename' => $filename,
				'recorded' => (int) $recorded,
				'current'  => $filename === $current_archive,
			);
		}

		usort(
			$scheduled,
			function ( $left, $right ) {
				if ( $left['recorded'] === $right['recorded'] ) {
					if ( $left['current'] !== $right['current'] ) {
						return $left['current'] ? 1 : -1;
					}

					return strcmp( $left['filename'], $right['filename'] );
				}

				return $left['recorded'] < $right['recorded'] ? -1 : 1;
			}
		);

		$delete_count = max( 0, count( $scheduled ) - $retention_count );
		$candidates   = array_slice( $scheduled, 0, $delete_count );

		foreach ( $candidates as $candidate ) {
			$filename = $candidate['filename'];

			if ( ! Leadwerk_Migration_Backups::delete_file( $filename ) ) {
				$error = array(
					'archive' => $filename,
					'message' => __( 'The retained backup could not be deleted.', 'leadwerk-migration' ),
				);
				$result['errors'][] = $error;
				self::log_activity( 'retention_error', $error['message'], $error );
				continue;
			}

			$removed = Leadwerk_Migration_Integrity::remove( $filename );
			if ( is_wp_error( $removed ) ) {
				$result['errors'][] = array(
					'archive' => $filename,
					'message' => $removed->get_error_message(),
				);
			}

			$result['deleted'][] = $filename;
			self::log_activity(
				'retention',
				__( 'An old scheduled backup was deleted by retention.', 'leadwerk-migration' ),
				array( 'archive' => $filename )
			);
		}

		return $result;
	}

	/**
	 * Send the configured run notification.
	 *
	 * @param boolean $success  Whether the export succeeded.
	 * @param array   $settings Scheduler settings.
	 * @param array   $result   Run result.
	 * @return boolean
	 */
	private static function send_notification( $success, $settings, $result ) {
		$toggle = $success ? 'notify_success' : 'notify_error';
		$email  = isset( $settings['notification_email'] ) ? sanitize_email( $settings['notification_email'] ) : '';

		if ( empty( $settings[ $toggle ] ) || $email === '' || ! is_email( $email ) ) {
			return false;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$site_url  = home_url( '/' );

		if ( $success ) {
			$subject = sprintf(
				/* translators: %s: Site name. */
				__( '[%s] Backup completed', 'leadwerk-migration' ),
				$site_name
			);
			$message = sprintf(
				/* translators: 1: Site URL, 2: Backup filename, 3: Duration in seconds. */
				__( "Leadwerk Migration completed a backup.\n\nSite: %1$s\nArchive: %2$s\nDuration: %3$d seconds\nIntegrity: SHA-256 baseline recorded", 'leadwerk-migration' ),
				$site_url,
				isset( $result['archive'] ) ? $result['archive'] : '',
				isset( $result['duration'] ) ? (int) $result['duration'] : 0
			);
		} else {
			$subject = sprintf(
				/* translators: %s: Site name. */
				__( '[%s] Backup failed', 'leadwerk-migration' ),
				$site_name
			);
			$message = sprintf(
				/* translators: 1: Site URL, 2: Error message, 3: Duration in seconds. */
				__( "Leadwerk Migration could not complete a backup.\n\nSite: %1$s\nError: %2$s\nDuration: %3$d seconds", 'leadwerk-migration' ),
				$site_url,
				isset( $result['message'] ) ? $result['message'] : __( 'Unknown error', 'leadwerk-migration' ),
				isset( $result['duration'] ) ? (int) $result['duration'] : 0
			);
		}

		return (bool) wp_mail( $email, $subject, $message );
	}

	/**
	 * Convert saved exclusions to the options format consumed by exports.
	 *
	 * @param array $exclusions Sanitized exclusions.
	 * @return array
	 */
	private static function export_options( $exclusions ) {
		$options = array();

		foreach ( self::allowed_exclusions() as $key ) {
			if ( ! empty( $exclusions[ $key ] ) ) {
				$options[ $key ] = 1;
			}
		}

		return $options;
	}

	/**
	 * Sanitize exclusion settings from associative, list or options formats.
	 *
	 * @param array $settings Candidate settings.
	 * @return array
	 */
	private static function sanitize_exclusions( $settings ) {
		$exclusions = array_fill_keys( self::allowed_exclusions(), false );
		$source     = array();

		if ( isset( $settings['exclusions'] ) && is_array( $settings['exclusions'] ) ) {
			$source = $settings['exclusions'];
		} elseif ( isset( $settings['options'] ) && is_array( $settings['options'] ) ) {
			$source = $settings['options'];
		}

		$is_list = array_keys( $source ) === range( 0, count( $source ) - 1 );
		foreach ( self::allowed_exclusions() as $key ) {
			if ( $is_list ) {
				$exclusions[ $key ] = in_array( $key, $source, true );
			} elseif ( array_key_exists( $key, $source ) ) {
				$exclusions[ $key ] = self::to_boolean( $source[ $key ] );
			} elseif ( array_key_exists( $key, $settings ) ) {
				$exclusions[ $key ] = self::to_boolean( $settings[ $key ] );
			}
		}

		$aliases = array(
			'exclude_spam_comments'  => 'no_spam_comments',
			'exclude_post_revisions' => 'no_post_revisions',
			'exclude_database'       => 'no_database',
			'exclude_media'          => 'no_media',
			'exclude_themes'         => 'no_themes',
			'exclude_plugins'        => 'no_plugins',
			'exclude_muplugins'      => 'no_muplugins',
			'exclude_cache'          => 'no_cache',
		);

		foreach ( $aliases as $alias => $key ) {
			if ( array_key_exists( $alias, $source ) ) {
				$exclusions[ $key ] = self::to_boolean( $source[ $alias ] );
			} elseif ( array_key_exists( $alias, $settings ) ) {
				$exclusions[ $key ] = self::to_boolean( $settings[ $alias ] );
			}
		}

		return $exclusions;
	}

	/**
	 * Allowed export exclusion flags.
	 *
	 * @return array
	 */
	private static function allowed_exclusions() {
		return array(
			'no_spam_comments',
			'no_post_revisions',
			'no_database',
			'no_email_replace',
			'no_media',
			'no_themes',
			'no_inactive_themes',
			'no_muplugins',
			'no_plugins',
			'no_inactive_plugins',
			'no_cache',
		);
	}

	/**
	 * Supported WordPress recurrence names.
	 *
	 * @return array
	 */
	private static function allowed_frequencies() {
		return array( 'hourly', 'twicedaily', 'daily', 'weekly', 'monthly' );
	}

	/**
	 * Resolve the WordPress site timezone without changing PHP globals.
	 *
	 * @return DateTimeZone
	 */
	private static function site_timezone() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		$timezone_string = get_option( 'timezone_string', '' );
		if ( is_string( $timezone_string ) && $timezone_string !== '' ) {
			try {
				return new DateTimeZone( $timezone_string );
			} catch ( Exception $error ) {
				// Fall through to the numeric offset.
			}
		}

		$offset  = (float) get_option( 'gmt_offset', 0 );
		$sign    = $offset < 0 ? '-' : '+';
		$offset  = abs( $offset );
		$hours   = (int) floor( $offset );
		$minutes = (int) round( ( $offset - $hours ) * 60 );

		return new DateTimeZone( sprintf( '%s%02d:%02d', $sign, $hours, $minutes ) );
	}

	/**
	 * Normalize a run origin.
	 *
	 * @param mixed $origin Origin value.
	 * @return string
	 */
	private static function sanitize_origin( $origin ) {
		$origin = is_scalar( $origin ) ? strtolower( trim( (string) $origin ) ) : 'scheduled';

		return in_array( $origin, array( 'manual', 'sync' ), true ) ? $origin : 'scheduled';
	}

	/**
	 * Normalize common boolean representations.
	 *
	 * @param mixed $value Candidate value.
	 * @return boolean
	 */
	private static function to_boolean( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 !== (int) $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return ! empty( $value );
	}

	/**
	 * Recursively sanitize activity context and remove sensitive keys.
	 *
	 * @param array   $context Context data.
	 * @param integer $depth   Current recursion depth.
	 * @return array
	 */
	private static function sanitize_context( $context, $depth = 0 ) {
		if ( $depth >= 4 ) {
			return array();
		}

		$clean = array();
		foreach ( $context as $key => $value ) {
			$key = preg_replace( '/[^a-z0-9_-]+/i', '_', (string) $key );
			$key = substr( trim( $key, '_' ), 0, 64 );

			if ( $key === '' || in_array( strtolower( $key ), array( 'secret', 'secret_key', 'password', 'token' ), true ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = self::sanitize_context( $value, $depth + 1 );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || is_null( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$clean[ $key ] = substr( trim( wp_strip_all_tags( (string) $value ) ), 0, 1000 );
			}
		}

		return $clean;
	}
}
