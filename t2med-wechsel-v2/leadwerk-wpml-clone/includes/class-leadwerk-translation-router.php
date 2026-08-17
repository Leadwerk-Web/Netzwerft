<?php
/**
 * Optional /en/ page routing. No EN route exists while EN is inactive.
 *
 * @package Leadwerk_WPML_Clone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Translation_Router {
	public static function init() {
		add_action( 'parse_request', array( __CLASS__, 'parse_request' ), 1 );
		add_filter( 'page_link', array( __CLASS__, 'page_link' ), 20, 2 );
		add_filter( 'locale', array( __CLASS__, 'locale' ) );
		add_filter( 'language_attributes', array( __CLASS__, 'language_attributes' ), 20, 2 );
		add_action( 'wp_head', array( __CLASS__, 'hreflang' ), 3 );
	}

	public static function parse_request( $wp ) {
		if ( is_admin() || ! $wp instanceof WP || ! Leadwerk_Translation_API::is_active( 'en' ) ) {
			Leadwerk_Translation_API::set_current_language( 'de' );
			return;
		}
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
		$home_path   = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $home_path && ( $path === $home_path || str_starts_with( $path, $home_path . '/' ) ) ) {
			$path = trim( substr( $path, strlen( $home_path ) ), '/' );
		}
		if ( 'en' !== $path && ! str_starts_with( $path, 'en/' ) ) {
			Leadwerk_Translation_API::set_current_language( 'de' );
			return;
		}
		Leadwerk_Translation_API::set_current_language( 'en' );
		$slug = trim( substr( $path, 2 ), '/' );
		if ( '' === $slug ) {
			$source_id = absint( get_option( 'page_on_front' ) );
			$page_id   = Leadwerk_Translation_API::get_counterpart( $source_id, 'en' );
		} else {
			$parts   = explode( '/', $slug );
			$leaf    = sanitize_title( end( $parts ) );
			$matches = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'name'           => $leaf,
					'posts_per_page' => 10,
					'meta_key'       => '_leadwerk_language',
					'meta_value'     => 'en',
				)
			);
			$page_id = $matches ? absint( $matches[0]->ID ) : 0;
		}
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			$wp->query_vars = array( 'page_id' => $page_id );
		}
	}

	public static function page_link( $url, $post_id ) {
		if ( 'en' !== Leadwerk_Translation_API::language_of( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			return $url;
		}
		$front_source = absint( get_option( 'page_on_front' ) );
		if ( Leadwerk_Translation_API::get_counterpart( $front_source, 'en' ) === absint( $post_id ) ) {
			return home_url( '/en/' );
		}
		return home_url( '/en/' . get_page_uri( $post_id ) . '/' );
	}

	public static function locale( $locale ) {
		return 'en' === Leadwerk_Translation_API::current_language() ? 'en_US' : $locale;
	}

	public static function language_attributes( $output, $doctype ) {
		if ( 'en' !== Leadwerk_Translation_API::current_language() ) {
			return $output;
		}
		return preg_replace( "~lang=([\"'])[^\"']+\\1~", 'lang="en-US"', $output );
	}

	public static function hreflang() {
		if ( ! is_singular( 'page' ) || ! Leadwerk_Translation_API::is_active( 'en' ) ) {
			return;
		}
		$post_id = get_queried_object_id();
		$de_id   = 'de' === Leadwerk_Translation_API::language_of( $post_id ) ? $post_id : Leadwerk_Translation_API::get_counterpart( $post_id, 'de' );
		$en_id   = 'en' === Leadwerk_Translation_API::language_of( $post_id ) ? $post_id : Leadwerk_Translation_API::get_counterpart( $post_id, 'en' );
		if ( ! $de_id || ! $en_id || 'publish' !== get_post_status( $de_id ) || 'publish' !== get_post_status( $en_id ) ) {
			return;
		}
		echo '<link rel="alternate" hreflang="de" href="' . esc_url( get_permalink( $de_id ) ) . '">' . "\n";
		echo '<link rel="alternate" hreflang="en" href="' . esc_url( get_permalink( $en_id ) ) . '">' . "\n";
	}
}
