<?php
/**
 * T2med field definitions shared by admin, importer and theme.
 *
 * @package Leadwerk_Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Content_Schema {
	const HOME_KEY      = 'nw-t2med-home-v2';
	const THANK_YOU_KEY = 'nw-danke-v1';
	const IMPRINT_KEY   = 'nw-impressum-v1';
	const PRIVACY_KEY   = 'nw-datenschutz-v1';
	const NOT_FOUND_KEY = 'nw-404-v1';

	public static function get_groups() {
		return array(
			self::HOME_KEY      => array(
				'label'       => 'T2med Landingpage',
				'description' => 'Alle sichtbaren Inhalte der zehn Landingpage-Abschnitte.',
				'fields'      => self::home_fields(),
			),
			self::THANK_YOU_KEY => array(
				'label'       => 'Danke-Seite',
				'description' => 'Bestätigungsseite nach erfolgreicher Formularübermittlung.',
				'fields'      => self::simple_page_fields( false ),
			),
			self::IMPRINT_KEY   => array(
				'label'       => 'Impressum',
				'description' => 'Rechtlicher Seed-Inhalt. Vor Livegang fachlich prüfen.',
				'fields'      => self::legal_fields(),
			),
			self::PRIVACY_KEY   => array(
				'label'       => 'Datenschutz',
				'description' => 'Datenschutz-Seed. WPForms und YouTube vor Livegang fachlich prüfen.',
				'fields'      => self::legal_fields(),
			),
			self::NOT_FOUND_KEY => array(
				'label'       => '404-Inhalt',
				'description' => 'Inhalte der echten HTTP-404-Seite.',
				'fields'      => self::simple_page_fields( true ),
			),
		);
	}

	public static function get_group_for_post( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		$key    = (string) get_post_meta( $post->ID, 'leadwerk_source_key', true );
		$groups = self::get_groups();
		return isset( $groups[ $key ] ) ? $groups[ $key ] : null;
	}

	public static function get_group( $source_key ) {
		$groups = self::get_groups();
		return isset( $groups[ $source_key ] ) ? $groups[ $source_key ] : null;
	}

	public static function get_all_post_field_names() {
		$names = array();
		foreach ( self::get_groups() as $group ) {
			$names = array_merge( $names, array_keys( $group['fields'] ) );
		}
		return array_values( array_unique( $names ) );
	}

	public static function get_options_fields() {
		return array(
			'brand_logo'          => self::field( 'Logo', 'image' ),
			'site_favicon'        => self::field( 'Favicon', 'image' ),
			'company_name'        => self::field( 'Firmenname' ),
			'company_address'     => self::field( 'Adresse', 'textarea' ),
			'company_phone'       => self::field( 'Telefon' ),
			'company_email'       => self::field( 'E-Mail', 'email' ),
			'header_menu'         => self::repeater( 'Header-Menü', self::link_subfields() ),
			'footer_menu'         => self::repeater( 'Footer-Menü', self::link_subfields() ),
			'footer_description'  => self::field( 'Footer-Beschreibung', 'textarea' ),
			'wpforms_form_id'     => self::field( 'WPForms Formular-ID', 'number' ),
			'wpforms_field_map'   => self::field( 'WPForms Feldzuordnung (JSON)', 'json' ),
			'notification_email'  => self::field( 'Formular-Empfänger', 'email' ),
			'legal_review_status' => self::field(
				'Legal-Prüfstatus',
				'select',
				array(
					'choices' => array(
						'pending'  => 'Prüfung ausstehend',
						'approved' => 'Fachlich freigegeben',
					),
				)
			),
			'legal_review_notes'  => self::field( 'Legal-Prüfnotizen', 'textarea' ),
		);
	}

	private static function home_fields() {
		$fields = array(
			'seo_focus_keyphrase'     => self::field( 'SEO-Fokus-Keyphrase' ),
			'seo_title'               => self::field( 'SEO-Titel' ),
			'seo_description'         => self::field( 'SEO-Beschreibung', 'textarea' ),
			'og_image'                => self::field( 'OpenGraph-Bild', 'image' ),
			'hero_eyebrow'            => self::field( 'Hero: Eyebrow' ),
			'hero_title'              => self::field( 'Hero: Titel' ),
			'hero_title_bold'         => self::field( 'Hero: hervorgehobener Titelteil' ),
			'hero_text'               => self::field( 'Hero: Text', 'textarea' ),
			'hero_cta_label'          => self::field( 'Hero: CTA-Text' ),
			'hero_trust'              => self::field( 'Hero: Vertrauenszeile', 'textarea' ),
			'hero_image'              => self::field( 'Hero: T2med-Bild', 'image' ),
			'hero_image_alt'          => self::field( 'Hero: Bild-Alttext' ),
			'hero_image_focus'        => self::field( 'Hero: Bildfokus (z. B. 50% 50%)' ),
			'problem_eyebrow'         => self::field( 'Probleme: Eyebrow' ),
			'problem_title'           => self::field( 'Probleme: Titel' ),
			'problem_title_bold'      => self::field( 'Probleme: hervorgehobener Titelteil' ),
			'problem_intro'           => self::field( 'Probleme: Intro', 'textarea' ),
			'problem_items'           => self::repeater(
				'Probleme: Tabs',
				array(
					'title' => self::field( 'Titel' ),
					'text'  => self::field( 'Text', 'textarea' ),
				)
			),
			'solution_eyebrow'        => self::field( 'Lösung: Eyebrow' ),
			'solution_title'          => self::field( 'Lösung: Titel' ),
			'solution_title_bold'     => self::field( 'Lösung: hervorgehobener Titelteil' ),
			'solution_intro'          => self::field( 'Lösung: Intro', 'textarea' ),
			'solution_cta_label'      => self::field( 'Lösung: CTA-Text' ),
			'solution_items'          => self::repeater(
				'Lösung: Leistungen',
				array(
					'title' => self::field( 'Titel' ),
					'text'  => self::field( 'Text', 'textarea' ),
				)
			),
			'video_eyebrow'           => self::field( 'Video: Eyebrow' ),
			'video_title'             => self::field( 'Video: Titel' ),
			'video_title_bold'        => self::field( 'Video: hervorgehobener Titelteil' ),
			'video_intro'             => self::field( 'Video: Intro', 'textarea' ),
			'video_items'             => self::repeater( 'Video: Stichpunkte', array( 'text' => self::field( 'Text' ) ) ),
			'video_cta_label'         => self::field( 'Video: CTA-Text' ),
			'video_youtube_id'        => self::field( 'YouTube Video-ID' ),
			'video_title_attr'        => self::field( 'YouTube Titel' ),
			'video_poster'            => self::field( 'Video: Vorschaubild', 'image' ),
			'video_poster_alt'        => self::field( 'Video: Vorschaubild-Alttext' ),
			'video_external_label'    => self::field( 'Video: externer Linktext' ),
			'video_external_url'      => self::field( 'Video: externe URL', 'url' ),
			'services_eyebrow'        => self::field( 'Leistungen: Eyebrow' ),
			'services_title'          => self::field( 'Leistungen: Titel' ),
			'services_title_bold'     => self::field( 'Leistungen: hervorgehobener Titelteil' ),
			'services_intro'          => self::field( 'Leistungen: Intro', 'textarea' ),
			'services_items'          => self::repeater(
				'Leistungskarten',
				array(
					'icon_key' => self::icon_field(),
					'title'    => self::field( 'Titel' ),
					'text'     => self::field( 'Text', 'textarea' ),
				)
			),
			'process_eyebrow'         => self::field( 'Ablauf: Eyebrow' ),
			'process_title'           => self::field( 'Ablauf: Titel' ),
			'process_title_bold'      => self::field( 'Ablauf: hervorgehobener Titelteil' ),
			'process_intro'           => self::field( 'Ablauf: Intro', 'textarea' ),
			'process_items'           => self::repeater(
				'Ablauf: Schritte',
				array(
					'number' => self::field( 'Nummer' ),
					'title'  => self::field( 'Titel' ),
					'text'   => self::field( 'Text', 'textarea' ),
				)
			),
			'trust_eyebrow'           => self::field( 'Vertrauen: Eyebrow' ),
			'trust_title'             => self::field( 'Vertrauen: Titel' ),
			'trust_title_bold'        => self::field( 'Vertrauen: hervorgehobener Titelteil' ),
			'trust_intro'             => self::field( 'Vertrauen: Intro', 'textarea' ),
			'trust_items'             => self::repeater(
				'Vertrauen: Argumente',
				array(
					'icon_key' => self::icon_field(),
					'title'    => self::field( 'Titel' ),
					'text'     => self::field( 'Text', 'textarea' ),
				)
			),
			'trust_stats'             => self::repeater(
				'Vertrauen: Kennzahlen',
				array(
					'value' => self::field( 'Wert' ),
					'label' => self::field( 'Bezeichnung' ),
				)
			),
			'qualify_eyebrow'         => self::field( 'Vorprüfung: Eyebrow' ),
			'qualify_title'           => self::field( 'Vorprüfung: Titel' ),
			'qualify_title_bold'      => self::field( 'Vorprüfung: hervorgehobener Titelteil' ),
			'qualify_intro'           => self::field( 'Vorprüfung: Intro', 'textarea' ),
			'qualify_situation_label' => self::field( 'Vorprüfung: Situation-Label' ),
			'qualify_situation_items' => self::repeater( 'Vorprüfung: Situationen', self::choice_subfields() ),
			'qualify_location_label'  => self::field( 'Vorprüfung: Standort-Label' ),
			'qualify_location_holder' => self::field( 'Vorprüfung: Standort-Platzhalter' ),
			'qualify_start_label'     => self::field( 'Vorprüfung: Start-Label' ),
			'qualify_start_items'     => self::repeater( 'Vorprüfung: Projektstart', self::choice_subfields() ),
			'qualify_scope_label'     => self::field( 'Vorprüfung: Umfang-Label' ),
			'qualify_scope_items'     => self::repeater( 'Vorprüfung: Umfang', self::choice_subfields() ),
			'qualify_cta_label'       => self::field( 'Vorprüfung: CTA-Text' ),
			'qualify_note'            => self::field( 'Vorprüfung: Hinweis', 'textarea' ),
			'faq_eyebrow'             => self::field( 'FAQ: Eyebrow' ),
			'faq_title'               => self::field( 'FAQ: Titel' ),
			'faq_title_bold'          => self::field( 'FAQ: hervorgehobener Titelteil' ),
			'faq_items'               => self::repeater(
				'FAQ-Einträge',
				array(
					'question' => self::field( 'Frage' ),
					'answer'   => self::field( 'Antwort', 'textarea' ),
				)
			),
			'final_title'             => self::field( 'Final CTA: Titel' ),
			'final_title_bold'        => self::field( 'Final CTA: hervorgehobener Titelteil' ),
			'final_text'              => self::field( 'Final CTA: Text', 'textarea' ),
			'final_cta_label'         => self::field( 'Final CTA: Button' ),
			'final_contact_prefix'    => self::field( 'Final CTA: Kontakt-Einleitung' ),
			'modal_eyebrow'           => self::field( 'Modal: Eyebrow' ),
			'modal_title'             => self::field( 'Modal: Titel' ),
			'modal_intro'             => self::field( 'Modal: Intro', 'textarea' ),
		);
		return $fields;
	}

	private static function simple_page_fields( $include_secondary ) {
		$fields = array(
			'page_eyebrow'    => self::field( 'Eyebrow' ),
			'page_headline'   => self::field( 'Überschrift' ),
			'page_text'       => self::field( 'Text', 'textarea' ),
			'page_cta_label'  => self::field( 'Primärer Button' ),
			'page_cta_target' => self::field( 'Primäres Ziel', 'page_reference' ),
		);
		if ( $include_secondary ) {
			$fields['page_secondary_label']  = self::field( 'Sekundärer Button' );
			$fields['page_secondary_target'] = self::field( 'Sekundäres Ziel', 'page_reference' );
		}
		return $fields;
	}

	private static function legal_fields() {
		return array(
			'page_eyebrow'  => self::field( 'Eyebrow' ),
			'page_headline' => self::field( 'Überschrift' ),
			'page_content'  => self::field( 'Inhalt', 'editor' ),
		);
	}

	private static function link_subfields() {
		return array(
			'label'  => self::field( 'Bezeichnung' ),
			'target' => self::field( 'WordPress-Ziel', 'page_reference' ),
		);
	}

	private static function choice_subfields() {
		return array(
			'label' => self::field( 'Bezeichnung' ),
			'value' => self::field( 'Technischer Wert' ),
		);
	}

	private static function icon_field() {
		return self::field(
			'Icon',
			'select',
			array(
				'choices' => array(
					'software' => 'Software',
					'server'   => 'IT / Server',
					'phone'    => 'Telefonie',
					'security' => 'Sicherheit',
					'support'  => 'Support',
					'check'    => 'Check',
				),
			)
		);
	}

	private static function repeater( $label, $sub_fields ) {
		return self::field( $label, 'repeater', array( 'sub_fields' => $sub_fields ) );
	}

	private static function field( $label, $type = 'text', $extra = array() ) {
		return array_merge(
			array(
				'label' => $label,
				'type'  => $type,
			),
			$extra
		);
	}
}
