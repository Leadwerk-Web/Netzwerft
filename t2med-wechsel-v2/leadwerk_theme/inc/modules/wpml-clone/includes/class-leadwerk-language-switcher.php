<?php
/** Widget, shortcode and template helper for published language pairs. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Language_Switcher extends WP_Widget {
	public function __construct() {
		parent::__construct( 'leadwerk_language_switcher', 'Leadwerk Language Switcher', array( 'description' => 'Zeigt nur veröffentlichte Sprachgegenstücke.' ) );
	}

	public static function init() {
		add_action( 'widgets_init', array( __CLASS__, 'register_widget' ) );
		add_shortcode( 'leadwerk_language_switcher', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_footer', array( __CLASS__, 'automatic' ), 90 );
	}

	public static function automatic() {
		if ( ! get_option( 'leadwerk_translation_auto_switcher', false ) ) {
			return;
		}
		$html = self::render( array( 'style' => 'names', 'menu_id' => 'leadwerk-floating-language-menu' ) );
		if ( '' === $html ) {
			return;
		}
		$current = Leadwerk_Translation_API::current_language();
		$label   = 'en' === $current ? 'Change language' : 'Sprache wechseln';
		echo '<div class="leadwerk-language-switcher-floating"><button type="button" class="leadwerk-language-switcher-floating__toggle" aria-expanded="false" aria-controls="leadwerk-floating-language-menu" aria-label="' . esc_attr( $label ) . '"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M3 12h18M12 3c2.4 2.5 3.6 5.5 3.6 9S14.4 18.5 12 21M12 3C9.6 5.5 8.4 8.5 8.4 12s1.2 6.5 3.6 9"></path></svg></button>' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG and escaped render output.
		echo '<style>
		.leadwerk-language-switcher-floating{position:fixed;right:max(16px,env(safe-area-inset-right));bottom:max(104px,calc(env(safe-area-inset-bottom) + 88px));z-index:9997;width:46px;height:46px}
		.leadwerk-language-switcher-floating__toggle{display:grid;place-items:center;width:46px;height:46px;margin:0;padding:0;border:1px solid rgba(255,255,255,.3);border-radius:50%;background:rgba(0,39,60,.94);color:#fff;box-shadow:0 12px 32px rgba(0,39,60,.3);cursor:pointer;backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);transition:background-color .2s ease,transform .2s ease}.leadwerk-language-switcher-floating__toggle:hover,.leadwerk-language-switcher-floating.is-open .leadwerk-language-switcher-floating__toggle{background:#1e88e5;transform:translateY(-1px)}.leadwerk-language-switcher-floating__toggle svg{width:21px;height:21px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}.leadwerk-language-switcher-floating__toggle:focus-visible{outline:3px solid #64b5f6;outline-offset:3px}
		.leadwerk-language-switcher{position:absolute;right:0;bottom:calc(100% + 6px);display:grid;gap:3px;min-width:138px;margin:0;padding:6px;border:1px solid rgba(255,255,255,.28);border-radius:14px;background:rgba(0,39,60,.96);box-shadow:0 14px 38px rgba(0,39,60,.3);opacity:0;visibility:hidden;pointer-events:none;transform:translateY(7px) scale(.97);transform-origin:bottom right;transition:opacity .18s ease,transform .18s ease,visibility .18s ease;backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}.leadwerk-language-switcher:before{content:"";position:absolute;right:0;bottom:-8px;width:100%;height:8px}.leadwerk-language-switcher-floating:hover .leadwerk-language-switcher,.leadwerk-language-switcher-floating:focus-within .leadwerk-language-switcher,.leadwerk-language-switcher-floating.is-open .leadwerk-language-switcher{opacity:1;visibility:visible;pointer-events:auto;transform:none}.leadwerk-language-switcher__link{display:flex;align-items:center;justify-content:space-between;min-height:38px;padding:8px 11px;border-radius:9px;color:#fff!important;font:700 12px/1.2 Montserrat,Arial,sans-serif;text-decoration:none!important;transition:background-color .18s ease}.leadwerk-language-switcher__link:hover{background:rgba(255,255,255,.13)}.leadwerk-language-switcher__link.is-active{background:#1e88e5;color:#fff!important}.leadwerk-language-switcher__link.is-active:after{content:"✓";margin-left:12px}.leadwerk-language-switcher__link:focus-visible{outline:3px solid #64b5f6;outline-offset:1px}.leadwerk-language-switcher__link.is-disabled{color:rgba(255,255,255,.5)!important;cursor:not-allowed}.leadwerk-language-switcher__link.is-disabled:after{content:"–";margin-left:12px}
		@media(max-width:600px){.leadwerk-language-switcher-floating{right:max(10px,env(safe-area-inset-right));bottom:max(82px,calc(env(safe-area-inset-bottom) + 72px));width:42px;height:42px}.leadwerk-language-switcher-floating__toggle{width:42px;height:42px}.leadwerk-language-switcher{min-width:132px}}
		@media(prefers-reduced-motion:reduce){.leadwerk-language-switcher-floating__toggle,.leadwerk-language-switcher{transition:none}}
		</style>';
		echo '<script>(function(){document.querySelectorAll(".leadwerk-language-switcher-floating").forEach(function(root){if(root.dataset.ready)return;root.dataset.ready="1";var button=root.querySelector(".leadwerk-language-switcher-floating__toggle");if(!button)return;function close(){root.classList.remove("is-open");button.setAttribute("aria-expanded","false");}button.addEventListener("click",function(event){event.stopPropagation();var open=!root.classList.contains("is-open");root.classList.toggle("is-open",open);button.setAttribute("aria-expanded",open?"true":"false");});root.addEventListener("keydown",function(event){if(event.key==="Escape"){close();button.focus();}});document.addEventListener("click",function(event){if(!root.contains(event.target))close();});});})();</script>';
	}

	public static function register_widget() { register_widget( __CLASS__ ); }

	public function widget( $args, $instance ) {
		echo wp_kses_post( $args['before_widget'] ?? '' );
		echo self::render( $instance ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by render().
		echo wp_kses_post( $args['after_widget'] ?? '' );
	}

	public function form( $instance ) {
		$style = sanitize_key( (string) ( $instance['style'] ?? 'compact' ) );
		?>
		<p><label for="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>">Darstellung</label>
		<select id="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'style' ) ); ?>">
			<option value="compact" <?php selected( $style, 'compact' ); ?>>DE / EN</option>
			<option value="names" <?php selected( $style, 'names' ); ?>>Sprachnamen</option>
		</select></p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$style = sanitize_key( (string) ( $new_instance['style'] ?? 'compact' ) );
		return array( 'style' => in_array( $style, array( 'compact', 'names' ), true ) ? $style : 'compact' );
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'style' => 'compact' ), $atts, 'leadwerk_language_switcher' );
		return self::render( $atts );
	}

	public static function render( $options = array() ) {
		if ( ! Leadwerk_Translation_API::is_active( 'en' ) ) {
			return '';
		}
		$urls    = self::destinations();
		$style   = 'names' === sanitize_key( (string) ( $options['style'] ?? 'compact' ) ) ? 'names' : 'compact';
		$current = Leadwerk_Translation_API::current_language();
		$menu_id = sanitize_html_class( (string) ( $options['menu_id'] ?? '' ) );
		$nav_label = 'en' === $current ? 'Change language' : 'Sprache wechseln';
		$html      = '<nav' . ( $menu_id ? ' id="' . esc_attr( $menu_id ) . '"' : '' ) . ' class="leadwerk-language-switcher leadwerk-language-switcher--' . esc_attr( $style ) . '" aria-label="' . esc_attr( $nav_label ) . '">';
		foreach ( array( 'de' => 'Deutsch', 'en' => 'English' ) as $code => $name ) {
			$label = 'names' === $style ? $name : strtoupper( $code );
			if ( ! empty( $urls[ $code ] ) ) {
				$html .= '<a class="leadwerk-language-switcher__link' . ( $current === $code ? ' is-active' : '' ) . '" href="' . esc_url( $urls[ $code ] ) . '" hreflang="' . esc_attr( $code ) . '" lang="' . esc_attr( $code ) . '"' . ( $current === $code ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
			} else {
				$unavailable = 'en' === $current ? 'Translation not published' : 'Übersetzung noch nicht veröffentlicht';
				$html .= '<span class="leadwerk-language-switcher__link is-disabled" lang="' . esc_attr( $code ) . '" aria-disabled="true" title="' . esc_attr( $unavailable ) . '">' . esc_html( $label ) . '</span>';
			}
		}
		return $html . '</nav>';
	}

	/** Return published destinations for the current pair or the site home pair. */
	private static function destinations() {
		if ( class_exists( 'Leadwerk_Translation_Router' ) ) {
			$paired = Leadwerk_Translation_Router::get_current_page_alternate_urls();
			if ( count( $paired ) >= 2 ) {
				return $paired;
			}
		}
		$urls = array( 'de' => '', 'en' => '' );
		if ( is_singular( 'page' ) ) {
			$post_id = get_queried_object_id();
			$source  = Leadwerk_Translation_API::source_id( $post_id );
			$english = $source ? Leadwerk_Translation_API::get_counterpart( $source, 'en' ) : 0;
			$urls['de'] = $source && 'publish' === get_post_status( $source ) ? Leadwerk_Translation_API::public_url( $source ) : '';
			$urls['en'] = $english && 'publish' === get_post_status( $english ) ? Leadwerk_Translation_API::public_url( $english ) : '';
			return $urls;
		}
		$front      = absint( get_option( 'page_on_front' ) );
		$front_en   = $front ? Leadwerk_Translation_API::get_counterpart( $front, 'en' ) : 0;
		$urls['de'] = $front && 'publish' === get_post_status( $front ) ? Leadwerk_Translation_API::public_url( $front ) : home_url( '/' );
		$urls['en'] = $front_en && 'publish' === get_post_status( $front_en ) ? Leadwerk_Translation_API::public_url( $front_en ) : '';
		return $urls;
	}
}

if ( ! function_exists( 'leadwerk_language_switcher' ) ) {
	function leadwerk_language_switcher( $options = array() ) {
		echo Leadwerk_Language_Switcher::render( $options ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
