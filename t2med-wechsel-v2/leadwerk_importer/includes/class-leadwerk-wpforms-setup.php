<?php
/**
 * WPForms setup through the official WordPress Abilities API.
 *
 * @package Leadwerk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_WPForms_Setup {
	public static function ensure_form() {
		$existing_id = absint( leadwerk_get_option( 'wpforms_form_id', 0 ) );
		if ( $existing_id && 'wpforms' === get_post_type( $existing_id ) && 'trash' !== get_post_status( $existing_id ) ) {
			$map = leadwerk_get_option( 'wpforms_field_map', array() );
			if ( is_array( $map ) && ! empty( $map['email'] ) ) {
				self::clear_choice_defaults( $existing_id, $map );
				self::ensure_notification_reply_to( $existing_id, $map );
				return array(
					'form_id'   => $existing_id,
					'field_map' => $map,
					'created'   => false,
				);
			}
			$map = self::map_existing_fields( $existing_id );
			self::clear_choice_defaults( $existing_id, $map );
			self::ensure_notification_reply_to( $existing_id, $map );
			leadwerk_update_field( 'wpforms_field_map', $map, 'option' );
			return array(
				'form_id'   => $existing_id,
				'field_map' => $map,
				'created'   => false,
			);
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'wpforms/create-form' ) : null;
		if ( ! $ability ) {
			throw new RuntimeException( 'WPForms-Fähigkeit wpforms/create-form ist nicht verfügbar.' );
		}

		$definitions = self::field_definitions();
		$fields      = array_values( $definitions );
		add_filter( 'wpforms_integrations_abilities_allow_write', '__return_true', 1000 );
		$result = $ability->execute(
			array(
				'title'    => 'T2med Erstgespräch',
				'fields'   => $fields,
				'settings' => array(
					'form_title'  => 'T2med Erstgespräch',
					'form_desc'   => 'Vorprüfung und Kontaktdaten für ein persönliches Erstgespräch.',
					'submit_text' => 'Nachricht senden',
				),
			)
		);
		remove_filter( 'wpforms_integrations_abilities_allow_write', '__return_true', 1000 );

		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( 'WPForms konnte das Formular nicht erstellen: ' . $result->get_error_message() );
		}
		$form_id = absint( $result['form_id'] ?? 0 );
		if ( ! $form_id || 'wpforms' !== get_post_type( $form_id ) ) {
			throw new RuntimeException( 'WPForms lieferte keine gültige Formular-ID.' );
		}

		$keys       = array_keys( $definitions );
		$field_map  = array();
		$field_rows = is_array( $result['fields'] ?? null ) ? array_values( $result['fields'] ) : array();
		foreach ( $keys as $index => $key ) {
			$field_map[ $key ] = absint( $field_rows[ $index ]['id'] ?? $index );
		}
		self::clear_choice_defaults( $form_id, $field_map );
		self::ensure_notification_reply_to( $form_id, $field_map );
		leadwerk_update_field( 'wpforms_form_id', $form_id, 'option' );
		leadwerk_update_field( 'wpforms_field_map', $field_map, 'option' );
		return array(
			'form_id'   => $form_id,
			'field_map' => $field_map,
			'created'   => true,
		);
	}

	private static function map_existing_fields( $form_id ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'wpforms/get-form' ) : null;
		if ( ! $ability ) {
			throw new RuntimeException( 'WPForms-Fähigkeit wpforms/get-form ist nicht verfügbar.' );
		}
		$result = $ability->execute(
			array(
				'form_id'        => $form_id,
				'include_fields' => true,
			)
		);
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}
		$label_map = array();
		foreach ( self::field_definitions() as $key => $definition ) {
			$label_map[ $definition['label'] ] = $key;
		}
		$map = array();
		foreach ( $result['fields'] ?? array() as $field ) {
			$label = (string) ( $field['label'] ?? '' );
			if ( isset( $label_map[ $label ] ) ) {
				$map[ $label_map[ $label ] ] = absint( $field['id'] ?? 0 );
			}
		}
		if ( empty( $map['email'] ) ) {
			throw new RuntimeException( 'Vorhandenes WPForms-Formular hat nicht die erwartete T2med-Feldstruktur.' );
		}
		return $map;
	}

	/**
	 * Clear defaults inherited from WPForms' new-field templates.
	 *
	 * The create-form ability deliberately preserves internal choice metadata
	 * while replacing labels. That metadata can include template defaults, which
	 * would preselect a radio option or even the privacy checkbox. The importer
	 * removes only the `default` flag after the official ability created the form.
	 */
	private static function clear_choice_defaults( $form_id, $field_map ) {
		$form_handler = function_exists( 'wpforms' ) ? wpforms()->obj( 'form' ) : null;
		if ( ! $form_handler ) {
			throw new RuntimeException( 'WPForms-Formularverwaltung ist nicht verfügbar.' );
		}
		$form_data = $form_handler->get( $form_id, array( 'content_only' => true ) );
		if ( ! is_array( $form_data ) || empty( $form_data['fields'] ) ) {
			throw new RuntimeException( 'WPForms-Formulardaten konnten nicht gelesen werden.' );
		}
		$changed = false;
		foreach ( array( 'situation', 'start', 'scope', 'consent' ) as $key ) {
			$field_id = absint( $field_map[ $key ] ?? 0 );
			if ( ! isset( $form_data['fields'][ $field_id ]['choices'] ) || ! is_array( $form_data['fields'][ $field_id ]['choices'] ) ) {
				continue;
			}
			foreach ( $form_data['fields'][ $field_id ]['choices'] as &$choice ) {
				if ( is_array( $choice ) && array_key_exists( 'default', $choice ) ) {
					unset( $choice['default'] );
					$changed = true;
				}
			}
			unset( $choice );
		}
		if ( ! $changed ) {
			return;
		}
		if ( ! $form_handler->update( $form_id, $form_data ) ) {
			throw new RuntimeException( 'WPForms-Standardauswahlen konnten nicht bereinigt werden.' );
		}
	}

	/**
	 * Route replies to the address entered in the T2med email field.
	 */
	private static function ensure_notification_reply_to( $form_id, $field_map ) {
		$email_field_id = absint( $field_map['email'] ?? 0 );
		if ( ! $email_field_id ) {
			throw new RuntimeException( 'Das WPForms-E-Mail-Feld für Reply-To fehlt.' );
		}

		$form_handler = function_exists( 'wpforms' ) ? wpforms()->obj( 'form' ) : null;
		if ( ! $form_handler ) {
			throw new RuntimeException( 'WPForms-Formularverwaltung ist nicht verfügbar.' );
		}
		$form_data = $form_handler->get( $form_id, array( 'content_only' => true ) );
		if ( ! is_array( $form_data ) ) {
			throw new RuntimeException( 'WPForms-Benachrichtigungen konnten nicht gelesen werden.' );
		}

		$notifications   = $form_data['settings']['notifications'] ?? array();
		$notifications   = is_array( $notifications ) ? $notifications : array();
		$notification_id = array_key_first( $notifications );
		if ( null === $notification_id ) {
			$notification_id                   = '1';
			$notifications[ $notification_id ] = array(
				'email'          => sanitize_email( leadwerk_get_option( 'notification_email', 'info@dienetzwerft.de' ) ),
				'subject'        => 'Neuer Eintrag: T2med Erstgespräch',
				'sender_name'    => 'Netzwerft',
				'sender_address' => '{admin_email}',
				'message'        => '{all_fields}',
				'template'       => 'default',
			);
		}

		$reply_to = sprintf( '{field_id="%d"}', $email_field_id );
		if ( ( $notifications[ $notification_id ]['replyto'] ?? '' ) === $reply_to ) {
			return;
		}
		$notifications[ $notification_id ]['replyto'] = $reply_to;
		$form_data['settings']['notifications']       = $notifications;
		if ( ! $form_handler->update( $form_id, $form_data ) ) {
			throw new RuntimeException( 'WPForms Reply-To konnte nicht gespeichert werden.' );
		}
	}

	private static function field_definitions() {
		return array(
			'situation' => array(
				'type'          => 'radio',
				'label'         => 'Situation',
				'required'      => true,
				'input_columns' => '2',
				'choices'       => self::choices( array( 'Softwarewechsel / T2med', 'Praxisgründung', 'Praxisübernahme', 'Laufende Betreuung' ) ),
			),
			'location'  => array(
				'type'        => 'text',
				'label'       => 'PLZ / Ort',
				'required'    => true,
				'placeholder' => 'z. B. 76275 Ettlingen',
			),
			'start'     => array(
				'type'          => 'radio',
				'label'         => 'Projektstart',
				'required'      => true,
				'input_columns' => '2',
				'choices'       => self::choices( array( 'sofort', '1–3 Monate', '3–6 Monate', 'später' ) ),
			),
			'scope'     => array(
				'type'          => 'radio',
				'label'         => 'Umfang',
				'required'      => false,
				'input_columns' => '2',
				'choices'       => self::choices( array( 'Nur T2med', 'T2med + IT & Telefonie', 'Noch unklar' ) ),
			),
			'name'      => array(
				'type'        => 'text',
				'label'       => 'Name',
				'required'    => true,
				'placeholder' => 'Vor- und Nachname',
			),
			'email'     => array(
				'type'        => 'email',
				'label'       => 'E-Mail',
				'required'    => true,
				'placeholder' => 'name@praxis.de',
			),
			'phone'     => array(
				'type'        => 'text',
				'label'       => 'Telefon',
				'required'    => true,
				'placeholder' => 'Telefonnummer',
			),
			'practice'  => array(
				'type'        => 'text',
				'label'       => 'Praxisname',
				'required'    => false,
				'placeholder' => 'Optional',
			),
			'message'   => array(
				'type'        => 'textarea',
				'label'       => 'Nachricht',
				'required'    => false,
				'placeholder' => 'Was sollten wir vorab wissen?',
			),
			'consent'   => array(
				'type'     => 'checkbox',
				'label'    => 'Datenschutz',
				'required' => true,
				'choices'  => self::choices( array( 'Ich habe die Datenschutzerklärung gelesen und stimme der Verarbeitung meiner Angaben zur Bearbeitung der Anfrage zu.' ) ),
			),
			'source'    => array(
				'type'          => 'text',
				'label'         => 'CTA-Quelle',
				'required'      => false,
				'default_value' => 't2med-landingpage',
			),
		);
	}

	private static function choices( $labels ) {
		return array_map(
			static fn( $label ) => array(
				'label' => $label,
				'value' => $label,
			),
			$labels
		);
	}
}
