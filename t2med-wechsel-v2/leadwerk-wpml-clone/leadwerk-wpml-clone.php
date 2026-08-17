<?php
/**
 * Plugin Name: Leadwerk WPML Clone
 * Description: DE-first translation cloning for structured Leadwerk pages.
 * Version: 3.0.0
 * Author: Leadwerk
 * Text Domain: leadwerk-wpml-clone
 * Requires at least: 6.9
 * Requires PHP: 8.1
 *
 * @package Leadwerk_WPML_Clone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LEADWERK_WPML_CLONE_VERSION', '3.0.0' );
define( 'LEADWERK_WPML_CLONE_PATH', plugin_dir_path( __FILE__ ) );

require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-api.php';
require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-router.php';
require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-admin.php';

register_activation_hook(
	__FILE__,
	static function () {
		if ( false === get_option( 'leadwerk_translation_languages', false ) ) {
			update_option( 'leadwerk_translation_languages', array( 'de' ), false );
		}
		update_option( 'leadwerk_translation_default', 'de', false );
		update_option( 'leadwerk_wpml_clone_version', LEADWERK_WPML_CLONE_VERSION, false );
		flush_rewrite_rules( false );
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( false === get_option( 'leadwerk_translation_languages', false ) ) {
			update_option( 'leadwerk_translation_languages', array( 'de' ), false );
		}
		Leadwerk_Translation_API::init();
		Leadwerk_Translation_Router::init();
		Leadwerk_Translation_Admin::init();
	},
	20
);

add_action(
	'admin_notices',
	static function () {
		if ( current_user_can( 'manage_options' ) && ( defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'pll_languages_list' ) ) ) {
			echo '<div class="notice notice-error"><p><strong>Leadwerk WPML Clone:</strong> Offizielles WPML oder Polylang ist aktiv. Bitte nur eine Routing-/Übersetzungslösung verwenden.</p></div>';
		}
	}
);

function leadwerk_translation_get_counterpart( $post_id, $language = '' ) {
	return Leadwerk_Translation_API::get_counterpart( $post_id, $language );
}

function leadwerk_translation_current_language() {
	return Leadwerk_Translation_API::current_language();
}
