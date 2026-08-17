<?php
/**
 * Leadwerk T2med theme.
 *
 * @package Leadwerk_T2med
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LEADWERK_THEME_VERSION', '2.0.4' );
define( 'LEADWERK_THEME_DIR', get_template_directory() );
define( 'LEADWERK_THEME_URI', get_template_directory_uri() );

function leadwerk_theme_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails', array( 'page' ) );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	remove_theme_support( 'block-templates' );
}
add_action( 'after_setup_theme', 'leadwerk_theme_setup' );

function leadwerk_theme_assets() {
	wp_enqueue_style( 'leadwerk-base', LEADWERK_THEME_URI . '/assets/css/styles-v2.css', array(), LEADWERK_THEME_VERSION );
	wp_enqueue_style( 'leadwerk-t2med', LEADWERK_THEME_URI . '/assets/css/landing-t2med-v2.css', array( 'leadwerk-base' ), LEADWERK_THEME_VERSION );
	wp_enqueue_style( 'leadwerk-wordpress', LEADWERK_THEME_URI . '/assets/css/wordpress.css', array( 'leadwerk-t2med' ), LEADWERK_THEME_VERSION );

	wp_enqueue_script( 'leadwerk-lenis', LEADWERK_THEME_URI . '/assets/js/lenis.min.js', array(), LEADWERK_THEME_VERSION, true );
	wp_enqueue_script( 'leadwerk-scroll', LEADWERK_THEME_URI . '/assets/js/scroll-smooth.js', array( 'leadwerk-lenis' ), LEADWERK_THEME_VERSION, true );
	wp_enqueue_script( 'leadwerk-ui', LEADWERK_THEME_URI . '/assets/js/main.js', array(), LEADWERK_THEME_VERSION, true );
	wp_enqueue_script( 'leadwerk-particles', LEADWERK_THEME_URI . '/assets/js/hero-particles.js', array(), LEADWERK_THEME_VERSION, true );
	wp_enqueue_script( 'leadwerk-wp', LEADWERK_THEME_URI . '/assets/js/t2med.js', array( 'jquery', 'leadwerk-ui' ), LEADWERK_THEME_VERSION, true );

	$form_id   = absint( leadwerk_theme_option( 'wpforms_form_id', 0 ) );
	$field_map = leadwerk_theme_option( 'wpforms_field_map', array() );
	$thank_you = leadwerk_get_page_url( 'nw-danke-v1' );
	$privacy   = leadwerk_get_page_url( 'nw-datenschutz-v1' );
	wp_localize_script(
		'leadwerk-wp',
		'leadwerkT2med',
		array(
			'formId'      => $form_id,
			'fieldMap'    => is_array( $field_map ) ? $field_map : array(),
			'thankYouUrl' => $thank_you,
			'privacyUrl'  => $privacy,
		)
	);

	$source_field = absint( is_array( $field_map ) ? ( $field_map['source'] ?? 0 ) : 0 );
	if ( $form_id && $source_field ) {
		wp_add_inline_style( 'leadwerk-wordpress', '#wpforms-' . $form_id . '-field_' . $source_field . '-container{display:none!important}' );
	}
}
add_action( 'wp_enqueue_scripts', 'leadwerk_theme_assets' );

function leadwerk_theme_field( $name, $post_id = null, $default = '' ) {
	if ( function_exists( 'leadwerk_get_field' ) ) {
		return leadwerk_get_field( $name, $post_id, $default );
	}
	return $default;
}

function leadwerk_theme_option( $name, $default = '' ) {
	return function_exists( 'leadwerk_get_option' ) ? leadwerk_get_option( $name, $default ) : $default;
}

function leadwerk_theme_find_page( $source_key ) {
	$posts = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => 'leadwerk_source_key',
					'value' => sanitize_key( $source_key ),
				),
				array(
					'key'   => '_leadwerk_language',
					'value' => 'de',
				),
			),
		)
	);
	return $posts ? absint( $posts[0] ) : 0;
}

function leadwerk_get_page_url( $source_key, $anchor = '' ) {
	$post_id = leadwerk_theme_find_page( $source_key );
	if ( ! $post_id ) {
		return home_url( '/' );
	}
	if ( function_exists( 'leadwerk_translation_get_counterpart' ) ) {
		$translated = leadwerk_translation_get_counterpart( $post_id );
		if ( $translated && 'publish' === get_post_status( $translated ) ) {
			$post_id = $translated;
		}
	}
	$url = get_permalink( $post_id );
	if ( 'nw-t2med-home-v2' === $source_key && absint( get_option( 'page_on_front' ) ) === $post_id ) {
		$url = home_url( '/' );
	}
	$anchor = sanitize_title( ltrim( (string) $anchor, '#' ) );
	return $anchor ? trailingslashit( $url ) . '#' . $anchor : $url;
}

function leadwerk_page_reference_url( $reference, $fallback = '' ) {
	if ( ! is_array( $reference ) || empty( $reference['post_id'] ) ) {
		return $fallback ? $fallback : home_url( '/' );
	}
	$post_id = absint( $reference['post_id'] );
	if ( function_exists( 'leadwerk_translation_get_counterpart' ) ) {
		$translated = leadwerk_translation_get_counterpart( $post_id );
		if ( $translated && 'publish' === get_post_status( $translated ) ) {
			$post_id = $translated;
		}
	}
	$url    = get_permalink( $post_id );
	$anchor = sanitize_title( (string) ( $reference['anchor'] ?? '' ) );
	return $anchor ? trailingslashit( $url ) . '#' . $anchor : $url;
}

function leadwerk_theme_image( $field, $size = 'full', $fallback_alt = '', $post_id = null, $attributes = array() ) {
	$id = absint( leadwerk_theme_field( $field, $post_id, 0 ) );
	if ( ! $id ) {
		return '';
	}
	$alt               = get_post_meta( $id, '_wp_attachment_image_alt', true );
	$attributes['alt'] = $alt ? $alt : $fallback_alt;
	return wp_get_attachment_image( $id, $size, false, $attributes );
}

function leadwerk_theme_icon( $key ) {
	$icons = array(
		'software' => '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 20h8M12 17v3M8 10l2 2 4-4"/>',
		'server'   => '<rect x="3" y="4" width="18" height="6" rx="2"/><rect x="3" y="14" width="18" height="6" rx="2"/><path d="M7 7h.01M7 17h.01"/>',
		'phone'    => '<path d="M5 4h3l1.5 4-2 1.5a12 12 0 0 0 5 5l1.5-2 4 1.5v3a2 2 0 0 1-2 2A15 15 0 0 1 4 6a2 2 0 0 1 1-2z"/>',
		'security' => '<path d="M12 3 4 6v6c0 4.5 3.2 7.7 8 9 4.8-1.3 8-4.5 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
		'support'  => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
		'check'    => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>',
	);
	$key   = isset( $icons[ $key ] ) ? $key : 'check';
	return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icons[ $key ] . '</svg>';
}

function leadwerk_theme_head_meta() {
	$post_id   = get_queried_object_id();
	$is_home   = is_front_page();
	$title     = $is_home ? leadwerk_theme_field( 'seo_title', $post_id, get_bloginfo( 'name' ) ) : wp_get_document_title();
	$desc      = $is_home ? leadwerk_theme_field( 'seo_description', $post_id, get_bloginfo( 'description' ) ) : '';
	$canonical = is_404() ? '' : ( $is_home ? home_url( '/' ) : get_permalink( $post_id ) );
	$image_id  = $is_home ? absint( leadwerk_theme_field( 'og_image', $post_id, 0 ) ) : get_post_thumbnail_id( $post_id );
	$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
	if ( ! defined( 'WPSEO_VERSION' ) ) {
		if ( $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
		if ( $canonical ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
		}
		echo '<meta property="og:type" content="website">' . "\n";
		echo '<meta property="og:locale" content="de_DE">' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
		if ( $desc ) {
			echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
		if ( $canonical ) {
			echo '<meta property="og:url" content="' . esc_url( $canonical ) . '">' . "\n";
		}
		if ( $image_url ) {
			echo '<meta property="og:image" content="' . esc_url( $image_url ) . '">' . "\n";
		}
	} elseif ( ! get_option( 'blog_public' ) ) {
		if ( $canonical ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
		}
	}
	$favicon = absint( leadwerk_theme_option( 'site_favicon', 0 ) );
	if ( $favicon ) {
		echo '<link rel="icon" href="' . esc_url( wp_get_attachment_url( $favicon ) ) . '" type="image/svg+xml">' . "\n";
	}
	if ( $is_home ) {
		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Service',
			'serviceType' => 'T2med Wechsel und Praxis-IT',
			'provider'    => array(
				'@type' => 'Organization',
				'name'  => leadwerk_theme_option( 'company_name', 'die netzwerft GmbH' ),
				'url'   => home_url( '/' ),
			),
			'areaServed'  => array( 'Karlsruhe', 'Ettlingen', 'Speyer' ),
			'description' => $desc,
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}
}
remove_action( 'wp_head', 'rel_canonical' );
add_action( 'wp_head', 'leadwerk_theme_head_meta', 2 );

function leadwerk_theme_robots( $robots ) {
	if ( is_404() || '1' === get_post_meta( get_queried_object_id(), '_leadwerk_noindex', true ) ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['index'] );
	}
	return $robots;
}
add_filter( 'wp_robots', 'leadwerk_theme_robots' );

function leadwerk_theme_document_title( $title ) {
	if ( is_front_page() ) {
		return (string) leadwerk_theme_field( 'seo_title', get_queried_object_id(), $title );
	}
	if ( is_404() ) {
		$content_id = leadwerk_theme_find_page( 'nw-404-v1' );
		$page_title = leadwerk_theme_field( 'page_eyebrow', $content_id, '404 · Seite nicht gefunden' );
		return (string) $page_title . ' | ' . leadwerk_theme_option( 'company_name', get_bloginfo( 'name' ) );
	}
	return $title;
}
add_filter( 'pre_get_document_title', 'leadwerk_theme_document_title' );

function leadwerk_theme_wpseo_title( $title ) {
	return ( is_front_page() || is_404() ) ? leadwerk_theme_document_title( $title ) : $title;
}
add_filter( 'wpseo_title', 'leadwerk_theme_wpseo_title', 20 );
add_filter( 'wpseo_opengraph_title', 'leadwerk_theme_wpseo_title', 20 );

function leadwerk_theme_wpseo_description( $description ) {
	if ( is_front_page() ) {
		return (string) leadwerk_theme_field( 'seo_description', get_queried_object_id(), $description );
	}
	return $description;
}
add_filter( 'wpseo_metadesc', 'leadwerk_theme_wpseo_description', 20 );
add_filter( 'wpseo_opengraph_desc', 'leadwerk_theme_wpseo_description', 20 );

function leadwerk_theme_wpseo_canonical( $canonical ) {
	if ( is_404() ) {
		return false;
	}
	return is_front_page() ? home_url( '/' ) : $canonical;
}
add_filter( 'wpseo_canonical', 'leadwerk_theme_wpseo_canonical', 20 );

function leadwerk_theme_wpseo_og_url( $url ) {
	return is_front_page() ? home_url( '/' ) : $url;
}
add_filter( 'wpseo_opengraph_url', 'leadwerk_theme_wpseo_og_url', 20 );

function leadwerk_theme_wpseo_og_image( $image ) {
	if ( ! is_front_page() ) {
		return $image;
	}
	$image_id  = absint( leadwerk_theme_field( 'og_image', get_queried_object_id(), 0 ) );
	$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
	return $image_url ? $image_url : $image;
}
add_filter( 'wpseo_opengraph_image', 'leadwerk_theme_wpseo_og_image', 20 );

function leadwerk_theme_wpseo_add_og_image( $image_container ) {
	if ( ! is_front_page() || ! is_object( $image_container ) || ! method_exists( $image_container, 'add_image' ) ) {
		return;
	}
	$image_id  = absint( leadwerk_theme_field( 'og_image', get_queried_object_id(), 0 ) );
	$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
	if ( $image_url ) {
		$image_container->add_image( $image_url );
	}
}
add_action( 'wpseo_add_opengraph_images', 'leadwerk_theme_wpseo_add_og_image', 20 );

function leadwerk_theme_notification_recipient( $email, $fields, $entry, $form_data, $notification_id ) {
	$target_id = absint( leadwerk_theme_option( 'wpforms_form_id', 0 ) );
	if ( $target_id && absint( $form_data['id'] ?? 0 ) === $target_id ) {
		$recipient = sanitize_email( leadwerk_theme_option( 'notification_email', 'info@dienetzwerft.de' ) );
		if ( $recipient ) {
			$email['address'] = array( $recipient );
		}
	}
	return $email;
}
add_filter( 'wpforms_entry_email_atts', 'leadwerk_theme_notification_recipient', 20, 5 );

function leadwerk_theme_wpforms_data( $form_data ) {
	$target_id = absint( leadwerk_theme_option( 'wpforms_form_id', 0 ) );
	if ( $target_id && absint( $form_data['id'] ?? 0 ) === $target_id ) {
		$form_data['settings']['ajax_submit'] = '1';
		$form_data['settings']['honeypot']    = '1';
		$form_data['settings']['antispam']    = '1';
	}
	return $form_data;
}
add_filter( 'wpforms_frontend_form_data', 'leadwerk_theme_wpforms_data', 20 );

function leadwerk_theme_confirmation_message( $message, $form_data ) {
	$target_id = absint( leadwerk_theme_option( 'wpforms_form_id', 0 ) );
	if ( ! $target_id || absint( $form_data['id'] ?? 0 ) !== $target_id ) {
		return $message;
	}
	return '<p>Vielen Dank für deine Nachricht! Wir haben deine Nachricht erhalten und melden uns zeitnah bei dir.</p><p><a href="' . esc_url( leadwerk_get_page_url( 'nw-danke-v1' ) ) . '">Zur Danke-Seite</a></p>';
}
add_filter( 'wpforms_frontend_confirmation_message', 'leadwerk_theme_confirmation_message', 20, 2 );
