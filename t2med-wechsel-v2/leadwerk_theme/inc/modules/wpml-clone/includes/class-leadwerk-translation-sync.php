<?php
/** Structured page/options translation bundles and non-destructive source sync. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Translation_Sync {
	private static $technical_keys = array(
		'anchor', 'anchor_id', 'brand_logo', 'company_email', 'company_phone', 'field_id',
		'hero_image_focus', 'icon_key', 'notification_email', 'og_image', 'post_id',
		'site_favicon', 'target', 'value', 'video_external_url', 'video_poster',
		'video_youtube_id', 'wpforms_field_map', 'wpforms_form_id',
	);

	/** Seed an EN draft; translations stay empty but media/references/technical values are shared safely. */
	public static function seed_translation( $source_id, $target_id ) {
		return self::refresh_translation( $source_id, $target_id, true );
	}

	/** Refresh source hashes without overwriting editorial EN translations. */
	public static function refresh_translation( $source_id, $target_id, $apply = true ) {
		$context = self::page_context( $source_id );
		if ( ! $context ) {
			return array();
		}
		$bundle = self::reconcile( $context['definitions'], $context['values'], Leadwerk_Translation_API::get_bundle( $target_id ) );
		Leadwerk_Translation_API::update_bundle( $target_id, $bundle );
		$status = self::bundle_status( $bundle );
		$source = get_post( absint( $source_id ) );
		$title_hash = $source instanceof WP_Post ? hash( 'sha256', (string) $source->post_title ) : '';
		$stored_title_hash = (string) get_post_meta( $target_id, Leadwerk_Translation_API::META_TITLE_HASH, true );
		if ( '' === $stored_title_hash && '' !== $title_hash ) {
			update_post_meta( $target_id, Leadwerk_Translation_API::META_TITLE_HASH, $title_hash );
		} elseif ( '' !== $title_hash && ! hash_equals( $stored_title_hash, $title_hash ) && 'not_translated' !== $status ) {
			$status = 'needs_review';
		}
		Leadwerk_Translation_API::update_status( $target_id, $status );
		update_post_meta( $target_id, Leadwerk_Translation_API::META_SOURCE_HASH, self::source_hash( $bundle ) );
		if ( $apply ) {
			self::apply_page_bundle( $target_id, $context, $bundle );
		}
		return $bundle;
	}

	/** Save submitted page segments, rebuild fields, and populate translation memory. */
	public static function save_page_segments( $source_id, $target_id, $submitted ) {
		$context = self::page_context( $source_id );
		if ( ! $context ) {
			return array();
		}
		$bundle    = self::reconcile( $context['definitions'], $context['values'], Leadwerk_Translation_API::get_bundle( $target_id ) );
		$submitted = is_array( $submitted ) ? $submitted : array();
		foreach ( $bundle['paths'] as $path => $segment ) {
			$id = (string) ( $segment['id'] ?? '' );
			if ( ! $id || ! array_key_exists( $id, $submitted ) ) {
				continue;
			}
			$value = wp_unslash( $submitted[ $id ] );
			$value = Leadwerk_Translation_Text::sanitize( $value, $segment['type'] ?? 'text' );
			$bundle['paths'][ $path ]['translation'] = trim( $value );
			$bundle['paths'][ $path ]['needs_review'] = false;
			if ( '' !== trim( $value ) ) {
				Leadwerk_Translation_API::remember( $segment['source'] ?? '', $value, $segment['type'] ?? 'text', 'page:' . absint( $target_id ) . ':' . $path );
			}
		}
		$bundle['updated_at'] = current_time( 'mysql', true );
		Leadwerk_Translation_API::update_bundle( $target_id, $bundle );
		Leadwerk_Translation_API::update_status( $target_id, self::bundle_status( $bundle ) );
		self::apply_page_bundle( $target_id, $context, $bundle );
		return $bundle;
	}

	public static function page_sections( $source_id, $target_id ) {
		$bundle = self::refresh_translation( $source_id, $target_id, false );
		return self::bundle_sections( $bundle );
	}

	public static function can_publish( $bundle ) {
		return 'translated' === self::bundle_status( $bundle );
	}

	public static function bundle_status( $bundle ) {
		$paths = (array) ( $bundle['paths'] ?? array() );
		if ( ! $paths ) {
			return 'translated';
		}
		$filled = 0;
		$review = false;
		foreach ( $paths as $segment ) {
			if ( '' !== trim( (string) ( $segment['translation'] ?? '' ) ) ) {
				$filled++;
			}
			$review = $review || ! empty( $segment['needs_review'] );
		}
		if ( $review ) {
			return 'needs_review';
		}
		if ( 0 === $filled ) {
			return 'not_translated';
		}
		return $filled === count( $paths ) ? 'translated' : 'in_progress';
	}

	/** Return current global-option translation sections. */
	public static function option_sections() {
		$definitions = class_exists( 'Leadwerk_Content_Schema' ) ? Leadwerk_Content_Schema::get_options_fields() : array();
		$values      = self::source_option_values( $definitions );
		$bundle      = get_option( Leadwerk_Translation_API::OPTION_STRINGS_EN, array() );
		$bundle      = self::reconcile( $definitions, $values, is_array( $bundle ) ? $bundle : array() );
		update_option( Leadwerk_Translation_API::OPTION_STRINGS_EN, $bundle, false );
		return self::bundle_sections( $bundle );
	}

	public static function save_option_segments( $submitted ) {
		$definitions = class_exists( 'Leadwerk_Content_Schema' ) ? Leadwerk_Content_Schema::get_options_fields() : array();
		$values      = self::source_option_values( $definitions );
		$bundle      = get_option( Leadwerk_Translation_API::OPTION_STRINGS_EN, array() );
		$bundle      = self::reconcile( $definitions, $values, is_array( $bundle ) ? $bundle : array() );
		$submitted   = is_array( $submitted ) ? $submitted : array();
		foreach ( $bundle['paths'] as $path => $segment ) {
			$id = (string) ( $segment['id'] ?? '' );
			if ( ! $id || ! array_key_exists( $id, $submitted ) ) {
				continue;
			}
			$value = Leadwerk_Translation_Text::sanitize( wp_unslash( $submitted[ $id ] ), $segment['type'] ?? 'text' );
			$bundle['paths'][ $path ]['translation'] = trim( $value );
			$bundle['paths'][ $path ]['needs_review'] = false;
			if ( '' !== trim( $value ) ) {
				Leadwerk_Translation_API::remember( $segment['source'] ?? '', $value, $segment['type'] ?? 'text', 'options:' . $path );
			}
		}
		$bundle['updated_at'] = current_time( 'mysql', true );
		update_option( Leadwerk_Translation_API::OPTION_STRINGS_EN, $bundle, false );
		return $bundle;
	}

	public static function refresh_options_bundle() {
		self::option_sections();
	}

	/** Value returned by pre_option filters during an EN request. */
	public static function localized_option_value( $field_name ) {
		if ( ! class_exists( 'Leadwerk_Content_Schema' ) ) {
			return null;
		}
		$definitions = Leadwerk_Content_Schema::get_options_fields();
		if ( ! isset( $definitions[ $field_name ] ) ) {
			return null;
		}
		$source = self::decode_storage( self::source_option_raw( $field_name ) );
		$bundle = get_option( Leadwerk_Translation_API::OPTION_STRINGS_EN, array() );
		$paths  = is_array( $bundle ) ? (array) ( $bundle['paths'] ?? array() ) : array();
		return self::translated_value( $field_name, $definitions[ $field_name ], $source, array( 'fields', $field_name ), $paths, true );
	}

	private static function page_context( $source_id ) {
		$post = get_post( absint( $source_id ) );
		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
			return null;
		}
		$group = class_exists( 'Leadwerk_Content_Schema' ) ? Leadwerk_Content_Schema::get_group_for_post( $post ) : null;
		if ( is_array( $group ) && ! empty( $group['fields'] ) ) {
			$values = array();
			foreach ( $group['fields'] as $name => $definition ) {
				$values[ $name ] = class_exists( 'Leadwerk_Fields_API' ) ? Leadwerk_Fields_API::get_field( $name, $post->ID ) : get_post_meta( $post->ID, $name, true );
			}
			return array( 'native' => false, 'definitions' => $group['fields'], 'values' => $values, 'label' => $group['label'] ?? 'Content' );
		}
		return array(
			'native'      => true,
			'definitions' => array(
				'post_excerpt' => array( 'label' => 'Excerpt', 'type' => 'textarea' ),
				'post_content' => array( 'label' => 'Content', 'type' => 'editor' ),
			),
			'values'      => array( 'post_excerpt' => $post->post_excerpt, 'post_content' => $post->post_content ),
			'label'       => 'Content',
		);
	}

	private static function apply_page_bundle( $target_id, $context, $bundle ) {
		$translated = array();
		$paths      = (array) ( $bundle['paths'] ?? array() );
		foreach ( $context['definitions'] as $name => $definition ) {
			$translated[ $name ] = self::translated_value( $name, $definition, $context['values'][ $name ] ?? '', array( 'fields', $name ), $paths );
		}
		if ( ! empty( $context['native'] ) ) {
			wp_update_post( array( 'ID' => absint( $target_id ), 'post_excerpt' => $translated['post_excerpt'] ?? '', 'post_content' => $translated['post_content'] ?? '' ) );
			return;
		}
		foreach ( $translated as $name => $value ) {
			if ( class_exists( 'Leadwerk_Fields_API' ) ) {
				Leadwerk_Fields_API::update_field( $name, $value, $target_id );
			} else {
				update_post_meta( $target_id, $name, $value );
			}
		}
	}

	private static function reconcile( $definitions, $values, $bundle ) {
		$existing    = is_array( $bundle['paths'] ?? null ) ? $bundle['paths'] : array();
		$descriptors = array();
		self::collect( $definitions, is_array( $values ) ? $values : array(), array( 'fields' ), array(), 'general', 'Content', $descriptors );
		$paths = array();
		foreach ( $descriptors as $descriptor ) {
			$path        = $descriptor['path'];
			$previous    = isset( $existing[ $path ] ) && is_array( $existing[ $path ] ) ? $existing[ $path ] : array();
			$translation = (string) ( $previous['translation'] ?? '' );
			if ( '' === trim( $translation ) ) {
				$translation = Leadwerk_Translation_API::memory_match( $descriptor['source'], $descriptor['type'] );
			}
			$changed = isset( $previous['source_hash'] ) && ! hash_equals( (string) $previous['source_hash'], (string) $descriptor['source_hash'] );
			$paths[ $path ] = array_merge(
				$descriptor,
				array(
					'id'            => $previous['id'] ?? 'lw_' . substr( hash( 'sha256', $path ), 0, 20 ),
					'translation'   => $translation,
					'needs_review'  => $changed && '' !== trim( $translation ) ? true : ! empty( $previous['needs_review'] ),
				)
			);
		}
		return array( 'version' => 2, 'paths' => $paths, 'updated_at' => current_time( 'mysql', true ) );
	}

	private static function collect( $definitions, $values, $parts, $labels, $section_key, $section_label, &$out ) {
		foreach ( (array) $definitions as $key => $definition ) {
			$type    = sanitize_key( (string) ( $definition['type'] ?? 'text' ) );
			$label   = (string) ( $definition['label'] ?? ucfirst( $key ) );
			$value   = is_array( $values ) && array_key_exists( $key, $values ) ? $values[ $key ] : self::default_value( $definition );
			$path    = array_merge( $parts, array( (string) $key ) );
			$section = self::section_from_label( $label, $section_key, $section_label );
			if ( 'repeater' === $type ) {
				foreach ( is_array( $value ) ? array_values( $value ) : array() as $index => $row ) {
					self::collect( $definition['sub_fields'] ?? array(), is_array( $row ) ? $row : array(), array_merge( $path, array( (string) $index ) ), array_merge( $labels, array( $label . ' ' . ( $index + 1 ) ) ), $section[0], $section[1], $out );
				}
				continue;
			}
			if ( ! self::is_translatable( $key, $definition ) ) {
				continue;
			}
			$path_key = implode( '.', $path );
			if ( 'editor' === $type && class_exists( 'Leadwerk_HTML_Segments' ) ) {
				$segments = Leadwerk_HTML_Segments::extract( (string) $value );
				foreach ( $segments as $html_key => $segment ) {
					self::add_descriptor( $out, $path_key . '::html::' . $html_key, $segment['source'], 'text', implode( ' → ', array_merge( $labels, array( $label ) ) ), $section[0], $section[1] );
				}
				continue;
			}
			if ( '' !== Leadwerk_Translation_API::normalize_memory_source( (string) $value, 'text' ) ) {
				self::add_descriptor( $out, $path_key, (string) $value, 'text', implode( ' → ', array_merge( $labels, array( $label ) ) ), $section[0], $section[1] );
			}
		}
	}

	private static function add_descriptor( &$out, $path, $source, $type, $label, $section_key, $section_label ) {
		$out[] = array(
			'path'          => $path,
			'type'          => $type,
			'label'         => $label,
			'section_key'   => $section_key,
			'section_label' => $section_label,
			'source'        => $source,
			'source_hash'   => hash( 'sha256', Leadwerk_Translation_API::normalize_memory_source( $source, $type ) ),
		);
	}

	private static function translated_value( $key, $definition, $source, $parts, $paths, $fallback_source = false ) {
		$type = sanitize_key( (string) ( $definition['type'] ?? 'text' ) );
		if ( 'repeater' === $type ) {
			$out = array();
			foreach ( is_array( $source ) ? array_values( $source ) : array() as $index => $row ) {
				$item = array();
				foreach ( $definition['sub_fields'] ?? array() as $sub_key => $sub_definition ) {
					$item[ $sub_key ] = self::translated_value( $sub_key, $sub_definition, is_array( $row ) ? ( $row[ $sub_key ] ?? '' ) : '', array_merge( $parts, array( (string) $index, $sub_key ) ), $paths, $fallback_source );
				}
				$out[] = $item;
			}
			return $out;
		}
		if ( ! self::is_translatable( $key, $definition ) ) {
			return 'page_reference' === $type ? Leadwerk_Translation_API::map_page_references( $source, 'en' ) : $source;
		}
		$path = implode( '.', $parts );
		if ( 'editor' === $type && class_exists( 'Leadwerk_HTML_Segments' ) ) {
			$translations = array();
			foreach ( (array) $paths as $bundle_path => $segment ) {
				$prefix = $path . '::html::';
				if ( 0 === strpos( $bundle_path, $prefix ) ) {
					$translations[ substr( $bundle_path, strlen( $prefix ) ) ] = (string) ( $segment['translation'] ?? '' );
				}
			}
			return Leadwerk_HTML_Segments::apply( (string) $source, $translations );
		}
		$translation = isset( $paths[ $path ]['translation'] ) ? (string) $paths[ $path ]['translation'] : '';
		return $fallback_source && '' === trim( $translation ) ? $source : $translation;
	}

	private static function is_translatable( $key, $definition ) {
		$type = sanitize_key( (string) ( $definition['type'] ?? 'text' ) );
		if ( ! in_array( $type, array( 'text', 'textarea', 'editor' ), true ) || in_array( sanitize_key( (string) $key ), self::$technical_keys, true ) ) {
			return false;
		}
		return (bool) apply_filters( 'leadwerk_translation_field_is_translatable', true, $key, $definition );
	}

	private static function section_from_label( $label, $fallback_key, $fallback_label ) {
		$parts = array_map( 'trim', explode( ':', (string) $label, 2 ) );
		if ( count( $parts ) > 1 && '' !== $parts[0] ) {
			return array( sanitize_key( $parts[0] ), $parts[0] );
		}
		return array( $fallback_key, $fallback_label );
	}

	private static function bundle_sections( $bundle ) {
		$sections = array();
		foreach ( (array) ( $bundle['paths'] ?? array() ) as $segment ) {
			$key = sanitize_key( (string) ( $segment['section_key'] ?? 'general' ) ) ?: 'general';
			if ( ! isset( $sections[ $key ] ) ) {
				$sections[ $key ] = array( 'key' => $key, 'label' => $segment['section_label'] ?? 'Content', 'segments' => array() );
			}
			$status = ! empty( $segment['needs_review'] ) ? 'needs_review' : ( '' === trim( (string) ( $segment['translation'] ?? '' ) ) ? 'not_translated' : 'translated' );
			$segment['status'] = $status;
			$sections[ $key ]['segments'][] = $segment;
		}
		return array_values( $sections );
	}

	private static function source_option_values( $definitions ) {
		$values = array();
		foreach ( $definitions as $name => $definition ) {
			$values[ $name ] = self::decode_storage( self::source_option_raw( $name ) );
		}
		return $values;
	}

	/** Read the DE source option without re-entering the EN pre_option filter. */
	private static function source_option_raw( $field_name ) {
		$option = 'leadwerk_opt_' . sanitize_key( $field_name );
		remove_filter( 'pre_option_' . $option, array( 'Leadwerk_Translation_API', 'filter_localized_option' ), 10 );
		$value = get_option( $option, null );
		add_filter( 'pre_option_' . $option, array( 'Leadwerk_Translation_API', 'filter_localized_option' ), 10, 3 );
		return $value;
	}

	private static function decode_storage( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		if ( in_array( substr( $value, 0, 1 ), array( '[', '{' ), true ) ) {
			$decoded = json_decode( $value, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				return $decoded;
			}
		}
		return ctype_digit( $value ) ? (int) $value : $value;
	}

	private static function default_value( $definition ) {
		return 'repeater' === ( $definition['type'] ?? '' ) ? array() : '';
	}

	private static function source_hash( $bundle ) {
		$hashes = array_map( static fn( $segment ) => (string) ( $segment['source_hash'] ?? '' ), (array) ( $bundle['paths'] ?? array() ) );
		return hash( 'sha256', implode( '|', $hashes ) );
	}
}
