<?php
/** Translation identity, pairing, memory and public URL API. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Translation_API {
	const META_LANG         = '_leadwerk_language';
	const META_GROUP        = '_leadwerk_translation_group';
	const META_STATUS       = '_leadwerk_translation_status';
	const META_BUNDLE       = '_leadwerk_translation_bundle';
	const META_SOURCE_HASH  = '_leadwerk_translation_source_hash';
	const META_UPDATED_AT   = '_leadwerk_translation_updated_at';
	const META_PUBLIC_SLUG  = '_leadwerk_translation_public_slug';
	const META_TITLE_HASH   = '_leadwerk_translation_title_source_hash';
	const OPTION_LANGUAGES  = 'leadwerk_translation_languages';
	const OPTION_DEFAULT    = 'leadwerk_translation_default';
	const OPTION_MEMORY     = 'leadwerk_translation_memory_v1';
	const OPTION_STRINGS_EN = 'leadwerk_translation_options_bundle_en';

	private static $current_language = 'de';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 5 );
		add_action( 'init', array( __CLASS__, 'ensure_front_page_identity' ), 30 );
		if ( class_exists( 'Leadwerk_Content_Schema' ) ) {
			foreach ( array_keys( Leadwerk_Content_Schema::get_options_fields() ) as $option_name ) {
				add_filter( 'pre_option_leadwerk_opt_' . sanitize_key( $option_name ), array( __CLASS__, 'filter_localized_option' ), 10, 3 );
			}
		}
	}

	public static function register_meta() {
		foreach ( array( self::META_LANG, self::META_GROUP, self::META_STATUS, self::META_SOURCE_HASH, self::META_UPDATED_AT, self::META_PUBLIC_SLUG, self::META_TITLE_HASH ) as $key ) {
			register_post_meta(
				'page',
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'revisions_enabled' => true,
					'auth_callback'     => static function () { return current_user_can( 'edit_pages' ); },
				)
			);
		}
		register_post_meta(
			'page',
			self::META_BUNDLE,
			array(
				'type'              => 'object',
				'single'            => true,
				'show_in_rest'      => false,
				'revisions_enabled' => true,
				'auth_callback'     => static function () { return current_user_can( 'edit_pages' ); },
			)
		);
	}

	public static function languages() {
		return array(
			'de' => array( 'label' => 'Deutsch', 'locale' => 'de_DE', 'prefix' => '' ),
			'en' => array( 'label' => 'English', 'locale' => 'en_US', 'prefix' => 'en' ),
		);
	}

	public static function active_languages() {
		$languages = get_option( self::OPTION_LANGUAGES, array( 'de' ) );
		$languages = is_array( $languages ) ? array_values( array_intersect( array( 'de', 'en' ), array_map( 'sanitize_key', $languages ) ) ) : array( 'de' );
		if ( ! in_array( 'de', $languages, true ) ) {
			array_unshift( $languages, 'de' );
		}
		return array_values( array_unique( $languages ) );
	}

	public static function is_active( $language ) {
		return in_array( sanitize_key( (string) $language ), self::active_languages(), true );
	}

	public static function default_language() { return 'de'; }
	public static function current_language() { return self::$current_language; }

	public static function set_current_language( $language ) {
		$language               = sanitize_key( (string) $language );
		self::$current_language = self::is_active( $language ) ? $language : 'de';
	}

	public static function locale_for( $language ) {
		return 'en' === sanitize_key( (string) $language ) ? 'en_US' : 'de_DE';
	}

	/** Legacy pages without metadata are German by design. */
	public static function language_of( $post_id ) {
		$language = sanitize_key( (string) get_post_meta( absint( $post_id ), self::META_LANG, true ) );
		return in_array( $language, array( 'de', 'en' ), true ) ? $language : 'de';
	}

	public static function group_of( $post_id ) {
		$post_id = absint( $post_id );
		$group   = sanitize_key( (string) get_post_meta( $post_id, self::META_GROUP, true ) );
		if ( $group ) {
			return $group;
		}
		$source_key = sanitize_key( (string) get_post_meta( $post_id, 'leadwerk_source_key', true ) );
		return $source_key ? $source_key : 'page-' . $post_id;
	}

	public static function ensure_identity( $post_id, $language = '' ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || 'page' !== get_post_type( $post_id ) ) {
			return false;
		}
		$language = $language ? sanitize_key( (string) $language ) : self::language_of( $post_id );
		$language = in_array( $language, array( 'de', 'en' ), true ) ? $language : 'de';
		if ( $language !== (string) get_post_meta( $post_id, self::META_LANG, true ) ) {
			update_post_meta( $post_id, self::META_LANG, $language );
		}
		$group = self::group_of( $post_id );
		if ( $group !== (string) get_post_meta( $post_id, self::META_GROUP, true ) ) {
			update_post_meta( $post_id, self::META_GROUP, $group );
		}
		return true;
	}

	public static function ensure_front_page_identity() {
		$front_id = absint( get_option( 'page_on_front' ) );
		if ( $front_id ) {
			self::ensure_identity( $front_id );
		}
	}

	public static function source_id( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || 'page' !== get_post_type( $post_id ) ) {
			return 0;
		}
		return 'de' === self::language_of( $post_id ) ? $post_id : self::get_counterpart( $post_id, 'de' );
	}

	/** Find a pair and repair safe source-key candidates from earlier versions. */
	public static function get_counterpart( $post_id, $language = '' ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || 'page' !== get_post_type( $post_id ) ) {
			return 0;
		}
		self::ensure_identity( $post_id );
		$source_language = self::language_of( $post_id );
		$language        = $language ? sanitize_key( (string) $language ) : self::current_language();
		if ( $language === $source_language ) {
			return $post_id;
		}
		if ( ! in_array( $language, array( 'de', 'en' ), true ) || ! self::is_active( $language ) ) {
			return 0;
		}
		$query = array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		$query['meta_query'] = array(
			'relation' => 'AND',
			array( 'key' => self::META_GROUP, 'value' => self::group_of( $post_id ) ),
			array( 'key' => self::META_LANG, 'value' => $language ),
		);
		$ids = get_posts( $query );
		if ( $ids ) {
			return absint( $ids[0] );
		}
		$source_key = sanitize_key( (string) get_post_meta( $post_id, 'leadwerk_source_key', true ) );
		if ( ! $source_key ) {
			return 0;
		}
		$query['meta_query'][0] = array( 'key' => 'leadwerk_source_key', 'value' => $source_key );
		$ids = get_posts( $query );
		foreach ( $ids as $candidate_id ) {
			if ( absint( $candidate_id ) !== $post_id ) {
				update_post_meta( $candidate_id, self::META_GROUP, self::group_of( $post_id ) );
				return absint( $candidate_id );
			}
		}
		return 0;
	}

	public static function translations( $post_id, $published_only = false ) {
		$source_id = self::source_id( $post_id ) ?: absint( $post_id );
		$out       = array();
		foreach ( self::active_languages() as $language ) {
			$id = 'de' === $language ? $source_id : self::get_counterpart( $source_id, $language );
			if ( $id && ( ! $published_only || 'publish' === get_post_status( $id ) ) ) {
				$out[ $language ] = $id;
			}
		}
		return $out;
	}

	/** Clone a German page as an unpublished English translation. */
	public static function clone_to_english( $post_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type || 'de' !== self::language_of( $post->ID ) ) {
			return new WP_Error( 'leadwerk_invalid_source', 'Nur deutsche Seiten können geklont werden.' );
		}
		if ( ! self::is_active( 'en' ) ) {
			return new WP_Error( 'leadwerk_en_inactive', 'Englisch muss zuerst in den Einstellungen aktiviert werden.' );
		}
		self::ensure_identity( $post->ID, 'de' );
		$existing = self::get_counterpart( $post->ID, 'en' );
		if ( $existing ) {
			return $existing;
		}
		$parent_id = $post->post_parent ? self::get_counterpart( $post->post_parent, 'en' ) : 0;
		$clone_id  = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'draft',
				'post_title'     => $post->post_title . ' (EN)',
				'post_name'      => wp_unique_post_slug( $post->post_name . '-en', 0, 'draft', 'page', absint( $parent_id ) ),
				'post_parent'    => absint( $parent_id ),
				'menu_order'     => absint( $post->menu_order ),
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);
		if ( is_wp_error( $clone_id ) ) {
			return $clone_id;
		}
		$group = self::group_of( $post->ID );
		update_post_meta( $post->ID, self::META_LANG, 'de' );
		update_post_meta( $post->ID, self::META_GROUP, $group );
		update_post_meta( $clone_id, self::META_LANG, 'en' );
		update_post_meta( $clone_id, self::META_GROUP, $group );
		update_post_meta( $clone_id, self::META_STATUS, 'not_translated' );
		update_post_meta( $clone_id, self::META_TITLE_HASH, hash( 'sha256', (string) $post->post_title ) );
		update_post_meta( $clone_id, 'leadwerk_source_key', get_post_meta( $post->ID, 'leadwerk_source_key', true ) );
		update_post_meta( $clone_id, '_wp_page_template', get_post_meta( $post->ID, '_wp_page_template', true ) ?: 'default' );
		if ( has_post_thumbnail( $post->ID ) ) {
			set_post_thumbnail( $clone_id, get_post_thumbnail_id( $post->ID ) );
		}
		if ( class_exists( 'Leadwerk_Translation_Sync' ) ) {
			Leadwerk_Translation_Sync::seed_translation( $post->ID, $clone_id );
		}
		do_action( 'leadwerk_translation_created', $post->ID, $clone_id, 'en' );
		return $clone_id;
	}

	public static function public_url( $post_id, $allow_unpublished = false ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type || ( ! $allow_unpublished && 'publish' !== $post->post_status ) ) {
			return '';
		}
		if ( 'en' !== self::language_of( $post->ID ) ) {
			return get_permalink( $post->ID );
		}
		$source_front = absint( get_option( 'page_on_front' ) );
		if ( self::get_counterpart( $source_front, 'en' ) === $post->ID ) {
			return home_url( '/en/' );
		}
		$public_slug = sanitize_title( (string) get_post_meta( $post->ID, self::META_PUBLIC_SLUG, true ) );
		$path        = $public_slug ? $public_slug : trim( get_page_uri( $post->ID ), '/' );
		$path        = preg_replace( '#(?:^|/)en(?:/|$)#', '/', (string) $path );
		return home_url( '/en/' . trim( (string) $path, '/' ) . '/' );
	}

	public static function translation_url( $post_id, $language, $fallback = '' ) {
		$target = self::get_counterpart( $post_id, $language );
		$url    = $target ? self::public_url( $target ) : '';
		return $url ? $url : $fallback;
	}

	public static function get_bundle( $post_id ) {
		$bundle = get_post_meta( absint( $post_id ), self::META_BUNDLE, true );
		$bundle = is_array( $bundle ) ? $bundle : array();
		return class_exists( 'Leadwerk_Translation_Text' ) ? Leadwerk_Translation_Text::normalize_bundle( $bundle ) : $bundle;
	}

	public static function update_bundle( $post_id, $bundle ) {
		$bundle = is_array( $bundle ) ? $bundle : array();
		if ( class_exists( 'Leadwerk_Translation_Text' ) ) {
			$bundle = Leadwerk_Translation_Text::normalize_bundle( $bundle );
		}
		update_post_meta( absint( $post_id ), self::META_BUNDLE, $bundle );
		update_post_meta( absint( $post_id ), self::META_UPDATED_AT, current_time( 'mysql', true ) );
	}

	public static function status_of( $post_id ) {
		if ( 'de' === self::language_of( $post_id ) ) {
			return 'source';
		}
		$status = sanitize_key( (string) get_post_meta( absint( $post_id ), self::META_STATUS, true ) );
		return in_array( $status, array( 'not_translated', 'in_progress', 'needs_review', 'translated' ), true ) ? $status : 'not_translated';
	}

	public static function update_status( $post_id, $status ) {
		$status = sanitize_key( (string) $status );
		$status = in_array( $status, array( 'not_translated', 'in_progress', 'needs_review', 'translated' ), true ) ? $status : 'not_translated';
		update_post_meta( absint( $post_id ), self::META_STATUS, $status );
	}

	public static function completeness( $post_id ) {
		$paths = (array) ( self::get_bundle( $post_id )['paths'] ?? array() );
		if ( ! $paths ) {
			return 0;
		}
		$complete = 0;
		foreach ( $paths as $segment ) {
			if ( is_array( $segment ) && '' !== trim( (string) ( $segment['translation'] ?? '' ) ) && empty( $segment['needs_review'] ) ) {
				$complete++;
			}
		}
		return (int) round( ( $complete / count( $paths ) ) * 100 );
	}

	public static function map_page_references( $value, $language = 'en' ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_key_exists( 'post_id', $value ) ) {
			$source_id        = absint( $value['post_id'] );
			$translated       = $source_id ? self::get_counterpart( $source_id, $language ) : 0;
			$value['post_id'] = $translated ? $translated : $source_id;
			return $value;
		}
		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::map_page_references( $child, $language );
		}
		return $value;
	}

	public static function memory_match( $source, $type = 'text' ) {
		$key    = hash( 'sha256', sanitize_key( $type ) . "\0" . self::normalize_memory_source( $source, $type ) );
		$memory = get_option( self::OPTION_MEMORY, array() );
		return is_array( $memory ) && isset( $memory[ $key ]['translation'] ) ? (string) $memory[ $key ]['translation'] : '';
	}

	public static function remember( $source, $translation, $type = 'text', $origin = '' ) {
		if ( class_exists( 'Leadwerk_Translation_Text' ) ) {
			$translation = Leadwerk_Translation_Text::sanitize( $translation, $type );
		}
		if ( '' === trim( (string) $source ) || '' === trim( (string) $translation ) ) {
			return;
		}
		$key    = hash( 'sha256', sanitize_key( $type ) . "\0" . self::normalize_memory_source( $source, $type ) );
		$memory = get_option( self::OPTION_MEMORY, array() );
		$memory = is_array( $memory ) ? $memory : array();
		$memory[ $key ] = array( 'translation' => (string) $translation, 'type' => sanitize_key( $type ), 'origin' => sanitize_text_field( (string) $origin ), 'updated_at' => time() );
		if ( count( $memory ) > 1000 ) {
			uasort( $memory, static fn( $a, $b ) => (int) ( $b['updated_at'] ?? 0 ) <=> (int) ( $a['updated_at'] ?? 0 ) );
			$memory = array_slice( $memory, 0, 1000, true );
		}
		update_option( self::OPTION_MEMORY, $memory, false );
	}

	public static function normalize_memory_source( $source, $type = 'text' ) {
		$source = 'richtext' === $type ? wp_strip_all_tags( (string) $source, true ) : (string) $source;
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $source, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/** Transparently localize Leadwerk options during an English frontend request. */
	public static function filter_localized_option( $pre_option, $option, $default_value ) {
		if ( is_admin() || 'en' !== self::current_language() || ! class_exists( 'Leadwerk_Translation_Sync' ) ) {
			return $pre_option;
		}
		$prefix = 'leadwerk_opt_';
		if ( 0 !== strpos( (string) $option, $prefix ) ) {
			return $pre_option;
		}
		$field_name = sanitize_key( substr( (string) $option, strlen( $prefix ) ) );
		$localized  = Leadwerk_Translation_Sync::localized_option_value( $field_name );
		return null === $localized ? $pre_option : $localized;
	}
}
