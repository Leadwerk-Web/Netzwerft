<?php
/** Shared media with language-specific accessible text. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Translation_Media {
	const ALT     = '_leadwerk_attachment_alt_en';
	const TITLE   = '_leadwerk_attachment_title_en';
	const CAPTION = '_leadwerk_attachment_caption_en';

	public static function init() {
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'attributes' ), 20, 2 );
		add_filter( 'wp_get_attachment_caption', array( __CLASS__, 'caption' ), 20, 2 );
		add_filter( 'the_title', array( __CLASS__, 'title' ), 20, 2 );
	}

	public static function section( $source_id ) {
		$segments = array();
		foreach ( self::attachment_ids( $source_id ) as $attachment_id ) {
			$title   = (string) get_the_title( $attachment_id );
			$caption = (string) wp_get_attachment_caption( $attachment_id );
			$alt     = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			$label   = $title ?: wp_basename( (string) get_attached_file( $attachment_id ) );
			foreach ( array(
				'alt' => array( 'label' => $label . ' — Alt text', 'source' => $alt, 'meta' => self::ALT ),
				'title' => array( 'label' => $label . ' — Title', 'source' => $title, 'meta' => self::TITLE ),
				'caption' => array( 'label' => $label . ' — Caption', 'source' => $caption, 'meta' => self::CAPTION ),
			) as $kind => $data ) {
				if ( '' === trim( $data['source'] ) && 'alt' !== $kind ) {
					continue;
				}
				$value       = (string) get_post_meta( $attachment_id, $data['meta'], true );
				$source_hash = hash( 'sha256', $data['source'] );
				$stored_hash = (string) get_post_meta( $attachment_id, self::hash_key( $kind ), true );
				$needs_review = '' !== trim( $value ) && '' !== $stored_hash && ! hash_equals( $stored_hash, $source_hash );
				$segments[] = array(
					'id' => $attachment_id . '_' . $kind,
					'attachment_id' => $attachment_id,
					'kind' => $kind,
					'label' => $data['label'],
					'source' => $data['source'],
					'translation' => $value,
					'type' => 'text',
					'source_hash' => $source_hash,
					'status' => $needs_review ? 'needs_review' : ( '' === trim( $value ) ? 'not_translated' : 'translated' ),
				);
			}
		}
		return $segments ? array( 'key' => 'media', 'label' => 'Bilder: Alt-Texte und Metadaten', 'segments' => $segments ) : array();
	}

	public static function save( $source_id, $submitted ) {
		$submitted = is_array( $submitted ) ? $submitted : array();
		$allowed   = array_flip( self::attachment_ids( $source_id ) );
		foreach ( $submitted as $id => $value ) {
			if ( ! preg_match( '/^(\d+)_(alt|title|caption)$/', sanitize_key( (string) $id ), $match ) ) {
				continue;
			}
			$attachment_id = absint( $match[1] );
			if ( ! isset( $allowed[ $attachment_id ] ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
				continue;
			}
			$meta = array( 'alt' => self::ALT, 'title' => self::TITLE, 'caption' => self::CAPTION )[ $match[2] ];
			update_post_meta( $attachment_id, $meta, Leadwerk_Translation_Text::sanitize( wp_unslash( $value ), 'text' ) );
			$source = 'alt' === $match[2] ? (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) : ( 'title' === $match[2] ? (string) get_post_field( 'post_title', $attachment_id ) : (string) get_post_field( 'post_excerpt', $attachment_id ) );
			update_post_meta( $attachment_id, self::hash_key( $match[2] ), hash( 'sha256', $source ) );
		}
	}

	private static function hash_key( $kind ) {
		return '_leadwerk_attachment_' . sanitize_key( (string) $kind ) . '_en_source_hash';
	}

	public static function attributes( $attributes, $attachment ) {
		if ( is_admin() || 'en' !== Leadwerk_Translation_API::current_language() || ! $attachment instanceof WP_Post ) {
			return $attributes;
		}
		$alt = (string) get_post_meta( $attachment->ID, self::ALT, true );
		if ( '' !== trim( $alt ) ) {
			$attributes['alt'] = $alt;
		}
		$title = (string) get_post_meta( $attachment->ID, self::TITLE, true );
		if ( '' !== trim( $title ) ) {
			$attributes['title'] = $title;
		}
		return $attributes;
	}

	public static function caption( $caption, $post_id ) {
		if ( is_admin() || 'en' !== Leadwerk_Translation_API::current_language() ) {
			return $caption;
		}
		$translated = (string) get_post_meta( absint( $post_id ), self::CAPTION, true );
		return '' !== trim( $translated ) ? $translated : $caption;
	}

	public static function title( $title, $post_id ) {
		if ( is_admin() || 'en' !== Leadwerk_Translation_API::current_language() || 'attachment' !== get_post_type( $post_id ) ) {
			return $title;
		}
		$translated = (string) get_post_meta( absint( $post_id ), self::TITLE, true );
		return '' !== trim( $translated ) ? $translated : $title;
	}

	private static function attachment_ids( $source_id ) {
		$ids = array();
		$featured = get_post_thumbnail_id( $source_id );
		if ( $featured ) {
			$ids[] = absint( $featured );
		}
		if ( class_exists( 'Leadwerk_Content_Schema' ) ) {
			$post  = get_post( $source_id );
			$group = $post ? Leadwerk_Content_Schema::get_group_for_post( $post ) : null;
			if ( is_array( $group ) && ! empty( $group['fields'] ) ) {
				foreach ( $group['fields'] as $name => $definition ) {
					$value = class_exists( 'Leadwerk_Fields_API' ) ? Leadwerk_Fields_API::get_field( $name, $source_id ) : get_post_meta( $source_id, $name, true );
					self::collect_ids( $definition, $value, $ids );
				}
			}
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ), static fn( $id ) => 'attachment' === get_post_type( $id ) ) ) );
	}

	private static function collect_ids( $definition, $value, &$ids ) {
		$type = sanitize_key( (string) ( $definition['type'] ?? '' ) );
		if ( 'image' === $type ) {
			$ids[] = absint( is_array( $value ) ? ( $value['id'] ?? 0 ) : $value );
			return;
		}
		if ( 'repeater' === $type ) {
			foreach ( is_array( $value ) ? $value : array() as $row ) {
				foreach ( (array) ( $definition['sub_fields'] ?? array() ) as $key => $sub_definition ) {
					self::collect_ids( $sub_definition, is_array( $row ) ? ( $row[ $key ] ?? null ) : null, $ids );
				}
			}
		}
	}
}
