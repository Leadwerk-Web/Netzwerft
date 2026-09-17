<?php
/** Translate theme-owned runtime strings without duplicating templates. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Shared_Translations {
	const OPTION = 'leadwerk_translation_runtime_strings_en';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 0 );
		add_action( 'wp_footer', array( __CLASS__, 'javascript_strings' ), 100 );
	}

	public static function definitions() {
		return array(
			'skip_link'          => array( 'label' => 'Accessibility: Skip link', 'source' => 'Zum Inhalt springen', 'default' => 'Skip to content' ),
			'home_aria'          => array( 'label' => 'Header: Home ARIA label', 'source' => 'die netzwerft – zur Startseite', 'default' => 'die netzwerft – Home' ),
			'primary_navigation' => array( 'label' => 'Header: Navigation ARIA label', 'source' => 'Hauptnavigation', 'default' => 'Primary navigation' ),
			'footer_navigation'  => array( 'label' => 'Footer: Navigation ARIA label', 'source' => 'Footer-Navigation', 'default' => 'Footer navigation' ),
			'consultation'       => array( 'label' => 'Header: CTA', 'source' => 'Erstgespräch', 'default' => 'Initial consultation' ),
			'open_menu'          => array( 'label' => 'Header: Open menu', 'source' => 'Menü öffnen', 'default' => 'Open menu' ),
			'contact'            => array( 'label' => 'Footer: Contact', 'source' => 'Kontakt', 'default' => 'Contact' ),
			'legal'              => array( 'label' => 'Footer: Legal', 'source' => 'Rechtliches', 'default' => 'Legal' ),
			'legal_notice'       => array( 'label' => 'Footer: Legal notice', 'source' => 'Impressum', 'default' => 'Legal notice' ),
			'privacy_policy'     => array( 'label' => 'Footer/Form: Privacy policy', 'source' => 'Datenschutzerklärung', 'default' => 'Privacy policy' ),
			'privacy_short'      => array( 'label' => 'Footer: Privacy', 'source' => 'Datenschutz', 'default' => 'Privacy' ),
			'quick_actions'      => array( 'label' => 'Footer: Quick actions', 'source' => 'Schnellaktionen', 'default' => 'Quick actions' ),
			'scroll_top_aria'    => array( 'label' => 'Footer: Scroll top ARIA label', 'source' => 'Nach oben scrollen', 'default' => 'Scroll to top' ),
			'scroll_top'         => array( 'label' => 'Footer: Back to top', 'source' => 'Nach oben', 'default' => 'Back to top' ),
			'close_dialog'       => array( 'label' => 'Modal: Close dialog', 'source' => 'Dialog schließen', 'default' => 'Close dialog' ),
			'problem_tabs_aria'  => array( 'label' => 'Page: Problem tabs ARIA label', 'source' => 'Typische Probleme im Praxisalltag', 'default' => 'Typical challenges in daily practice' ),
			'play_video'        => array( 'label' => 'Video: Play prefix', 'source' => 'Video abspielen:', 'default' => 'Play video:' ),
			'watch_video'       => array( 'label' => 'Video: Watch video', 'source' => 'Video ansehen · T2med in der Praxis', 'default' => 'Watch video · T2med in practice' ),
			'close_video'       => array( 'label' => 'Video: Close button', 'source' => 'Video schließen', 'default' => 'Close video' ),
			'privacy_new_tab'   => array( 'label' => 'Form: Privacy link ARIA label', 'source' => 'Datenschutzerklärung (öffnet in neuem Tab)', 'default' => 'Privacy policy (opens in a new tab)' ),
			'missing_form'      => array( 'label' => 'Form: Missing form message', 'source' => 'Das Anfrageformular ist derzeit nicht verfügbar.', 'default' => 'The enquiry form is currently unavailable.' ),
			'form_not_setup'    => array( 'label' => 'Form: Not configured', 'source' => 'Das Kontaktformular ist noch nicht eingerichtet.', 'default' => 'The contact form has not been configured yet.' ),
			'run_importer'      => array( 'label' => 'Form: Importer help', 'source' => 'Bitte führen Sie den T2med-Importer aus oder schreiben Sie an', 'default' => 'Please run the T2med importer or email' ),
			'video_privacy'     => array( 'label' => 'Video: Privacy notice', 'source' => 'Beim Klick wird YouTube im erweiterten Datenschutzmodus geladen. Hinweise stehen im', 'default' => 'When clicked, YouTube loads in enhanced privacy mode. Further information is available in the' ),
			'service_type'      => array( 'label' => 'SEO: Schema service type', 'source' => 'T2med Wechsel und Praxis-IT', 'default' => 'T2med migration and medical practice IT' ),
			'thank_you_page'    => array( 'label' => 'Form: Thank-you link', 'source' => 'Zur Danke-Seite', 'default' => 'Go to the thank-you page' ),
			'form_situation'     => array( 'label' => 'WPForms: Situation', 'source' => 'Situation', 'default' => 'Situation' ),
			'form_change'        => array( 'label' => 'WPForms: Software change', 'source' => 'Softwarewechsel / T2med', 'default' => 'Software migration / T2med' ),
			'form_startup'       => array( 'label' => 'WPForms: New practice', 'source' => 'Praxisgründung', 'default' => 'New medical practice' ),
			'form_takeover'      => array( 'label' => 'WPForms: Practice takeover', 'source' => 'Praxisübernahme', 'default' => 'Practice takeover' ),
			'form_support'       => array( 'label' => 'WPForms: Ongoing support', 'source' => 'Laufende Betreuung', 'default' => 'Ongoing support' ),
			'form_software'      => array( 'label' => 'WPForms: Current software', 'source' => 'Welche Praxisverwaltungssoftware ist aktuell im Einsatz?', 'default' => 'Which practice management software do you currently use?' ),
			'form_software_hint' => array( 'label' => 'WPForms: Software example', 'source' => 'z. B. Medistar, CGM, isynet, tomedo', 'default' => 'e.g. Medistar, CGM, isynet, tomedo' ),
			'form_location'      => array( 'label' => 'WPForms: Location', 'source' => 'PLZ / Ort', 'default' => 'Postcode / City' ),
			'form_location_hint' => array( 'label' => 'WPForms: Location example', 'source' => 'z. B. 76275 Ettlingen', 'default' => 'e.g. 76275 Ettlingen' ),
			'form_start'         => array( 'label' => 'WPForms: Project start', 'source' => 'Projektstart', 'default' => 'Project start' ),
			'form_now'           => array( 'label' => 'WPForms: Immediately', 'source' => 'sofort', 'default' => 'immediately' ),
			'form_months_1_3'    => array( 'label' => 'WPForms: 1–3 months', 'source' => '1–3 Monate', 'default' => '1–3 months' ),
			'form_months_3_6'    => array( 'label' => 'WPForms: 3–6 months', 'source' => '3–6 Monate', 'default' => '3–6 months' ),
			'form_later'         => array( 'label' => 'WPForms: Later', 'source' => 'später', 'default' => 'later' ),
			'form_scope'         => array( 'label' => 'WPForms: Scope', 'source' => 'Umfang', 'default' => 'Scope' ),
			'form_t2med_only'    => array( 'label' => 'WPForms: T2med only', 'source' => 'Nur T2med', 'default' => 'T2med only' ),
			'form_t2med_it'      => array( 'label' => 'WPForms: T2med and IT', 'source' => 'T2med + IT & Telefonie', 'default' => 'T2med + IT & telephony' ),
			'form_unclear'       => array( 'label' => 'WPForms: Unclear', 'source' => 'Noch unklar', 'default' => 'Not sure yet' ),
			'form_full_name'     => array( 'label' => 'WPForms: Full name', 'source' => 'Vor- und Nachname', 'default' => 'First and last name' ),
			'form_phone'         => array( 'label' => 'WPForms: Phone', 'source' => 'Telefon', 'default' => 'Phone' ),
			'form_phone_number'  => array( 'label' => 'WPForms: Phone number', 'source' => 'Telefonnummer', 'default' => 'Phone number' ),
			'form_practice'      => array( 'label' => 'WPForms: Practice name', 'source' => 'Praxisname', 'default' => 'Practice name' ),
			'form_optional'      => array( 'label' => 'WPForms: Optional', 'source' => 'Optional', 'default' => 'Optional' ),
			'form_message'       => array( 'label' => 'WPForms: Message', 'source' => 'Nachricht', 'default' => 'Message' ),
			'form_message_hint'  => array( 'label' => 'WPForms: Message hint', 'source' => 'Was sollten wir vorab wissen?', 'default' => 'What should we know in advance?' ),
			'form_consent'       => array( 'label' => 'WPForms: Consent', 'source' => 'Ich habe die Datenschutzerklärung gelesen und stimme der Verarbeitung meiner Angaben zur Bearbeitung der Anfrage zu.', 'default' => 'I have read the privacy policy and consent to the processing of my information for handling my enquiry.' ),
			'form_submit'        => array( 'label' => 'WPForms: Submit', 'source' => 'Nachricht senden', 'default' => 'Send message' ),
		);
	}

	public static function sections() {
		$saved    = get_option( self::OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$segments = array();
		foreach ( self::definitions() as $key => $definition ) {
			$value      = isset( $saved[ $key ] ) ? (string) $saved[ $key ] : (string) $definition['default'];
			$segments[] = array(
				'id'          => $key,
				'label'       => $definition['label'],
				'source'      => $definition['source'],
				'translation' => $value,
				'type'        => 'text',
				'source_hash' => hash( 'sha256', $definition['source'] ),
				'status'      => '' === trim( $value ) ? 'not_translated' : 'translated',
			);
		}
		return array( array( 'key' => 'theme_runtime', 'label' => 'Theme- und Systemtexte', 'segments' => $segments ) );
	}

	public static function save( $submitted ) {
		$submitted = is_array( $submitted ) ? $submitted : array();
		$out       = array();
		foreach ( self::definitions() as $key => $definition ) {
			if ( array_key_exists( $key, $submitted ) ) {
				$out[ $key ] = Leadwerk_Translation_Text::sanitize( wp_unslash( $submitted[ $key ] ), 'text' );
			} else {
				$out[ $key ] = (string) $definition['default'];
			}
			if ( '' !== trim( $out[ $key ] ) ) {
				Leadwerk_Translation_API::remember( $definition['source'], $out[ $key ], 'text', 'runtime:' . $key );
			}
		}
		update_option( self::OPTION, $out, false );
		return $out;
	}

	public static function get( $key ) {
		$definitions = self::definitions();
		if ( ! isset( $definitions[ $key ] ) ) {
			return '';
		}
		$saved = get_option( self::OPTION, array() );
		return isset( $saved[ $key ] ) ? (string) $saved[ $key ] : (string) $definitions[ $key ]['default'];
	}

	public static function start_buffer() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || 'en' !== Leadwerk_Translation_API::current_language() ) {
			return;
		}
		if ( ! apply_filters( 'leadwerk_translation_runtime_buffer_enabled', true, get_queried_object_id() ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'translate_html' ) );
	}

	public static function translate_html( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		$replace = array();
		foreach ( self::definitions() as $key => $definition ) {
			$translation = self::get( $key );
			if ( '' !== trim( $translation ) ) {
				$replace[ $definition['source'] ] = $translation;
			}
		}
		// Longer phrases must win when one source string contains another.
		uksort( $replace, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
		$html = strtr( $html, $replace );
		if ( 'en' === Leadwerk_Translation_API::current_language() && is_singular( 'page' ) ) {
			$current_url = Leadwerk_Translation_API::public_url( get_queried_object_id() );
			$front_id    = absint( get_option( 'page_on_front' ) );
			$front_en    = $front_id ? Leadwerk_Translation_API::get_counterpart( $front_id, 'en' ) : 0;
			$home_en     = $front_en && 'publish' === get_post_status( $front_en ) ? Leadwerk_Translation_API::public_url( $front_en ) : '';
			if ( $current_url ) {
				$html = preg_replace( '~<link\s+rel="canonical"\s+href="[^"]*"\s*/?>~i', '<link rel="canonical" href="' . esc_url( $current_url ) . '">', $html );
				$html = preg_replace( '~<meta\s+property="og:url"\s+content="[^"]*"\s*/?>~i', '<meta property="og:url" content="' . esc_url( $current_url ) . '">', $html );
			}
			$html = str_replace( 'content="de_DE"', 'content="en_US"', $html );
			if ( $home_en ) {
				$html = preg_replace( '~(<a\s+class="brand"\s+href=")[^"]*(")~i', '$1' . esc_url( $home_en ) . '$2', $html, 1 );
			}
		}
		return $html;
	}

	/** Translate labels which are created by the existing frontend JavaScript. */
	public static function javascript_strings() {
		if ( 'en' !== Leadwerk_Translation_API::current_language() ) {
			return;
		}
		$map = array(
			'Datenschutzerklärung (öffnet in neuem Tab)' => self::get( 'privacy_new_tab' ),
			'Video schließen' => self::get( 'close_video' ),
		);
		?>
		<script id="leadwerk-runtime-translations">
		(function(map){function apply(root){if(!root||!root.querySelectorAll)return;root.querySelectorAll('[aria-label],[title]').forEach(function(el){['aria-label','title'].forEach(function(a){var v=el.getAttribute(a);if(v&&map[v])el.setAttribute(a,map[v]);});});}apply(document);new MutationObserver(function(items){items.forEach(function(item){item.addedNodes.forEach(function(node){if(node.nodeType===1)apply(node);});});}).observe(document.documentElement,{childList:true,subtree:true});})(<?php echo wp_json_encode( $map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>);
		</script>
		<?php
	}
}
