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

class Leadwerk_Migration_Import_Controller {

	public static function index() {
		Leadwerk_Migration_Template::render( 'import/index' );
	}

	public static function import( $params = array() ) {
		global $leadwerk_migration_params;

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( array_merge( $_GET, $_POST ) );
		}

		// Set priority
		if ( ! isset( $params['priority'] ) ) {
			$params['priority'] = 10;
		}

		$leadwerk_migration_params = $params;

		// Set job ID for per-job status tracking
		if ( isset( $params['storage'] ) && is_string( $params['storage'] ) && preg_match( '/\A[A-Za-z0-9_-]{8,64}\z/D', $params['storage'] ) ) {
			Leadwerk_Migration_Status::$job_id = $params['storage'];
		}

		// Set secret key
		$secret_key = null;
		if ( isset( $params['secret_key'] ) ) {
			$secret_key = trim( $params['secret_key'] );
		}

		leadwerk_migration_setup_environment();

		$__lw_auth = Leadwerk_Migration_Security::authorize_pipeline( 'import', $params );
		if ( ! $__lw_auth ) {
			status_header( 403 );
			leadwerk_migration_json_response( array( 'errors' => array( array( 'code' => 403, 'message' => __( 'You are not allowed to start this import.', 'leadwerk-migration' ) ) ) ) );
			exit;
		}

		// After authorization, restore mode flags may be recovered from the job
		// status. Do this only post-auth so continuation tickets keep verifying
		// against the exact request state that issued them.
		$params                    = self::rehydrate_restore_state( $params );
		$leadwerk_migration_params = $params;

		if ( empty( $params['leadwerk_migration_internal'] ) && empty( $params[ Leadwerk_Migration_Security::PIPELINE_TOKEN_PARAM ] ) ) {
			$continuation_ticket = Leadwerk_Migration_Security::issue_pipeline_token( 'import', $params );
			if ( $continuation_ticket === false ) {
				status_header( 500 );
				leadwerk_migration_json_response( array( 'errors' => array( array( 'code' => 500, 'message' => __( 'Could not create a secure import continuation ticket.', 'leadwerk-migration' ) ) ) ) );
				exit;
			}
			$params[ Leadwerk_Migration_Security::PIPELINE_TOKEN_PARAM ] = $continuation_ticket;
			$leadwerk_migration_params = $params;
		}

		// The browser receives an operation-scoped credential, never the master.
		// Authorization above validates its scope before legacy pipeline checks run.
		if ( ! Leadwerk_Migration_Security::verify_secret( $secret_key ) ) {
			$secret_key = get_option( LEADWERK_MIGRATION_SECRET_KEY, '' );
		}

		try {
			// Ensure that unauthorized people cannot access import action
			leadwerk_migration_verify_secret_key( $secret_key );
		} catch ( Leadwerk_Migration_Not_Valid_Secret_Key_Exception $e ) {
			exit;
		}

		// Error handling is set up for the authorised request, after the secret-key check.
		leadwerk_migration_setup_errors();

		// Loop over filters
		if ( ( $filters = leadwerk_migration_get_filters( 'leadwerk_migration_import' ) ) ) {
			while ( $hooks = current( $filters ) ) {
				if ( intval( $params['priority'] ) === key( $filters ) ) {
					foreach ( $hooks as $hook ) {
						try {

							// Run function hook
							$params = call_user_func_array( $hook['function'], array( $params ) );

						} catch ( Leadwerk_Migration_Upload_Exception $e ) {
							do_action( 'leadwerk_migration_status_upload_error', $params, $e );

							if ( defined( 'WP_CLI' ) ) {
								/* translators: 1: Error code, 2: Error message. */
								WP_CLI::error( sprintf( __( 'Import failed. Code: %1$s. %2$s', 'leadwerk-migration' ), $e->getCode(), $e->getMessage() ) );
							}

							// REST API: write job-scoped error so poll clients see the terminal state, then re-throw
							if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
								Leadwerk_Migration_Status::error( __( 'Import failed', 'leadwerk-migration' ), $e->getMessage() );
								throw $e;
							}

							status_header( $e->getCode() );
							leadwerk_migration_json_response( array( 'errors' => array( array( 'code' => $e->getCode(), 'message' => $e->getMessage() ) ) ) );
							exit;
						} catch ( Leadwerk_Migration_Database_Exception $e ) {
							do_action( 'leadwerk_migration_status_import_error', $params, $e );

							if ( defined( 'WP_CLI' ) ) {
								/* translators: 1: Error code, 2: Error message. */
								WP_CLI::error( sprintf( __( 'Import failed (database error). Code: %1$s. %2$s', 'leadwerk-migration' ), $e->getCode(), $e->getMessage() ) );
							}

							// REST API: write job-scoped error so poll clients see the terminal state, then re-throw
							if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
								Leadwerk_Migration_Status::error( __( 'Import failed', 'leadwerk-migration' ), $e->getMessage() );
								throw $e;
							}

							status_header( $e->getCode() );
							leadwerk_migration_json_response( array( 'errors' => array( array( 'code' => $e->getCode(), 'message' => $e->getMessage() ) ) ) );
							exit;
						} catch ( Leadwerk_Migration_Not_Decryptable_Exception $e ) {
							// Check_Encryption signals pipeline halt; storage/status preserved for retry via /confirm
							if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
								throw $e;
							}

							exit;
						} catch ( Leadwerk_Migration_Import_Halted_Exception $e ) {
							// Confirm/disk-space prompts halt for an explicit second REST acknowledgement.
							if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
								throw $e;
							}

							exit;
						} catch ( Exception $e ) {
							do_action( 'leadwerk_migration_status_import_error', $params, $e );

							if ( defined( 'WP_CLI' ) ) {
								/* translators: Error message. */
								WP_CLI::error( sprintf( __( 'Import failed: %s', 'leadwerk-migration' ), $e->getMessage() ) );
							}

							// Write job-scoped error first so REST poll clients see the terminal state
							if ( $e instanceof Leadwerk_Migration_CRC_Exception ) {
								Leadwerk_Migration_Status::left_error( __( 'Import failed', 'leadwerk-migration' ), $e->getMessage() );
							} else {
								Leadwerk_Migration_Status::error( __( 'Import failed', 'leadwerk-migration' ), $e->getMessage() );
							}

							// REST API: re-throw so the handler can return a WP_Error
							if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
								throw $e;
							}

							Leadwerk_Migration_Notification::error( __( 'Import failed', 'leadwerk-migration' ), $e->getMessage() );
							exit;
						}
					}

					// Set completed
					$completed = true;
					if ( isset( $params['completed'] ) ) {
						$completed = (bool) $params['completed'];
					}

					// Do request
					if ( $completed === false || ( $next = next( $filters ) ) && ( $params['priority'] = key( $filters ) ) ) {
						if ( empty( $params['leadwerk_migration_internal'] ) ) {
							$continuation_ticket = Leadwerk_Migration_Security::issue_pipeline_token( 'import', $params );
							if ( $continuation_ticket === false ) {
								throw new RuntimeException( __( 'Could not renew the secure import continuation ticket.', 'leadwerk-migration' ) );
							}
							$params[ Leadwerk_Migration_Security::PIPELINE_TOKEN_PARAM ] = $continuation_ticket;
						}

						if ( defined( 'WP_CLI' ) ) {
							if ( ! defined( 'DOING_CRON' ) ) {
								continue;
							}
						}

						if ( isset( $params['leadwerk_migration_manual_import'] ) || isset( $params['leadwerk_migration_manual_restore'] ) ) {
							leadwerk_migration_json_response( $params );
							exit;
						}

						if ( ! empty( $params['leadwerk_migration_sync_job_id'] ) && class_exists( 'Leadwerk_Migration_Sync' ) ) {
							Leadwerk_Migration_Sync::remember_import_continuation( $params );
						}

						wp_remote_request(
							apply_filters( 'leadwerk_migration_http_import_url', add_query_arg( array( 'leadwerk_migration_import' => 1 ), admin_url( 'admin-ajax.php?action=leadwerk_migration_import' ) ) ),
							array(
								'method'    => apply_filters( 'leadwerk_migration_http_import_method', 'POST' ),
								'timeout'   => apply_filters( 'leadwerk_migration_http_import_timeout', 10 ),
								'blocking'  => apply_filters( 'leadwerk_migration_http_import_blocking', false ),
								'sslverify' => apply_filters( 'leadwerk_migration_http_import_sslverify', true ),
								'headers'   => apply_filters( 'leadwerk_migration_http_import_headers', array() ),
								'body'      => apply_filters( 'leadwerk_migration_http_import_body', $params ),
							)
						);

						// REST API: return params instead of exit so the handler can build a response
						if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
							return $params;
						}

						exit;
					}
				}

				next( $filters );
			}
		}

		return $params;
	}

	public static function buttons() {
		return array(
			apply_filters( 'leadwerk_migration_import_file', Leadwerk_Migration_Template::get_content( 'import/button-file' ) ),
		);
	}

	/**
	 * Legacy extension button registry retained for third-party source compatibility.
	 * Leadwerk's interface deliberately exposes only sources that are bundled.
	 *
	 * @return array
	 */
	private static function legacy_buttons() {
		$active_filters = array();
		$static_filters = array();

		// Leadwerk Migration
		if ( defined( 'LEADWERK_MIGRATION_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_import_file', Leadwerk_Migration_Template::get_content( 'import/button-file' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_import_file', Leadwerk_Migration_Template::get_content( 'import/button-file' ) );
		}

		// Add Google Drive Extension
		if ( defined( 'LEADWERK_MIGRATIONGE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_import_gdrive', Leadwerk_Migration_Template::get_content( 'import/button-gdrive' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_import_gdrive', Leadwerk_Migration_Template::get_content( 'import/button-gdrive' ) );
		}

		// Add FTP Extension
		if ( defined( 'LEADWERK_MIGRATIONFE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_import_ftp', Leadwerk_Migration_Template::get_content( 'import/button-ftp' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_import_ftp', Leadwerk_Migration_Template::get_content( 'import/button-ftp' ) );
		}

		// Add Dropbox Extension
		if ( defined( 'LEADWERK_MIGRATIONDE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_import_dropbox', Leadwerk_Migration_Template::get_content( 'import/button-dropbox' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_import_dropbox', Leadwerk_Migration_Template::get_content( 'import/button-dropbox' ) );
		}

		// Add URL Extension
		if ( defined( 'LEADWERK_MIGRATIONLE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_import_url', Leadwerk_Migration_Template::get_content( 'import/button-url' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_import_url', Leadwerk_Migration_Template::get_content( 'import/button-url' ) );
		}

		// Add Amazon S3 Extension
		if ( defined( 'LEADWERK_MIGRATIONSE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_import_s3', Leadwerk_Migration_Template::get_content( 'import/button-s3' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_import_s3', Leadwerk_Migration_Template::get_content( 'import/button-s3' ) );
		}

		// Add OneDrive Extension
		if ( defined( 'LEADWERK_MIGRATIONOE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_import_onedrive', Leadwerk_Migration_Template::get_content( 'import/button-onedrive' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_import_onedrive', Leadwerk_Migration_Template::get_content( 'import/button-onedrive' ) );
		}

		// Add pCloud Extension
		if ( defined( 'LEADWERK_MIGRATIONPE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_import_pcloud', Leadwerk_Migration_Template::get_content( 'import/button-pcloud' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_import_pcloud', Leadwerk_Migration_Template::get_content( 'import/button-pcloud' ) );
		}

		// Add S3 Client Extension
		if ( defined( 'LEADWERK_MIGRATIONNE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_import_s3_client', Leadwerk_Migration_Template::get_content( 'import/button-s3-client' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_import_s3_client', Leadwerk_Migration_Template::get_content( 'import/button-s3-client' ) );
		}

		// Add Google Cloud Storage Extension
		if ( defined( 'LEADWERK_MIGRATIONCE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_import_gcloud_storage', Leadwerk_Migration_Template::get_content( 'import/button-gcloud-storage' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_import_gcloud_storage', Leadwerk_Migration_Template::get_content( 'import/button-gcloud-storage' ) );
		}

		// Add DigitalOcean Spaces Extension
		if ( defined( 'LEADWERK_MIGRATIONIE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_import_digitalocean', Leadwerk_Migration_Template::get_content( 'import/button-digitalocean' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_import_digitalocean', Leadwerk_Migration_Template::get_content( 'import/button-digitalocean' ) );
		}

		// Add Mega Extension
		if ( defined( 'LEADWERK_MIGRATIONEE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_import_mega', Leadwerk_Migration_Template::get_content( 'import/button-mega' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_import_mega', Leadwerk_Migration_Template::get_content( 'import/button-mega' ) );
		}

		// Add Backblaze B2 Extension
		if ( defined( 'LEADWERK_MIGRATIONAE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_import_b2', Leadwerk_Migration_Template::get_content( 'import/button-b2' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_import_b2', Leadwerk_Migration_Template::get_content( 'import/button-b2' ) );
		}

		// Add Box Extension
		if ( defined( 'LEADWERK_MIGRATIONBE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_import_box', Leadwerk_Migration_Template::get_content( 'import/button-box' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_import_box', Leadwerk_Migration_Template::get_content( 'import/button-box' ) );
		}

		// Add Microsoft Azure Extension
		if ( defined( 'LEADWERK_MIGRATIONZE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_import_azure_storage', Leadwerk_Migration_Template::get_content( 'import/button-azure-storage' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_import_azure_storage', Leadwerk_Migration_Template::get_content( 'import/button-azure-storage' ) );
		}

		// Add WebDAV Extension
		if ( defined( 'LEADWERK_MIGRATIONWE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_import_webdav', Leadwerk_Migration_Template::get_content( 'import/button-webdav' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_import_webdav', Leadwerk_Migration_Template::get_content( 'import/button-webdav' ) );
		}

		// Add Amazon Glacier Extension
		if ( defined( 'LEADWERK_MIGRATIONRE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_import_glacier', Leadwerk_Migration_Template::get_content( 'import/button-glacier' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_import_glacier', Leadwerk_Migration_Template::get_content( 'import/button-glacier' ) );
		}

		return array_merge( $active_filters, $static_filters );
	}

	/**
	 * Rehydrate local-restore flags from the job status after interactive prompts.
	 *
	 * Browser decrypt/confirm resumes intentionally omit the prior continuation
	 * ticket and may drop verified-copy markers that lived only in memory. The
	 * job status is the durable record of those markers.
	 *
	 * @param array $params Pipeline parameters.
	 * @return array
	 */
	private static function rehydrate_restore_state( $params ) {
		if ( ! is_array( $params ) || empty( $params['storage'] ) || ! is_string( $params['storage'] ) ) {
			return $params;
		}

		$status = get_option( 'leadwerk_migration_status_' . $params['storage'], array() );
		if ( ! is_array( $status ) ) {
			return $params;
		}

		$status_archive  = isset( $status['archive'] ) && is_string( $status['archive'] ) ? $status['archive'] : '';
		$request_archive = isset( $params['archive'] ) && is_string( $params['archive'] ) ? $params['archive'] : '';
		if ( $status_archive !== '' && $request_archive !== '' && ! hash_equals( $status_archive, $request_archive ) ) {
			return $params;
		}

		foreach ( array( 'leadwerk_migration_manual_restore', 'leadwerk_migration_verified_local_restore' ) as $restore_flag ) {
			if ( empty( $params[ $restore_flag ] ) && ! empty( $status[ $restore_flag ] ) ) {
				$params[ $restore_flag ] = 1;
			}
		}

		return $params;
	}

	public static function pro() {
		return '';
	}

	public static function max_chunk_size() {
		return min(
			leadwerk_migration_parse_size( ini_get( 'post_max_size' ), LEADWERK_MIGRATION_MAX_CHUNK_SIZE ),
			leadwerk_migration_parse_size( ini_get( 'upload_max_filesize' ), LEADWERK_MIGRATION_MAX_CHUNK_SIZE ),
			leadwerk_migration_parse_size( LEADWERK_MIGRATION_MAX_CHUNK_SIZE )
		);
	}
}
