<?php
/** Published-counterpart-only DE/EN routing and SEO alternates. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Translation_Router {
	private static $front_request = false;

	public static function init() {
		add_action( 'parse_request', array( __CLASS__, 'parse_request' ), 1 );
		add_filter( 'page_link', array( __CLASS__, 'page_link' ), 20, 2 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'redirect_canonical' ), 20, 2 );
		add_filter( 'locale', array( __CLASS__, 'locale' ) );
		add_filter( 'plugin_locale', array( __CLASS__, 'plugin_locale' ), 20, 2 );
		add_filter( 'language_attributes', array( __CLASS__, 'language_attributes' ), 20, 2 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_filter( 'wpseo_canonical', array( __CLASS__, 'canonical' ), 999 );
		add_filter( 'wpseo_opengraph_url', array( __CLASS__, 'canonical' ), 999 );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'nav_menu_objects' ), 20, 2 );
		add_action( 'wp_head', array( __CLASS__, 'hreflang' ), 3 );
	}

	/** Resolve /en/ paths without rewrite rules or unsafe fallback pages. */
	public static function parse_request( $wp ) {
		Leadwerk_Translation_API::set_current_language( 'de' );
		if ( is_admin() || ! $wp instanceof WP || ! Leadwerk_Translation_API::is_active( 'en' ) ) {
			return;
		}
		$path      = self::request_path();
		$home_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $home_path && ( $path === $home_path || 0 === strpos( $path, $home_path . '/' ) ) ) {
			$path = trim( substr( $path, strlen( $home_path ) ), '/' );
		}
		if ( 'en' !== $path && 0 !== strpos( $path, 'en/' ) ) {
			return;
		}
		Leadwerk_Translation_API::set_current_language( 'en' );
		$slug    = trim( substr( $path, 2 ), '/' );
		$page_id = self::published_english_page_for_path( $slug );
		if ( $page_id ) {
			self::$front_request = '' === $slug;
			$wp->query_vars = array( 'page_id' => $page_id );
		}
	}

	public static function is_front_request() {
		return self::$front_request;
	}

	public static function template_include( $template ) {
		if ( ! self::$front_request ) {
			return $template;
		}
		$front = locate_template( 'front-page.php' );
		return $front ? $front : $template;
	}

	private static function published_english_page_for_path( $path ) {
		if ( '' === $path ) {
			$source_front = absint( get_option( 'page_on_front' ) );
			$target       = $source_front ? Leadwerk_Translation_API::get_counterpart( $source_front, 'en' ) : 0;
			return $target && 'publish' === get_post_status( $target ) ? $target : 0;
		}
		$path = trim( rawurldecode( (string) $path ), '/' );
		$ids  = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => Leadwerk_Translation_API::META_LANG,
				'meta_value'     => 'en',
			)
		);
		foreach ( $ids as $id ) {
			$custom = sanitize_title( (string) get_post_meta( $id, Leadwerk_Translation_API::META_PUBLIC_SLUG, true ) );
			$uri    = $custom ? $custom : trim( get_page_uri( $id ), '/' );
			$uri    = trim( (string) preg_replace( '#(?:^|/)en(?:/|$)#', '/', $uri ), '/' );
			if ( $path === $uri ) {
				return absint( $id );
			}
		}
		return 0;
	}

	public static function page_link( $url, $post_id ) {
		if ( 'en' !== Leadwerk_Translation_API::language_of( $post_id ) ) {
			return $url;
		}
		$public = Leadwerk_Translation_API::public_url( $post_id, true );
		return $public ? $public : $url;
	}

	public static function redirect_canonical( $redirect_url, $requested_url ) {
		if ( 'en' === Leadwerk_Translation_API::current_language() && is_singular( 'page' ) ) {
			$canonical = Leadwerk_Translation_API::public_url( get_queried_object_id() );
			return $canonical && untrailingslashit( $canonical ) === untrailingslashit( (string) $requested_url ) ? false : $canonical;
		}
		return $redirect_url;
	}

	public static function locale( $locale ) {
		return 'en' === Leadwerk_Translation_API::current_language() && ! is_admin() ? 'en_US' : $locale;
	}

	public static function plugin_locale( $locale, $domain ) {
		return 'en' === Leadwerk_Translation_API::current_language() && ! is_admin() ? 'en_US' : $locale;
	}

	public static function language_attributes( $output, $doctype ) {
		if ( 'en' !== Leadwerk_Translation_API::current_language() ) {
			return $output;
		}
		if ( preg_match( '~lang=(["\'])[^"\']+\1~', $output ) ) {
			return preg_replace( '~lang=(["\'])[^"\']+\1~', 'lang="en-US"', $output );
		}
		return trim( $output . ' lang="en-US"' );
	}

	public static function body_class( $classes ) {
		$classes[] = 'leadwerk-lang-' . Leadwerk_Translation_API::current_language();
		if ( self::$front_request ) {
			$classes[] = 'home';
			$classes[] = 'front-page';
		}
		return array_values( array_unique( $classes ) );
	}

	public static function canonical( $url ) {
		if ( is_singular( 'page' ) ) {
			$public = Leadwerk_Translation_API::public_url( get_queried_object_id() );
			return $public ? $public : $url;
		}
		return $url;
	}

	/** Add alternates only when both real pages are published. */
	public static function hreflang() {
		$urls = self::get_current_page_alternate_urls();
		if ( count( $urls ) < 2 ) {
			return;
		}
		echo '<link rel="alternate" hreflang="de-DE" href="' . esc_url( $urls['de'] ) . '">' . "\n";
		echo '<link rel="alternate" hreflang="en-US" href="' . esc_url( $urls['en'] ) . '">' . "\n";
		echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $urls['de'] ) . '">' . "\n";
	}

	public static function get_current_page_alternate_urls() {
		if ( ! is_singular( 'page' ) || ! Leadwerk_Translation_API::is_active( 'en' ) ) {
			return array();
		}
		$post_id = get_queried_object_id();
		$source  = Leadwerk_Translation_API::source_id( $post_id );
		$english = $source ? Leadwerk_Translation_API::get_counterpart( $source, 'en' ) : 0;
		if ( ! $source || ! $english || 'publish' !== get_post_status( $source ) || 'publish' !== get_post_status( $english ) ) {
			return array();
		}
		return array( 'de' => Leadwerk_Translation_API::public_url( $source ), 'en' => Leadwerk_Translation_API::public_url( $english ) );
	}

	/** Keep page-based WordPress menus on the current language counterpart. */
	public static function nav_menu_objects( $items, $args ) {
		if ( 'en' !== Leadwerk_Translation_API::current_language() ) {
			return $items;
		}
		foreach ( $items as $item ) {
			if ( ! $item instanceof WP_Post || 'post_type' !== $item->type || 'page' !== $item->object ) {
				continue;
			}
			$target = Leadwerk_Translation_API::get_counterpart( absint( $item->object_id ), 'en' );
			if ( $target && 'publish' === get_post_status( $target ) ) {
				$item->url       = Leadwerk_Translation_API::public_url( $target );
				$item->object_id = $target;
			}
		}
		return $items;
	}

	private static function request_path() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return trim( (string) wp_parse_url( esc_url_raw( $request_uri ), PHP_URL_PATH ), '/' );
	}
}
