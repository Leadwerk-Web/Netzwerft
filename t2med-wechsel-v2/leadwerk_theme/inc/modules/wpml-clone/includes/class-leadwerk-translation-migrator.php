<?php
/** Upgrade and compatibility migration for earlier Leadwerk/ACM metadata. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Translation_Migrator {
	const VERSION = '4.3.4';
	const OPTION  = 'leadwerk_translation_db_version';

	public static function maybe_upgrade() {
		$installed = (string) get_option( self::OPTION, '' );
		self::ensure_defaults();
		if ( self::VERSION === $installed ) {
			return;
		}
		if ( '' === $installed || version_compare( $installed, '4.3.2', '<' ) ) {
			update_option( 'leadwerk_translation_auto_switcher', true, false );
		}
		self::migrate_page_identities();
		if ( version_compare( $installed ?: '0', '4.3.4', '<' ) ) {
			self::repair_encoded_translations();
		}
		update_option( self::OPTION, self::VERSION, false );
		update_option( 'leadwerk_wpml_clone_version', self::VERSION, false );
	}

	private static function ensure_defaults() {
		if ( false === get_option( Leadwerk_Translation_API::OPTION_LANGUAGES, false ) ) {
			update_option( Leadwerk_Translation_API::OPTION_LANGUAGES, array( 'de' ), false );
		}
		update_option( Leadwerk_Translation_API::OPTION_DEFAULT, 'de', false );
		if ( null === get_option( 'leadwerk_translation_auto_switcher', null ) ) {
			update_option( 'leadwerk_translation_auto_switcher', true, false );
		}
		if ( false === get_option( 'leadwerk_translation_switcher_style', false ) ) {
			update_option( 'leadwerk_translation_switcher_style', 'compact', false );
		}
		if ( class_exists( 'Leadwerk_Shared_Translations' ) ) {
			$stored   = get_option( 'leadwerk_translation_runtime_strings_en', array() );
			$stored   = is_array( $stored ) ? $stored : array();
			$defaults = array();
			foreach ( Leadwerk_Shared_Translations::definitions() as $key => $definition ) {
				$defaults[ $key ] = (string) $definition['default'];
			}
			$merged = array_merge( $defaults, $stored );
			if ( $merged !== $stored ) {
				update_option( 'leadwerk_translation_runtime_strings_en', $merged, false );
			}
		}
	}

	private static function migrate_page_identities() {
		$ids = get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ), 'posts_per_page' => -1, 'fields' => 'ids' ) );
		foreach ( $ids as $post_id ) {
			$language = sanitize_key( (string) get_post_meta( $post_id, Leadwerk_Translation_API::META_LANG, true ) );
			if ( ! $language ) {
				$language = sanitize_key( (string) get_post_meta( $post_id, 'leadwerk_lang', true ) );
			}
			$language = in_array( $language, array( 'de', 'en' ), true ) ? $language : 'de';
			$group    = sanitize_key( (string) get_post_meta( $post_id, Leadwerk_Translation_API::META_GROUP, true ) );
			if ( ! $group ) {
				$group = sanitize_key( (string) get_post_meta( $post_id, 'leadwerk_trid', true ) );
			}
			if ( $group ) {
				update_post_meta( $post_id, Leadwerk_Translation_API::META_GROUP, $group );
			}
			Leadwerk_Translation_API::ensure_identity( $post_id, $language );
			$legacy_status = sanitize_key( (string) get_post_meta( $post_id, 'leadwerk_translation_status', true ) );
			if ( 'en' === $language && $legacy_status && ! get_post_meta( $post_id, Leadwerk_Translation_API::META_STATUS, true ) ) {
				$map = array( 'complete' => 'translated', 'needs_update' => 'needs_review', 'in_progress' => 'in_progress', 'not_translated' => 'not_translated' );
				Leadwerk_Translation_API::update_status( $post_id, $map[ $legacy_status ] ?? 'not_translated' );
			}
		}
	}

	/** Repair entity-encoded values written by browser translation extensions. */
	private static function repair_encoded_translations() {
		if ( ! class_exists( 'Leadwerk_Translation_Text' ) ) {
			return;
		}

		$runtime = get_option( Leadwerk_Shared_Translations::OPTION, array() );
		if ( is_array( $runtime ) ) {
			$normalized = Leadwerk_Translation_Text::normalize_text_map( $runtime );
			if ( $normalized !== $runtime ) {
				update_option( Leadwerk_Shared_Translations::OPTION, $normalized, false );
			}
		}

		$options_bundle = get_option( Leadwerk_Translation_API::OPTION_STRINGS_EN, array() );
		if ( is_array( $options_bundle ) ) {
			$normalized = Leadwerk_Translation_Text::normalize_bundle( $options_bundle );
			if ( $normalized !== $options_bundle ) {
				update_option( Leadwerk_Translation_API::OPTION_STRINGS_EN, $normalized, false );
			}
		}

		$memory = get_option( Leadwerk_Translation_API::OPTION_MEMORY, array() );
		if ( is_array( $memory ) ) {
			$normalized = $memory;
			foreach ( $normalized as &$entry ) {
				if ( is_array( $entry ) && array_key_exists( 'translation', $entry ) ) {
					$entry['translation'] = Leadwerk_Translation_Text::sanitize( $entry['translation'], $entry['type'] ?? 'text' );
				}
			}
			unset( $entry );
			if ( $normalized !== $memory ) {
				update_option( Leadwerk_Translation_API::OPTION_MEMORY, $normalized, false );
			}
		}

		$english_ids = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => Leadwerk_Translation_API::META_LANG,
				'meta_value'     => 'en',
			)
		);
		foreach ( $english_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post ) {
				$title = sanitize_text_field( Leadwerk_Translation_Text::decode_entities( $post->post_title ) );
				if ( $title !== $post->post_title ) {
					wp_update_post( array( 'ID' => $post_id, 'post_title' => $title ) );
				}
			}
			foreach ( array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw' ) as $meta_key ) {
				$value      = (string) get_post_meta( $post_id, $meta_key, true );
				$normalized = Leadwerk_Translation_Text::sanitize( $value, 'text' );
				if ( $normalized !== $value ) {
					update_post_meta( $post_id, $meta_key, $normalized );
				}
			}
			$bundle     = get_post_meta( $post_id, Leadwerk_Translation_API::META_BUNDLE, true );
			$normalized = Leadwerk_Translation_Text::normalize_bundle( is_array( $bundle ) ? $bundle : array() );
			if ( $normalized !== $bundle ) {
				Leadwerk_Translation_API::update_bundle( $post_id, $normalized );
			}
			$source_id = Leadwerk_Translation_API::source_id( $post_id );
			if ( $source_id && class_exists( 'Leadwerk_Translation_Sync' ) ) {
				Leadwerk_Translation_Sync::refresh_translation( $source_id, $post_id, true );
			}
		}

		$attachment_ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => Leadwerk_Translation_Media::ALT, 'compare' => 'EXISTS' ),
					array( 'key' => Leadwerk_Translation_Media::TITLE, 'compare' => 'EXISTS' ),
					array( 'key' => Leadwerk_Translation_Media::CAPTION, 'compare' => 'EXISTS' ),
				),
			)
		);
		foreach ( $attachment_ids as $attachment_id ) {
			foreach ( array( Leadwerk_Translation_Media::ALT, Leadwerk_Translation_Media::TITLE, Leadwerk_Translation_Media::CAPTION ) as $meta_key ) {
				$value      = (string) get_post_meta( $attachment_id, $meta_key, true );
				$normalized = Leadwerk_Translation_Text::sanitize( $value, 'text' );
				if ( $normalized !== $value ) {
					update_post_meta( $attachment_id, $meta_key, $normalized );
				}
			}
		}
	}
}
