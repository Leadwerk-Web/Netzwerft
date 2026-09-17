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

// ================
// = Plugin Debug =
// ================
define( 'LEADWERK_MIGRATION_DEBUG', false );

// ==================
// = Plugin Version =
// ==================
define( 'LEADWERK_MIGRATION_VERSION', '1.5.13' );
define( 'LEADWERK_MIGRATION_UPSTREAM_VERSION', '7.109' );

// ===============
// = Plugin Name =
// ===============
define( 'LEADWERK_MIGRATION_PLUGIN_NAME', 'leadwerk-migration' );

// ========================
// = Private Storage Path =
// ========================
$leadwerk_migration_runtime_seed = ABSPATH . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : ( defined( 'DB_NAME' ) ? DB_NAME : LEADWERK_MIGRATION_PATH ) );
define( 'LEADWERK_MIGRATION_PRIVATE_ROOT_OPTION', 'leadwerk_migration_private_root_resolved' );

if ( ! defined( 'LEADWERK_MIGRATION_PRIVATE_ROOT' ) ) {
	$leadwerk_migration_sibling_root = dirname( rtrim( ABSPATH, "/\\" ) );
	$leadwerk_migration_sibling_real = realpath( $leadwerk_migration_sibling_root );
	$leadwerk_migration_document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) && $_SERVER['DOCUMENT_ROOT'] !== ''
		? realpath( $_SERVER['DOCUMENT_ROOT'] )
		: false;

	// Store the document root used to validate an automatic path. Web requests
	// can then hand that evidence to WP-CLI/system cron, avoiding a split where
	// the same installation silently switches from its durable sibling path to
	// the temporary directory merely because DOCUMENT_ROOT is unavailable.
	$leadwerk_migration_saved_value         = get_option( LEADWERK_MIGRATION_PRIVATE_ROOT_OPTION, '' );
	$leadwerk_migration_saved_path          = '';
	$leadwerk_migration_saved_document_root = false;
	if ( is_array( $leadwerk_migration_saved_value ) && isset( $leadwerk_migration_saved_value['version'], $leadwerk_migration_saved_value['path'] ) && (int) $leadwerk_migration_saved_value['version'] === 1 ) {
		$leadwerk_migration_saved_path = is_string( $leadwerk_migration_saved_value['path'] ) ? $leadwerk_migration_saved_value['path'] : '';
		if ( isset( $leadwerk_migration_saved_value['validated_document_root'] ) && is_string( $leadwerk_migration_saved_value['validated_document_root'] ) && $leadwerk_migration_saved_value['validated_document_root'] !== '' ) {
			$leadwerk_migration_saved_document_root = realpath( $leadwerk_migration_saved_value['validated_document_root'] );
		}
	} elseif ( is_string( $leadwerk_migration_saved_value ) ) {
		// Upgrade the original string format during a web request. Without a
		// document root it receives no special trust and therefore fails closed.
		$leadwerk_migration_saved_path = $leadwerk_migration_saved_value;
	}

	$leadwerk_migration_saved_real              = $leadwerk_migration_saved_path !== '' ? realpath( $leadwerk_migration_saved_path ) : false;
	$leadwerk_migration_effective_document_root = $leadwerk_migration_document_root !== false
		? $leadwerk_migration_document_root
		: $leadwerk_migration_saved_document_root;
	$leadwerk_migration_temp_real               = realpath( sys_get_temp_dir() );

	$leadwerk_migration_is_outside_web = function ( $candidate ) use ( $leadwerk_migration_effective_document_root ) {
		$candidate = realpath( $candidate );
		if ( $candidate === false || ! is_dir( $candidate ) || ! is_writable( $candidate ) ) {
			return false;
		}

		$candidate = rtrim( str_replace( '\\', '/', $candidate ), '/' ) . '/';
		if ( DIRECTORY_SEPARATOR === '\\' ) {
			$candidate = strtolower( $candidate );
		}
		$roots     = array( realpath( ABSPATH ) );
		if ( $leadwerk_migration_effective_document_root !== false ) {
			$roots[] = $leadwerk_migration_effective_document_root;
		} else {
			// No current or previously validated web root is available. Treat the
			// WordPress parent as public until an HTTP request can prove otherwise.
			$roots[] = realpath( dirname( rtrim( ABSPATH, "/\\" ) ) );
		}

		foreach ( $roots as $root ) {
			if ( $root === false ) {
				continue;
			}
			$root = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
			if ( DIRECTORY_SEPARATOR === '\\' ) {
				$root = strtolower( $root );
			}
			if ( strpos( $candidate, $root ) === 0 ) {
				return false;
			}
		}

		return true;
	};

	$leadwerk_migration_is_temp_path = function ( $candidate ) use ( $leadwerk_migration_temp_real ) {
		if ( $candidate === false || $leadwerk_migration_temp_real === false ) {
			return false;
		}

		$normalize = function ( $path ) {
			$path = rtrim( str_replace( '\\', '/', $path ), '/' );
			if ( DIRECTORY_SEPARATOR === '\\' ) {
				$path = strtolower( $path );
			}
			return $path;
		};

		return $normalize( $candidate ) === $normalize( $leadwerk_migration_temp_real );
	};

	$leadwerk_migration_persist_root = true;

	// Prefer a web-proven sibling directory over a previously persisted OS temp
	// fallback. CLI/cron without DOCUMENT_ROOT must not permanently demote the
	// installation onto a volatile temporary volume.
	if ( $leadwerk_migration_document_root !== false && $leadwerk_migration_sibling_real !== false && $leadwerk_migration_is_outside_web( $leadwerk_migration_sibling_real ) ) {
		$leadwerk_migration_private_root            = $leadwerk_migration_sibling_real;
		$leadwerk_migration_validated_document_root = $leadwerk_migration_document_root;
	} elseif (
		$leadwerk_migration_saved_real !== false &&
		$leadwerk_migration_is_outside_web( $leadwerk_migration_saved_real ) &&
		! (
			$leadwerk_migration_is_temp_path( $leadwerk_migration_saved_real ) &&
			$leadwerk_migration_document_root !== false &&
			$leadwerk_migration_sibling_real !== false &&
			$leadwerk_migration_is_outside_web( $leadwerk_migration_sibling_real )
		)
	) {
		$leadwerk_migration_private_root            = $leadwerk_migration_saved_real;
		$leadwerk_migration_validated_document_root = $leadwerk_migration_effective_document_root;
	} else {
		$leadwerk_migration_private_root            = $leadwerk_migration_temp_real !== false ? $leadwerk_migration_temp_real : $leadwerk_migration_sibling_root;
		$leadwerk_migration_validated_document_root = $leadwerk_migration_document_root;
		// A CLI/cron process without a live document root may use temp for this
		// request, but must not overwrite a web-validated durable root option.
		if ( $leadwerk_migration_document_root === false ) {
			$leadwerk_migration_persist_root = false;
		}
	}

	define( 'LEADWERK_MIGRATION_PRIVATE_ROOT', rtrim( $leadwerk_migration_private_root, "/\\" ) );
	define( 'LEADWERK_MIGRATION_VALIDATED_DOCUMENT_ROOT', $leadwerk_migration_validated_document_root !== false ? rtrim( $leadwerk_migration_validated_document_root, "/\\" ) : '' );
	if ( $leadwerk_migration_persist_root ) {
		update_option(
			LEADWERK_MIGRATION_PRIVATE_ROOT_OPTION,
			array(
				'version'                 => 1,
				'path'                    => LEADWERK_MIGRATION_PRIVATE_ROOT,
				'validated_document_root' => LEADWERK_MIGRATION_VALIDATED_DOCUMENT_ROOT,
			),
			false
		);
	}

	unset( $leadwerk_migration_sibling_root, $leadwerk_migration_document_root, $leadwerk_migration_sibling_real, $leadwerk_migration_saved_value, $leadwerk_migration_saved_path, $leadwerk_migration_saved_document_root, $leadwerk_migration_saved_real, $leadwerk_migration_effective_document_root, $leadwerk_migration_is_outside_web, $leadwerk_migration_is_temp_path, $leadwerk_migration_temp_real, $leadwerk_migration_private_root, $leadwerk_migration_validated_document_root, $leadwerk_migration_persist_root );
}

if ( ! defined( 'LEADWERK_MIGRATION_VALIDATED_DOCUMENT_ROOT' ) ) {
	define( 'LEADWERK_MIGRATION_VALIDATED_DOCUMENT_ROOT', '' );
}

if ( ! defined( 'LEADWERK_MIGRATION_STORAGE_PATH' ) ) {
	define( 'LEADWERK_MIGRATION_STORAGE_PATH', LEADWERK_MIGRATION_PRIVATE_ROOT . DIRECTORY_SEPARATOR . 'leadwerk-work-' . substr( hash( 'sha256', $leadwerk_migration_runtime_seed ), 0, 12 ) );
}

unset( $leadwerk_migration_runtime_seed );

// ==================
// = Error Log Path =
// ==================
define( 'LEADWERK_MIGRATION_ERROR_FILE', LEADWERK_MIGRATION_STORAGE_PATH . DIRECTORY_SEPARATOR . 'error.log' );

// ===============
// = Status Path =
// ===============
define( 'LEADWERK_MIGRATION_STATUS_FILE', LEADWERK_MIGRATION_STORAGE_PATH . DIRECTORY_SEPARATOR . 'status.js' );

// ============
// = Lib Path =
// ============
define( 'LEADWERK_MIGRATION_LIB_PATH', LEADWERK_MIGRATION_PATH . DIRECTORY_SEPARATOR . 'lib' );

// ===================
// = Controller Path =
// ===================
define( 'LEADWERK_MIGRATION_CONTROLLER_PATH', LEADWERK_MIGRATION_LIB_PATH . DIRECTORY_SEPARATOR . 'controller' );

// ==============
// = Model Path =
// ==============
define( 'LEADWERK_MIGRATION_MODEL_PATH', LEADWERK_MIGRATION_LIB_PATH . DIRECTORY_SEPARATOR . 'model' );

// =============
// = View Path =
// =============
define( 'LEADWERK_MIGRATION_TEMPLATES_PATH', LEADWERK_MIGRATION_LIB_PATH . DIRECTORY_SEPARATOR . 'view' );

// ===================
// = Set Bandar Path =
// ===================
define( 'LEADWERK_MIGRATION_BANDAR_TEMPLATES_PATH', LEADWERK_MIGRATION_TEMPLATES_PATH );

// ===============
// = Vendor Path =
// ===============
define( 'LEADWERK_MIGRATION_VENDOR_PATH', LEADWERK_MIGRATION_LIB_PATH . DIRECTORY_SEPARATOR . 'vendor' );

// =========================
// = ServMask Feedback URL =
// =========================
define( 'LEADWERK_MIGRATION_FEEDBACK_URL', '' );

// ==============================
// = ServMask Archive Tools URL =
// ==============================
define( 'LEADWERK_MIGRATION_ARCHIVE_TOOLS_URL', '' );

// =========================
// = ServMask Table Prefix =
// =========================
define( 'LEADWERK_MIGRATION_TABLE_PREFIX', 'SERVMASK_PREFIX_' );

// ========================
// = Archive Backups Name =
// ========================
define( 'LEADWERK_MIGRATION_BACKUPS_NAME', 'leadwerk-migration-private' );

// =========================
// = Archive Database Name =
// =========================
define( 'LEADWERK_MIGRATION_DATABASE_NAME', 'database.sql' );

// ========================
// = Archive Package Name =
// ========================
define( 'LEADWERK_MIGRATION_PACKAGE_NAME', 'package.json' );

// ==========================
// = Archive Multisite Name =
// ==========================
define( 'LEADWERK_MIGRATION_MULTISITE_NAME', 'multisite.json' );

// ====================================
// = Authenticated Archive Manifest   =
// ====================================
define( 'LEADWERK_MIGRATION_AUTH_MANIFEST_NAME', 'leadwerk-auth.json' );

// ======================
// = Archive Blogs Name =
// ======================
define( 'LEADWERK_MIGRATION_BLOGS_NAME', 'blogs.json' );

// =========================
// = Archive Settings Name =
// =========================
define( 'LEADWERK_MIGRATION_SETTINGS_NAME', 'settings.json' );

// ==========================
// = Archive Multipart Name =
// ==========================
define( 'LEADWERK_MIGRATION_MULTIPART_NAME', 'multipart.list' );

// =============================
// = Archive Content List Name =
// =============================
define( 'LEADWERK_MIGRATION_CONTENT_LIST_NAME', 'content.list' );

// ===========================
// = Archive Media List Name =
// ===========================
define( 'LEADWERK_MIGRATION_MEDIA_LIST_NAME', 'media.list' );

// =============================
// = Archive Plugins List Name =
// =============================
define( 'LEADWERK_MIGRATION_PLUGINS_LIST_NAME', 'plugins.list' );

// ============================
// = Archive Themes List Name =
// ============================
define( 'LEADWERK_MIGRATION_THEMES_LIST_NAME', 'themes.list' );

// ============================
// = Archive Tables List Name =
// ============================
define( 'LEADWERK_MIGRATION_TABLES_LIST_NAME', 'tables.list' );

// =================================
// = Incremental Content List Name =
// =================================
define( 'LEADWERK_MIGRATION_INCREMENTAL_CONTENT_LIST_NAME', 'incremental.content.list' );

// ===============================
// = Incremental Media List Name =
// ===============================
define( 'LEADWERK_MIGRATION_INCREMENTAL_MEDIA_LIST_NAME', 'incremental.media.list' );

// =================================
// = Incremental Plugins List Name =
// =================================
define( 'LEADWERK_MIGRATION_INCREMENTAL_PLUGINS_LIST_NAME', 'incremental.plugins.list' );

// ================================
// = Incremental Themes List Name =
// ================================
define( 'LEADWERK_MIGRATION_INCREMENTAL_THEMES_LIST_NAME', 'incremental.themes.list' );

// =================================
// = Incremental Backups List Name =
// =================================
define( 'LEADWERK_MIGRATION_INCREMENTAL_BACKUPS_LIST_NAME', 'incremental.backups.list' );

// =============================
// = Archive Cookies Text Name =
// =============================
define( 'LEADWERK_MIGRATION_COOKIES_NAME', 'cookies.txt' );

// =================================
// = Archive Must-Use Plugins Name =
// =================================
define( 'LEADWERK_MIGRATION_MUPLUGINS_NAME', 'mu-plugins' );

// ========================
// = Less Cache Extension =
// ========================
define( 'LEADWERK_MIGRATION_LESS_CACHE_EXTENSION', '.less.cache' );

// =============================
// = SQLite Database Extension =
// =============================
define( 'LEADWERK_MIGRATION_SQLITE_DATABASE_EXTENSION', '.sqlite' );

// ============================
// = Elementor CSS Cache Name =
// ============================
define( 'LEADWERK_MIGRATION_ELEMENTOR_CSS_NAME', 'uploads' . DIRECTORY_SEPARATOR . 'elementor' . DIRECTORY_SEPARATOR . 'css' );

// =========================
// = CiviCRM Settings Name =
// =========================
define( 'LEADWERK_MIGRATION_CIVICRM_SETTINGS_NAME', 'uploads' . DIRECTORY_SEPARATOR . 'civicrm' . DIRECTORY_SEPARATOR . 'civicrm.settings.php' );

// ============================
// = CiviCRM Templates C Name =
// ============================
define( 'LEADWERK_MIGRATION_CIVICRM_TEMPLATES_C_NAME', 'uploads' . DIRECTORY_SEPARATOR . 'civicrm' . DIRECTORY_SEPARATOR . 'templates_c' );

// ===============================
// = CiviCRM Config And Log Name =
// ===============================
define( 'LEADWERK_MIGRATION_CIVICRM_CONFIG_AND_LOG_NAME', 'uploads' . DIRECTORY_SEPARATOR . 'civicrm' . DIRECTORY_SEPARATOR . 'ConfigAndLog' );

// =======================
// = CiviCRM Upload Name =
// =======================
define( 'LEADWERK_MIGRATION_CIVICRM_UPLOAD_NAME', 'uploads' . DIRECTORY_SEPARATOR . 'civicrm' . DIRECTORY_SEPARATOR . 'upload' );

// ========================
// = CiviCRM Dynamic Name =
// ========================
define( 'LEADWERK_MIGRATION_CIVICRM_DYNAMIC_NAME', 'uploads' . DIRECTORY_SEPARATOR . 'civicrm' . DIRECTORY_SEPARATOR . 'dynamic' );

// =========================
// = Themes Functions Name =
// =========================
define( 'LEADWERK_MIGRATION_THEMES_FUNCTIONS_NAME', 'themes' . DIRECTORY_SEPARATOR . 'functions.php' );

// =============================
// = Endurance Page Cache Name =
// =============================
define( 'LEADWERK_MIGRATION_ENDURANCE_PAGE_CACHE_NAME', 'endurance-page-cache.php' );

// ===========================
// = Endurance PHP Edge Name =
// ===========================
define( 'LEADWERK_MIGRATION_ENDURANCE_PHP_EDGE_NAME', 'endurance-php-edge.php' );

// ================================
// = Endurance Browser Cache Name =
// ================================
define( 'LEADWERK_MIGRATION_ENDURANCE_BROWSER_CACHE_NAME', 'endurance-browser-cache.php' );

// =========================
// = GD System Plugin Name =
// =========================
define( 'LEADWERK_MIGRATION_GD_SYSTEM_PLUGIN_NAME', 'gd-system-plugin.php' );

// =======================
// = WP Stack Cache Name =
// =======================
define( 'LEADWERK_MIGRATION_WP_STACK_CACHE_NAME', 'wp-stack-cache.php' );

// ===========================
// = WP.com Site Loader Name =
// ===========================
define( 'LEADWERK_MIGRATION_WP_COMSH_LOADER_NAME', 'wpcomsh-loader.php' );

// ===========================
// = WP.com Site Helper Name =
// ===========================
define( 'LEADWERK_MIGRATION_WP_COMSH_HELPER_NAME', 'wpcomsh' );

// ====================================
// = SQLite Database Integration Name =
// ====================================
define( 'LEADWERK_MIGRATION_SQLITE_DATABASE_INTEGRATION_NAME', 'sqlite-database-integration' );

// =============================
// = SQLite Database Zero Name =
// =============================
define( 'LEADWERK_MIGRATION_SQLITE_DATABASE_ZERO_NAME', '0-sqlite.php' );

// ================================
// = WP Engine System Plugin Name =
// ================================
define( 'LEADWERK_MIGRATION_WP_ENGINE_SYSTEM_PLUGIN_NAME', 'mu-plugin.php' );

// ===========================
// = WPE Sign On Plugin Name =
// ===========================
define( 'LEADWERK_MIGRATION_WPE_SIGN_ON_PLUGIN_NAME', 'wpe-wp-sign-on-plugin.php' );

// ===================================
// = WP Engine Security Auditor Name =
// ===================================
define( 'LEADWERK_MIGRATION_WP_ENGINE_SECURITY_AUDITOR_NAME', 'wpengine-security-auditor.php' );

// ===========================
// = WP Cerber Security Name =
// ===========================
define( 'LEADWERK_MIGRATION_WP_CERBER_SECURITY_NAME', 'aaa-wp-cerber.php' );

// =============================
// = EOS Deactivate Plugins Name
// =============================
define( 'LEADWERK_MIGRATION_EOS_DEACTIVATE_PLUGINS_NAME', 'eos-deactivate-plugins.php' );

// ===============================
// = W3TC config file to exclude =
// ===============================
define( 'LEADWERK_MIGRATION_W3TC_CONFIG_FILE', 'w3tc-config' . DIRECTORY_SEPARATOR . 'master.php' );

// ==================
// = Error Log Name =
// ==================
define( 'LEADWERK_MIGRATION_ERROR_NAME', 'error-log-%s.log' );

// ==============
// = Secret Key =
// ==============
define( 'LEADWERK_MIGRATION_SECRET_KEY', 'leadwerk_migration_secret_key' );

// =============
// = Auth User =
// =============
define( 'LEADWERK_MIGRATION_AUTH_USER', 'leadwerk_migration_auth_user' );

// =================
// = Auth Password =
// =================
define( 'LEADWERK_MIGRATION_AUTH_PASSWORD', 'leadwerk_migration_auth_password' );

// ===============
// = Auth Header =
// ===============
define( 'LEADWERK_MIGRATION_AUTH_HEADER', 'leadwerk_migration_auth_header' );

// ============
// = Site URL =
// ============
define( 'LEADWERK_MIGRATION_SITE_URL', 'siteurl' );

// ============
// = Home URL =
// ============
define( 'LEADWERK_MIGRATION_HOME_URL', 'home' );

// ================
// = Uploads Path =
// ================
define( 'LEADWERK_MIGRATION_UPLOADS_PATH', 'upload_path' );

// ====================
// = Uploads URL Path =
// ====================
define( 'LEADWERK_MIGRATION_UPLOADS_URL_PATH', 'upload_url_path' );

// ==================
// = Active Plugins =
// ==================
define( 'LEADWERK_MIGRATION_ACTIVE_PLUGINS', 'active_plugins' );

// ===========================
// = Active Sitewide Plugins =
// ===========================
define( 'LEADWERK_MIGRATION_ACTIVE_SITEWIDE_PLUGINS', 'active_sitewide_plugins' );

// ==========================
// = Jetpack Active Modules =
// ==========================
define( 'LEADWERK_MIGRATION_JETPACK_ACTIVE_MODULES', 'jetpack_active_modules' );

// ====================================
// = Swift Optimizer Plugin Organizer =
// ====================================
define( 'LEADWERK_MIGRATION_SWIFT_OPTIMIZER_PLUGIN_ORGANIZER', 'swift_performance_plugin_organizer' );

// ======================
// = MS Files Rewriting =
// ======================
define( 'LEADWERK_MIGRATION_MS_FILES_REWRITING', 'ms_files_rewriting' );

// ===================
// = Active Template =
// ===================
define( 'LEADWERK_MIGRATION_ACTIVE_TEMPLATE', 'template' );

// =====================
// = Active Stylesheet =
// =====================
define( 'LEADWERK_MIGRATION_ACTIVE_STYLESHEET', 'stylesheet' );

// ==============
// = DB Version =
// ==============
define( 'LEADWERK_MIGRATION_DB_VERSION', 'db_version' );

// ======================
// = Initial DB Version =
// ======================
define( 'LEADWERK_MIGRATION_INITIAL_DB_VERSION', 'initial_db_version' );

// ============
// = Cron Key =
// ============
define( 'LEADWERK_MIGRATION_CRON', 'cron' );

// =======================
// = Backups Path Option =
// =======================
define( 'LEADWERK_MIGRATION_BACKUPS_PATH_OPTION', 'leadwerk_migration_backups_path' );

// ===================
// = Backups Labels  =
// ===================
define( 'LEADWERK_MIGRATION_BACKUPS_LABELS', 'leadwerk_migration_backups_labels' );

// ===============
// = Sites Links =
// ===============
define( 'LEADWERK_MIGRATION_SITES_LINKS', 'leadwerk_migration_sites_links' );

// ==============================
// = Last Check For Updates Key =
// ==============================
define( 'LEADWERK_MIGRATION_LAST_CHECK_FOR_UPDATES', 'leadwerk_migration_last_check_for_updates' );

// ===============
// = Updater Key =
// ===============
define( 'LEADWERK_MIGRATION_UPDATER', 'leadwerk_migration_updater' );

// ==============
// = Status Key =
// ==============
define( 'LEADWERK_MIGRATION_STATUS', 'leadwerk_migration_status' );

// ================
// = Messages Key =
// ================
define( 'LEADWERK_MIGRATION_MESSAGES', 'leadwerk_migration_messages' );

// =================
// = Support Email =
// =================
define( 'LEADWERK_MIGRATION_SUPPORT_EMAIL', get_option( 'admin_email' ) );

// ==================
// = Max Chunk Size =
// ==================
define( 'LEADWERK_MIGRATION_MAX_CHUNK_SIZE', 5 * 1024 * 1024 );

// =====================
// = Maximum File Size =
// =====================
define( 'LEADWERK_MIGRATION_MAX_FILE_SIZE', 536870912 * 200 );

// =====================
// = Max Chunk Retries =
// =====================
define( 'LEADWERK_MIGRATION_MAX_CHUNK_RETRIES', 10 );

// ===============
// = CIPHER NAME =
// ===============
define( 'LEADWERK_MIGRATION_CIPHER_NAME', 'AES-256-CBC' );

// ==================================
// = Authenticated Encryption Format =
// ==================================
define( 'LEADWERK_MIGRATION_ENCRYPTION_FORMAT', 'leadwerk-aes-256-gcm-pbkdf2-aad-v3' );
define( 'LEADWERK_MIGRATION_ENCRYPTION_FRAME_HEADER_SIZE', 54 );

// =============
// = SIGN TEXT =
// =============
define( 'LEADWERK_MIGRATION_SIGN_TEXT', '"How long do you want these messages to remain secret? I want them to remain secret for as long as men are capable of evil." - Neal Stephenson' );

// ===========================
// = Max Transaction Queries =
// ===========================
if ( ! defined( 'LEADWERK_MIGRATION_MAX_TRANSACTION_QUERIES' ) ) {
	define( 'LEADWERK_MIGRATION_MAX_TRANSACTION_QUERIES', 1000 );
}

// ======================
// = Max Select Records =
// ======================
if ( ! defined( 'LEADWERK_MIGRATION_MAX_SELECT_RECORDS' ) ) {
	define( 'LEADWERK_MIGRATION_MAX_SELECT_RECORDS', 1000 );
}

// =======================
// = Max Storage Cleanup =
// =======================
define( 'LEADWERK_MIGRATION_MAX_STORAGE_CLEANUP', 24 * 60 * 60 );

// ===================
// = Max Log Cleanup =
// ===================
define( 'LEADWERK_MIGRATION_MAX_LOG_CLEANUP', 7 * 24 * 60 * 60 );

// =====================
// = Disk Space Factor =
// =====================
define( 'LEADWERK_MIGRATION_DISK_SPACE_FACTOR', 2 );

// ====================
// = Disk Space Extra =
//=====================
define( 'LEADWERK_MIGRATION_DISK_SPACE_EXTRA', 300 * 1024 * 1024 );

// ===========================
// = WP_CONTENT_DIR Constant =
// ===========================
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

// ========================
// = Backups Default Path =
// ========================
if ( ! defined( 'LEADWERK_MIGRATION_DEFAULT_BACKUPS_PATH' ) ) {
	$leadwerk_migration_backup_seed  = defined( 'AUTH_KEY' ) ? AUTH_KEY : ( defined( 'DB_NAME' ) ? DB_NAME : LEADWERK_MIGRATION_PATH );
	$leadwerk_migration_private_name = 'leadwerk-backups-' . substr( hash( 'sha256', ABSPATH . $leadwerk_migration_backup_seed ), 0, 12 );
	define( 'LEADWERK_MIGRATION_DEFAULT_BACKUPS_PATH', LEADWERK_MIGRATION_PRIVATE_ROOT . DIRECTORY_SEPARATOR . $leadwerk_migration_private_name );
	unset( $leadwerk_migration_backup_seed, $leadwerk_migration_private_name );
}

// Leadwerk automation and integrity options
define( 'LEADWERK_MIGRATION_AUTOMATION_SETTINGS', 'leadwerk_migration_automation_settings' );
define( 'LEADWERK_MIGRATION_AUTOMATION_HOOK', 'leadwerk_migration_automation_run' );
define( 'LEADWERK_MIGRATION_AUTOMATION_LOCK', 'leadwerk_migration_automation_lock' );
define( 'LEADWERK_MIGRATION_BACKUP_MANIFESTS', 'leadwerk_migration_backup_manifests' );
define( 'LEADWERK_MIGRATION_ACTIVITY_LOG', 'leadwerk_migration_activity_log' );
define( 'LEADWERK_MIGRATION_HEALTH_CACHE', 'leadwerk_migration_health_cache' );

// Leadwerk Site Sync identity, hub, and job options.
define( 'LEADWERK_MIGRATION_SYNC_FAMILY', 'leadwerk_migration_sync_family' );
define( 'LEADWERK_MIGRATION_SYNC_ENVIRONMENT', 'leadwerk_migration_sync_environment' );
define( 'LEADWERK_MIGRATION_SYNC_SETTINGS', 'leadwerk_migration_sync_settings' );
define( 'LEADWERK_MIGRATION_SYNC_STATE', 'leadwerk_migration_sync_state' );
define( 'LEADWERK_MIGRATION_SYNC_HOOK', 'leadwerk_migration_sync_tick' );
define( 'LEADWERK_MIGRATION_SYNC_FAST_HOOK', 'leadwerk_migration_sync_fast_tick' );
define( 'LEADWERK_MIGRATION_SYNC_DB_VERSION', '8' );
define( 'LEADWERK_MIGRATION_SYNC_DB_OPTION', 'leadwerk_migration_sync_db_version' );
define( 'LEADWERK_MIGRATION_SYNC_CHUNK_SIZE', 2 * 1024 * 1024 );
define( 'LEADWERK_MIGRATION_SYNC_DIRECT_CHUNK_SIZE', 8 * 1024 * 1024 );
define( 'LEADWERK_MIGRATION_SYNC_DIRECT_BATCH_CHUNKS', 8 );

// ================
// = Backups Path =
// ================
define( 'LEADWERK_MIGRATION_BACKUPS_PATH', leadwerk_migration_resolve_backups_path() );

// ==========================
// = Storage index.php File =
// ==========================
define( 'LEADWERK_MIGRATION_STORAGE_INDEX_PHP', LEADWERK_MIGRATION_STORAGE_PATH . DIRECTORY_SEPARATOR . 'index.php' );

// ===========================
// = Storage index.html File =
// ===========================
define( 'LEADWERK_MIGRATION_STORAGE_INDEX_HTML', LEADWERK_MIGRATION_STORAGE_PATH . DIRECTORY_SEPARATOR . 'index.html' );

// ==========================
// = Storage .htaccess File =
// ==========================
define( 'LEADWERK_MIGRATION_STORAGE_HTACCESS', LEADWERK_MIGRATION_STORAGE_PATH . DIRECTORY_SEPARATOR . '.htaccess' );

// ===========================
// = Storage web.config File =
// ===========================
define( 'LEADWERK_MIGRATION_STORAGE_WEBCONFIG', LEADWERK_MIGRATION_STORAGE_PATH . DIRECTORY_SEPARATOR . 'web.config' );

// ==========================
// = Backups index.php File =
// ==========================
define( 'LEADWERK_MIGRATION_BACKUPS_INDEX_PHP', LEADWERK_MIGRATION_BACKUPS_PATH . DIRECTORY_SEPARATOR . 'index.php' );

// ===========================
// = Backups index.html File =
// ===========================
define( 'LEADWERK_MIGRATION_BACKUPS_INDEX_HTML', LEADWERK_MIGRATION_BACKUPS_PATH . DIRECTORY_SEPARATOR . 'index.html' );

// ===========================
// = Backups robots.txt File =
// ===========================
define( 'LEADWERK_MIGRATION_BACKUPS_ROBOTS_TXT', LEADWERK_MIGRATION_BACKUPS_PATH . DIRECTORY_SEPARATOR . 'robots.txt' );

// ==========================
// = Backups .htaccess File =
// ==========================
define( 'LEADWERK_MIGRATION_BACKUPS_HTACCESS', LEADWERK_MIGRATION_BACKUPS_PATH . DIRECTORY_SEPARATOR . '.htaccess' );

// ===========================
// = Backups web.config File =
// ===========================
define( 'LEADWERK_MIGRATION_BACKUPS_WEBCONFIG', LEADWERK_MIGRATION_BACKUPS_PATH . DIRECTORY_SEPARATOR . 'web.config' );

// ============================
// = WordPress .htaccess File =
// ============================
define( 'LEADWERK_MIGRATION_WORDPRESS_HTACCESS', ABSPATH . DIRECTORY_SEPARATOR . '.htaccess' );

// =============================
// = WordPress web.config File =
// =============================
define( 'LEADWERK_MIGRATION_WORDPRESS_WEBCONFIG', ABSPATH . DIRECTORY_SEPARATOR . 'web.config' );

// ================================
// = WP Migration Plugin Base Dir =
// ================================
if ( defined( 'LEADWERK_MIGRATION_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATION_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATION_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATION_PLUGIN_BASEDIR', 'leadwerk-migration' );
}

// ======================================
// = Microsoft Azure Extension Base Dir =
// ======================================
if ( defined( 'LEADWERK_MIGRATIONZE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONZE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONZE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONZE_PLUGIN_BASEDIR', 'leadwerk-migration-azure-storage-extension' );
}

// ===================================
// = Microsoft Azure Extension Title =
// ===================================
if ( ! defined( 'LEADWERK_MIGRATIONZE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONZE_PLUGIN_TITLE', 'Microsoft Azure Storage Extension' );
}

// ===================================
// = Microsoft Azure Extension About =
// ===================================
if ( ! defined( 'LEADWERK_MIGRATIONZE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONZE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/microsoft-azure-storage-extension.json' );
}

// ===================================
// = Microsoft Azure Extension Check =
// ===================================
if ( ! defined( 'LEADWERK_MIGRATIONZE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONZE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/microsoft-azure-storage-extension' );
}

// =================================
// = Microsoft Azure Extension Key =
// =================================
if ( ! defined( 'LEADWERK_MIGRATIONZE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONZE_PLUGIN_KEY', 'leadwerk_migrationze_plugin_key' );
}

// ===================================
// = Microsoft Azure Extension Short =
// ===================================
if ( ! defined( 'LEADWERK_MIGRATIONZE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONZE_PLUGIN_SHORT', 'azure-storage' );
}

// ===================================
// = Backblaze B2 Extension Base Dir =
// ===================================
if ( defined( 'LEADWERK_MIGRATIONAE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONAE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONAE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONAE_PLUGIN_BASEDIR', 'leadwerk-migration-b2-extension' );
}

// ================================
// = Backblaze B2 Extension Title =
// ================================
if ( ! defined( 'LEADWERK_MIGRATIONAE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONAE_PLUGIN_TITLE', 'Backblaze B2 Extension' );
}

// ================================
// = Backblaze B2 Extension About =
// ================================
if ( ! defined( 'LEADWERK_MIGRATIONAE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONAE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/backblaze-b2-extension.json' );
}

// ================================
// = Backblaze B2 Extension Check =
// ================================
if ( ! defined( 'LEADWERK_MIGRATIONAE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONAE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/backblaze-b2-extension' );
}

// ==============================
// = Backblaze B2 Extension Key =
// ==============================
if ( ! defined( 'LEADWERK_MIGRATIONAE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONAE_PLUGIN_KEY', 'leadwerk_migrationae_plugin_key' );
}

// ================================
// = Backblaze B2 Extension Short =
// ================================
if ( ! defined( 'LEADWERK_MIGRATIONAE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONAE_PLUGIN_SHORT', 'b2' );
}

// ==========================
// = Backup Plugin Base Dir =
// ==========================
if ( defined( 'LEADWERK_MIGRATIONVE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONVE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONVE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONVE_PLUGIN_BASEDIR', 'leadwerk-migration-backup' );
}

// =======================
// = Backup Plugin Title =
// =======================
if ( ! defined( 'LEADWERK_MIGRATIONVE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONVE_PLUGIN_TITLE', 'Backup Plugin' );
}

// =======================
// = Backup Plugin About =
// =======================
if ( ! defined( 'LEADWERK_MIGRATIONVE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONVE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/backup-plugin.json' );
}

// =======================
// = Backup Plugin Check =
// =======================
if ( ! defined( 'LEADWERK_MIGRATIONVE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONVE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/backup-plugin' );
}

// =====================
// = Backup Plugin Key =
// =====================
if ( ! defined( 'LEADWERK_MIGRATIONVE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONVE_PLUGIN_KEY', 'leadwerk_migrationve_plugin_key' );
}

// =======================
// = Backup Plugin Short =
// =======================
if ( ! defined( 'LEADWERK_MIGRATIONVE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONVE_PLUGIN_SHORT', 'backup' );
}

// ==========================
// = Box Extension Base Dir =
// ==========================
if ( defined( 'LEADWERK_MIGRATIONBE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONBE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONBE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONBE_PLUGIN_BASEDIR', 'leadwerk-migration-box-extension' );
}

// =======================
// = Box Extension Title =
// =======================
if ( ! defined( 'LEADWERK_MIGRATIONBE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONBE_PLUGIN_TITLE', 'Box Extension' );
}

// =======================
// = Box Extension About =
// =======================
if ( ! defined( 'LEADWERK_MIGRATIONBE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONBE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/box-extension.json' );
}

// =======================
// = Box Extension Check =
// =======================
if ( ! defined( 'LEADWERK_MIGRATIONBE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONBE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/box-extension' );
}

// =====================
// = Box Extension Key =
// =====================
if ( ! defined( 'LEADWERK_MIGRATIONBE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONBE_PLUGIN_KEY', 'leadwerk_migrationbe_plugin_key' );
}

// =======================
// = Box Extension Short =
// =======================
if ( ! defined( 'LEADWERK_MIGRATIONBE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONBE_PLUGIN_SHORT', 'box' );
}

// ==========================================
// = DigitalOcean Spaces Extension Base Dir =
// ==========================================
if ( defined( 'LEADWERK_MIGRATIONIE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONIE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONIE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONIE_PLUGIN_BASEDIR', 'leadwerk-migration-digitalocean-extension' );
}

// =======================================
// = DigitalOcean Spaces Extension Title =
// =======================================
if ( ! defined( 'LEADWERK_MIGRATIONIE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONIE_PLUGIN_TITLE', 'DigitalOcean Spaces Extension' );
}

// =======================================
// = DigitalOcean Spaces Extension About =
// =======================================
if ( ! defined( 'LEADWERK_MIGRATIONIE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONIE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/digitalocean-spaces-extension.json' );
}

// =======================================
// = DigitalOcean Spaces Extension Check =
// =======================================
if ( ! defined( 'LEADWERK_MIGRATIONIE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONIE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/digitalocean-spaces-extension' );
}

// =====================================
// = DigitalOcean Spaces Extension Key =
// =====================================
if ( ! defined( 'LEADWERK_MIGRATIONIE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONIE_PLUGIN_KEY', 'leadwerk_migrationie_plugin_key' );
}

// =======================================
// = DigitalOcean Spaces Extension Short =
// =======================================
if ( ! defined( 'LEADWERK_MIGRATIONIE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONIE_PLUGIN_SHORT', 'digitalocean' );
}

// =============================
// = Direct Extension Base Dir =
// =============================
if ( defined( 'LEADWERK_MIGRATIONXE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONXE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONXE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONXE_PLUGIN_BASEDIR', 'leadwerk-migration-direct-extension' );
}
// ==========================
// = Direct Extension Title =
// ==========================
if ( ! defined( 'LEADWERK_MIGRATIONXE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONXE_PLUGIN_TITLE', 'Direct Extension' );
}
// ==========================
// = Direct Extension About =
// ==========================
if ( ! defined( 'LEADWERK_MIGRATIONXE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONXE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/direct-extension.json' );
}

// ==========================
// = Direct Extension Check =
// ==========================
if ( ! defined( 'LEADWERK_MIGRATIONXE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONXE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/direct-extension' );
}

// ========================
// = Direct Extension Key =
// ========================
if ( ! defined( 'LEADWERK_MIGRATIONXE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONXE_PLUGIN_KEY', 'leadwerk_migrationxe_plugin_key' );
}
// ==========================
// = Direct Extension Short =
// ==========================
if ( ! defined( 'LEADWERK_MIGRATIONXE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONXE_PLUGIN_SHORT', 'direct' );
}

// ==============================
// = Dropbox Extension Base Dir =
// ==============================
if ( defined( 'LEADWERK_MIGRATIONDE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONDE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONDE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONDE_PLUGIN_BASEDIR', 'leadwerk-migration-dropbox-extension' );
}

// ===========================
// = Dropbox Extension Title =
// ===========================
if ( ! defined( 'LEADWERK_MIGRATIONDE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONDE_PLUGIN_TITLE', 'Dropbox Extension' );
}

// ===========================
// = Dropbox Extension About =
// ===========================
if ( ! defined( 'LEADWERK_MIGRATIONDE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONDE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/dropbox-extension.json' );
}

// ===========================
// = Dropbox Extension Check =
// ===========================
if ( ! defined( 'LEADWERK_MIGRATIONDE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONDE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/dropbox-extension' );
}

// =========================
// = Dropbox Extension Key =
// =========================
if ( ! defined( 'LEADWERK_MIGRATIONDE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONDE_PLUGIN_KEY', 'leadwerk_migrationde_plugin_key' );
}

// ===========================
// = Dropbox Extension Short =
// ===========================
if ( ! defined( 'LEADWERK_MIGRATIONDE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONDE_PLUGIN_SHORT', 'dropbox' );
}

// ===========================
// = File Extension Base Dir =
// ===========================
if ( defined( 'LEADWERK_MIGRATIONTE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONTE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONTE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONTE_PLUGIN_BASEDIR', 'leadwerk-migration-file-extension' );
}

// ========================
// = File Extension Title =
// ========================
if ( ! defined( 'LEADWERK_MIGRATIONTE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONTE_PLUGIN_TITLE', 'File Extension' );
}

// ========================
// = File Extension About =
// ========================
if ( ! defined( 'LEADWERK_MIGRATIONTE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONTE_PLUGIN_ABOUT', 'https://import.wp-migration.com/file-extension.json' );
}

// ========================
// = File Extension Check =
// ========================
if ( ! defined( 'LEADWERK_MIGRATIONTE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONTE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/file-extension' );
}

// ======================
// = File Extension Key =
// ======================
if ( ! defined( 'LEADWERK_MIGRATIONTE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONTE_PLUGIN_KEY', 'leadwerk_migrationte_plugin_key' );
}

// ========================
// = File Extension Short =
// ========================
if ( ! defined( 'LEADWERK_MIGRATIONTE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONTE_PLUGIN_SHORT', 'file' );
}

// ==========================
// = FTP Extension Base Dir =
// ==========================
if ( defined( 'LEADWERK_MIGRATIONFE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONFE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONFE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONFE_PLUGIN_BASEDIR', 'leadwerk-migration-ftp-extension' );
}

// =======================
// = FTP Extension Title =
// =======================
if ( ! defined( 'LEADWERK_MIGRATIONFE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONFE_PLUGIN_TITLE', 'FTP Extension' );
}

// =======================
// = FTP Extension About =
// =======================
if ( ! defined( 'LEADWERK_MIGRATIONFE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONFE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/ftp-extension.json' );
}

// =======================
// = FTP Extension Check =
// =======================
if ( ! defined( 'LEADWERK_MIGRATIONFE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONFE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/ftp-extension' );
}

// =====================
// = FTP Extension Key =
// =====================
if ( ! defined( 'LEADWERK_MIGRATIONFE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONFE_PLUGIN_KEY', 'leadwerk_migrationfe_plugin_key' );
}

// =======================
// = FTP Extension Short =
// =======================
if ( ! defined( 'LEADWERK_MIGRATIONFE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONFE_PLUGIN_SHORT', 'ftp' );
}

// ===========================================
// = Google Cloud Storage Extension Base Dir =
// ===========================================
if ( defined( 'LEADWERK_MIGRATIONCE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONCE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONCE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONCE_PLUGIN_BASEDIR', 'leadwerk-migration-gcloud-storage-extension' );
}

// ========================================
// = Google Cloud Storage Extension Title =
// ========================================
if ( ! defined( 'LEADWERK_MIGRATIONCE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONCE_PLUGIN_TITLE', 'Google Cloud Storage Extension' );
}

// ========================================
// = Google Cloud Storage Extension About =
// ========================================
if ( ! defined( 'LEADWERK_MIGRATIONCE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONCE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/google-cloud-storage-extension.json' );
}

// ========================================
// = Google Cloud Storage Extension Check =
// ========================================
if ( ! defined( 'LEADWERK_MIGRATIONCE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONCE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/google-cloud-storage-extension' );
}

// ======================================
// = Google Cloud Storage Extension Key =
// ======================================
if ( ! defined( 'LEADWERK_MIGRATIONCE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONCE_PLUGIN_KEY', 'leadwerk_migrationce_plugin_key' );
}

// ========================================
// = Google Cloud Storage Extension Short =
// ========================================
if ( ! defined( 'LEADWERK_MIGRATIONCE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONCE_PLUGIN_SHORT', 'gcloud-storage' );
}

// ===================================
// = Google Drive Extension Base Dir =
// ===================================
if ( defined( 'LEADWERK_MIGRATIONGE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONGE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONGE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONGE_PLUGIN_BASEDIR', 'leadwerk-migration-gdrive-extension' );
}

// ================================
// = Google Drive Extension Title =
// ================================
if ( ! defined( 'LEADWERK_MIGRATIONGE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONGE_PLUGIN_TITLE', 'Google Drive Extension' );
}

// ================================
// = Google Drive Extension About =
// ================================
if ( ! defined( 'LEADWERK_MIGRATIONGE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONGE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/google-drive-extension.json' );
}

// ================================
// = Google Drive Extension Check =
// ================================
if ( ! defined( 'LEADWERK_MIGRATIONGE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONGE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/google-drive-extension' );
}

// ==============================
// = Google Drive Extension Key =
// ==============================
if ( ! defined( 'LEADWERK_MIGRATIONGE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONGE_PLUGIN_KEY', 'leadwerk_migrationge_plugin_key' );
}

// ================================
// = Google Drive Extension Short =
// ================================
if ( ! defined( 'LEADWERK_MIGRATIONGE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONGE_PLUGIN_SHORT', 'gdrive' );
}

// =====================================
// = Amazon Glacier Extension Base Dir =
// =====================================
if ( defined( 'LEADWERK_MIGRATIONRE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONRE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONRE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONRE_PLUGIN_BASEDIR', 'leadwerk-migration-glacier-extension' );
}

// ==================================
// = Amazon Glacier Extension Title =
// ==================================
if ( ! defined( 'LEADWERK_MIGRATIONRE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONRE_PLUGIN_TITLE', 'Amazon Glacier Extension' );
}

// ==================================
// = Amazon Glacier Extension About =
// ==================================
if ( ! defined( 'LEADWERK_MIGRATIONRE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONRE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/amazon-glacier-extension.json' );
}

// ==================================
// = Amazon Glacier Extension Check =
// ==================================
if ( ! defined( 'LEADWERK_MIGRATIONRE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONRE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/amazon-glacier-extension' );
}

// ================================
// = Amazon Glacier Extension Key =
// ================================
if ( ! defined( 'LEADWERK_MIGRATIONRE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONRE_PLUGIN_KEY', 'leadwerk_migrationre_plugin_key' );
}

// ==================================
// = Amazon Glacier Extension Short =
// ==================================
if ( ! defined( 'LEADWERK_MIGRATIONRE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONRE_PLUGIN_SHORT', 'glacier' );
}

// ===========================
// = Mega Extension Base Dir =
// ===========================
if ( defined( 'LEADWERK_MIGRATIONEE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONEE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONEE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONEE_PLUGIN_BASEDIR', 'leadwerk-migration-mega-extension' );
}

// ========================
// = Mega Extension Title =
// ========================
if ( ! defined( 'LEADWERK_MIGRATIONEE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONEE_PLUGIN_TITLE', 'Mega Extension' );
}

// ========================
// = Mega Extension About =
// ========================
if ( ! defined( 'LEADWERK_MIGRATIONEE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONEE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/mega-extension.json' );
}

// ========================
// = Mega Extension Check =
// ========================
if ( ! defined( 'LEADWERK_MIGRATIONEE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONEE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/mega-extension' );
}

// ======================
// = Mega Extension Key =
// ======================
if ( ! defined( 'LEADWERK_MIGRATIONEE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONEE_PLUGIN_KEY', 'leadwerk_migrationee_plugin_key' );
}

// ========================
// = Mega Extension Short =
// ========================
if ( ! defined( 'LEADWERK_MIGRATIONEE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONEE_PLUGIN_SHORT', 'mega' );
}

// ================================
// = Multisite Extension Base Dir =
// ================================
if ( defined( 'LEADWERK_MIGRATIONME_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONME_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONME_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONME_PLUGIN_BASEDIR', 'leadwerk-migration-multisite-extension' );
}

// =============================
// = Multisite Extension Title =
// =============================
if ( ! defined( 'LEADWERK_MIGRATIONME_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONME_PLUGIN_TITLE', 'Multisite Extension' );
}

// =============================
// = Multisite Extension About =
// =============================
if ( ! defined( 'LEADWERK_MIGRATIONME_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONME_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/multisite-extension.json' );
}

// =============================
// = Multisite Extension Check =
// =============================
if ( ! defined( 'LEADWERK_MIGRATIONME_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONME_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/multisite-extension' );
}

// ===========================
// = Multisite Extension Key =
// ===========================
if ( ! defined( 'LEADWERK_MIGRATIONME_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONME_PLUGIN_KEY', 'leadwerk_migrationme_plugin_key' );
}

// =============================
// = Multisite Extension Short =
// =============================
if ( ! defined( 'LEADWERK_MIGRATIONME_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONME_PLUGIN_SHORT', 'multisite' );
}

// ===============================
// = OneDrive Extension Base Dir =
// ===============================
if ( defined( 'LEADWERK_MIGRATIONOE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONOE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONOE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONOE_PLUGIN_BASEDIR', 'leadwerk-migration-onedrive-extension' );
}

// ============================
// = OneDrive Extension Title =
// ============================
if ( ! defined( 'LEADWERK_MIGRATIONOE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONOE_PLUGIN_TITLE', 'OneDrive Extension' );
}

// ============================
// = OneDrive Extension About =
// ============================
if ( ! defined( 'LEADWERK_MIGRATIONOE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONOE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/onedrive-extension.json' );
}

// ============================
// = OneDrive Extension Check =
// ============================
if ( ! defined( 'LEADWERK_MIGRATIONOE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONOE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/onedrive-extension' );
}

// ==========================
// = OneDrive Extension Key =
// ==========================
if ( ! defined( 'LEADWERK_MIGRATIONOE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONOE_PLUGIN_KEY', 'leadwerk_migrationoe_plugin_key' );
}

// ============================
// = OneDrive Extension Short =
// ============================
if ( ! defined( 'LEADWERK_MIGRATIONOE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONOE_PLUGIN_SHORT', 'onedrive' );
}

// =============================
// = pCloud Extension Base Dir =
// =============================
if ( defined( 'LEADWERK_MIGRATIONPE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONPE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONPE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONPE_PLUGIN_BASEDIR', 'leadwerk-migration-pcloud-extension' );
}

// ==========================
// = pCloud Extension Title =
// ==========================
if ( ! defined( 'LEADWERK_MIGRATIONPE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONPE_PLUGIN_TITLE', 'pCloud Extension' );
}

// ==========================
// = pCloud Extension About =
// ==========================
if ( ! defined( 'LEADWERK_MIGRATIONPE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONPE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/pcloud-extension.json' );
}

// ==========================
// = pCloud Extension Check =
// ==========================
if ( ! defined( 'LEADWERK_MIGRATIONPE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONPE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/pcloud-extension' );
}

// ========================
// = pCloud Extension Key =
// ========================
if ( ! defined( 'LEADWERK_MIGRATIONPE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONPE_PLUGIN_KEY', 'leadwerk_migrationpe_plugin_key' );
}

// ==========================
// = pCloud Extension Short =
// ==========================
if ( ! defined( 'LEADWERK_MIGRATIONPE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONPE_PLUGIN_SHORT', 'pcloud' );
}

// =======================
// = Pro Plugin Base Dir =
// =======================
if ( defined( 'LEADWERK_MIGRATIONKE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONKE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONKE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONKE_PLUGIN_BASEDIR', 'leadwerk-migration-pro' );
}

// ====================
// = Pro Plugin Title =
// ====================
if ( ! defined( 'LEADWERK_MIGRATIONKE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONKE_PLUGIN_TITLE', 'Leadwerk Migration Pro' );
}

// ====================
// = Pro Plugin About =
// ====================
if ( ! defined( 'LEADWERK_MIGRATIONKE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONKE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/leadwerk-migration-pro.json' );
}

// ====================
// = Pro Plugin Check =
// ====================
if ( ! defined( 'LEADWERK_MIGRATIONKE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONKE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/leadwerk-migration-pro' );
}

// ==================
// = Pro Plugin Key =
// ==================
if ( ! defined( 'LEADWERK_MIGRATIONKE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONKE_PLUGIN_KEY', 'leadwerk_migrationke_plugin_key' );
}

// ====================
// = Pro Plugin Short =
// ====================
if ( ! defined( 'LEADWERK_MIGRATIONKE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONKE_PLUGIN_SHORT', 'pro' );
}

// ================================
// = S3 Client Extension Base Dir =
// ================================
if ( defined( 'LEADWERK_MIGRATIONNE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONNE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONNE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONNE_PLUGIN_BASEDIR', 'leadwerk-migration-s3-client-extension' );
}

// =============================
// = S3 Client Extension Title =
// =============================
if ( ! defined( 'LEADWERK_MIGRATIONNE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONNE_PLUGIN_TITLE', 'S3 Client Extension' );
}

// =============================
// = S3 Client Extension About =
// =============================
if ( ! defined( 'LEADWERK_MIGRATIONNE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONNE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/s3-client-extension.json' );
}

// =============================
// = S3 Client Extension Check =
// =============================
if ( ! defined( 'LEADWERK_MIGRATIONNE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONNE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/s3-client-extension' );
}

// ===========================
// = S3 Client Extension Key =
// ===========================
if ( ! defined( 'LEADWERK_MIGRATIONNE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONNE_PLUGIN_KEY', 'leadwerk_migrationne_plugin_key' );
}

// =============================
// = S3 Client Extension Short =
// =============================
if ( ! defined( 'LEADWERK_MIGRATIONNE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONNE_PLUGIN_SHORT', 's3-client' );
}

// ================================
// = Amazon S3 Extension Base Dir =
// ================================
if ( defined( 'LEADWERK_MIGRATIONSE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONSE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONSE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONSE_PLUGIN_BASEDIR', 'leadwerk-migration-s3-extension' );
}

// =============================
// = Amazon S3 Extension Title =
// =============================
if ( ! defined( 'LEADWERK_MIGRATIONSE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONSE_PLUGIN_TITLE', 'Amazon S3 Extension' );
}

// =============================
// = Amazon S3 Extension About =
// =============================
if ( ! defined( 'LEADWERK_MIGRATIONSE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONSE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/amazon-s3-extension.json' );
}

// =============================
// = Amazon S3 Extension Check =
// =============================
if ( ! defined( 'LEADWERK_MIGRATIONSE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONSE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/amazon-s3-extension' );
}

// ===========================
// = Amazon S3 Extension Key =
// ===========================
if ( ! defined( 'LEADWERK_MIGRATIONSE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONSE_PLUGIN_KEY', 'leadwerk_migrationse_plugin_key' );
}

// =============================
// = Amazon S3 Extension Short =
// =============================
if ( ! defined( 'LEADWERK_MIGRATIONSE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONSE_PLUGIN_SHORT', 's3' );
}

// ================================
// = Unlimited Extension Base Dir =
// ================================
if ( defined( 'LEADWERK_MIGRATIONUE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONUE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONUE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONUE_PLUGIN_BASEDIR', 'leadwerk-migration-unlimited-extension' );
}

// =============================
// = Unlimited Extension Title =
// =============================
if ( ! defined( 'LEADWERK_MIGRATIONUE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONUE_PLUGIN_TITLE', 'Unlimited Extension' );
}

// =============================
// = Unlimited Extension About =
// =============================
if ( ! defined( 'LEADWERK_MIGRATIONUE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONUE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/unlimited-extension.json' );
}

// =============================
// = Unlimited Extension Check =
// =============================
if ( ! defined( 'LEADWERK_MIGRATIONUE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONUE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/unlimited-extension' );
}

// ===========================
// = Unlimited Extension Key =
// ===========================
if ( ! defined( 'LEADWERK_MIGRATIONUE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONUE_PLUGIN_KEY', 'leadwerk_migrationue_plugin_key' );
}

// =============================
// = Unlimited Extension Short =
// =============================
if ( ! defined( 'LEADWERK_MIGRATIONUE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONUE_PLUGIN_SHORT', 'unlimited' );
}

// ==========================
// = URL Extension Base Dir =
// ==========================
if ( defined( 'LEADWERK_MIGRATIONLE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONLE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONLE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONLE_PLUGIN_BASEDIR', 'leadwerk-migration-url-extension' );
}

// =======================
// = URL Extension Title =
// =======================
if ( ! defined( 'LEADWERK_MIGRATIONLE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONLE_PLUGIN_TITLE', 'URL Extension' );
}

// =======================
// = URL Extension About =
// =======================
if ( ! defined( 'LEADWERK_MIGRATIONLE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONLE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/url-extension.json' );
}

// =======================
// = URL Extension Check =
// =======================
if ( ! defined( 'LEADWERK_MIGRATIONLE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONLE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/url-extension' );
}

// =====================
// = URL Extension Key =
// =====================
if ( ! defined( 'LEADWERK_MIGRATIONLE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONLE_PLUGIN_KEY', 'leadwerk_migrationle_plugin_key' );
}

// =======================
// = URL Extension Short =
// =======================
if ( ! defined( 'LEADWERK_MIGRATIONLE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONLE_PLUGIN_SHORT', 'url' );
}

// =============================
// = WebDAV Extension Base Dir =
// =============================
if ( defined( 'LEADWERK_MIGRATIONWE_PLUGIN_BASENAME' ) ) {
	define( 'LEADWERK_MIGRATIONWE_PLUGIN_BASEDIR', dirname( LEADWERK_MIGRATIONWE_PLUGIN_BASENAME ) );
} else {
	define( 'LEADWERK_MIGRATIONWE_PLUGIN_BASEDIR', 'leadwerk-migration-webdav-extension' );
}

// ==========================
// = WebDAV Extension Title =
// ==========================
if ( ! defined( 'LEADWERK_MIGRATIONWE_PLUGIN_TITLE' ) ) {
	define( 'LEADWERK_MIGRATIONWE_PLUGIN_TITLE', 'WebDAV Extension' );
}

// ==========================
// = WebDAV Extension About =
// ==========================
if ( ! defined( 'LEADWERK_MIGRATIONWE_PLUGIN_ABOUT' ) ) {
	define( 'LEADWERK_MIGRATIONWE_PLUGIN_ABOUT', 'https://plugin-updates.wp-migration.com/webdav-extension.json' );
}

// ==========================
// = WebDAV Extension Check =
// ==========================
if ( ! defined( 'LEADWERK_MIGRATIONWE_PLUGIN_CHECK' ) ) {
	define( 'LEADWERK_MIGRATIONWE_PLUGIN_CHECK', 'https://redirect.wp-migration.com/v1/check/webdav-extension' );
}

// ========================
// = WebDAV Extension Key =
// ========================
if ( ! defined( 'LEADWERK_MIGRATIONWE_PLUGIN_KEY' ) ) {
	define( 'LEADWERK_MIGRATIONWE_PLUGIN_KEY', 'leadwerk_migrationwe_plugin_key' );
}

// ==========================
// = WebDAV Extension Short =
// ==========================
if ( ! defined( 'LEADWERK_MIGRATIONWE_PLUGIN_SHORT' ) ) {
	define( 'LEADWERK_MIGRATIONWE_PLUGIN_SHORT', 'webdav' );
}
