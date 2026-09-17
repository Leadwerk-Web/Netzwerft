<?php
/** Canonical normalization for translated text received from browser translators. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Translation_Text {
	/**
	 * Decode HTML entities at the translation boundary.
	 *
	 * Browser translation extensions can return already escaped text. Decode a
	 * small, bounded number of layers so WordPress stores characters, not their
	 * transport representation. Context sanitization immediately follows the
	 * decode, preventing encoded markup from becoming executable HTML.
	 */
	public static function sanitize( $value, $type = 'text' ) {
		$value = self::decode_entities( (string) $value );
		return 'richtext' === sanitize_key( (string) $type ) ? wp_kses_post( $value ) : sanitize_textarea_field( $value );
	}

	public static function decode_entities( $value ) {
		$value = wp_check_invalid_utf8( (string) $value );
		for ( $depth = 0; $depth < 3 && preg_match( '/&(?:#[0-9]{1,7}|#x[0-9a-f]{1,6}|[a-z][a-z0-9]{1,31});/i', $value ); $depth++ ) {
			$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $decoded === $value ) {
				break;
			}
			$value = $decoded;
		}
		return $value;
	}

	public static function normalize_bundle( $bundle ) {
		if ( ! is_array( $bundle ) || ! isset( $bundle['paths'] ) || ! is_array( $bundle['paths'] ) ) {
			return $bundle;
		}
		foreach ( $bundle['paths'] as &$segment ) {
			if ( is_array( $segment ) && array_key_exists( 'translation', $segment ) ) {
				$segment['translation'] = self::sanitize( $segment['translation'], $segment['type'] ?? 'text' );
			}
		}
		unset( $segment );
		return $bundle;
	}

	public static function normalize_text_map( $values ) {
		if ( ! is_array( $values ) ) {
			return $values;
		}
		foreach ( $values as $key => $value ) {
			$values[ $key ] = is_array( $value ) ? self::normalize_text_map( $value ) : self::sanitize( $value, 'text' );
		}
		return $values;
	}
}
