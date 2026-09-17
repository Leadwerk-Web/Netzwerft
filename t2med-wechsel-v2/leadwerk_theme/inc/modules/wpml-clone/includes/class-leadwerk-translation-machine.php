<?php
/** DeepL browser-extension automation, adapted from the ACM translation editor. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Translation_Machine {
	const LEGACY_OPTION_KEY  = 'leadwerk_translation_deepl_key';
	const LEGACY_OPTION_TIER = 'leadwerk_translation_deepl_tier';

	public static function init() {
		// Version 4.3 does not send content to a paid DeepL API. Remove any
		// previously stored encrypted API credential during the upgrade.
		if ( get_option( self::LEGACY_OPTION_KEY, false ) ) {
			delete_option( self::LEGACY_OPTION_KEY );
		}
		delete_option( self::LEGACY_OPTION_TIER );
	}

	/** Kept for template compatibility: browser automation is always offered. */
	public static function is_configured() {
		return true;
	}

	public static function mode() {
		return 'browser_extension';
	}

	public static function script_url() {
		if ( defined( 'LEADWERK_WPML_CLONE_URL' ) ) {
			return LEADWERK_WPML_CLONE_URL . 'assets/deepl-extension.js';
		}
		return '';
	}
}
