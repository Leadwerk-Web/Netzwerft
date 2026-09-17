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
			if ( ! is_array( $map ) || empty( $map['email'] ) ) {
				$map = self::map_existing_fields( $existing_id );
			}
			$map = self::ensure_software_field( $existing_id, $map );
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
		$field_map = self::ensure_software_field( $form_id, $field_map );
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

		$notifications      = $form_data['settings']['notifications'] ?? array();
		$notifications      = is_array( $notifications ) ? $notifications : array();
		$notification_id    = array_key_first( $notifications );
		$notification_email = sanitize_email( leadwerk_get_option( 'notification_email', 'vertrieb@dienetzwerft.de' ) );
		$sender_email       = sanitize_email( apply_filters( 'leadwerk_wpforms_sender_email', 'kontakt@dienetzwerft.de' ) );
		$sender_name        = sanitize_text_field( leadwerk_get_option( 'company_name', 'die netzwerft GmbH' ) );
		if ( null === $notification_id ) {
			$notification_id                   = '1';
			$notifications[ $notification_id ] = array(
				'email'          => $notification_email,
				'subject'        => 'Neuer Eintrag: T2med Erstgespräch',
				'sender_name'    => $sender_name,
				'sender_address' => $sender_email,
				'message'        => '{all_fields}',
				'template'       => 'default',
			);
		}

		$desired = array(
			'email'          => $notification_email,
			'sender_name'    => $sender_name,
			'sender_address' => $sender_email,
			'replyto'        => sprintf( '{field_id="%d"}', $email_field_id ),
		);
		$changed = false;
		foreach ( $desired as $key => $value ) {
			if ( ( $notifications[ $notification_id ][ $key ] ?? '' ) !== $value ) {
				$notifications[ $notification_id ][ $key ] = $value;
				$changed                                        = true;
			}
		}
		if ( ! $changed ) {
			return;
		}
		$form_data['settings']['notifications'] = $notifications;
		if ( ! $form_handler->update( $form_id, $form_data ) ) {
			throw new RuntimeException( 'WPForms-Benachrichtigung konnte nicht gespeichert werden.' );
		}
	}

	/**
	 * Add the current-software field after Situation and show it only for
	 * Softwarewechsel / T2med or Praxisübernahme.
	 *
	 * @param int   $form_id   WPForms form ID.
	 * @param array $field_map Existing field map.
	 * @return array Updated field map.
	 */
	private static function ensure_software_field( $form_id, $field_map ) {
		$form_handler = function_exists( 'wpforms' ) ? wpforms()->obj( 'form' ) : null;
		if ( ! $form_handler ) {
			throw new RuntimeException( 'WPForms-Formularverwaltung ist nicht verfügbar.' );
		}
		$form_data = $form_handler->get( $form_id, array( 'content_only' => true ) );
		if ( ! is_array( $form_data ) || empty( $form_data['fields'] ) || ! is_array( $form_data['fields'] ) ) {
			throw new RuntimeException( 'WPForms-Formulardaten konnten nicht gelesen werden.' );
		}

		$definitions   = self::field_definitions();
		$software_def  = $definitions['current_software'];
		$situation_id  = self::find_mapped_field_id( $form_data, $field_map, 'situation' );
		$software_id   = self::find_mapped_field_id( $form_data, $field_map, 'current_software' );
		$choice_ids    = self::situation_choice_ids( $form_data, $situation_id );
		$conditionals  = self::software_conditionals( $situation_id, $choice_ids );
		$software_key  = $software_id ? self::field_array_key( $form_data['fields'], $software_id ) : null;
		$changed       = false;

		if ( null === $software_key ) {
			$software_id  = absint( $form_data['field_id'] ?? 0 );
			$max_existing = 0;
			foreach ( array_keys( $form_data['fields'] ) as $existing_id ) {
				$max_existing = max( $max_existing, absint( $existing_id ) );
			}
			if ( $software_id <= $max_existing ) {
				$software_id = $max_existing + 1;
			}
			$software_key                   = (string) $software_id;
			$form_data['field_id']          = $software_id + 1;
			$form_data['fields']            = self::insert_field_after(
				$form_data['fields'],
				$situation_id,
				$software_key,
				self::software_field_payload( $software_key, $software_def, $conditionals )
			);
			$changed = true;
		} else {
			$current = $form_data['fields'][ $software_key ];
			$desired = array_merge(
				$current,
				array(
					'label'             => $software_def['label'],
					'required'          => '1',
					'placeholder'       => $software_def['placeholder'],
					'conditional_logic' => '1',
					'conditional_type'  => 'show',
					'conditionals'      => $conditionals,
				)
			);
			if ( $current !== $desired ) {
				$form_data['fields'][ $software_key ] = $desired;
				$changed                                = true;
			}
			$reordered = self::move_field_after( $form_data['fields'], $software_key, $situation_id );
			if ( array_keys( $reordered ) !== array_keys( $form_data['fields'] ) ) {
				$form_data['fields'] = $reordered;
				$changed             = true;
			}
		}

		$field_map['current_software'] = absint( $software_id );
		if ( $changed && ! $form_handler->update( $form_id, $form_data ) ) {
			throw new RuntimeException( 'WPForms-Feld für die aktuelle Praxissoftware konnte nicht gespeichert werden.' );
		}
		return $field_map;
	}

	private static function find_mapped_field_id( $form_data, $field_map, $key ) {
		$mapped = absint( $field_map[ $key ] ?? 0 );
		if ( $mapped && self::field_array_key( $form_data['fields'], $mapped ) ) {
			return $mapped;
		}
		$label = self::field_definitions()[ $key ]['label'] ?? '';
		foreach ( $form_data['fields'] as $field ) {
			if ( $label && (string) ( $field['label'] ?? '' ) === $label ) {
				return absint( $field['id'] ?? 0 );
			}
		}
		if ( 'situation' === $key ) {
			throw new RuntimeException( 'Das WPForms-Feld Situation fehlt.' );
		}
		return 0;
	}

	private static function field_array_key( $fields, $field_id ) {
		foreach ( array_keys( $fields ) as $key ) {
			if ( absint( $key ) === absint( $field_id ) ) {
				return $key;
			}
		}
		return null;
	}

	private static function situation_choice_ids( $form_data, $situation_id ) {
		$key    = self::field_array_key( $form_data['fields'], $situation_id );
		$field  = $key ? $form_data['fields'][ $key ] : array();
		$wanted = array(
			'softwarewechsel'  => null,
			'praxisuebernahme' => null,
		);
		foreach ( (array) ( $field['choices'] ?? array() ) as $choice_id => $choice ) {
			$label = (string) ( is_array( $choice ) ? ( $choice['label'] ?? '' ) : '' );
			if ( false !== stripos( $label, 'Softwarewechsel' ) ) {
				$wanted['softwarewechsel'] = (string) $choice_id;
			}
			if ( false !== stripos( $label, 'Praxisübernahme' ) ) {
				$wanted['praxisuebernahme'] = (string) $choice_id;
			}
		}
		if ( ! $wanted['softwarewechsel'] || ! $wanted['praxisuebernahme'] ) {
			throw new RuntimeException( 'Die Situations-Optionen Softwarewechsel oder Praxisübernahme fehlen.' );
		}
		return $wanted;
	}

	private static function software_conditionals( $situation_id, $choice_ids ) {
		$rules = array();
		foreach ( $choice_ids as $choice_id ) {
			$rules[] = array(
				array(
					'field'    => (string) $situation_id,
					'operator' => '==',
					'value'    => (string) $choice_id,
				),
			);
		}
		return $rules;
	}

	private static function software_field_payload( $field_id, $definition, $conditionals ) {
		return array(
			'id'                => (string) $field_id,
			'type'              => 'text',
			'label'             => $definition['label'],
			'description'       => '',
			'required'          => '1',
			'size'              => 'medium',
			'placeholder'       => $definition['placeholder'],
			'limit_count'       => '1',
			'limit_mode'        => 'characters',
			'default_value'     => '',
			'input_mask'        => '',
			'css'               => '',
			'conditional_logic' => '1',
			'conditional_type'  => 'show',
			'conditionals'      => $conditionals,
		);
	}

	private static function insert_field_after( $fields, $after_id, $new_key, $new_field ) {
		$out     = array();
		$placed  = false;
		foreach ( $fields as $key => $field ) {
			$out[ $key ] = $field;
			if ( absint( $key ) === absint( $after_id ) ) {
				$out[ $new_key ] = $new_field;
				$placed          = true;
			}
		}
		if ( ! $placed ) {
			$out[ $new_key ] = $new_field;
		}
		return $out;
	}

	private static function move_field_after( $fields, $field_key, $after_id ) {
		if ( ! isset( $fields[ $field_key ] ) ) {
			return $fields;
		}
		$moving = $fields[ $field_key ];
		unset( $fields[ $field_key ] );
		return self::insert_field_after( $fields, $after_id, $field_key, $moving );
	}

	private static function field_definitions() {
		return array(
			'situation'         => array(
				'type'          => 'radio',
				'label'         => 'Situation',
				'required'      => true,
				'input_columns' => '2',
				'choices'       => self::choices( array( 'Softwarewechsel / T2med', 'Praxisgründung', 'Praxisübernahme', 'Laufende Betreuung' ) ),
			),
			'current_software'  => array(
				'type'        => 'text',
				'label'       => 'Welche Praxisverwaltungssoftware ist aktuell im Einsatz?',
				'required'    => true,
				'placeholder' => 'z. B. Medistar, CGM, isynet, tomedo',
			),
			'location'          => array(
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
