<?php
/** Translate Yoast SEO data together with each page. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Translation_SEO {
	private static $fields = array(
		'_yoast_wpseo_title'    => 'SEO title',
		'_yoast_wpseo_metadesc' => 'Meta description',
		'_yoast_wpseo_focuskw'  => 'Focus keyphrase',
	);

	public static function init() {
		add_filter( 'wpseo_locale', array( __CLASS__, 'locale' ) );
		add_filter( 'wpseo_opengraph_locale', array( __CLASS__, 'locale' ) );
	}

	public static function section( $source_id, $target_id ) {
		$segments = array();
		foreach ( self::$fields as $meta_key => $label ) {
			$source = (string) get_post_meta( $source_id, $meta_key, true );
			if ( '' === trim( $source ) ) {
				continue;
			}
			$value       = (string) get_post_meta( $target_id, $meta_key, true );
			$source_hash = hash( 'sha256', $source );
			$stored_hash = (string) get_post_meta( $target_id, self::hash_key( $meta_key ), true );
			$needs_review = '' !== trim( $value ) && '' !== $stored_hash && ! hash_equals( $stored_hash, $source_hash );
			$segments[] = array(
				'id' => ltrim( $meta_key, '_' ), 'meta_key' => $meta_key, 'label' => $label,
				'source' => $source, 'translation' => $value, 'type' => 'text',
				'source_hash' => $source_hash,
				'status' => $needs_review ? 'needs_review' : ( '' === trim( $value ) ? 'not_translated' : 'translated' ),
			);
		}
		return $segments ? array( 'key' => 'seo', 'label' => 'Yoast SEO', 'segments' => $segments ) : array();
	}

	public static function save( $source_id, $target_id, $submitted ) {
		$submitted = is_array( $submitted ) ? $submitted : array();
		foreach ( self::$fields as $meta_key => $label ) {
			$id = ltrim( $meta_key, '_' );
			if ( array_key_exists( $id, $submitted ) ) {
				update_post_meta( $target_id, $meta_key, Leadwerk_Translation_Text::sanitize( wp_unslash( $submitted[ $id ] ), 'text' ) );
				update_post_meta( $target_id, self::hash_key( $meta_key ), hash( 'sha256', (string) get_post_meta( $source_id, $meta_key, true ) ) );
			}
		}
	}

	private static function hash_key( $meta_key ) {
		return '_leadwerk_seo_source_hash_' . sanitize_key( ltrim( (string) $meta_key, '_' ) );
	}

	public static function locale( $locale ) {
		return 'en' === Leadwerk_Translation_API::current_language() ? 'en_US' : $locale;
	}
}
