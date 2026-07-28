<?php
/**
 * Page-only translation API.
 *
 * @package Leadwerk_WPML_Clone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Translation_API {
	private static $current_language = 'de';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
	}

	public static function register_meta() {
		foreach ( array( '_leadwerk_language', '_leadwerk_translation_group', '_leadwerk_translation_status' ) as $key ) {
			register_post_meta(
				'page',
				$key,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static function () {
						return current_user_can( 'edit_pages' );
					},
				)
			);
		}
	}

	public static function active_languages() {
		$languages = get_option( 'leadwerk_translation_languages', array( 'de' ) );
		$languages = is_array( $languages ) ? array_values( array_intersect( array( 'de', 'en' ), $languages ) ) : array( 'de' );
		if ( ! in_array( 'de', $languages, true ) ) {
			array_unshift( $languages, 'de' );
		}
		return array_values( array_unique( $languages ) );
	}

	public static function is_active( $language ) {
		return in_array( sanitize_key( $language ), self::active_languages(), true );
	}

	public static function current_language() {
		return self::$current_language;
	}

	public static function set_current_language( $language ) {
		$language               = sanitize_key( $language );
		self::$current_language = self::is_active( $language ) ? $language : 'de';
	}

	public static function language_of( $post_id ) {
		$language = sanitize_key( (string) get_post_meta( absint( $post_id ), '_leadwerk_language', true ) );
		return in_array( $language, array( 'de', 'en' ), true ) ? $language : 'de';
	}

	public static function group_of( $post_id ) {
		$group = sanitize_key( (string) get_post_meta( absint( $post_id ), '_leadwerk_translation_group', true ) );
		if ( $group ) {
			return $group;
		}
		$source_key = sanitize_key( (string) get_post_meta( absint( $post_id ), 'leadwerk_source_key', true ) );
		return $source_key ? $source_key : 'page-' . absint( $post_id );
	}

	public static function get_counterpart( $post_id, $language = '' ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || 'page' !== get_post_type( $post_id ) ) {
			return 0;
		}
		$source_language = self::language_of( $post_id );
		$language        = $language ? sanitize_key( $language ) : self::current_language();
		if ( $language === $source_language ) {
			return $post_id;
		}
		if ( ! self::is_active( $language ) ) {
			return 0;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_leadwerk_translation_group',
						'value' => self::group_of( $post_id ),
					),
					array(
						'key'   => '_leadwerk_language',
						'value' => $language,
					),
				),
			)
		);
		return $posts ? absint( $posts[0] ) : 0;
	}

	public static function clone_to_english( $post_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type || 'de' !== self::language_of( $post->ID ) ) {
			return new WP_Error( 'leadwerk_invalid_source', 'Nur deutsche Seiten können geklont werden.' );
		}
		if ( ! self::is_active( 'en' ) ) {
			return new WP_Error( 'leadwerk_en_inactive', 'Englisch muss zuerst in den Einstellungen aktiviert werden.' );
		}
		$existing = self::get_counterpart( $post->ID, 'en' );
		if ( $existing ) {
			return $existing;
		}
		$clone_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => $post->post_title . ' (EN)',
				'post_name'    => $post->post_name,
				'post_excerpt' => $post->post_excerpt,
				'post_content' => $post->post_content,
				'post_parent'  => self::translated_parent( $post->post_parent ),
			),
			true
		);
		if ( is_wp_error( $clone_id ) ) {
			return $clone_id;
		}
		$group = self::group_of( $post->ID );
		update_post_meta( $post->ID, '_leadwerk_language', 'de' );
		update_post_meta( $post->ID, '_leadwerk_translation_group', $group );
		update_post_meta( $clone_id, '_leadwerk_language', 'en' );
		update_post_meta( $clone_id, '_leadwerk_translation_group', $group );
		update_post_meta( $clone_id, '_leadwerk_translation_status', 'not_translated' );
		update_post_meta( $clone_id, 'leadwerk_source_key', get_post_meta( $post->ID, 'leadwerk_source_key', true ) );

		if ( class_exists( 'Leadwerk_Content_Schema' ) && class_exists( 'Leadwerk_Fields_API' ) ) {
			$group_definition = Leadwerk_Content_Schema::get_group_for_post( $post );
			foreach ( array_keys( $group_definition['fields'] ?? array() ) as $field_name ) {
				$value = Leadwerk_Fields_API::get_field( $field_name, $post->ID );
				Leadwerk_Fields_API::update_field( $field_name, self::map_page_references( $value ), $clone_id );
			}
		}
		if ( has_post_thumbnail( $post->ID ) ) {
			set_post_thumbnail( $clone_id, get_post_thumbnail_id( $post->ID ) );
		}
		return $clone_id;
	}

	private static function translated_parent( $parent_id ) {
		if ( ! $parent_id ) {
			return 0;
		}
		$translated = self::get_counterpart( $parent_id, 'en' );
		return $translated ? $translated : 0;
	}

	private static function map_page_references( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( isset( $value['post_id'] ) ) {
			$translated       = self::get_counterpart( absint( $value['post_id'] ), 'en' );
			$value['post_id'] = $translated ? $translated : absint( $value['post_id'] );
			return $value;
		}
		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::map_page_references( $child );
		}
		return $value;
	}
}
