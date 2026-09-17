<?php
/**
 * Copyright (C) 2014-2025 ServMask Inc.
 * Modifications Copyright (C) 2026 Leadwerk.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Upstream attribution: This file derives from the All-in-One WP Migration plugin, developed by
 *
 * ███████╗███████╗██████╗ ██╗   ██╗███╗   ███╗ █████╗ ███████╗██╗  ██╗
 * ██╔════╝██╔════╝██╔══██╗██║   ██║████╗ ████║██╔══██╗██╔════╝██║ ██╔╝
 * ███████╗█████╗  ██████╔╝██║   ██║██╔████╔██║███████║███████╗█████╔╝
 * ╚════██║██╔══╝  ██╔══██╗╚██╗ ██╔╝██║╚██╔╝██║██╔══██║╚════██║██╔═██╗
 * ███████║███████╗██║  ██║ ╚████╔╝ ██║ ╚═╝ ██║██║  ██║███████║██║  ██╗
 * ╚══════╝╚══════╝╚═╝  ╚═╝  ╚═══╝  ╚═╝     ╚═╝╚═╝  ╚═╝╚══════╝╚═╝  ╚═╝
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Leadwerk_Migration_Status {

	/**
	 * @var string|null
	 */
	public static $job_id = null;

	public static function error( $title, $message ) {
		self::log( array( 'type' => 'error', 'title' => $title, 'message' => $message ) );
	}

	public static function left_error( $title, $message ) {
		self::log( array( 'type' => 'error', 'title' => $title, 'message' => $message, 'leftAligned' => true ) );
	}

	public static function info( $message ) {
		self::log( array( 'type' => 'info', 'message' => $message ) );
	}

	public static function download( $message ) {
		self::log( array( 'type' => 'download', 'message' => $message ) );
	}

	public static function disk_space_confirm( $message ) {
		self::log( array( 'type' => 'disk_space_confirm', 'message' => $message ) );
	}

	public static function confirm( $message, $legacy_unauthenticated = false ) {
		self::log(
			array(
				'type'                   => 'confirm',
				'message'                => $message,
				'legacy_unauthenticated' => (bool) $legacy_unauthenticated,
			)
		);
	}

	public static function done( $title, $message = null ) {
		self::log( array( 'type' => 'done', 'title' => $title, 'message' => $message ) );
	}

	public static function blogs( $title, $message ) {
		self::log( array( 'type' => 'blogs', 'title' => $title, 'message' => $message ) );
	}

	public static function progress( $percent ) {
		self::log( array( 'type' => 'progress', 'percent' => $percent ) );
	}

	public static function backup_is_encrypted( $error ) {
		self::log( array( 'type' => 'backup_is_encrypted', 'error' => $error ) );
	}

	public static function server_cannot_decrypt( $message ) {
		self::log( array( 'type' => 'server_cannot_decrypt', 'message' => $message ) );
	}

	public static function log( $data ) {
		global $leadwerk_migration_params;

		// A Site Sync import runs through many asynchronous pipeline requests.
		// Relay each meaningful step to Hub so its AJAX tables do not remain at
		// "Ready 100%" while WordPress is still validating/restoring content.
		if ( is_array( $leadwerk_migration_params ) && ! empty( $leadwerk_migration_params['leadwerk_migration_sync_job_id'] ) && is_array( $data ) ) {
			$job_id = strtolower( trim( (string) $leadwerk_migration_params['leadwerk_migration_sync_job_id'] ) );
			$type = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : '';
			$message = isset( $data['message'] ) ? (string) $data['message'] : '';
			$snapshot = leadwerk_migration_storage_path( $leadwerk_migration_params ) . DIRECTORY_SEPARATOR . Leadwerk_Migration_Site_Identity::SNAPSHOT_NAME;
			if ( is_file( $snapshot ) ) {
				// Database restoration temporarily replaces the target options with
				// source values. Reapply its preserved identity before each Hub report.
				Leadwerk_Migration_Site_Identity::restore_after_import( $leadwerk_migration_params );
			}
			if ( 'done' === $type ) {
				$message = isset( $data['title'] ) ? (string) $data['title'] : __( 'Finalizing the imported WordPress site.', 'leadwerk-migration' );
				Leadwerk_Migration_Sync::report_import_progress( $job_id, $message, 'finalizing' );
			} elseif ( in_array( $type, array( 'info', 'error' ), true ) && '' !== trim( wp_strip_all_tags( $message ) ) ) {
				Leadwerk_Migration_Sync::report_import_progress( $job_id, $message, 'importing' );
			}
		}

		// Job-scoped write (when job_id is set, e.g. REST API or any pipeline with storage)
		if ( self::$job_id !== null ) {
			$job_data = $data;
			if ( is_array( $leadwerk_migration_params ) ) {
				if ( isset( $leadwerk_migration_params['archive'] ) && is_string( $leadwerk_migration_params['archive'] ) ) {
					$job_data['archive'] = $leadwerk_migration_params['archive'];
				}

				// Persist restore mode across browser prompts and page reloads so
				// interactive resumes keep reading the verified private copy.
				foreach ( array( 'leadwerk_migration_manual_restore', 'leadwerk_migration_verified_local_restore' ) as $restore_flag ) {
					if ( ! empty( $leadwerk_migration_params[ $restore_flag ] ) ) {
						$job_data[ $restore_flag ] = 1;
					}
				}
			}
			update_option( 'leadwerk_migration_status_' . self::$job_id, $job_data, false );
		}

		// Global write (only for non-scheduled, preserves existing browser UI behavior)
		if ( ! leadwerk_migration_is_scheduled_backup() ) {
			update_option( LEADWERK_MIGRATION_STATUS, $data );
		}
	}
}
