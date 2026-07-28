<?php
/**
 * Translation settings and safe clone workflow.
 *
 * @package Leadwerk_WPML_Clone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Translation_Admin {
	private static $syncing = false;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'save_settings' ) );
		add_action( 'add_meta_boxes_page', array( __CLASS__, 'metabox' ) );
		add_action( 'save_post_page', array( __CLASS__, 'mark_counterpart_for_review' ), 30, 3 );
		add_action( 'admin_post_leadwerk_clone_english', array( __CLASS__, 'clone_action' ) );
		add_shortcode( 'leadwerk_language_switcher', array( __CLASS__, 'switcher_shortcode' ) );
	}

	public static function menu() {
		add_options_page(
			'Leadwerk Sprachen',
			'Leadwerk Sprachen',
			'manage_options',
			'leadwerk-languages',
			array( __CLASS__, 'settings_page' )
		);
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$active = Leadwerk_Translation_API::active_languages();
		?>
		<div class="wrap">
			<h1>Leadwerk Sprachen</h1>
			<p>Deutsch ist die einzige aktive Standardsprache. Englisch erzeugt erst nach Aktivierung und manuellem Klonen öffentliche Routen.</p>
			<form method="post">
				<?php wp_nonce_field( 'leadwerk_languages_save', 'leadwerk_languages_nonce' ); ?>
				<input type="hidden" name="leadwerk_languages_submit" value="1">
				<p><label><input type="checkbox" checked disabled> Deutsch (Standard)</label></p>
				<p><label><input type="checkbox" name="leadwerk_enable_en" value="1" <?php checked( in_array( 'en', $active, true ) ); ?>> Englisch aktivieren</label></p>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function save_settings() {
		if ( empty( $_POST['leadwerk_languages_submit'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_POST['leadwerk_languages_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['leadwerk_languages_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'leadwerk_languages_save' ) ) {
			wp_die( esc_html__( 'Ungültige Anfrage.', 'leadwerk-wpml-clone' ), '', array( 'response' => 403 ) );
		}
		$languages = array( 'de' );
		if ( ! empty( $_POST['leadwerk_enable_en'] ) ) {
			$languages[] = 'en';
		}
		update_option( 'leadwerk_translation_languages', $languages, false );
		update_option( 'leadwerk_translation_default', 'de', false );
		flush_rewrite_rules( false );
		wp_safe_redirect( admin_url( 'options-general.php?page=leadwerk-languages&updated=1' ) );
		exit;
	}

	public static function metabox( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		add_meta_box(
			'leadwerk-translation',
			'Leadwerk Übersetzung',
			array( __CLASS__, 'render_metabox' ),
			'page',
			'side',
			'high'
		);
	}

	public static function render_metabox( $post ) {
		$language    = Leadwerk_Translation_API::language_of( $post->ID );
		$status      = sanitize_key( (string) get_post_meta( $post->ID, '_leadwerk_translation_status', true ) );
		$counterpart = Leadwerk_Translation_API::get_counterpart( $post->ID, 'de' === $language ? 'en' : 'de' );
		echo '<p><strong>Sprache:</strong> ' . esc_html( strtoupper( $language ) ) . '</p>';
		echo '<p><strong>Status:</strong> ' . esc_html( $status ? $status : ( 'de' === $language ? 'Original' : 'not_translated' ) ) . '</p>';
		if ( $counterpart ) {
			echo '<p><a href="' . esc_url( get_edit_post_link( $counterpart ) ) . '">Gegenstück bearbeiten</a></p>';
		} elseif ( 'de' === $language && Leadwerk_Translation_API::is_active( 'en' ) ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=leadwerk_clone_english&post_id=' . $post->ID ),
				'leadwerk_clone_english_' . $post->ID
			);
			echo '<p><a class="button" href="' . esc_url( $url ) . '">EN-Entwurf klonen</a></p>';
		} elseif ( 'de' === $language ) {
			echo '<p class="description">Englisch ist deaktiviert. Es gibt keine /en/-Route oder Sprachumschaltung.</p>';
		}
	}

	public static function clone_action() {
		$post_id = absint( $_GET['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'leadwerk-wpml-clone' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'leadwerk_clone_english_' . $post_id );
		self::$syncing = true;
		$result        = Leadwerk_Translation_API::clone_to_english( $post_id );
		self::$syncing = false;
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) );
		}
		wp_safe_redirect( get_edit_post_link( $result, 'url' ) );
		exit;
	}

	public static function mark_counterpart_for_review( $post_id, $post, $update ) {
		if ( self::$syncing || ! $update || ! $post instanceof WP_Post || 'de' !== Leadwerk_Translation_API::language_of( $post_id ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$english_id = Leadwerk_Translation_API::get_counterpart( $post_id, 'en' );
		if ( $english_id ) {
			update_post_meta( $english_id, '_leadwerk_translation_status', 'needs_review' );
		}
	}

	public static function switcher_shortcode() {
		if ( ! Leadwerk_Translation_API::is_active( 'en' ) || ! is_singular( 'page' ) ) {
			return '';
		}
		$post_id        = get_queried_object_id();
		$other_language = 'en' === Leadwerk_Translation_API::language_of( $post_id ) ? 'de' : 'en';
		$other_id       = Leadwerk_Translation_API::get_counterpart( $post_id, $other_language );
		if ( ! $other_id || 'publish' !== get_post_status( $other_id ) ) {
			return '';
		}
		return '<a class="leadwerk-language-switcher" href="' . esc_url( get_permalink( $other_id ) ) . '" hreflang="' . esc_attr( $other_language ) . '">' . esc_html( strtoupper( $other_language ) ) . '</a>';
	}
}
