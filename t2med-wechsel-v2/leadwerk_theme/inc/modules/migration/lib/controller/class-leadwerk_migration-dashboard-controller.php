<?php
/**
 * Leadwerk Migration dashboard and administrative actions.
 *
 * Copyright (C) 2026 Leadwerk.
 * Licensed under GPLv3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access is not allowed.' );
}

class Leadwerk_Migration_Dashboard_Controller {

	public static function index() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view migration diagnostics.', 'leadwerk-migration' ) );
		}

		$backups    = Leadwerk_Migration_Backups::get_files();
		$total_size = 0;
		foreach ( $backups as $backup ) {
			if ( isset( $backup['size'] ) && is_numeric( $backup['size'] ) ) {
				$total_size += (int) $backup['size'];
			}
		}

		$recent = array_slice( $backups, 0, 8 );
		foreach ( $recent as $index => $backup ) {
			$recent[ $index ]['integrity'] = Leadwerk_Migration_Integrity::get_status( $backup['filename'], false );
		}

		Leadwerk_Migration_Template::render(
			'dashboard/index',
			array(
				'report'      => Leadwerk_Migration_Health::get_report(),
				'backups'     => $recent,
				'backup_count' => count( $backups ),
				'total_size'  => $total_size,
				'settings'    => Leadwerk_Migration_Scheduler::get_settings(),
				'next_run'    => Leadwerk_Migration_Scheduler::next_run(),
				'activity'    => array_slice( Leadwerk_Migration_Scheduler::get_activity(), 0, 8 ),
				'is_running'  => (bool) get_option( LEADWERK_MIGRATION_AUTOMATION_LOCK, false ),
			)
		);
	}

	public static function automation_index() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage backup automation.', 'leadwerk-migration' ) );
		}

		Leadwerk_Migration_Template::render(
			'schedules/index',
			array(
				'settings'   => Leadwerk_Migration_Scheduler::get_settings(),
				'next_run'   => Leadwerk_Migration_Scheduler::next_run(),
				'activity'   => Leadwerk_Migration_Scheduler::get_activity(),
				'is_running' => (bool) get_option( LEADWERK_MIGRATION_AUTOMATION_LOCK, false ),
			)
		);
	}

	public static function enqueue_assets( $hook ) {
		$allowed = array(
			'toplevel_page_leadwerk_migration_dashboard',
			'leadwerk-migration_page_leadwerk_migration_schedules',
		);

		if ( ! in_array( $hook, $allowed, true ) ) {
			return;
		}

		wp_enqueue_style(
			'leadwerk-migration-dashboard',
			Leadwerk_Migration_Template::asset_link( 'css/leadwerk-dashboard.css' ),
			array(),
			LEADWERK_MIGRATION_VERSION
		);
		wp_enqueue_script(
			'leadwerk-migration-dashboard',
			Leadwerk_Migration_Template::asset_link( 'javascript/leadwerk-dashboard.js' ),
			array(),
			LEADWERK_MIGRATION_VERSION,
			true
		);
	}

	public static function refresh_health() {
		self::require_action( 'manage_options', 'leadwerk_migration_refresh_health' );
		Leadwerk_Migration_Health::clear_cache();
		self::redirect( 'leadwerk_migration_dashboard', 'health_refreshed' );
	}

	public static function verify_backup() {
		self::require_action( 'leadwerk_migration_import_site', 'leadwerk_migration_verify_backup' );

		$filename  = isset( $_POST['archive'] ) ? sanitize_file_name( wp_unslash( $_POST['archive'] ) ) : '';
		$establish = ! empty( $_POST['establish'] );
		$result    = Leadwerk_Migration_Integrity::verify( $filename, $establish );

		if ( is_wp_error( $result ) ) {
			Leadwerk_Migration_Scheduler::log_activity( 'integrity_error', $result->get_error_message(), array( 'archive' => $filename ) );
			self::redirect( 'leadwerk_migration_dashboard', 'verify_failed' );
		}

		Leadwerk_Migration_Scheduler::log_activity( 'integrity_verified', __( 'Backup integrity verification completed.', 'leadwerk-migration' ), array( 'archive' => $filename ) );
		self::redirect( 'leadwerk_migration_dashboard', isset( $result['status'] ) && $result['status'] === 'verified' ? 'verify_ok' : 'verify_failed' );
	}

	public static function save_automation() {
		self::require_action( 'manage_options', 'leadwerk_migration_save_automation' );
		$raw    = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$result = Leadwerk_Migration_Scheduler::save_settings( $raw );
		self::redirect( 'leadwerk_migration_schedules', is_wp_error( $result ) ? 'settings_failed' : 'settings_saved' );
	}

	public static function run_backup() {
		self::require_action( 'manage_options', 'leadwerk_migration_run_backup' );
		$result = Leadwerk_Migration_Scheduler::queue_manual_run();
		self::redirect( 'leadwerk_migration_schedules', is_wp_error( $result ) ? 'queue_failed' : 'backup_queued' );
	}

	public static function download_backup() {
		$filename = isset( $_GET['archive'] ) ? sanitize_file_name( wp_unslash( $_GET['archive'] ) ) : '';
		self::require_action( 'leadwerk_migration_import_site', 'leadwerk_migration_download_' . $filename );

		if ( ! leadwerk_migration_is_filename_supported( $filename ) || basename( $filename ) !== $filename ) {
			wp_die( esc_html__( 'Invalid backup file.', 'leadwerk-migration' ), '', array( 'response' => 400 ) );
		}

		$root = realpath( LEADWERK_MIGRATION_BACKUPS_PATH );
		$file = realpath( LEADWERK_MIGRATION_BACKUPS_PATH . DIRECTORY_SEPARATOR . $filename );
		if ( $root === false || $file === false || strpos( $file, $root . DIRECTORY_SEPARATOR ) !== 0 || ! is_file( $file ) || ! is_readable( $file ) ) {
			wp_die( esc_html__( 'Backup file not found.', 'leadwerk-migration' ), '', array( 'response' => 404 ) );
		}

		self::stream_file( $file, $filename );
	}

	public static function download_url( $filename ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'leadwerk_migration_download_backup',
					'archive' => $filename,
				),
				admin_url( 'admin-post.php' )
			),
			'leadwerk_migration_download_' . $filename
		);
	}

	public static function log_url_base() {
		return html_entity_decode( wp_nonce_url(
			add_query_arg( 'action', 'leadwerk_migration_download_log', admin_url( 'admin-post.php' ) ),
			'leadwerk_migration_download_log'
		), ENT_QUOTES, 'UTF-8' ) . '&job=';
	}

	public static function download_log() {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! is_user_logged_in() || ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'leadwerk_migration_import_site' ) ) || ! wp_verify_nonce( $nonce, 'leadwerk_migration_download_log' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'leadwerk-migration' ), '', array( 'response' => 403 ) );
		}

		$requested = isset( $_GET['job'] ) ? basename( wp_unslash( $_GET['job'] ) ) : '';
		if ( ! preg_match( '/\Aerror-log-([A-Za-z0-9_-]{8,64})\.log\z/D', $requested, $matches ) ) {
			wp_die( esc_html__( 'Invalid log identifier.', 'leadwerk-migration' ), '', array( 'response' => 400 ) );
		}

		$root = realpath( LEADWERK_MIGRATION_STORAGE_PATH );
		$file = realpath( leadwerk_migration_error_path( $matches[1] ) );
		if ( $root === false || $file === false || strpos( $file, $root . DIRECTORY_SEPARATOR ) !== 0 || ! is_file( $file ) || ! is_readable( $file ) ) {
			wp_die( esc_html__( 'Log file not found.', 'leadwerk-migration' ), '', array( 'response' => 404 ) );
		}

		self::stream_file( $file, $requested );
	}

	private static function stream_file( $file, $filename ) {
		// Large archives must not be truncated by the request's normal PHP time
		// limit. Hosts may disallow this override, in which case Range requests
		// still let clients resume from a confirmed byte boundary.
		@set_time_limit( 0 );

		$handle = @fopen( $file, 'rb' );
		if ( $handle === false ) {
			wp_die( esc_html__( 'The requested file could not be opened.', 'leadwerk-migration' ), '', array( 'response' => 404 ) );
		}

		$stat  = fstat( $handle );
		$size  = is_array( $stat ) && isset( $stat['size'] ) ? (int) $stat['size'] : 0;
		$start = 0;
		$end   = max( 0, $size - 1 );

		if ( isset( $_SERVER['HTTP_RANGE'] ) && is_string( $_SERVER['HTTP_RANGE'] ) ) {
			$range = trim( $_SERVER['HTTP_RANGE'] );
			$single_range = preg_match( '/\Abytes=(\d*)-(\d*)\z/iD', $range, $matches ) && ( $matches[1] !== '' || $matches[2] !== '' );

			// Multipart or malformed Range fields are deliberately ignored and the
			// complete representation is returned with 200. A 416 response is valid
			// only for a syntactically valid range that cannot overlap this file.
			if ( $single_range && $size === 0 ) {
				fclose( $handle );
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}

			if ( $single_range && $matches[1] === '' ) {
				$suffix = (int) $matches[2];
				if ( $suffix <= 0 ) {
					fclose( $handle );
					status_header( 416 );
					header( 'Content-Range: bytes */' . $size );
					exit;
				}
				$start = max( 0, $size - $suffix );
				$end   = $size - 1;
			} elseif ( $single_range ) {
				$start = (int) $matches[1];
				$end   = $matches[2] === '' ? $size - 1 : min( (int) $matches[2], $size - 1 );
				if ( $start >= $size || $end < $start ) {
					fclose( $handle );
					status_header( 416 );
					header( 'Content-Range: bytes */' . $size );
					exit;
				}
			}

			if ( $single_range ) {
				status_header( 206 );
				header( sprintf( 'Content-Range: bytes %d-%d/%d', $start, $end, $size ) );
			}
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $filename ) . '"' );
		$remaining = $size === 0 ? 0 : $end - $start + 1;
		header( 'Content-Length: ' . $remaining );

		if ( $remaining > 0 ) {
			fseek( $handle, $start );
		}
		while ( $remaining > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, min( 1024 * 1024, $remaining ) );
			if ( $chunk === false || $chunk === '' ) {
				break;
			}
			echo $chunk;
			$remaining -= strlen( $chunk );
			flush();
		}
		fclose( $handle );
		exit;
	}

	private static function require_action( $capability, $nonce_action ) {
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! Leadwerk_Migration_Security::authorize_admin_action( $capability, $nonce_action, $nonce ) ) {
			wp_die( esc_html__( 'Security check failed.', 'leadwerk-migration' ), '', array( 'response' => 403 ) );
		}
	}

	private static function redirect( $page, $notice ) {
		wp_safe_redirect( add_query_arg( array( 'page' => $page, 'leadwerk_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
