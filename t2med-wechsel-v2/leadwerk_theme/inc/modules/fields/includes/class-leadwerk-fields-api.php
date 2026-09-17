<?php
/**
 * Native post-meta/options storage for Leadwerk Fields.
 *
 * @package Leadwerk_Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Fields_API {
	private static $cache = array();

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'admin_notices', array( __CLASS__, 'acf_notice' ) );
	}

	public static function register_meta() {
		foreach ( Leadwerk_Content_Schema::get_all_post_field_names() as $name ) {
			register_post_meta(
				'page',
				$name,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'revisions_enabled' => true,
					'auth_callback'     => static function () {
						return current_user_can( 'edit_pages' );
					},
				)
			);
		}

		register_post_meta(
			'page',
			'leadwerk_source_key',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'revisions_enabled' => true,
				'auth_callback'     => static function () {
					return current_user_can( 'edit_pages' );
				},
			)
		);
	}

	public static function acf_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! defined( 'ACF_VERSION' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>Leadwerk Fields:</strong> ACF ist aktiv. Leadwerk verwendet deshalb ausschließlich die konfliktfreien <code>leadwerk_get_field()</code>- und <code>leadwerk_update_field()</code>-APIs.</p></div>';
	}

	public static function get_field( $name, $post_id = null ) {
		$name = sanitize_key( (string) $name );
		if ( '' === $name ) {
			return null;
		}

		if ( 'option' === $post_id || 'options' === $post_id ) {
			$key = 'leadwerk_opt_' . $name;
			if ( array_key_exists( $key, self::$cache ) ) {
				return self::$cache[ $key ];
			}
			$value               = self::decode( get_option( $key, null ) );
			self::$cache[ $key ] = $value;
			return $value;
		}

		$post_id = null === $post_id ? get_the_ID() : absint( $post_id );
		if ( ! $post_id ) {
			return null;
		}
		return self::decode( get_post_meta( $post_id, $name, true ) );
	}

	public static function update_field( $name, $value, $post_id = null ) {
		$name = sanitize_key( (string) $name );
		if ( '' === $name ) {
			return false;
		}
		$stored = self::encode( $value );

		if ( 'option' === $post_id || 'options' === $post_id ) {
			$key                 = 'leadwerk_opt_' . $name;
			self::$cache[ $key ] = $value;
			return update_option( $key, $stored, false );
		}

		$post_id = null === $post_id ? get_the_ID() : absint( $post_id );
		return $post_id ? update_post_meta( $post_id, $name, wp_slash( $stored ) ) : false;
	}

	private static function encode( $value ) {
		return ( is_array( $value ) || is_object( $value ) )
			? wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			: $value;
	}

	private static function decode( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		$first = substr( $value, 0, 1 );
		if ( '[' === $first || '{' === $first ) {
			$decoded = json_decode( $value, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				return $decoded;
			}
		}
		return ctype_digit( $value ) ? (int) $value : $value;
	}
}
