<?php
/**
 * Import preflight.
 *
 * @package Leadwerk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Import_Preflight {
	public static function run( $manifest ) {
		$errors   = array();
		$warnings = array();

		if ( version_compare( get_bloginfo( 'version' ), '6.9', '<' ) ) {
			$errors[] = 'WordPress 6.9 oder neuer ist erforderlich.';
		}
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			$errors[] = 'PHP 8.1 oder neuer ist erforderlich.';
		}
		foreach ( array( 'dom', 'json', 'fileinfo' ) as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				$errors[] = 'PHP-Erweiterung fehlt: ' . $extension;
			}
		}
		if ( ! class_exists( 'Leadwerk_Content_Schema' ) || ! function_exists( 'leadwerk_update_field' ) ) {
			$errors[] = 'Leadwerk Fields 2.0+ muss aktiv sein.';
		}
		if ( ! defined( 'LEADWERK_WPML_CLONE_VERSION' ) ) {
			$errors[] = 'Leadwerk WPML Clone 3.0+ muss aktiv sein.';
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'pll_languages_list' ) ) {
			$errors[] = 'Offizielles WPML/Polylang darf nicht gleichzeitig mit Leadwerk WPML Clone das Routing verwalten.';
		}
		$theme = wp_get_theme();
		if ( ! in_array( $theme->get_stylesheet(), array( 'leadwerk_theme', 'leadwerk-theme' ), true ) && 'leadwerk-t2med' !== sanitize_title( $theme->get( 'TextDomain' ) ) ) {
			$errors[] = 'Das aktive Theme muss Leadwerk T2med sein (Ordner: leadwerk_theme).';
		}

		$wpforms_version = defined( 'WPFORMS_VERSION' ) ? WPFORMS_VERSION : '';
		if ( '' === $wpforms_version || version_compare( $wpforms_version, '1.10.2', '<' ) ) {
			$errors[] = 'WPForms Lite/Pro 1.10.2 oder neuer muss aktiv sein.';
		}
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$errors[] = 'WordPress Abilities API ist nicht verfügbar.';
		} elseif ( ! wp_get_ability( 'wpforms/create-form' ) ) {
			$errors[] = 'WPForms-Fähigkeit wpforms/create-form ist nicht registriert.';
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			$errors[] = 'Upload-Verzeichnis: ' . $uploads['error'];
		} elseif ( ! wp_is_writable( $uploads['basedir'] ) ) {
			$errors[] = 'Upload-Verzeichnis ist nicht beschreibbar.';
		}

		$source = LEADWERK_IMPORTER_PATH . ltrim( $manifest['source']['path'] ?? '', '/' );
		if ( ! is_file( $source ) ) {
			$errors[] = 'T2med HTML-Snapshot fehlt.';
		} elseif ( ! hash_equals( (string) ( $manifest['source']['sha256'] ?? '' ), hash_file( 'sha256', $source ) ) ) {
			$errors[] = 'Checksum des T2med HTML-Snapshots stimmt nicht.';
		}

		foreach ( $manifest['media'] ?? array() as $item ) {
			$path = LEADWERK_IMPORTER_PATH . ltrim( $item['path'] ?? '', '/' );
			if ( ! is_file( $path ) ) {
				$errors[] = 'Medium fehlt: ' . ( $item['path'] ?? '(unbekannt)' );
				continue;
			}
			if ( ! hash_equals( (string) ( $item['sha256'] ?? '' ), hash_file( 'sha256', $path ) ) ) {
				$errors[] = 'Checksum stimmt nicht: ' . $item['path'];
			}
		}

		$theme_dir = get_template_directory();
		foreach ( array( 'styles-v2.css', 'landing-t2med-v2.css' ) as $stylesheet ) {
			if ( ! is_file( $theme_dir . '/assets/css/' . $stylesheet ) ) {
				$errors[] = 'Theme-Stylesheet fehlt: ' . $stylesheet;
			}
		}
		foreach ( array( 'lenis.min.js', 'scroll-smooth.js', 'main.js', 'hero-particles.js', 't2med.js' ) as $script ) {
			if ( ! is_file( $theme_dir . '/assets/js/' . $script ) ) {
				$errors[] = 'Theme-Script fehlt: ' . $script;
			}
		}

		$discovery = Leadwerk_Media_Importer::discover_references( $source, $theme_dir . '/assets/css' );
		if ( ! empty( $discovery['missing'] ) ) {
			$errors[] = 'Nicht auflösbare lokale Medien: ' . implode( ', ', $discovery['missing'] );
		}
		if ( 'pending' === leadwerk_get_option( 'legal_review_status', 'pending' ) ) {
			$warnings[] = 'Rechtliche Prüfung von Impressum/Datenschutz ist noch offen.';
		}

		return array(
			'ok'       => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
			'media'    => $discovery['found'],
		);
	}
}
