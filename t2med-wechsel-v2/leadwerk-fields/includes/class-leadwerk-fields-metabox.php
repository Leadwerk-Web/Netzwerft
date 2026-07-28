<?php
/**
 * ACF-like page metaboxes and global options screen.
 *
 * @package Leadwerk_Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Fields_Metabox {
	public static function init() {
		add_action( 'add_meta_boxes_page', array( __CLASS__, 'register_metabox' ) );
		add_action( 'save_post_page', array( __CLASS__, 'save_post' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'register_options_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'save_options' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'use_block_editor_for_post', array( __CLASS__, 'disable_block_editor' ), 10, 2 );
	}

	public static function register_metabox( $post ) {
		$group = Leadwerk_Content_Schema::get_group_for_post( $post );
		if ( ! $group ) {
			return;
		}
		remove_post_type_support( 'page', 'editor' );
		add_meta_box(
			'leadwerk-t2med-fields',
			$group['label'],
			array( __CLASS__, 'render_metabox' ),
			'page',
			'normal',
			'high'
		);
	}

	public static function disable_block_editor( $use, $post ) {
		return ( $post instanceof WP_Post && Leadwerk_Content_Schema::get_group_for_post( $post ) ) ? false : $use;
	}

	public static function render_metabox( $post ) {
		$group = Leadwerk_Content_Schema::get_group_for_post( $post );
		if ( ! $group ) {
			return;
		}
		wp_nonce_field( 'leadwerk_fields_save_' . $post->ID, 'leadwerk_fields_nonce' );
		echo '<p class="description">' . esc_html( $group['description'] ) . '</p>';
		echo '<div class="leadwerk-fields">';
		foreach ( $group['fields'] as $name => $definition ) {
			self::render_field( $name, $definition, leadwerk_get_field( $name, $post->ID ), 'leadwerk_fields[' . $name . ']' );
		}
		echo '</div>';
	}

	public static function save_post( $post_id, $post ) {
		if ( ! $post instanceof WP_Post || ! Leadwerk_Content_Schema::get_group_for_post( $post ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$nonce = isset( $_POST['leadwerk_fields_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['leadwerk_fields_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'leadwerk_fields_save_' . $post_id ) ) {
			return;
		}

		$group = Leadwerk_Content_Schema::get_group_for_post( $post );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nested values are sanitized by schema type below.
		$posted_fields = isset( $_POST['leadwerk_fields'] ) && is_array( $_POST['leadwerk_fields'] ) ? wp_unslash( $_POST['leadwerk_fields'] ) : array();
		$raw           = is_array( $posted_fields )
			? $posted_fields
			: array();
		foreach ( $group['fields'] as $name => $definition ) {
			$value = isset( $raw[ $name ] ) ? self::sanitize_value( $raw[ $name ], $definition ) : self::empty_value( $definition );
			leadwerk_update_field( $name, $value, $post_id );
		}
	}

	public static function register_options_page() {
		add_menu_page(
			'Leadwerk Optionen',
			'Leadwerk Optionen',
			'manage_options',
			'leadwerk-options',
			array( __CLASS__, 'render_options_page' ),
			'dashicons-admin-generic',
			59
		);
	}

	public static function render_options_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>Leadwerk Optionen</h1>';
		echo '<p>Globale Marken-, Kontakt-, Navigations- und Formularwerte. Interne Ziele werden als WordPress-Referenz gespeichert.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=leadwerk-options' ) ) . '">';
		wp_nonce_field( 'leadwerk_options_save', 'leadwerk_options_nonce' );
		echo '<input type="hidden" name="leadwerk_options_submit" value="1">';
		echo '<div class="leadwerk-fields leadwerk-options">';
		foreach ( Leadwerk_Content_Schema::get_options_fields() as $name => $definition ) {
			self::render_field( $name, $definition, leadwerk_get_option( $name ), 'leadwerk_options[' . $name . ']' );
		}
		echo '</div>';
		submit_button( 'Optionen speichern' );
		echo '</form></div>';
	}

	public static function save_options() {
		if ( empty( $_POST['leadwerk_options_submit'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_POST['leadwerk_options_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['leadwerk_options_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'leadwerk_options_save' ) ) {
			wp_die( esc_html__( 'Ungültige Anfrage.', 'leadwerk-fields' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nested values are sanitized by schema type below.
		$posted_options = isset( $_POST['leadwerk_options'] ) && is_array( $_POST['leadwerk_options'] ) ? wp_unslash( $_POST['leadwerk_options'] ) : array();
		$raw            = is_array( $posted_options )
			? $posted_options
			: array();
		foreach ( Leadwerk_Content_Schema::get_options_fields() as $name => $definition ) {
			$value = isset( $raw[ $name ] ) ? self::sanitize_value( $raw[ $name ], $definition ) : self::empty_value( $definition );
			leadwerk_update_field( $name, $value, 'option' );
		}
		wp_safe_redirect( add_query_arg( 'leadwerk-updated', '1', admin_url( 'admin.php?page=leadwerk-options' ) ) );
		exit;
	}

	public static function assets( $hook ) {
		$screen         = get_current_screen();
		$is_page_editor = $screen && 'page' === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true );
		if ( ! $is_page_editor && 'toplevel_page_leadwerk-options' !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'leadwerk-fields-admin', LEADWERK_FIELDS_URL . 'assets/admin.css', array(), LEADWERK_FIELDS_VERSION );
		wp_enqueue_script( 'leadwerk-fields-admin', LEADWERK_FIELDS_URL . 'assets/admin.js', array( 'jquery' ), LEADWERK_FIELDS_VERSION, true );
	}

	private static function render_field( $name, $definition, $value, $input_name ) {
		$type  = $definition['type'] ?? 'text';
		$label = $definition['label'] ?? $name;
		echo '<div class="leadwerk-field leadwerk-field--' . esc_attr( $type ) . '">';
		echo '<label class="leadwerk-field__label">' . esc_html( $label ) . '</label>';

		if ( 'repeater' === $type ) {
			self::render_repeater( $name, $definition, is_array( $value ) ? $value : array(), $input_name );
			echo '</div>';
			return;
		}
		if ( 'page_reference' === $type ) {
			self::render_page_reference( $value, $input_name );
			echo '</div>';
			return;
		}
		if ( 'image' === $type ) {
			self::render_image( $value, $input_name );
			echo '</div>';
			return;
		}
		if ( 'editor' === $type ) {
			$editor_id = 'leadwerk_' . sanitize_key( str_replace( array( '[', ']' ), '_', $input_name ) );
			wp_editor(
				(string) $value,
				$editor_id,
				array(
					'textarea_name' => $input_name,
					'textarea_rows' => 14,
					'media_buttons' => false,
					'teeny'         => false,
				)
			);
			echo '</div>';
			return;
		}
		if ( 'textarea' === $type || 'json' === $type ) {
			$display = 'json' === $type && is_array( $value ) ? wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : (string) $value;
			echo '<textarea name="' . esc_attr( $input_name ) . '" rows="' . ( 'json' === $type ? '10' : '4' ) . '">' . esc_textarea( $display ) . '</textarea>';
			echo '</div>';
			return;
		}
		if ( 'select' === $type ) {
			echo '<select name="' . esc_attr( $input_name ) . '">';
			foreach ( $definition['choices'] ?? array() as $choice_value => $choice_label ) {
				echo '<option value="' . esc_attr( $choice_value ) . '"' . selected( (string) $value, (string) $choice_value, false ) . '>' . esc_html( $choice_label ) . '</option>';
			}
			echo '</select></div>';
			return;
		}
		$html_type = in_array( $type, array( 'url', 'email', 'number' ), true ) ? $type : 'text';
		echo '<input type="' . esc_attr( $html_type ) . '" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( (string) $value ) . '">';
		echo '</div>';
	}

	private static function render_repeater( $name, $definition, $rows, $input_name ) {
		$sub_fields = $definition['sub_fields'] ?? array();
		echo '<div class="leadwerk-repeater" data-leadwerk-repeater data-next-index="' . esc_attr( count( $rows ) ) . '" data-input-name="' . esc_attr( $input_name ) . '">';
		echo '<div class="leadwerk-repeater__rows">';
		foreach ( array_values( $rows ) as $index => $row ) {
			self::render_repeater_row( $sub_fields, is_array( $row ) ? $row : array(), $input_name, (string) $index );
		}
		echo '</div>';
		echo '<template data-leadwerk-template>';
		self::render_repeater_row( $sub_fields, array(), $input_name, '__INDEX__' );
		echo '</template>';
		echo '<button type="button" class="button" data-leadwerk-add>Eintrag hinzufügen</button>';
		echo '</div>';
	}

	private static function render_repeater_row( $sub_fields, $row, $input_name, $index ) {
		echo '<div class="leadwerk-repeater__row">';
		echo '<div class="leadwerk-repeater__toolbar"><button type="button" class="button-link-delete" data-leadwerk-remove>Entfernen</button></div>';
		foreach ( $sub_fields as $sub_name => $sub_definition ) {
			self::render_field(
				$sub_name,
				$sub_definition,
				$row[ $sub_name ] ?? null,
				$input_name . '[' . $index . '][' . $sub_name . ']'
			);
		}
		echo '</div>';
	}

	private static function render_image( $value, $input_name ) {
		$id  = absint( $value );
		$url = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
		echo '<div class="leadwerk-image" data-leadwerk-image>';
		echo '<input type="hidden" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( $id ) . '" data-leadwerk-image-id>';
		echo '<img src="' . esc_url( $url ? $url : '' ) . '" alt="" data-leadwerk-image-preview' . ( $url ? '' : ' hidden' ) . '>';
		echo '<div><button type="button" class="button" data-leadwerk-image-select>Bild wählen</button> ';
		echo '<button type="button" class="button-link-delete" data-leadwerk-image-remove' . ( $id ? '' : ' hidden' ) . '>Entfernen</button></div>';
		echo '</div>';
	}

	private static function render_page_reference( $value, $input_name ) {
		$value   = is_array( $value ) ? $value : array();
		$post_id = absint( $value['post_id'] ?? 0 );
		$anchor  = sanitize_title( (string) ( $value['anchor'] ?? '' ) );
		$pages   = get_pages( array( 'post_status' => array( 'publish', 'draft', 'private' ) ) );
		echo '<div class="leadwerk-page-reference"><select name="' . esc_attr( $input_name . '[post_id]' ) . '">';
		echo '<option value="0">— Seite wählen —</option>';
		foreach ( $pages as $page ) {
			echo '<option value="' . esc_attr( $page->ID ) . '"' . selected( $post_id, $page->ID, false ) . '>' . esc_html( $page->post_title . ' (#' . $page->ID . ')' ) . '</option>';
		}
		echo '</select><input type="text" name="' . esc_attr( $input_name . '[anchor]' ) . '" value="' . esc_attr( $anchor ) . '" placeholder="anchor-ohne-raute"></div>';
	}

	private static function sanitize_value( $value, $definition ) {
		$type = $definition['type'] ?? 'text';
		switch ( $type ) {
			case 'repeater':
				$rows = array();
				foreach ( is_array( $value ) ? array_values( $value ) : array() as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$clean = array();
					foreach ( $definition['sub_fields'] ?? array() as $sub_name => $sub_definition ) {
						$clean[ $sub_name ] = self::sanitize_value( $row[ $sub_name ] ?? '', $sub_definition );
					}
					if ( array_filter( $clean, static fn( $item ) => '' !== $item && array() !== $item && 0 !== $item ) ) {
						$rows[] = $clean;
					}
				}
				return $rows;
			case 'page_reference':
				$post_id = absint( is_array( $value ) ? ( $value['post_id'] ?? 0 ) : 0 );
				return array(
					'post_id' => $post_id && 'page' === get_post_type( $post_id ) ? $post_id : 0,
					'anchor'  => sanitize_title( is_array( $value ) ? (string) ( $value['anchor'] ?? '' ) : '' ),
				);
			case 'image':
			case 'number':
				return absint( $value );
			case 'url':
				return esc_url_raw( (string) $value );
			case 'email':
				return sanitize_email( (string) $value );
			case 'textarea':
				return sanitize_textarea_field( (string) $value );
			case 'editor':
				return wp_kses_post( (string) $value );
			case 'json':
				$decoded = is_array( $value ) ? $value : json_decode( (string) $value, true );
				return is_array( $decoded ) ? $decoded : array();
			case 'select':
				$value = sanitize_key( (string) $value );
				return isset( $definition['choices'][ $value ] ) ? $value : '';
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	private static function empty_value( $definition ) {
		return in_array( $definition['type'] ?? '', array( 'repeater', 'json' ), true ) ? array() : '';
	}
}
