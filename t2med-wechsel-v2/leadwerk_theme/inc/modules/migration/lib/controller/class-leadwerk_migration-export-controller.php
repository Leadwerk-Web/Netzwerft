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

class Leadwerk_Migration_Export_Controller {

	public static function index() {
		Leadwerk_Migration_Template::render( 'export/index' );
	}

	public static function export( $params = array() ) {
		global $leadwerk_migration_params, $leadwerk_migration_automation_lock_token;

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( array_merge( $_GET, $_POST ) );
		}

		// Set priority
		if ( ! isset( $params['priority'] ) ) {
			$params['priority'] = 5;
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

		if ( ! Leadwerk_Migration_Security::authorize_pipeline( 'export', $params ) ) {
			status_header( 403 );
			leadwerk_migration_json_response( array( 'errors' => array( array( 'code' => 403, 'message' => __( 'You are not allowed to start this export.', 'leadwerk-migration' ) ) ) ) );
			exit;
		}

		if ( empty( $params['leadwerk_migration_internal'] ) && empty( $params[ Leadwerk_Migration_Security::PIPELINE_TOKEN_PARAM ] ) ) {
			$continuation_ticket = Leadwerk_Migration_Security::issue_pipeline_token( 'export', $params );
			if ( $continuation_ticket === false ) {
				status_header( 500 );
				leadwerk_migration_json_response( array( 'errors' => array( array( 'code' => 500, 'message' => __( 'Could not create a secure export continuation ticket.', 'leadwerk-migration' ) ) ) ) );
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
			// Ensure that unauthorized people cannot access export action
			leadwerk_migration_verify_secret_key( $secret_key );
		} catch ( Leadwerk_Migration_Not_Valid_Secret_Key_Exception $e ) {
			exit;
		}

		// Error handling is set up for the authorised request, after the secret-key check.
		leadwerk_migration_setup_errors();

		// Loop over filters
		if ( ( $filters = leadwerk_migration_get_filters( 'leadwerk_migration_export' ) ) ) {
			while ( $hooks = current( $filters ) ) {
				if ( intval( $params['priority'] ) === key( $filters ) ) {
					foreach ( $hooks as $hook ) {
						try {
							if ( is_string( $leadwerk_migration_automation_lock_token ) && $leadwerk_migration_automation_lock_token !== '' && ! Leadwerk_Migration_Scheduler::renew_lock( $leadwerk_migration_automation_lock_token ) ) {
								throw new RuntimeException( __( 'The automation lock was lost while the backup was running.', 'leadwerk-migration' ) );
							}

							// Run function hook
							$params = call_user_func_array( $hook['function'], array( $params ) );

							if ( is_string( $leadwerk_migration_automation_lock_token ) && $leadwerk_migration_automation_lock_token !== '' && ! Leadwerk_Migration_Scheduler::renew_lock( $leadwerk_migration_automation_lock_token ) ) {
								throw new RuntimeException( __( 'The automation lock was lost while the backup was running.', 'leadwerk-migration' ) );
							}

						} catch ( Leadwerk_Migration_Database_Exception $e ) {
							do_action( 'leadwerk_migration_status_export_error', $params, $e );

							if ( defined( 'WP_CLI' ) ) {
								/* translators: 1: Error code, 2: Error message. */
								WP_CLI::error( sprintf( __( 'Export failed (database error). Code: %1$s. Message: %2$s', 'leadwerk-migration' ), $e->getCode(), $e->getMessage() ) );
							}

							// REST API: write job-scoped error so poll clients see the terminal state, then re-throw
							if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
								Leadwerk_Migration_Status::error( __( 'Export failed', 'leadwerk-migration' ), $e->getMessage() );
								throw $e;
							}

							status_header( $e->getCode() );
							leadwerk_migration_json_response( array( 'errors' => array( array( 'code' => $e->getCode(), 'message' => $e->getMessage() ) ) ) );
							exit;
						} catch ( Exception $e ) {
							do_action( 'leadwerk_migration_status_export_error', $params, $e );

							if ( defined( 'WP_CLI' ) ) {
								/* translators: 1: Error message. */
								WP_CLI::error( sprintf( __( 'Export failed: %s', 'leadwerk-migration' ), $e->getMessage() ) );
							}

							// Write job-scoped error first so REST poll clients see the terminal state
							Leadwerk_Migration_Status::error( __( 'Export failed', 'leadwerk-migration' ), $e->getMessage() );

							// REST API: re-throw so the handler can return a WP_Error
							if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
								throw $e;
							}

							if ( is_string( $leadwerk_migration_automation_lock_token ) && $leadwerk_migration_automation_lock_token !== '' ) {
								throw $e;
							}

							Leadwerk_Migration_Notification::error( __( 'Export failed', 'leadwerk-migration' ), $e->getMessage() );
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
						if ( ! empty( $params['leadwerk_migration_inline'] ) ) {
							continue;
						}

						if ( empty( $params['leadwerk_migration_internal'] ) ) {
							$continuation_ticket = Leadwerk_Migration_Security::issue_pipeline_token( 'export', $params );
							if ( $continuation_ticket === false ) {
								throw new RuntimeException( __( 'Could not renew the secure export continuation ticket.', 'leadwerk-migration' ) );
							}
							$params[ Leadwerk_Migration_Security::PIPELINE_TOKEN_PARAM ] = $continuation_ticket;
						}

						if ( defined( 'WP_CLI' ) ) {
							if ( ! defined( 'DOING_CRON' ) ) {
								continue;
							}
						}

						if ( isset( $params['leadwerk_migration_manual_export'] ) ) {
							leadwerk_migration_json_response( $params );
							exit;
						}

						wp_remote_request(
							apply_filters( 'leadwerk_migration_http_export_url', add_query_arg( array( 'leadwerk_migration_import' => 1 ), admin_url( 'admin-ajax.php?action=leadwerk_migration_export' ) ) ),
							array(
								'method'    => apply_filters( 'leadwerk_migration_http_export_method', 'POST' ),
								'timeout'   => apply_filters( 'leadwerk_migration_http_export_timeout', 10 ),
								'blocking'  => apply_filters( 'leadwerk_migration_http_export_blocking', false ),
								'sslverify' => apply_filters( 'leadwerk_migration_http_export_sslverify', true ),
								'headers'   => apply_filters( 'leadwerk_migration_http_export_headers', array() ),
								'body'      => apply_filters( 'leadwerk_migration_http_export_body', $params ),
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
			apply_filters( 'leadwerk_migration_export_file', Leadwerk_Migration_Template::get_content( 'export/button-file' ) ),
		);
	}

	/**
	 * Legacy extension button registry retained for third-party source compatibility.
	 * Leadwerk's interface deliberately exposes only destinations that are bundled.
	 *
	 * @return array
	 */
	private static function legacy_buttons() {
		$active_filters = array();
		$static_filters = array();

		// Leadwerk Migration
		if ( defined( 'LEADWERK_MIGRATION_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_export_file', Leadwerk_Migration_Template::get_content( 'export/button-file' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_export_file', Leadwerk_Migration_Template::get_content( 'export/button-file' ) );
		}

		// Add Google Drive Extension
		if ( defined( 'LEADWERK_MIGRATIONGE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_export_gdrive', Leadwerk_Migration_Template::get_content( 'export/button-gdrive' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_export_gdrive', Leadwerk_Migration_Template::get_content( 'export/button-gdrive' ) );
		}

		// Add FTP Extension
		if ( defined( 'LEADWERK_MIGRATIONFE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_export_ftp', Leadwerk_Migration_Template::get_content( 'export/button-ftp' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_export_ftp', Leadwerk_Migration_Template::get_content( 'export/button-ftp' ) );
		}

		// Add Dropbox Extension
		if ( defined( 'LEADWERK_MIGRATIONDE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_export_dropbox', Leadwerk_Migration_Template::get_content( 'export/button-dropbox' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_export_dropbox', Leadwerk_Migration_Template::get_content( 'export/button-dropbox' ) );
		}

		// Add Amazon S3 Extension
		if ( defined( 'LEADWERK_MIGRATIONSE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_export_s3', Leadwerk_Migration_Template::get_content( 'export/button-s3' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_export_s3', Leadwerk_Migration_Template::get_content( 'export/button-s3' ) );
		}

		// Add OneDrive Extension
		if ( defined( 'LEADWERK_MIGRATIONOE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_export_onedrive', Leadwerk_Migration_Template::get_content( 'export/button-onedrive' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_export_onedrive', Leadwerk_Migration_Template::get_content( 'export/button-onedrive' ) );
		}

		// Add pCloud Extension
		if ( defined( 'LEADWERK_MIGRATIONPE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_export_pcloud', Leadwerk_Migration_Template::get_content( 'export/button-pcloud' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_export_pcloud', Leadwerk_Migration_Template::get_content( 'export/button-pcloud' ) );
		}

		// Add S3 Client Extension
		if ( defined( 'LEADWERK_MIGRATIONNE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_export_s3_client', Leadwerk_Migration_Template::get_content( 'export/button-s3-client' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_export_s3_client', Leadwerk_Migration_Template::get_content( 'export/button-s3-client' ) );
		}

		// Add Google Cloud Storage Extension
		if ( defined( 'LEADWERK_MIGRATIONCE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_export_gcloud_storage', Leadwerk_Migration_Template::get_content( 'export/button-gcloud-storage' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_export_gcloud_storage', Leadwerk_Migration_Template::get_content( 'export/button-gcloud-storage' ) );
		}

		// Add DigitalOcean Spaces Extension
		if ( defined( 'LEADWERK_MIGRATIONIE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_export_digitalocean', Leadwerk_Migration_Template::get_content( 'export/button-digitalocean' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_export_digitalocean', Leadwerk_Migration_Template::get_content( 'export/button-digitalocean' ) );
		}

		// Add Mega Extension
		if ( defined( 'LEADWERK_MIGRATIONEE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_export_mega', Leadwerk_Migration_Template::get_content( 'export/button-mega' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_export_mega', Leadwerk_Migration_Template::get_content( 'export/button-mega' ) );
		}

		// Add Backblaze B2 Extension
		if ( defined( 'LEADWERK_MIGRATIONAE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_export_b2', Leadwerk_Migration_Template::get_content( 'export/button-b2' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_export_b2', Leadwerk_Migration_Template::get_content( 'export/button-b2' ) );
		}

		// Add Box Extension
		if ( defined( 'LEADWERK_MIGRATIONBE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_export_box', Leadwerk_Migration_Template::get_content( 'export/button-box' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_export_box', Leadwerk_Migration_Template::get_content( 'export/button-box' ) );
		}

		// Add Microsoft Azure Extension
		if ( defined( 'LEADWERK_MIGRATIONZE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_export_azure_storage', Leadwerk_Migration_Template::get_content( 'export/button-azure-storage' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_export_azure_storage', Leadwerk_Migration_Template::get_content( 'export/button-azure-storage' ) );
		}

		// Add WebDAV Extension
		if ( defined( 'LEADWERK_MIGRATIONWE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_export_webdav', Leadwerk_Migration_Template::get_content( 'export/button-webdav' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_export_webdav', Leadwerk_Migration_Template::get_content( 'export/button-webdav' ) );
		}

		// Add Amazon Glacier Extension
		if ( defined( 'LEADWERK_MIGRATIONRE_PLUGIN_NAME' ) ) {
			$active_filters[] = apply_filters( 'leadwerk_migration_export_glacier', Leadwerk_Migration_Template::get_content( 'export/button-glacier' ) );
		} else {
			$static_filters[] = apply_filters( 'leadwerk_migration_export_glacier', Leadwerk_Migration_Template::get_content( 'export/button-glacier' ) );
		}

		return array_merge( $active_filters, $static_filters );
	}

	public static function cleanup() {
		try {
			// Iterate over storage directory
			$iterator = new Leadwerk_Migration_Recursive_Directory_Iterator( LEADWERK_MIGRATION_STORAGE_PATH );

			// Exclude index.php
			$iterator = new Leadwerk_Migration_Recursive_Exclude_Filter( $iterator, array( 'index.php', 'index.html' ) );

			// Loop over folders and files
			foreach ( $iterator as $item ) {
				try {
					if ( $item->isFile() && $item->getExtension() === 'log' ) {
						if ( $item->getMTime() < ( time() - LEADWERK_MIGRATION_MAX_LOG_CLEANUP ) ) {
							Leadwerk_Migration_File::delete( $item->getPathname() );
						}
					} elseif ( $item->getMTime() < ( time() - LEADWERK_MIGRATION_MAX_STORAGE_CLEANUP ) ) {
						if ( $item->isDir() ) {
							delete_option( 'leadwerk_migration_status_' . basename( $item->getPathname() ) );
							Leadwerk_Migration_Directory::delete( $item->getPathname() );
						} else {
							Leadwerk_Migration_File::delete( $item->getPathname() );
						}
					}
				} catch ( Exception $e ) {
				}
			}
		} catch ( Exception $e ) {
		}

		// Sweep orphan leadwerk_migration_status_<job_id> options whose storage folder is gone.
		// Pipeline Clean stages delete the folder before this cron sees it, so the
		// folder-iterating loop above can't reach their option rows.
		global $wpdb;
		if ( $wpdb instanceof wpdb ) {
			$prefix       = 'leadwerk_migration_status_';
			$prefix_len   = strlen( $prefix );
			$storage_root = LEADWERK_MIGRATION_STORAGE_PATH . DIRECTORY_SEPARATOR;
			$like         = $wpdb->esc_like( $prefix ) . '%';
			// $wpdb->options is a trusted property, not user input.
			// @phpstan-ignore-next-line argument.type
			$sql  = $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like );
			$rows = $wpdb->get_col( $sql );
			if ( is_array( $rows ) ) {
				foreach ( $rows as $option_name ) {
					$job_id = substr( $option_name, $prefix_len );
					if ( ! preg_match( '/\A[a-zA-Z0-9_-]+\z/', $job_id ) ) {
						continue;
					}
					if ( ! is_dir( $storage_root . $job_id ) ) {
						delete_option( $option_name );
					}
				}
			}
		}
	}
}
