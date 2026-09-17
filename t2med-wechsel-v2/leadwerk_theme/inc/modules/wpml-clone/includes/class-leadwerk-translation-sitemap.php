<?php
/** Keep published EN counterparts in sitemaps without exposing drafts. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Translation_Sitemap {
	public static function init() {
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'query_args' ), 20, 2 );
		add_filter( 'wp_sitemaps_posts_entry', array( __CLASS__, 'core_entry' ), 20, 3 );
		add_filter( 'wpseo_sitemap_entry', array( __CLASS__, 'yoast_entry' ), 20, 3 );
		add_filter( 'rank_math/sitemap/url', array( __CLASS__, 'rank_math_entry' ), 20, 2 );
	}

	public static function query_args( $args, $post_type ) {
		if ( 'page' === $post_type ) {
			$args['post_status'] = array( 'publish' );
		}
		return $args;
	}

	public static function core_entry( $entry, $post, $post_type ) {
		if ( 'page' === $post_type && $post instanceof WP_Post ) {
			$entry['loc'] = Leadwerk_Translation_API::public_url( $post->ID ) ?: $entry['loc'];
		}
		return $entry;
	}

	public static function rank_math_entry( $url, $object ) {
		if ( $object instanceof WP_Post && 'page' === $object->post_type ) {
			$url['loc'] = Leadwerk_Translation_API::public_url( $object->ID ) ?: ( $url['loc'] ?? '' );
		}
		return $url;
	}

	/** Yoast accepts extra data keys; alternates are available to compatible XSL/extensions. */
	public static function yoast_entry( $url, $type, $object ) {
		if ( 'post' !== $type || ! $object instanceof WP_Post || 'page' !== $object->post_type ) {
			return $url;
		}
		$translations = Leadwerk_Translation_API::translations( $object->ID, true );
		if ( count( $translations ) < 2 ) {
			return $url;
		}
		$url['alternates'] = array();
		foreach ( $translations as $language => $post_id ) {
			$url['alternates'][ $language ] = Leadwerk_Translation_API::public_url( $post_id );
		}
		return $url;
	}
}
