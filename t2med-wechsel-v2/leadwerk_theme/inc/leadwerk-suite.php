<?php
/**
 * Loads the complete Leadwerk toolset from the active theme.
 *
 * The public APIs and option names intentionally stay identical to the former
 * plugins so an existing installation can switch without a data migration.
 *
 * @package Leadwerk_T2med
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LEADWERK_SUITE_VERSION', '3.0.8' );
define( 'LEADWERK_SUITE_PATH', LEADWERK_THEME_DIR . '/inc/modules/' );
define( 'LEADWERK_SUITE_URI', LEADWERK_THEME_URI . '/inc/modules/' );

/** Read the version declared by a Migration release without executing it. */
function leadwerk_suite_migration_version( $root ) {
	$constants = trailingslashit( $root ) . 'constants.php';
	$contents  = is_readable( $constants ) ? file_get_contents( $constants ) : false;
	if ( ! is_string( $contents ) || ! preg_match( "/define\(\s*'LEADWERK_MIGRATION_VERSION'\s*,\s*'([^']+)'\s*\)/", $contents, $match ) ) {
		return '';
	}
	return preg_match( '/\A[0-9]+(?:\.[0-9]+){2,3}\z/D', $match[1] ) ? $match[1] : '';
}

/** Calculate the canonical signed Migration file-tree hash. */
function leadwerk_suite_migration_tree_hash( $root ) {
	$root = realpath( $root );
	if ( false === $root || ! is_dir( $root ) ) {
		return new WP_Error( 'leadwerk_suite_release_root', 'Migration release root is missing.' );
	}

	$hashes   = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ( $iterator as $file ) {
		if ( $file->isLink() ) {
			return new WP_Error( 'leadwerk_suite_release_symlink', 'Migration releases may not contain symbolic links.' );
		}
		if ( ! $file->isFile() || '.DS_Store' === $file->getFilename() || 'release-manifest.json' === $file->getFilename() ) {
			continue;
		}
		$real     = $file->getRealPath();
		$relative = false !== $real ? str_replace( DIRECTORY_SEPARATOR, '/', substr( $real, strlen( $root ) + 1 ) ) : '';
		$hash     = false !== $real ? hash_file( 'sha256', $real ) : false;
		if ( '' === $relative || ! is_string( $hash ) ) {
			return new WP_Error( 'leadwerk_suite_release_read', 'Migration release could not be verified.' );
		}
		$hashes[ $relative ] = $hash;
	}

	ksort( $hashes, SORT_STRING );
	$context = hash_init( 'sha256' );
	foreach ( $hashes as $path => $hash ) {
		hash_update( $context, $path . "\0" . strtolower( $hash ) . "\n" );
	}
	return hash_final( $context );
}

/** Verify an official publisher-signed Migration directory. */
function leadwerk_suite_verify_migration_release( $root ) {
	$manifest_path = trailingslashit( $root ) . 'release-manifest.json';
	$manifest      = is_readable( $manifest_path ) ? json_decode( (string) file_get_contents( $manifest_path ), true ) : null;
	$version       = leadwerk_suite_migration_version( $root );
	$tree          = leadwerk_suite_migration_tree_hash( $root );
	if ( is_wp_error( $tree ) ) {
		return $tree;
	}
	if ( ! is_array( $manifest ) || '' === $version ) {
		return new WP_Error( 'leadwerk_suite_release_manifest', 'Migration release manifest is missing.' );
	}

	$declared_version = isset( $manifest['version'] ) ? trim( (string) $manifest['version'] ) : '';
	$declared_tree    = isset( $manifest['tree_sha256'] ) ? strtolower( trim( (string) $manifest['tree_sha256'] ) ) : '';
	$signature        = isset( $manifest['signature'] ) ? base64_decode( (string) $manifest['signature'], true ) : false;
	$public_key       = base64_decode( 'H5OBWQBL7hNIvWLvxnHGYasxLsJ+ZQSh+ut2CKsXGW4=', true );
	$message          = "leadwerk-migration-release-v1\n" . $version . "\n" . $declared_tree;
	if (
		! hash_equals( $version, $declared_version ) ||
		! preg_match( '/\A[a-f0-9]{64}\z/D', $declared_tree ) ||
		! hash_equals( $declared_tree, strtolower( $tree ) ) ||
		! is_string( $signature ) ||
		! is_string( $public_key ) ||
		! function_exists( 'sodium_crypto_sign_verify_detached' ) ||
		! sodium_crypto_sign_verify_detached( $signature, $message, $public_key )
	) {
		return new WP_Error( 'leadwerk_suite_release_signature', 'Migration release publisher signature is invalid.' );
	}

	return array(
		'version'     => $version,
		'tree_sha256' => $declared_tree,
	);
}

/** Recursively copy a verified release into a private staging directory. */
function leadwerk_suite_copy_directory( $source, $destination ) {
	$source = realpath( $source );
	if ( false === $source || ! is_dir( $source ) || file_exists( $destination ) || ! wp_mkdir_p( $destination ) ) {
		return false;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $iterator as $item ) {
		if ( $item->isLink() ) {
			return false;
		}
		$relative = substr( $item->getPathname(), strlen( $source ) + 1 );
		$target   = $destination . DIRECTORY_SEPARATOR . $relative;
		if ( $item->isDir() ) {
			if ( ! wp_mkdir_p( $target ) ) {
				return false;
			}
		} elseif ( ! copy( $item->getPathname(), $target ) ) {
			return false;
		}
	}
	return true;
}

/** Delete only an explicitly resolved Leadwerk staging/candidate directory. */
function leadwerk_suite_delete_directory( $path, $allowed_parent ) {
	$path_real   = realpath( $path );
	$parent_real = realpath( $allowed_parent );
	if ( false === $path_real || false === $parent_real || ! is_dir( $path_real ) ) {
		return false;
	}
	$prefix = trailingslashit( $parent_real );
	if ( 0 !== strpos( trailingslashit( $path_real ), $prefix ) || $path_real === $parent_real ) {
		return false;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path_real, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		if ( $item->isLink() || $item->isFile() ) {
			if ( ! unlink( $item->getPathname() ) ) {
				return false;
			}
		} elseif ( ! rmdir( $item->getPathname() ) ) {
			return false;
		}
	}
	return rmdir( $path_real );
}

/**
 * Promote a newer signed Hub package from the plugin staging location into the
 * embedded module. Hub may keep using its existing signed plugin channel, but
 * the site never keeps or activates a separate Migration plugin.
 */
function leadwerk_suite_promote_migration_candidate() {
	if ( class_exists( 'Leadwerk_Migration_Main_Controller', false ) ) {
		return;
	}

	$candidate = trailingslashit( WP_PLUGIN_DIR ) . 'leadwerk-migration';
	$current   = LEADWERK_SUITE_PATH . 'migration';
	if ( ! is_dir( $candidate ) || ! is_dir( $current ) ) {
		return;
	}
	$candidate_version = leadwerk_suite_migration_version( $candidate );
	$current_version   = leadwerk_suite_migration_version( $current );
	if ( '' === $candidate_version || '' === $current_version || version_compare( $candidate_version, $current_version, '<=' ) ) {
		return;
	}
	$verified = leadwerk_suite_verify_migration_release( $candidate );
	if ( is_wp_error( $verified ) ) {
		update_option( 'leadwerk_suite_update_error', $verified->get_error_message(), false );
		return;
	}

	$module_parent = dirname( $current );
	$token         = function_exists( 'wp_generate_password' ) ? wp_generate_password( 12, false, false ) : substr( hash( 'sha256', microtime( true ) ), 0, 12 );
	$staging       = $module_parent . '/.migration-update-' . sanitize_file_name( strtolower( $token ) );
	$previous      = $module_parent . '/.migration-previous-' . sanitize_file_name( strtolower( $token ) );
	if ( ! leadwerk_suite_copy_directory( $candidate, $staging ) ) {
		leadwerk_suite_delete_directory( $staging, $module_parent );
		update_option( 'leadwerk_suite_update_error', 'The signed Migration update could not be staged in the active theme.', false );
		return;
	}
	$staged = leadwerk_suite_verify_migration_release( $staging );
	if ( is_wp_error( $staged ) || ! rename( $current, $previous ) ) {
		leadwerk_suite_delete_directory( $staging, $module_parent );
		update_option( 'leadwerk_suite_update_error', 'The staged Migration update could not be verified or activated.', false );
		return;
	}
	if ( ! rename( $staging, $current ) ) {
		rename( $previous, $current );
		leadwerk_suite_delete_directory( $staging, $module_parent );
		update_option( 'leadwerk_suite_update_error', 'The embedded Migration update was rolled back safely.', false );
		return;
	}

	leadwerk_suite_delete_directory( $previous, $module_parent );
	leadwerk_suite_delete_directory( $candidate, WP_PLUGIN_DIR );
	delete_option( 'leadwerk_suite_update_error' );
	update_option( 'leadwerk_suite_updated_migration', $candidate_version, false );
}

/** Load the native Fields API and editor. */
function leadwerk_suite_load_fields() {
	if ( class_exists( 'Leadwerk_Fields_API', false ) ) {
		return;
	}
	define( 'LEADWERK_FIELDS_VERSION', '2.0.2' );
	define( 'LEADWERK_FIELDS_PATH', LEADWERK_SUITE_PATH . 'fields/' );
	define( 'LEADWERK_FIELDS_URL', LEADWERK_SUITE_URI . 'fields/' );
	require_once LEADWERK_FIELDS_PATH . 'includes/class-leadwerk-content-schema.php';
	require_once LEADWERK_FIELDS_PATH . 'includes/class-leadwerk-fields-api.php';
	require_once LEADWERK_FIELDS_PATH . 'includes/leadwerk-fields-functions.php';
	require_once LEADWERK_FIELDS_PATH . 'includes/class-leadwerk-fields-metabox.php';
	Leadwerk_Fields_API::init();
	Leadwerk_Fields_Metabox::init();
	define( 'LEADWERK_SUITE_EMBEDDED_FIELDS', true );
}

/** Load the DE-first translation clone module before the importer. */
function leadwerk_suite_load_translation() {
	if ( class_exists( 'Leadwerk_Translation_API', false ) ) {
		return;
	}
	define( 'LEADWERK_WPML_CLONE_VERSION', '4.3.3' );
	define( 'LEADWERK_WPML_CLONE_PATH', LEADWERK_SUITE_PATH . 'wpml-clone/' );
	define( 'LEADWERK_WPML_CLONE_URL', LEADWERK_SUITE_URI . 'wpml-clone/' );
	require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-api.php';
	require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-migrator.php';
	require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-html-segments.php';
	require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-machine.php';
	require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-shared-translations.php';
	require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-media.php';
	require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-seo.php';
	require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-tools.php';
	require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-sync.php';
	require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-router.php';
	require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-language-switcher.php';
	require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-sitemap.php';
	require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-admin.php';
	Leadwerk_Translation_API::init();
	Leadwerk_Translation_Migrator::maybe_upgrade();
	Leadwerk_Translation_Machine::init();
	Leadwerk_Shared_Translations::init();
	Leadwerk_Translation_Media::init();
	Leadwerk_Translation_SEO::init();
	Leadwerk_Translation_Router::init();
	Leadwerk_Language_Switcher::init();
	Leadwerk_Translation_Sitemap::init();
	Leadwerk_Translation_Admin::init();
	define( 'LEADWERK_SUITE_EMBEDDED_TRANSLATION', true );
}

/** Load the T2med importer and its AJAX/admin integration. */
function leadwerk_suite_load_importer() {
	if ( class_exists( 'Leadwerk_Importer', false ) ) {
		return;
	}
	define( 'LEADWERK_IMPORTER_VERSION', '3.1.5' );
	define( 'LEADWERK_IMPORTER_PATH', LEADWERK_SUITE_PATH . 'importer/' );
	define( 'LEADWERK_IMPORTER_URL', LEADWERK_SUITE_URI . 'importer/' );
	require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-t2med-seed.php';
	require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-media-importer.php';
	require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-import-preflight.php';
	require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-wpforms-setup.php';
	require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-importer.php';
	require_once LEADWERK_THEME_DIR . '/inc/leadwerk-importer-module.php';
	define( 'LEADWERK_SUITE_EMBEDDED_IMPORTER', true );
}

/** Load the signed Migration release without registering a standalone plugin. */
function leadwerk_suite_load_migration() {
	if ( class_exists( 'Leadwerk_Migration_Main_Controller', false ) ) {
		return;
	}
	leadwerk_suite_promote_migration_candidate();
	define( 'LEADWERK_MIGRATION_EMBEDDED', true );
	define( 'LEADWERK_MIGRATION_PLUGIN_BASENAME', 'leadwerk-migration/leadwerk-migration.php' );
	define( 'LEADWERK_MIGRATION_PATH', LEADWERK_SUITE_PATH . 'migration' );
	define( 'LEADWERK_MIGRATION_URL', LEADWERK_SUITE_URI . 'migration' );
	define( 'LEADWERK_MIGRATION_STORAGE_URL', '' );
	require_once LEADWERK_MIGRATION_PATH . '/functions.php';
	require_once LEADWERK_MIGRATION_PATH . '/constants.php';
	require_once LEADWERK_MIGRATION_PATH . '/deprecated.php';
	require_once LEADWERK_MIGRATION_PATH . '/exceptions.php';
	require_once LEADWERK_MIGRATION_PATH . '/loader.php';
	$GLOBALS['leadwerk_theme_migration_controller'] = new Leadwerk_Migration_Main_Controller();

	// Theme functions load after plugins_loaded; run these three registration
	// callbacks now instead of leaving them attached to an elapsed hook.
	if ( did_action( 'plugins_loaded' ) ) {
		$GLOBALS['leadwerk_theme_migration_controller']->leadwerk_migration_loaded();
		$GLOBALS['leadwerk_theme_migration_controller']->leadwerk_migration_commands();
		$GLOBALS['leadwerk_theme_migration_controller']->leadwerk_migration_buttons();
	}
	define( 'LEADWERK_SUITE_EMBEDDED_MIGRATION', true );
}

leadwerk_suite_load_fields();
leadwerk_suite_load_translation();
leadwerk_suite_load_importer();
leadwerk_suite_load_migration();

if ( ! function_exists( 'leadwerk_translation_get_counterpart' ) ) {
	function leadwerk_translation_get_counterpart( $post_id, $language = '' ) {
		return Leadwerk_Translation_API::get_counterpart( $post_id, $language );
	}
}

if ( ! function_exists( 'leadwerk_translation_current_language' ) ) {
	function leadwerk_translation_current_language() {
		return Leadwerk_Translation_API::current_language();
	}
}

/** Initialize activation-only defaults after all old plugins are deactivated. */
function leadwerk_suite_activate_embedded_modules() {
	$embedded = defined( 'LEADWERK_SUITE_EMBEDDED_FIELDS' ) && defined( 'LEADWERK_SUITE_EMBEDDED_TRANSLATION' ) && defined( 'LEADWERK_SUITE_EMBEDDED_IMPORTER' ) && defined( 'LEADWERK_SUITE_EMBEDDED_MIGRATION' );
	if ( ! $embedded || LEADWERK_SUITE_VERSION === get_option( 'leadwerk_suite_version', '' ) ) {
		return;
	}
	if ( false === get_option( 'leadwerk_translation_languages', false ) ) {
		update_option( 'leadwerk_translation_languages', array( 'de' ), false );
	}
	update_option( 'leadwerk_translation_default', 'de', false );
	update_option( 'leadwerk_wpml_clone_version', LEADWERK_WPML_CLONE_VERSION, false );
	if ( isset( $GLOBALS['leadwerk_theme_migration_controller'] ) ) {
		$GLOBALS['leadwerk_theme_migration_controller']->activation_hook();
	}
	update_option( 'leadwerk_suite_version', LEADWERK_SUITE_VERSION, false );
}
add_action( 'after_setup_theme', 'leadwerk_suite_activate_embedded_modules', 100 );

/** Keep every former plugin screen under the single Leadwerk Optionen menu. */
function leadwerk_suite_admin_menu() {
	remove_menu_page( 'leadwerk_migration_dashboard' );
	remove_submenu_page( 'tools.php', 'leadwerk-t2med-import' );
	remove_submenu_page( 'options-general.php', 'leadwerk-languages' );

	add_submenu_page( 'leadwerk-options', 'T2med Import', 'T2med Import', 'manage_options', 'leadwerk-t2med-import', 'leadwerk_importer_admin_page' );
	add_submenu_page( 'leadwerk-options', 'Translation Dashboard', 'Übersetzungen', 'edit_pages', 'leadwerk-translations', array( 'Leadwerk_Translation_Admin', 'dashboard_page' ) );
	add_submenu_page( 'leadwerk-options', 'Leadwerk Sprachen', 'Sprachen', 'manage_options', 'leadwerk-languages', array( 'Leadwerk_Translation_Admin', 'settings_page' ) );
	add_submenu_page( 'leadwerk-options', 'Übersetzungsdiagnose', 'Übersetzung: Diagnose', 'manage_options', 'leadwerk-translation-tools', array( 'Leadwerk_Translation_Admin', 'tools_page' ) );
	if ( class_exists( 'Leadwerk_Migration_Main_Controller' ) ) {
		add_submenu_page( 'leadwerk-options', __( 'Migration Overview', 'leadwerk-migration' ), __( 'Migration: Overview', 'leadwerk-migration' ), 'manage_options', 'leadwerk_migration_dashboard', 'Leadwerk_Migration_Dashboard_Controller::index' );
		add_submenu_page( 'leadwerk-options', __( 'Migration Export', 'leadwerk-migration' ), __( 'Migration: Export', 'leadwerk-migration' ), 'manage_options', 'leadwerk_migration_export', 'Leadwerk_Migration_Export_Controller::index' );
		add_submenu_page( 'leadwerk-options', __( 'Migration Import', 'leadwerk-migration' ), __( 'Migration: Import', 'leadwerk-migration' ), 'leadwerk_migration_import_site', 'leadwerk_migration_import', 'Leadwerk_Migration_Import_Controller::index' );
		add_submenu_page( 'leadwerk-options', __( 'Migration Backups', 'leadwerk-migration' ), __( 'Migration: Backups', 'leadwerk-migration' ), 'leadwerk_migration_import_site', 'leadwerk_migration_backups', 'Leadwerk_Migration_Backups_Controller::index' );
		add_submenu_page( 'leadwerk-options', __( 'Migration Automation', 'leadwerk-migration' ), __( 'Migration: Automation', 'leadwerk-migration' ), 'manage_options', 'leadwerk_migration_schedules', 'Leadwerk_Migration_Schedules_Controller::index' );
		add_submenu_page( 'leadwerk-options', __( 'Migration Site Sync', 'leadwerk-migration' ), __( 'Migration: Site Sync', 'leadwerk-migration' ), 'manage_options', 'leadwerk_migration_sync', 'Leadwerk_Migration_Sync_Controller::index' );
	}
}
add_action( 'admin_menu', 'leadwerk_suite_admin_menu', 999 );

/** Bridge Migration's legacy page-hook checks to the new parent menu. */
function leadwerk_suite_migration_admin_assets() {
	if ( empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$controller = isset( $GLOBALS['leadwerk_theme_migration_controller'] ) ? $GLOBALS['leadwerk_theme_migration_controller'] : null;
	if ( ! $controller && isset( $GLOBALS['main_controller'] ) && $GLOBALS['main_controller'] instanceof Leadwerk_Migration_Main_Controller ) {
		$controller = $GLOBALS['main_controller'];
	}
	if ( ! $controller ) {
		return;
	}
	$page       = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	switch ( $page ) {
		case 'leadwerk_migration_dashboard':
			Leadwerk_Migration_Dashboard_Controller::enqueue_assets( 'toplevel_page_leadwerk_migration_dashboard' );
			break;
		case 'leadwerk_migration_export':
			$controller->enqueue_export_scripts_and_styles( 'leadwerk-migration_page_leadwerk_migration_export' );
			break;
		case 'leadwerk_migration_import':
			$controller->enqueue_import_scripts_and_styles( 'leadwerk-migration_page_leadwerk_migration_import' );
			break;
		case 'leadwerk_migration_backups':
			$controller->enqueue_backups_scripts_and_styles( 'leadwerk-migration_page_leadwerk_migration_backups' );
			break;
		case 'leadwerk_migration_schedules':
			Leadwerk_Migration_Dashboard_Controller::enqueue_assets( 'leadwerk-migration_page_leadwerk_migration_schedules' );
			break;
		case 'leadwerk_migration_sync':
			Leadwerk_Migration_Sync_Controller::enqueue_assets( 'leadwerk-migration_page_leadwerk_migration_sync' );
			break;
	}
}
add_action( 'admin_enqueue_scripts', 'leadwerk_suite_migration_admin_assets', 100 );

/** Surface a failed signed-module promotion without exposing filesystem paths. */
function leadwerk_suite_update_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$error = get_option( 'leadwerk_suite_update_error', '' );
	if ( is_string( $error ) && '' !== $error ) {
		echo '<div class="notice notice-error"><p><strong>Leadwerk Suite:</strong> ' . esc_html( $error ) . '</p></div>';
	}
}
add_action( 'admin_notices', 'leadwerk_suite_update_notice' );
