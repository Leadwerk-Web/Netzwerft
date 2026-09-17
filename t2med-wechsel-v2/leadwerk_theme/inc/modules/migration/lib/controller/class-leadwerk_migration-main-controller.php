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

class Leadwerk_Migration_Main_Controller {

	/**
	 * Main Application Controller
	 *
	 * @return Leadwerk_Migration_Main_Controller
	 */
	public function __construct() {
		register_activation_hook( LEADWERK_MIGRATION_PLUGIN_BASENAME, array( $this, 'activation_hook' ) );
		register_deactivation_hook( LEADWERK_MIGRATION_PLUGIN_BASENAME, array( $this, 'deactivation_hook' ) );

		// Activate hooks
		$this->activate_actions();
		$this->activate_filters();
	}

	/**
	 * Activation hook callback
	 *
	 * @return void
	 */
	public function activation_hook() {
		if ( extension_loaded( 'litespeed' ) ) {
			$this->create_litespeed_htaccess( LEADWERK_MIGRATION_WORDPRESS_HTACCESS );
		}

		$this->setup_backups_folder();
		$this->setup_storage_folder();
		$this->setup_secret_key();
		Leadwerk_Migration_Scheduler::activate();
		// Activation creates only the per-installation environment identity. Site
		// Families are created exclusively by the explicit Site Sync admin action.
		Leadwerk_Migration_Site_Identity::discard_legacy_blank_family();
		Leadwerk_Migration_Sync::activate();
		Leadwerk_Migration_Sync_Hub::maybe_install();
	}

	public function deactivation_hook() {
		Leadwerk_Migration_Scheduler::deactivate();
		Leadwerk_Migration_Sync::deactivate();
	}

	/**
	 * Initializes language domain for the plugin
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( LEADWERK_MIGRATION_PLUGIN_NAME, false, dirname( LEADWERK_MIGRATION_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Register listeners for actions
	 *
	 * @return void
	 */
	private function activate_actions() {
		// Load admin header styles
		add_action( 'admin_head', array( $this, 'admin_head' ) );

		// Load core functionality
		add_action( 'cli_init', array( $this, 'cli_init' ) );
		add_action( 'admin_init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'router' ) );
		add_action( 'admin_init', array( $this, 'wp_importing' ), 5 );
		add_action( 'admin_init', array( $this, 'setup_backups_folder' ) );
		add_action( 'admin_init', array( $this, 'setup_storage_folder' ) );
		add_action( 'admin_init', array( $this, 'setup_secret_key' ) );
		add_action( 'admin_init', array( $this, 'check_auto_increment' ) );
		add_action( 'admin_init', array( $this, 'check_user_role_capability' ) );
		add_action( 'admin_init', array( $this, 'schedule_crons' ) );
		add_action( 'admin_init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', 'Leadwerk_Migration_Sync_Hub::maybe_install' );

		// Load commands and buttons
		add_action( 'plugins_loaded', array( $this, 'leadwerk_migration_loaded' ), 10 );
		add_action( 'plugins_loaded', array( $this, 'leadwerk_migration_commands' ), 10 );
		add_action( 'plugins_loaded', array( $this, 'leadwerk_migration_buttons' ), 10 );

		// Register scripts and styles
		add_action( 'admin_enqueue_scripts', array( $this, 'register_servmask_scripts_and_styles' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_settings_scripts_and_styles' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_export_scripts_and_styles' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_import_scripts_and_styles' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_backups_scripts_and_styles' ), 5 );
		add_action( 'admin_enqueue_scripts', 'Leadwerk_Migration_Dashboard_Controller::enqueue_assets', 20 );
		add_action( 'admin_enqueue_scripts', 'Leadwerk_Migration_Sync_Controller::enqueue_assets', 21 );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_reset_scripts_and_styles' ), 5 );

		// Enqueue scripts and styles
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_export_scripts_and_styles' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_import_scripts_and_styles' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_backups_scripts_and_styles' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_reset_scripts_and_styles' ), 5 );

		// Render the bulk delete button below the backups list
		add_action( 'leadwerk_migration_backups_left_end', 'Leadwerk_Migration_Backups_Controller::bulk_delete_button' );

		// Handle export/import exceptions (errors)
		add_action( 'leadwerk_migration_status_export_error', array( $this, 'handle_error_cleanup' ), 5, 2 );
		add_action( 'leadwerk_migration_status_import_error', array( $this, 'handle_error_cleanup' ), 5, 2 );

		// Leadwerk automation, health and integrity actions.
		add_action( LEADWERK_MIGRATION_AUTOMATION_HOOK, 'Leadwerk_Migration_Scheduler::run', 10, 1 );
		add_action( 'leadwerk_migration_status_export_done', 'Leadwerk_Migration_Integrity::record_export', 20 );
		add_action( 'leadwerk_migration_status_backup_deleted', 'Leadwerk_Migration_Integrity::remove', 20 );
		add_action( 'admin_post_leadwerk_migration_refresh_health', 'Leadwerk_Migration_Dashboard_Controller::refresh_health' );
		add_action( 'admin_post_leadwerk_migration_verify_backup', 'Leadwerk_Migration_Dashboard_Controller::verify_backup' );
		add_action( 'admin_post_leadwerk_migration_save_automation', 'Leadwerk_Migration_Dashboard_Controller::save_automation' );
		add_action( 'admin_post_leadwerk_migration_run_backup', 'Leadwerk_Migration_Dashboard_Controller::run_backup' );
		add_action( 'admin_post_leadwerk_migration_download_backup', 'Leadwerk_Migration_Dashboard_Controller::download_backup' );
		add_action( 'admin_post_leadwerk_migration_download_log', 'Leadwerk_Migration_Dashboard_Controller::download_log' );
		add_action( 'admin_post_leadwerk_migration_sync_save', 'Leadwerk_Migration_Sync_Controller::save_settings' );
		add_action( 'admin_post_leadwerk_migration_sync_initialize', 'Leadwerk_Migration_Sync_Controller::initialize' );
		add_action( 'admin_post_leadwerk_migration_sync_register', 'Leadwerk_Migration_Sync_Controller::register' );
		add_action( 'admin_post_leadwerk_migration_sync_promote_live', 'Leadwerk_Migration_Sync_Controller::promote_live' );
		add_action( 'admin_post_leadwerk_migration_sync_request', 'Leadwerk_Migration_Sync_Controller::request_sync' );
		add_action( 'admin_post_leadwerk_migration_sync_approve', 'Leadwerk_Migration_Sync_Controller::approve_sync' );
		add_action( 'admin_post_leadwerk_migration_sync_process', 'Leadwerk_Migration_Sync_Controller::process_now' );
		add_action( 'admin_post_leadwerk_migration_runtime_reconcile', 'Leadwerk_Migration_Sync_Controller::runtime_reconcile' );
		add_action( 'admin_post_leadwerk_migration_sync_cancel_job', 'Leadwerk_Migration_Sync_Controller::cancel_job' );
		add_action( 'admin_post_leadwerk_migration_sync_retry_job', 'Leadwerk_Migration_Sync_Controller::retry_job' );
		add_action( 'admin_post_leadwerk_migration_runtime_cancel_command', 'Leadwerk_Migration_Sync_Controller::cancel_command' );
		add_action( 'admin_post_leadwerk_migration_runtime_retry_command', 'Leadwerk_Migration_Sync_Controller::retry_command' );
		add_action( 'wp_ajax_leadwerk_migration_sync_request', 'Leadwerk_Migration_Sync_Controller::ajax_request_sync' );
		add_action( 'wp_ajax_leadwerk_migration_sync_status', 'Leadwerk_Migration_Sync_Controller::ajax_status' );
		add_action( 'admin_post_leadwerk_migration_hub_approve', 'Leadwerk_Migration_Sync_Controller::hub_approve' );
		add_action( 'admin_post_leadwerk_migration_hub_revoke', 'Leadwerk_Migration_Sync_Controller::hub_revoke' );
		add_action( LEADWERK_MIGRATION_SYNC_HOOK, 'Leadwerk_Migration_Sync::process' );
		add_action( LEADWERK_MIGRATION_SYNC_FAST_HOOK, 'Leadwerk_Migration_Sync::process' );
		add_action( 'leadwerk_migration_status_import_done', 'Leadwerk_Migration_Site_Identity::restore_after_import', 5 );
		add_action( 'leadwerk_migration_status_import_done', 'Leadwerk_Migration_Sync::complete_import_job', 20 );
	}

	/**
	 * Register listeners for filters
	 *
	 * @return void
	 */
	private function activate_filters() {
		// Add links to plugin list page
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );

		// Add custom schedules
		add_filter( 'cron_schedules', 'Leadwerk_Migration_Scheduler::cron_schedules', 9999 );
		add_filter( 'cron_schedules', 'Leadwerk_Migration_Sync::cron_schedules', 10000 );

		// Map the full-site import meta capability
		add_filter( 'map_meta_cap', array( $this, 'add_map_meta_cap' ), 9999, 4 );

		// Keep the public Hub API out of search indexes without hiding the
		// Leadwerk corporate website itself.
		add_filter( 'rest_post_dispatch', 'Leadwerk_Migration_Sync_Hub::noindex_rest_response', 10, 3 );
		add_filter( 'robots_txt', 'Leadwerk_Migration_Sync_Hub::filter_robots_txt', 10, 2 );
	}

	/**
	 * Export and import commands
	 *
	 * @return void
	 */
	public function leadwerk_migration_commands() {
		// Add export commands
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Init::execute', 5 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Compatibility::execute', 10 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Archive::execute', 30 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Config::execute', 50 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Config_File::execute', 60 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Enumerate_Content::execute', 100 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Enumerate_Media::execute', 110 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Enumerate_Plugins::execute', 120 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Enumerate_Themes::execute', 130 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Enumerate_Tables::execute', 140 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Content::execute', 150 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Media::execute', 160 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Plugins::execute', 170 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Themes::execute', 180 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Database::execute', 200 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Database_File::execute', 220 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Archive_Crc::execute', 240 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Download::execute', 250 );
		add_filter( 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Clean::execute', 300 );

		// Add import commands
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Upload::execute', 5 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Compatibility::execute', 10 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Validate::execute', 50 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_WordPress_Version::execute', 52 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Validate_Crc::execute', 55 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Check_Compression::execute', 70 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Check_Encryption::execute', 75 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Confirm::execute', 100 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Blogs::execute', 150 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Permalinks::execute', 170 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Enumerate::execute', 200 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Content::execute', 250 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Mu_Plugins::execute', 270 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Database_File::execute', 295 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Database::execute', 300 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Users::execute', 310 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Options::execute', 330 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Done::execute', 350 );
		add_filter( 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Clean::execute', 400 );
	}

	/**
	 * Export and import buttons
	 *
	 * @return void
	 */
	public function leadwerk_migration_buttons() {
		add_filter( 'leadwerk_migration_export_buttons', 'Leadwerk_Migration_Export_Controller::buttons' );
		add_filter( 'leadwerk_migration_import_buttons', 'Leadwerk_Migration_Import_Controller::buttons' );
	}

	/**
	 * Leadwerk Migration loaded
	 *
	 * @return void
	 */
	public function leadwerk_migration_loaded() {
		if ( ! defined( 'LEADWERK_MIGRATIONME_PLUGIN_NAME' ) ) {
			if ( is_multisite() ) {
				add_action( 'network_admin_notices', array( $this, 'multisite_notice' ) );
			} else {
				add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			}
		} else {
			if ( is_multisite() ) {
				add_action( 'network_admin_menu', array( $this, 'admin_menu' ) );
			} else {
				add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			}
		}

		// Add HTTP export headers
		add_filter( 'leadwerk_migration_http_export_headers', 'leadwerk_migration_auth_headers' );

		// Add HTTP import headers
		add_filter( 'leadwerk_migration_http_import_headers', 'leadwerk_migration_auth_headers' );

		// Add HTTP reset headers
		add_filter( 'leadwerk_migration_http_reset_headers', 'leadwerk_migration_auth_headers' );

		// Add chunk size limit
		add_filter( 'leadwerk_migration_max_chunk_size', 'Leadwerk_Migration_Import_Controller::max_chunk_size' );

		// Add storage folder daily cleanup cron
		add_action( 'leadwerk_migration_storage_cleanup', 'Leadwerk_Migration_Export_Controller::cleanup' );

		// Register REST API routes
		add_action( 'rest_api_init', 'Leadwerk_Migration_Rest_Controller::register_routes' );
		add_action( 'rest_api_init', 'Leadwerk_Migration_Sync_Hub::register_routes' );
		add_action( 'rest_api_init', 'Leadwerk_Migration_Sync::register_routes' );

		// Let a valid secret_key authenticate read-only poll/log routes after an import wipes user credentials
		add_filter( 'rest_authentication_errors', 'Leadwerk_Migration_Rest_Controller::allow_secret_key_auth', 1000 );
	}

	/**
	 * WP CLI commands
	 *
	 * @return void
	 */
	public function cli_init() {
		if ( defined( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'leadwerk-migration', 'Leadwerk_Migration_WP_CLI_Command', array( 'shortdesc' => __( 'Leadwerk backup and migration tools.', 'leadwerk-migration' ) ) );
		}
	}

	/**
	 * Create backups folder with index.php, index.html, .htaccess and web.config files
	 *
	 * @return void
	 */
	public function setup_backups_folder() {
		if ( ! Leadwerk_Migration_Security::path_is_private( LEADWERK_MIGRATION_BACKUPS_PATH ) ) {
			if ( is_multisite() ) {
				add_action( 'network_admin_notices', array( $this, 'backups_path_notice' ) );
			} else {
				add_action( 'admin_notices', array( $this, 'backups_path_notice' ) );
			}
			return;
		}

		$this->create_backups_folder( LEADWERK_MIGRATION_BACKUPS_PATH );
		$this->create_backups_htaccess( LEADWERK_MIGRATION_BACKUPS_HTACCESS );
		$this->create_backups_webconfig( LEADWERK_MIGRATION_BACKUPS_WEBCONFIG );
		$this->create_backups_index_php( LEADWERK_MIGRATION_BACKUPS_INDEX_PHP );
		$this->create_backups_index_html( LEADWERK_MIGRATION_BACKUPS_INDEX_HTML );
		$this->create_backups_robots_txt( LEADWERK_MIGRATION_BACKUPS_ROBOTS_TXT );
	}

	/**
	 * Create storage folder with index.php and index.html files
	 *
	 * @return void
	 */
	public function setup_storage_folder() {
		if ( ! Leadwerk_Migration_Security::path_is_private( LEADWERK_MIGRATION_STORAGE_PATH ) ) {
			if ( is_multisite() ) {
				add_action( 'network_admin_notices', array( $this, 'storage_path_notice' ) );
			} else {
				add_action( 'admin_notices', array( $this, 'storage_path_notice' ) );
			}
			return;
		}

		$this->create_storage_folder( LEADWERK_MIGRATION_STORAGE_PATH );
		$this->create_storage_htaccess( LEADWERK_MIGRATION_STORAGE_HTACCESS );
		$this->create_storage_webconfig( LEADWERK_MIGRATION_STORAGE_WEBCONFIG );
		$this->create_storage_index_php( LEADWERK_MIGRATION_STORAGE_INDEX_PHP );
		$this->create_storage_index_html( LEADWERK_MIGRATION_STORAGE_INDEX_HTML );
	}

	/**
	 * Create secret key if they don't exist yet
	 *
	 * @return void
	 */
	public function setup_secret_key() {
		$secret = get_option( LEADWERK_MIGRATION_SECRET_KEY );
		if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
			update_option( LEADWERK_MIGRATION_SECRET_KEY, Leadwerk_Migration_Security::generate_secret(), false );
		}
	}

	/**
	 * Check auto increment
	 *
	 * @return void
	 */
	public function check_auto_increment() {
		global $wpdb;

		$db_client = Leadwerk_Migration_Database_Utility::get_client();
		if ( ! $db_client->has_auto_increment( $wpdb->options ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'missing_auto_increment' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'missing_auto_increment' ) );
			}
		}
	}

	/**
	 * Check user role capability
	 *
	 * @return void
	 */
	public function check_user_role_capability() {
		if ( ( $user = wp_get_current_user() ) && in_array( 'administrator', $user->roles ) ) {
			if ( ! $user->has_cap( 'import' ) ) {
				if ( is_multisite() ) {
					return add_action( 'network_admin_notices', array( $this, 'missing_role_capability_notice' ) );
				} else {
					return add_action( 'admin_notices', array( $this, 'missing_role_capability_notice' ) );
				}
			}
		}
	}

	/**
	 * Schedule cron tasks for plugin operation, if not done yet
	 *
	 * @return void
	 */
	public function schedule_crons() {
		if ( ! Leadwerk_Migration_Cron::exists( 'leadwerk_migration_storage_cleanup' ) ) {
			Leadwerk_Migration_Cron::add( 'leadwerk_migration_storage_cleanup', 'daily', time() );
		}

		Leadwerk_Migration_Cron::clear( 'leadwerk_migration_cleanup_cron' );
		Leadwerk_Migration_Sync::ensure_schedule();
	}

	/**
	 * Create storage folder
	 *
	 * @param  string Path to folder
	 * @return void
	 */
	public function create_storage_folder( $path ) {
		if ( ! Leadwerk_Migration_Directory::create( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'storage_path_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'storage_path_notice' ) );
			}
		}

		// Runtime artifacts can contain database credentials and error details.
		@chmod( $path, 0700 );
	}

	/**
	 * Create backups folder
	 *
	 * @param  string Path to folder
	 * @return void
	 */
	public function create_backups_folder( $path ) {
		if ( ! Leadwerk_Migration_Directory::create( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'backups_path_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'backups_path_notice' ) );
			}
		}

		// Backups are served through authenticated PHP streaming, never directly.
		@chmod( $path, 0700 );
	}

	/**
	 * Create storage .htaccess file
	 *
	 * @param  string Path to file
	 * @return void
	 */
	public function create_storage_htaccess( $path ) {
		if ( ! Leadwerk_Migration_File_Htaccess::storage( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'storage_htaccess_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'storage_htaccess_notice' ) );
			}
		}
	}

	/**
	 * Create storage web.config file
	 *
	 * @param  string Path to file
	 * @return void
	 */
	public function create_storage_webconfig( $path ) {
		if ( ! Leadwerk_Migration_File_Webconfig::storage( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'storage_webconfig_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'storage_webconfig_notice' ) );
			}
		}
	}

	/**
	 * Create storage index.php file
	 *
	 * @param  string Path to file
	 * @return void
	 */
	public function create_storage_index_php( $path ) {
		if ( ! Leadwerk_Migration_File_Index::create( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'storage_index_php_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'storage_index_php_notice' ) );
			}
		}
	}

	/**
	 * Create storage index.html file
	 *
	 * @param  string Path to file
	 * @return void
	 */
	public function create_storage_index_html( $path ) {
		if ( ! Leadwerk_Migration_File_Index::create( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'storage_index_html_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'storage_index_html_notice' ) );
			}
		}
	}

	/**
	 * Create backups .htaccess file
	 *
	 * @param  string Path to file
	 * @return void
	 */
	public function create_backups_htaccess( $path ) {
		if ( ! Leadwerk_Migration_File_Htaccess::backups( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'backups_htaccess_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'backups_htaccess_notice' ) );
			}
		}
	}

	/**
	 * Create backups web.config file
	 *
	 * @param  string Path to file
	 * @return void
	 */
	public function create_backups_webconfig( $path ) {
		if ( ! Leadwerk_Migration_File_Webconfig::backups( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'backups_webconfig_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'backups_webconfig_notice' ) );
			}
		}
	}

	/**
	 * Create backups index.php file
	 *
	 * @param  string Path to file
	 * @return void
	 */
	public function create_backups_index_php( $path ) {
		if ( ! Leadwerk_Migration_File_Index::create( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'backups_index_php_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'backups_index_php_notice' ) );
			}
		}
	}

	/**
	 * Create backups index.html file
	 *
	 * @param  string Path to file
	 * @return void
	 */
	public function create_backups_index_html( $path ) {
		if ( ! Leadwerk_Migration_File_Index::create( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'backups_index_html_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'backups_index_html_notice' ) );
			}
		}
	}

	/**
	 * Create backups robots.txt file
	 *
	 * @param  string Path to file
	 * @return void
	 */
	public function create_backups_robots_txt( $path ) {
		if ( ! Leadwerk_Migration_File_Robots::create( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'backups_robots_txt_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'backups_robots_txt_notice' ) );
			}
		}
	}

	/**
	 * If the "noabort" environment variable has been set,
	 * the script will continue to run even though the connection has been broken
	 *
	 * @return void
	 */
	public function create_litespeed_htaccess( $path ) {
		if ( ! Leadwerk_Migration_File_Htaccess::litespeed( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'wordpress_htaccess_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'wordpress_htaccess_notice' ) );
			}
		}
	}

	/**
	 * Display multisite notice
	 *
	 * @return void
	 */
	public function multisite_notice() {
		Leadwerk_Migration_Template::render( 'main/multisite-notice' );
	}

	/**
	 * Display notice for storage directory
	 *
	 * @return void
	 */
	public function storage_path_notice() {
		Leadwerk_Migration_Template::render( 'main/storage-path-notice' );
	}

	/**
	 * Display notice for .htaccess file in storage directory
	 *
	 * @return void
	 */
	public function storage_htaccess_notice() {
		Leadwerk_Migration_Template::render( 'main/storage-htaccess-notice' );
	}

	/**
	 * Display notice for web.config file in storage directory
	 *
	 * @return void
	 */
	public function storage_webconfig_notice() {
		Leadwerk_Migration_Template::render( 'main/storage-webconfig-notice' );
	}

	/**
	 * Display notice for index.php file in storage directory
	 *
	 * @return void
	 */
	public function storage_index_php_notice() {
		Leadwerk_Migration_Template::render( 'main/storage-index-php-notice' );
	}

	/**
	 * Display notice for index.html file in storage directory
	 *
	 * @return void
	 */
	public function storage_index_html_notice() {
		Leadwerk_Migration_Template::render( 'main/storage-index-html-notice' );
	}

	/**
	 * Display notice for backups directory
	 *
	 * @return void
	 */
	public function backups_path_notice() {
		Leadwerk_Migration_Template::render( 'main/backups-path-notice' );
	}

	/**
	 * Display notice for .htaccess file in backups directory
	 *
	 * @return void
	 */
	public function backups_htaccess_notice() {
		Leadwerk_Migration_Template::render( 'main/backups-htaccess-notice' );
	}

	/**
	 * Display notice for web.config file in backups directory
	 *
	 * @return void
	 */
	public function backups_webconfig_notice() {
		Leadwerk_Migration_Template::render( 'main/backups-webconfig-notice' );
	}

	/**
	 * Display notice for index.php file in backups directory
	 *
	 * @return void
	 */
	public function backups_index_php_notice() {
		Leadwerk_Migration_Template::render( 'main/backups-index-php-notice' );
	}

	/**
	 * Display notice for index.html file in backups directory
	 *
	 * @return void
	 */
	public function backups_index_html_notice() {
		Leadwerk_Migration_Template::render( 'main/backups-index-html-notice' );
	}

	/**
	 * Display notice for robots.txt file in backups directory
	 *
	 * @return void
	 */
	public function backups_robots_txt_notice() {
		Leadwerk_Migration_Template::render( 'main/backups-robots-txt-notice' );
	}

	/**
	 * Display notice for .htaccess file in WordPress directory
	 *
	 * @return void
	 */
	public function wordpress_htaccess_notice() {
		Leadwerk_Migration_Template::render( 'main/wordpress-htaccess-notice' );
	}

	/**
	 * Display notice for missing auto increment
	 *
	 * @return void
	 */
	public function missing_auto_increment() {
		Leadwerk_Migration_Template::render( 'main/missing-auto-increment' );
	}

	/**
	 * Display notice for missing role capability
	 *
	 * @return void
	 */
	public function missing_role_capability_notice() {
		Leadwerk_Migration_Template::render( 'main/missing-role-capability-notice' );
	}

	/**
	 * Add links to plugin list page
	 *
	 * @return array
	 */
	public function plugin_row_meta( $links, $file ) {
		if ( $file === LEADWERK_MIGRATION_PLUGIN_BASENAME ) {
			$links[] = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=leadwerk_migration_dashboard' ) ), esc_html__( 'Migration health', 'leadwerk-migration' ) );
			$links[] = esc_html__( 'GPLv3 or later', 'leadwerk-migration' );
		}

		return $links;
	}

	/**
	 * Register plugin menus
	 *
	 * @return void
	 */
	public function admin_menu() {
		add_menu_page(
			__( 'Leadwerk Migration', 'leadwerk-migration' ),
			__( 'Leadwerk Migration', 'leadwerk-migration' ),
			'manage_options',
			'leadwerk_migration_dashboard',
			'Leadwerk_Migration_Dashboard_Controller::index',
			'dashicons-backup',
			'76.295'
		);

		add_submenu_page(
			'leadwerk_migration_dashboard',
			__( 'Overview', 'leadwerk-migration' ),
			__( 'Overview', 'leadwerk-migration' ),
			'manage_options',
			'leadwerk_migration_dashboard',
			'Leadwerk_Migration_Dashboard_Controller::index'
		);

		add_submenu_page(
			'leadwerk_migration_dashboard',
			__( 'Export', 'leadwerk-migration' ),
			__( 'Export', 'leadwerk-migration' ),
			'manage_options',
			'leadwerk_migration_export',
			'Leadwerk_Migration_Export_Controller::index'
		);

		add_submenu_page(
			'leadwerk_migration_dashboard',
			__( 'Import', 'leadwerk-migration' ),
			__( 'Import', 'leadwerk-migration' ),
			'leadwerk_migration_import_site',
			'leadwerk_migration_import',
			'Leadwerk_Migration_Import_Controller::index'
		);

		add_submenu_page(
			'leadwerk_migration_dashboard',
			__( 'Backups', 'leadwerk-migration' ),
			__( 'Backups', 'leadwerk-migration' ) . ' ' . Leadwerk_Migration_Template::get_content( 'main/backups', array( 'count' => Leadwerk_Migration_Backups::count_files() ) ),
			'leadwerk_migration_import_site',
			'leadwerk_migration_backups',
			'Leadwerk_Migration_Backups_Controller::index'
		);

		add_submenu_page(
			'leadwerk_migration_dashboard',
			__( 'Automation', 'leadwerk-migration' ),
			__( 'Automation', 'leadwerk-migration' ),
			'manage_options',
			'leadwerk_migration_schedules',
			'Leadwerk_Migration_Schedules_Controller::index'
		);

		add_submenu_page(
			'leadwerk_migration_dashboard',
			__( 'Site Sync', 'leadwerk-migration' ),
			__( 'Site Sync', 'leadwerk-migration' ),
			'manage_options',
			'leadwerk_migration_sync',
			'Leadwerk_Migration_Sync_Controller::index'
		);
	}

	/**
	 * Register ServMask scripts and styles
	 *
	 * @return void
	 */
	public function register_servmask_scripts_and_styles() {
		leadwerk_migration_register_style(
			'leadwerk_migration_servmask',
			Leadwerk_Migration_Template::asset_link( 'css/servmask.min.css' )
		);

		leadwerk_migration_register_script(
			'leadwerk_migration_util',
			Leadwerk_Migration_Template::asset_link( 'javascript/util.min.js' ),
			array( 'jquery' ),
			LEADWERK_MIGRATION_VERSION,
			false
		);

		leadwerk_migration_register_script(
			'leadwerk_migration_servmask',
			Leadwerk_Migration_Template::asset_link( 'javascript/servmask.min.js' ),
			array( 'leadwerk_migration_util' ),
			LEADWERK_MIGRATION_VERSION,
			false
		);

		wp_localize_script(
			'leadwerk_migration_servmask',
			'leadwerk_migration_feedback',
			array()
		);

		$upload_limit_text = sprintf(
			/* translators: 1: Max upload file size. */
			__(
				'Your file exceeds the <strong>%1$s</strong> upload limit configured by the server. Increase <code>upload_max_filesize</code> and <code>post_max_size</code>, or ask your hosting provider to change them.',
				'leadwerk-migration'
			),
			esc_html( leadwerk_migration_size_format( apply_filters( 'leadwerk_migration_max_file_size', LEADWERK_MIGRATION_MAX_FILE_SIZE ) ) )
		);

		wp_localize_script(
			'leadwerk_migration_servmask',
			'leadwerk_migration_locale',
			array(
				// Feedback
				'thanks_for_submitting_your_feedback' => __( 'Thank you! We have received your request and will be in touch soon.', 'leadwerk-migration' ),

				// Updater
				'check_for_updates'                   => __( 'Check for updates', 'leadwerk-migration' ),
				'invalid_purchase_id'                 => __( 'The update configuration is invalid.', 'leadwerk-migration' ),

				// Export
				'stop_exporting_your_website'         => __( 'Are you sure you want to stop the export?', 'leadwerk-migration' ),
				'preparing_to_export'                 => __( 'Preparing to export...', 'leadwerk-migration' ),
				'unable_to_export'                    => __( 'Export failed', 'leadwerk-migration' ),
				'unable_to_start_the_export'          => __( 'Could not start the export. Please refresh and try again.', 'leadwerk-migration' ),
				'unable_to_run_the_export'            => __( 'Could not run the export. Please refresh and try again.', 'leadwerk-migration' ),
				'unable_to_stop_the_export'           => __( 'Could not stop the export. Please refresh and try again.', 'leadwerk-migration' ),
				'please_wait_stopping_the_export'     => __( 'Stopping the export, please wait...', 'leadwerk-migration' ),
				'close_export'                        => __( 'Close', 'leadwerk-migration' ),
				'stop_export'                         => __( 'Stop export', 'leadwerk-migration' ),
				/* translators: 1: Number of backups. */
				'backups_count_singular'              => __( 'You have %d backup', 'leadwerk-migration' ),
				/* translators: 1: Number of backups. */
				'backups_count_plural'                => __( 'You have %d backups', 'leadwerk-migration' ),
				'archive_browser_download_error'      => __( 'Could not download backup', 'leadwerk-migration' ),
				'view_error_log_button'               => __( 'View Error Log', 'leadwerk-migration' ),

				// Import
				'stop_importing_your_website'         => __( 'Are you sure you want to stop the import?', 'leadwerk-migration' ),
				'preparing_to_import'                 => __( 'Preparing to import...', 'leadwerk-migration' ),
				'unable_to_import'                    => __( 'Import failed', 'leadwerk-migration' ),
				'unable_to_start_the_import'          => __( 'Could not start the import. Please refresh and try again.', 'leadwerk-migration' ),
				'unable_to_confirm_the_import'        => __( 'Could not confirm the import. Please refresh and try again.', 'leadwerk-migration' ),
				'unable_to_check_decryption_password' => __( 'Could not check the decryption password. Please refresh and try again.', 'leadwerk-migration' ),
				'unable_to_prepare_blogs_on_import'   => __( 'Could not prepare blogs for import. Please refresh and try again.', 'leadwerk-migration' ),
				'unable_to_stop_the_import'           => __( 'Could not stop the import. Please refresh and try again.', 'leadwerk-migration' ),
				'please_wait_stopping_the_import'     => __( 'Stopping the import, please wait...', 'leadwerk-migration' ),
				'close_import'                        => __( 'Close', 'leadwerk-migration' ),
				'stop_import'                         => __( 'Stop import', 'leadwerk-migration' ),
				'finish_import'                       => __( 'Finish', 'leadwerk-migration' ),
				'confirm_import'                      => __( 'Proceed', 'leadwerk-migration' ),
				'confirm_disk_space'                  => __( 'I have enough disk space', 'leadwerk-migration' ),
				'continue_import'                     => __( 'Continue', 'leadwerk-migration' ),
				'please_do_not_close_this_browser'    => __( 'Please do not close this browser window or your import will fail', 'leadwerk-migration' ),
				'backup_encrypted'                    => __( 'The backup is encrypted', 'leadwerk-migration' ),
				'backup_encrypted_message'            => __( 'Please enter a password to restore the backup', 'leadwerk-migration' ),
				'unlock'                              => __( 'Unlock', 'leadwerk-migration' ),
				'enter_password'                      => __( 'Enter a password', 'leadwerk-migration' ),
				'repeat_password'                     => __( 'Repeat the password', 'leadwerk-migration' ),
				'passwords_do_not_match'              => __( 'The passwords do not match', 'leadwerk-migration' ),
				'upload_failed_connection_lost'       => __( 'Upload failed - connection lost or timeout. Try uploading the file again.', 'leadwerk-migration' ),
				'upload_failed'                       => __( 'Upload failed', 'leadwerk-migration' ),
				/* translators: Disk space to free up. */
				'out_of_disk_space'                   => __( 'Not enough disk space.<br /> Free up %s before restoring.', 'leadwerk-migration' ),
				'file_too_large'                      => __( 'The file exceeds the web server upload limit. Increase upload_max_filesize and post_max_size, then try again.', 'leadwerk-migration' ),
				'import_from_file'                    => $upload_limit_text,
				'invalid_archive_extension'           => __( 'Invalid file type. Select a valid <strong>.wpress</strong> migration archive.', 'leadwerk-migration' ),
				'upgrade'                             => $upload_limit_text,

				// Backups
				'want_to_delete_this_file'            => __( 'Are you sure you want to delete this backup?', 'leadwerk-migration' ),
				'want_to_delete_selected_singular'    => __( 'Are you sure you want to delete %d selected backup?', 'leadwerk-migration' ),
				'want_to_delete_selected_plural'      => __( 'Are you sure you want to delete %d selected backups?', 'leadwerk-migration' ),
				'delete_selected'                     => __( 'Delete (%d selected)', 'leadwerk-migration' ),
				'unlimited'                           => __( 'Download the backup and restore it from the Import screen.', 'leadwerk-migration' ),
				'restore_from_file'                   => __( 'Download this backup, open Import, and select the .wpress file to restore it.', 'leadwerk-migration' ),
				'archive_browser_error'               => __( 'Error', 'leadwerk-migration' ),
				'archive_browser_list_error'          => __( 'Could not read backup content', 'leadwerk-migration' ),
				'archive_browser_title'               => __( 'List the content of the backup', 'leadwerk-migration' ),
				'progress_bar_title'                  => __( 'Reading...', 'leadwerk-migration' ),
				'close_encryption'                    => __( 'Close', 'leadwerk-migration' ),
				'check_encryption'                    => __( 'Submit', 'leadwerk-migration' ),
			)
		);
	}

	/**
	 * Register settings scripts and styles
	 *
	 * @return void
	 */
	public function register_settings_scripts_and_styles() {
		leadwerk_migration_register_script(
			'leadwerk_migration_settings',
			Leadwerk_Migration_Template::asset_link( 'javascript/settings.min.js' ),
			array( 'leadwerk_migration_servmask' ),
			LEADWERK_MIGRATION_VERSION,
			false
		);
	}

	/**
	 * Register export scripts and styles
	 *
	 * @return void
	 */
	public function register_export_scripts_and_styles() {
		$backup_action_args = array(
			'leadwerk_migration_import'          => 1,
			'_leadwerk_migration_backup_nonce' => wp_create_nonce( 'leadwerk_migration_backups' ),
		);

		leadwerk_migration_register_style(
			'leadwerk_migration_export',
			Leadwerk_Migration_Template::asset_link( 'css/export.min.css' )
		);

		leadwerk_migration_register_script(
			'leadwerk_migration_export',
			Leadwerk_Migration_Template::asset_link( 'javascript/export.min.js' ),
			array( 'leadwerk_migration_servmask' ),
			LEADWERK_MIGRATION_VERSION,
			false
		);

		wp_localize_script(
			'leadwerk_migration_export',
			'leadwerk_migration_export',
			array(
				'ajax'            => array(
					'url' => wp_make_link_relative( add_query_arg( array( 'leadwerk_migration_import' => 1 ), admin_url( 'admin-ajax.php?action=leadwerk_migration_export' ) ) ),
				),
				'download_backup' => array(
					'url' => wp_make_link_relative( add_query_arg( $backup_action_args, admin_url( 'admin-ajax.php?action=leadwerk_migration_backup_download_backup' ) ) ),
				),
				'status'          => array(
					'url'        => wp_make_link_relative( add_query_arg( array( 'leadwerk_migration_import' => 1 ), admin_url( 'admin-ajax.php?action=leadwerk_migration_status' ) ) ),
					'secret_key' => Leadwerk_Migration_Security::scoped_secret( 'status' ),
				),
				'storage'         => array(
					'url' => wp_make_link_relative( Leadwerk_Migration_Dashboard_Controller::log_url_base() ),
				),
				'error_log'       => array(
					'pattern' => LEADWERK_MIGRATION_ERROR_NAME,
				),
				'secret_key'      => Leadwerk_Migration_Security::scoped_secret( 'export' ),
			)
		);
	}

	/**
	 * Register import scripts and styles
	 *
	 * @return void
	 */
	public function register_import_scripts_and_styles() {
		leadwerk_migration_register_style(
			'leadwerk_migration_import',
			Leadwerk_Migration_Template::asset_link( 'css/import.min.css' )
		);

		leadwerk_migration_register_script(
			'leadwerk_migration_import',
			Leadwerk_Migration_Template::asset_link( 'javascript/import.min.js' ),
			array( 'leadwerk_migration_servmask' ),
			LEADWERK_MIGRATION_VERSION,
			false
		);

		wp_localize_script(
			'leadwerk_migration_import',
			'leadwerk_migration_uploader',
			array(
				'max_file_size' => apply_filters( 'leadwerk_migration_max_file_size', LEADWERK_MIGRATION_MAX_FILE_SIZE ),
				'chunk_size'    => apply_filters( 'leadwerk_migration_max_chunk_size', LEADWERK_MIGRATION_MAX_CHUNK_SIZE ),
				'url'           => wp_make_link_relative( add_query_arg( array( 'leadwerk_migration_import' => 1 ), admin_url( 'admin-ajax.php?action=leadwerk_migration_import' ) ) ),
				'filters'       => array(
					'leadwerk_migration_archive_size'      => apply_filters( 'leadwerk_migration_max_file_size', LEADWERK_MIGRATION_MAX_FILE_SIZE ),
					'leadwerk_migration_archive_extension' => array( 'wpress' ),
				),
				'params'        => array(
					'priority'                  => 5,
					'secret_key'                => Leadwerk_Migration_Security::scoped_secret( 'import' ),
					'_leadwerk_migration_nonce' => wp_create_nonce( 'leadwerk_migration_import' ),
				),
			)
		);

		wp_localize_script(
			'leadwerk_migration_import',
			'leadwerk_migration_import',
			array(
				'ajax'       => array(
					'url' => wp_make_link_relative( add_query_arg( array( 'leadwerk_migration_import' => 1 ), admin_url( 'admin-ajax.php?action=leadwerk_migration_import' ) ) ),
				),
				'status'     => array(
					'url'        => wp_make_link_relative( add_query_arg( array( 'leadwerk_migration_import' => 1 ), admin_url( 'admin-ajax.php?action=leadwerk_migration_status' ) ) ),
					'secret_key' => Leadwerk_Migration_Security::scoped_secret( 'status' ),
				),
				'storage'    => array(
					'url' => wp_make_link_relative( Leadwerk_Migration_Dashboard_Controller::log_url_base() ),
				),
				'error_log'  => array(
					'pattern' => LEADWERK_MIGRATION_ERROR_NAME,
				),
				'secret_key' => Leadwerk_Migration_Security::scoped_secret( 'import' ),
			)
		);

		wp_localize_script(
			'leadwerk_migration_import',
			'leadwerk_migration_compatibility',
			array(
				'messages' => Leadwerk_Migration_Compatibility::get( array() ),
			)
		);

		wp_localize_script(
			'leadwerk_migration_import',
			'leadwerk_migration_disk_space',
			array(
				'free'   => leadwerk_migration_disk_free_space( LEADWERK_MIGRATION_STORAGE_PATH ),
				'factor' => LEADWERK_MIGRATION_DISK_SPACE_FACTOR,
				'extra'  => LEADWERK_MIGRATION_DISK_SPACE_EXTRA,
			)
		);
	}

	/**
	 * Register backups scripts and styles
	 *
	 * @return void
	 */
	public function register_backups_scripts_and_styles() {
		$backup_action_args = array(
			'leadwerk_migration_import'          => 1,
			'_leadwerk_migration_backup_nonce' => wp_create_nonce( 'leadwerk_migration_backups' ),
		);

		leadwerk_migration_register_style(
			'leadwerk_migration_backups',
			Leadwerk_Migration_Template::asset_link( 'css/backups.min.css' )
		);

		leadwerk_migration_register_script(
			'leadwerk_migration_backups',
			Leadwerk_Migration_Template::asset_link( 'javascript/backups.min.js' ),
			array( 'leadwerk_migration_export', 'leadwerk_migration_import' ),
			LEADWERK_MIGRATION_VERSION,
			false
		);

		wp_localize_script(
			'leadwerk_migration_backups',
			'leadwerk_migration_backups',
			array(
				'clean'            => array(
					'url' => wp_make_link_relative( add_query_arg( $backup_action_args, admin_url( 'admin-ajax.php?action=leadwerk_migration_backup_clean' ) ) ),
				),
				'delete'           => array(
					'url' => wp_make_link_relative( add_query_arg( $backup_action_args, admin_url( 'admin-ajax.php?action=leadwerk_migration_backup_delete' ) ) ),
				),
				'list'             => array(
					'url' => wp_make_link_relative( add_query_arg( $backup_action_args, admin_url( 'admin-ajax.php?action=leadwerk_migration_backup_list' ) ) ),
				),
				'add_label'        => array(
					'url' => wp_make_link_relative( add_query_arg( $backup_action_args, admin_url( 'admin-ajax.php?action=leadwerk_migration_backup_add_label' ) ) ),
				),
				'get_config'       => array(
					'url' => wp_make_link_relative( add_query_arg( $backup_action_args, admin_url( 'admin-ajax.php?action=leadwerk_migration_backup_get_config' ) ) ),
				),
				'check_encryption' => array(
					'url' => wp_make_link_relative( add_query_arg( $backup_action_args, admin_url( 'admin-ajax.php?action=leadwerk_migration_backup_check_encryption' ) ) ),
				),
				'list_content'     => array(
					'url' => wp_make_link_relative( add_query_arg( $backup_action_args, admin_url( 'admin-ajax.php?action=leadwerk_migration_backup_list_content' ) ) ),
				),
				'download_file'    => array(
					'url' => wp_make_link_relative( add_query_arg( $backup_action_args, admin_url( 'admin-ajax.php?action=leadwerk_migration_backup_download_file' ) ) ),
				),
				'download_backup'  => array(
					'url' => wp_make_link_relative( add_query_arg( $backup_action_args, admin_url( 'admin-ajax.php?action=leadwerk_migration_backup_download_backup' ) ) ),
				),
				'secret_key'       => Leadwerk_Migration_Security::scoped_secret( 'backups' ),
			)
		);
	}

	/**
	 * Register schedules scripts and styles
	 *
	 * @return void
	 */
	public function register_schedules_scripts_and_styles() {
		leadwerk_migration_register_style(
			'leadwerk_migration_schedules',
			Leadwerk_Migration_Template::asset_link( 'css/schedules.min.css' )
		);

		leadwerk_migration_register_script(
			'leadwerk_migration_schedules',
			Leadwerk_Migration_Template::asset_link( 'javascript/schedules.min.js' ),
			array( 'leadwerk_migration_servmask' ),
			LEADWERK_MIGRATION_VERSION,
			false
		);
	}

	/**
	 * Register reset scripts and styles
	 *
	 * @return void
	 */
	public function register_reset_scripts_and_styles() {
		leadwerk_migration_register_style(
			'leadwerk_migration_reset',
			Leadwerk_Migration_Template::asset_link( 'css/reset.min.css' )
		);

		leadwerk_migration_register_script(
			'leadwerk_migration_reset',
			Leadwerk_Migration_Template::asset_link( 'javascript/reset.min.js' ),
			array( 'leadwerk_migration_servmask' ),
			LEADWERK_MIGRATION_VERSION,
			false
		);
	}

	/**
	 * Register updater scripts and styles
	 *
	 * @return void
	 */
	public function register_updater_scripts_and_styles() {
		leadwerk_migration_register_style(
			'leadwerk_migration_updater',
			Leadwerk_Migration_Template::asset_link( 'css/updater.min.css' )
		);
	}

	/**
	 * Enqueue scripts and styles for Export Controller
	 *
	 * @param  string $hook Hook suffix
	 * @return void
	 */
	public function enqueue_export_scripts_and_styles( $hook ) {
		if ( stripos( 'leadwerk-migration_page_leadwerk_migration_export', $hook ) === false ) {
			return;
		}

		// We don't want heartbeat to occur when exporting
		wp_deregister_script( 'heartbeat' );

		// We don't want auth check for monitoring whether the user is still logged in
		remove_action( 'admin_enqueue_scripts', 'wp_auth_check_load' );

		// Load scripts and styles
		leadwerk_migration_enqueue_style( 'leadwerk_migration_export' );
		leadwerk_migration_enqueue_script( 'leadwerk_migration_export' );
	}

	/**
	 * Enqueue scripts and styles for Import Controller
	 *
	 * @param  string $hook Hook suffix
	 * @return void
	 */
	public function enqueue_import_scripts_and_styles( $hook ) {
		if ( stripos( 'leadwerk-migration_page_leadwerk_migration_import', $hook ) === false ) {
			return;
		}

		// We don't want heartbeat to occur when importing
		wp_deregister_script( 'heartbeat' );

		// We don't want auth check for monitoring whether the user is still logged in
		remove_action( 'admin_enqueue_scripts', 'wp_auth_check_load' );

		// Load scripts and styles
		leadwerk_migration_enqueue_style( 'leadwerk_migration_import' );
		leadwerk_migration_enqueue_script( 'leadwerk_migration_import' );
	}

	/**
	 * Enqueue scripts and styles for Backups Controller
	 *
	 * @param  string $hook Hook suffix
	 * @return void
	 */
	public function enqueue_backups_scripts_and_styles( $hook ) {
		if ( stripos( 'leadwerk-migration_page_leadwerk_migration_backups', $hook ) === false ) {
			return;
		}

		// We don't want heartbeat to occur when restoring
		wp_deregister_script( 'heartbeat' );

		// We don't want auth check for monitoring whether the user is still logged in
		remove_action( 'admin_enqueue_scripts', 'wp_auth_check_load' );

		// Load scripts and styles
		leadwerk_migration_enqueue_style( 'leadwerk_migration_backups' );
		leadwerk_migration_enqueue_script( 'leadwerk_migration_backups' );
	}

	/**
	 * Enqueue scripts and styles for Schedules page
	 *
	 * @param  string $hook Hook suffix
	 * @return void
	 */
	public function enqueue_schedules_scripts_and_styles( $hook ) {
		if ( stripos( 'leadwerk-migration_page_leadwerk_migration_schedules', $hook ) === false ) {
			return;
		}

		// We don't want heartbeat to occur when restoring
		wp_deregister_script( 'heartbeat' );

		// We don't want auth check for monitoring whether the user is still logged in
		remove_action( 'admin_enqueue_scripts', 'wp_auth_check_load' );

		// Load scripts and styles
		leadwerk_migration_enqueue_style( 'leadwerk_migration_schedules' );
		leadwerk_migration_enqueue_script( 'leadwerk_migration_schedules' );
	}

	/**
	 * Enqueue scripts and styles for Reset page
	 *
	 * @param  string $hook Hook suffix
	 * @return void
	 */
	public function enqueue_reset_scripts_and_styles( $hook ) {
		if ( stripos( 'leadwerk-migration_page_leadwerk_migration_reset', $hook ) === false ) {
			return;
		}

		// We don't want heartbeat to occur when restoring
		wp_deregister_script( 'heartbeat' );

		// We don't want auth check for monitoring whether the user is still logged in
		remove_action( 'admin_enqueue_scripts', 'wp_auth_check_load' );

		// Load scripts and styles
		leadwerk_migration_enqueue_style( 'leadwerk_migration_reset' );
		leadwerk_migration_enqueue_script( 'leadwerk_migration_reset' );
	}

	/**
	 * Enqueue scripts and styles for Updater Controller
	 *
	 * @param  string $hook Hook suffix
	 * @return void
	 */
	public function enqueue_updater_scripts_and_styles( $hook ) {
		if ( 'plugins.php' !== strtolower( $hook ) ) {
			return;
		}

		// Load styles
		leadwerk_migration_enqueue_style( 'leadwerk_migration_updater' );
	}

	/**
	 * Outputs menu icon between head tags
	 *
	 * @return void
	 */
	public function admin_head() {
		global $wp_version;

		// Admin header
		Leadwerk_Migration_Template::render( 'main/admin-head', array( 'version' => $wp_version ) );
	}

	/**
	 * Register initial parameters
	 *
	 * @return void
	 */
	public function init() {
		// Scrub credentials persisted by the upstream implementation or an
		// earlier Leadwerk build. Basic Auth is forwarded in memory only.
		delete_option( LEADWERK_MIGRATION_AUTH_USER );
		delete_option( LEADWERK_MIGRATION_AUTH_PASSWORD );
		delete_option( LEADWERK_MIGRATION_AUTH_HEADER );

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$authorization = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? trim( (string) $_SERVER['HTTP_AUTHORIZATION'] ) : '';
		if ( $authorization === '' && isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			$authorization = 'Basic ' . base64_encode( (string) $_SERVER['PHP_AUTH_USER'] . ':' . (string) $_SERVER['PHP_AUTH_PW'] );
		}

		leadwerk_migration_set_auth_header( $authorization );

	}

	/**
	 * Register initial router
	 *
	 * @return void
	 */
	public function router() {
		// Public actions
		add_action( 'wp_ajax_nopriv_leadwerk_migration_export', 'Leadwerk_Migration_Export_Controller::export' );
		add_action( 'wp_ajax_nopriv_leadwerk_migration_import', 'Leadwerk_Migration_Import_Controller::import' );
		add_action( 'wp_ajax_nopriv_leadwerk_migration_status', 'Leadwerk_Migration_Status_Controller::status' );

		// Private actions
		add_action( 'wp_ajax_leadwerk_migration_export', 'Leadwerk_Migration_Export_Controller::export' );
		add_action( 'wp_ajax_leadwerk_migration_import', 'Leadwerk_Migration_Import_Controller::import' );
		add_action( 'wp_ajax_leadwerk_migration_status', 'Leadwerk_Migration_Status_Controller::status' );
		add_action( 'wp_ajax_leadwerk_migration_backup_clean', 'Leadwerk_Migration_Backups_Controller::clean' );
		add_action( 'wp_ajax_leadwerk_migration_backup_delete', 'Leadwerk_Migration_Backups_Controller::delete' );
		add_action( 'wp_ajax_leadwerk_migration_backup_list', 'Leadwerk_Migration_Backups_Controller::backup_list' );
		add_action( 'wp_ajax_leadwerk_migration_backup_get_config', 'Leadwerk_Migration_Backups_Controller::backup_get_config' );
		add_action( 'wp_ajax_leadwerk_migration_backup_check_encryption', 'Leadwerk_Migration_Backups_Controller::backup_check_encryption' );
		add_action( 'wp_ajax_leadwerk_migration_backup_list_content', 'Leadwerk_Migration_Backups_Controller::backup_list_content' );
		add_action( 'wp_ajax_leadwerk_migration_backup_add_label', 'Leadwerk_Migration_Backups_Controller::add_label' );
		add_action( 'wp_ajax_leadwerk_migration_backup_download_file', 'Leadwerk_Migration_Backups_Controller::download_file' );
		add_action( 'wp_ajax_leadwerk_migration_backup_download_backup', 'Leadwerk_Migration_Backups_Controller::download_backup' );
	}

	/**
	 * Enable WP importing
	 *
	 * @return void
	 */
	public function wp_importing() {
		if ( isset( $_GET['leadwerk_migration_import'] ) ) {
			if ( ! defined( 'WP_IMPORTING' ) ) {
				define( 'WP_IMPORTING', true );
			}
		}
	}

	/**
	 * Add custom cron schedules
	 *
	 * @param  array $schedules List of schedules
	 * @return array
	 */
	public function add_cron_schedules( $schedules ) {
		$schedules['weekly']  = array(
			'display'  => __( 'Weekly', 'leadwerk-migration' ),
			'interval' => 60 * 60 * 24 * 7,
		);
		$schedules['monthly'] = array(
			'display'  => __( 'Monthly', 'leadwerk-migration' ),
			'interval' => ( strtotime( '+1 month' ) - time() ),
		);

		return $schedules;
	}

	/**
	 * Add map meta capabilities
	 *
	 * @param  array<int, string> $caps    Primitive capabilities required of the user
	 * @param  string             $cap     Capability being checked
	 * @param  int                $user_id The user ID
	 * @param  array<int, mixed>  $args    Adds context to the capability check, typically starting with an object ID
	 * @return array<int, string>
	 */
	public function add_map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( $cap === 'leadwerk_migration_import_site' ) {
			if ( is_multisite() ) {
				return array( 'import', 'manage_network_plugins', 'manage_network_themes' );
			}

			return array( 'import', 'install_plugins', 'install_themes' );
		}

		return $caps;
	}

	/**
	 * Handles leadwerk_migration_status_export_error hook
	 *
	 * @param $params
	 * @param $exception
	 *
	 * @return void
	 */
	public function handle_error_cleanup( $params, $exception = null ) {
		Leadwerk_Migration_Directory::delete( leadwerk_migration_storage_path( $params ) );
	}
}
