<?php
/**
 * Resumable, journaled T2med importer.
 *
 * @package Leadwerk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Importer {
	const JOURNAL_OPTION = 'leadwerk_t2med_import_journal';
	const NOTICE_OPTION  = 'leadwerk_t2med_legal_review_notice';

	private static $stages           = array( 'preflight', 'media', 'pages', 'fields', 'wpforms', 'links', 'seo', 'front_page', 'validate', 'complete' );
	private static $syncing_home_seo = false;

	public static function manifest() {
		$file = LEADWERK_IMPORTER_PATH . 'manifest/import-manifest.json';
		$data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException( 'Import-Manifest ist ungültig.' );
		}
		return $data;
	}

	public static function start( $dry_run, $force ) {
		$existing = get_option( self::JOURNAL_OPTION, array() );
		if ( is_array( $existing ) && 'running' === ( $existing['status'] ?? '' ) ) {
			return $existing;
		}
		$journal = array(
			'run_id'              => wp_generate_uuid4(),
			'started_at'          => current_time( 'mysql', true ),
			'status'              => 'running',
			'dry_run'             => (bool) $dry_run,
			'force'               => (bool) $force,
			'stage_index'         => 0,
			'logs'                => array(),
			'warnings'            => array(),
			'conflicts'           => array(),
			'created_posts'       => array(),
			'created_attachments' => array(),
			'post_snapshots'      => array(),
			'option_snapshots'    => array(),
			'media_map'           => array(),
			'page_map'            => array(),
		);
		update_option( self::JOURNAL_OPTION, $journal, false );
		return $journal;
	}

	public static function current() {
		return get_option( self::JOURNAL_OPTION, array() );
	}

	public static function run_next() {
		$journal = self::current();
		if ( ! is_array( $journal ) || 'running' !== ( $journal['status'] ?? '' ) ) {
			throw new RuntimeException( 'Kein fortsetzbarer Import vorhanden.' );
		}
		$index = absint( $journal['stage_index'] ?? 0 );
		$stage = self::$stages[ $index ] ?? 'complete';
		try {
			self::run_stage( $stage, $journal );
			$journal['logs'][]      = sprintf( '[%s] %s abgeschlossen.', current_time( 'H:i:s' ), $stage );
			$journal['stage_index'] = $index + 1;
			if ( 'complete' === $stage ) {
				$journal['status']       = 'complete';
				$journal['completed_at'] = current_time( 'mysql', true );
			}
			update_option( self::JOURNAL_OPTION, $journal, false );
			return $journal;
		} catch ( Throwable $error ) {
			$journal['status'] = 'failed';
			$journal['error']  = $error->getMessage();
			$journal['logs'][] = sprintf( '[%s] FEHLER: %s', current_time( 'H:i:s' ), $error->getMessage() );
			update_option( self::JOURNAL_OPTION, $journal, false );
			if ( empty( $journal['dry_run'] ) ) {
				self::rollback( $journal );
				$journal = self::current();
			}
			return $journal;
		}
	}

	private static function run_stage( $stage, &$journal ) {
		$manifest = self::manifest();
		$dry_run  = ! empty( $journal['dry_run'] );
		switch ( $stage ) {
			case 'preflight':
				$result              = Leadwerk_Import_Preflight::run( $manifest );
				$journal['warnings'] = array_values( array_unique( array_merge( $journal['warnings'], $result['warnings'] ) ) );
				if ( ! $result['ok'] ) {
					throw new RuntimeException( implode( ' ', $result['errors'] ) );
				}
				break;
			case 'media':
				if ( ! $dry_run ) {
					$journal['media_map'] = Leadwerk_Media_Importer::import_manifest( $manifest['media'], $journal );
				}
				break;
			case 'pages':
				if ( $dry_run ) {
					self::validate_manifest_pages( $manifest );
				} else {
					self::import_pages( $manifest, $journal );
				}
				break;
			case 'fields':
				self::validate_seed_schema();
				if ( ! $dry_run ) {
					self::import_fields( $journal );
				}
				break;
			case 'wpforms':
				if ( ! $dry_run ) {
					self::snapshot_option( 'leadwerk_opt_wpforms_form_id', $journal );
					self::snapshot_option( 'leadwerk_opt_wpforms_field_map', $journal );
					$form = Leadwerk_WPForms_Setup::ensure_form();
					if ( ! empty( $form['created'] ) ) {
						$journal['created_posts'][] = (int) $form['form_id'];
					}
				}
				break;
			case 'links':
				self::validate_seed_references();
				if ( ! $dry_run ) {
					self::resolve_all_references( $journal );
				}
				break;
			case 'seo':
				if ( ! $dry_run ) {
					self::apply_site_settings( $manifest, $journal );
				}
				break;
			case 'front_page':
				if ( ! $dry_run ) {
					self::snapshot_option( 'show_on_front', $journal );
					self::snapshot_option( 'page_on_front', $journal );
					update_option( 'show_on_front', 'page' );
					update_option( 'page_on_front', absint( $journal['page_map']['nw-t2med-home-v2'] ?? 0 ) );
					flush_rewrite_rules( false );
				}
				break;
			case 'validate':
				self::validate_result( $journal, $dry_run );
				break;
			case 'complete':
				if ( ! $dry_run ) {
					update_option( self::NOTICE_OPTION, '1', false );
				}
				break;
		}
	}

	private static function import_pages( $manifest, &$journal ) {
		foreach ( $manifest['pages'] as $item ) {
			$key      = sanitize_key( $item['source_key'] );
			$existing = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'key'   => 'leadwerk_source_key',
							'value' => $key,
						),
						array(
							'key'   => '_leadwerk_language',
							'value' => 'de',
						),
					),
				)
			);
			if ( $existing ) {
				$post_id = (int) $existing[0];
				self::snapshot_post( $post_id, $journal );
				$current_hash = self::post_identity_hash( get_post( $post_id ) );
				$last_hash    = (string) get_post_meta( $post_id, '_leadwerk_import_post_hash', true );
				if ( ! empty( $journal['force'] ) || ( $last_hash && hash_equals( $last_hash, $current_hash ) ) ) {
					wp_update_post(
						array(
							'ID'          => $post_id,
							'post_title'  => sanitize_text_field( $item['title'] ),
							'post_status' => sanitize_key( $item['status'] ),
							'post_name'   => sanitize_title( $item['slug'] ),
						)
					);
				} elseif ( ! $last_hash ) {
					$journal['conflicts'][] = $key . ': Seitentitel/Status bestehend – unverändert gelassen.';
				}
			} else {
				$post_id = wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_title'   => sanitize_text_field( $item['title'] ),
						'post_name'    => sanitize_title( $item['slug'] ),
						'post_status'  => sanitize_key( $item['status'] ),
						'post_content' => '',
					),
					true
				);
				if ( is_wp_error( $post_id ) ) {
					throw new RuntimeException( $post_id->get_error_message() );
				}
				$journal['created_posts'][] = (int) $post_id;
				update_post_meta( $post_id, 'leadwerk_source_key', $key );
			}
			if ( ! get_post_meta( $post_id, 'leadwerk_source_key', true ) ) {
				update_post_meta( $post_id, 'leadwerk_source_key', $key );
			}
			update_post_meta( $post_id, '_leadwerk_language', 'de' );
			update_post_meta( $post_id, '_leadwerk_translation_group', $key );
			update_post_meta( $post_id, '_leadwerk_translation_status', 'translated' );
			if ( ! empty( $item['noindex'] ) ) {
				update_post_meta( $post_id, '_leadwerk_noindex', '1' );
			}
			update_post_meta( $post_id, '_leadwerk_import_post_hash', self::post_identity_hash( get_post( $post_id ) ) );
			$journal['page_map'][ $key ] = (int) $post_id;
		}
	}

	private static function import_fields( &$journal ) {
		$pages = Leadwerk_T2med_Seed::pages();
		foreach ( $pages as $source_key => $fields ) {
			$post_id = absint( $journal['page_map'][ $source_key ] ?? 0 );
			if ( ! $post_id ) {
				throw new RuntimeException( 'Seite fehlt für Feldimport: ' . $source_key );
			}
			self::snapshot_post( $post_id, $journal );
			if ( 'nw-t2med-home-v2' === $source_key ) {
				$fields['hero_image']   = absint( $journal['media_map']['hero_image'] ?? 0 );
				$fields['video_poster'] = absint( $journal['media_map']['video_poster'] ?? 0 );
				$fields['og_image']     = absint( $journal['media_map']['hero_image'] ?? 0 );
			}
			foreach ( $fields as $name => $value ) {
				self::apply_post_field( $post_id, $name, $value, $journal );
			}
		}

		$options                 = Leadwerk_T2med_Seed::options();
		$options['brand_logo']   = absint( $journal['media_map']['brand_logo'] ?? 0 );
		$options['site_favicon'] = absint( $journal['media_map']['site_favicon'] ?? 0 );
		foreach ( $options as $name => $value ) {
			self::apply_option_field( $name, $value, $journal );
		}
	}

	private static function apply_post_field( $post_id, $name, $value, &$journal ) {
		$current      = Leadwerk_Fields_API::get_field( $name, $post_id );
		$hashes       = get_post_meta( $post_id, '_leadwerk_import_field_hashes', true );
		$hashes       = is_array( $hashes ) ? $hashes : array();
		$last         = (string) ( $hashes[ $name ] ?? '' );
		$current_hash = self::value_hash( $current );
		$empty        = null === $current || '' === $current || array() === $current || 0 === $current;
		if ( ! empty( $journal['force'] ) || $empty || ( $last && hash_equals( $last, $current_hash ) ) ) {
			leadwerk_update_field( $name, $value, $post_id );
			$hashes[ $name ] = self::value_hash( $value );
			update_post_meta( $post_id, '_leadwerk_import_field_hashes', $hashes );
		} else {
			$journal['conflicts'][] = get_post_meta( $post_id, 'leadwerk_source_key', true ) . ':' . $name;
		}
	}

	private static function apply_option_field( $name, $value, &$journal ) {
		$key = 'leadwerk_opt_' . sanitize_key( $name );
		self::snapshot_option( $key, $journal );
		self::snapshot_option( 'leadwerk_t2med_option_hashes', $journal );
		$hashes       = get_option( 'leadwerk_t2med_option_hashes', array() );
		$hashes       = is_array( $hashes ) ? $hashes : array();
		$current      = Leadwerk_Fields_API::get_field( $name, 'option' );
		$current_hash = self::value_hash( $current );
		$last         = (string) ( $hashes[ $name ] ?? '' );
		$empty        = null === $current || '' === $current || array() === $current || 0 === $current;
		if ( ! empty( $journal['force'] ) || $empty || ( $last && hash_equals( $last, $current_hash ) ) ) {
			leadwerk_update_field( $name, $value, 'option' );
			$hashes[ $name ] = self::value_hash( $value );
			update_option( 'leadwerk_t2med_option_hashes', $hashes, false );
		} else {
			$journal['conflicts'][] = 'option:' . $name;
		}
	}

	private static function resolve_all_references( &$journal ) {
		foreach ( Leadwerk_T2med_Seed::pages() as $source_key => $fields ) {
			$post_id = absint( $journal['page_map'][ $source_key ] ?? 0 );
			foreach ( $fields as $name => $value ) {
				if ( self::contains_source_reference( $value ) ) {
					self::apply_post_field( $post_id, $name, self::resolve_value( $value, $journal['page_map'] ), $journal );
				}
			}
		}
		foreach ( Leadwerk_T2med_Seed::options() as $name => $value ) {
			if ( self::contains_source_reference( $value ) ) {
				self::apply_option_field( $name, self::resolve_value( $value, $journal['page_map'] ), $journal );
			}
		}
	}

	private static function resolve_value( $value, $page_map ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( isset( $value['source_key'] ) ) {
			$key = sanitize_key( $value['source_key'] );
			if ( empty( $page_map[ $key ] ) ) {
				throw new RuntimeException( 'Page-reference kann nicht aufgelöst werden: ' . $key );
			}
			return array(
				'post_id' => absint( $page_map[ $key ] ),
				'anchor'  => sanitize_title( (string) ( $value['anchor'] ?? '' ) ),
			);
		}
		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::resolve_value( $child, $page_map );
		}
		return $value;
	}

	private static function contains_source_reference( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( isset( $value['source_key'] ) ) {
			return true;
		}
		foreach ( $value as $child ) {
			if ( self::contains_source_reference( $child ) ) {
				return true;
			}
		}
		return false;
	}

	private static function apply_site_settings( $manifest, &$journal ) {
		foreach ( array( 'blogname', 'blogdescription' ) as $option ) {
			self::snapshot_option( $option, $journal );
		}
		update_option( 'blogname', sanitize_text_field( $manifest['site_title'] ) );
		update_option( 'blogdescription', sanitize_text_field( $manifest['site_tagline'] ) );
		foreach ( $manifest['pages'] as $item ) {
			$post_id = absint( $journal['page_map'][ $item['source_key'] ] ?? 0 );
			if ( $post_id && ! empty( $item['noindex'] ) ) {
				update_post_meta( $post_id, '_leadwerk_noindex', '1' );
			}
		}
		$home_id = absint( $journal['page_map']['nw-t2med-home-v2'] ?? 0 );
		if ( $home_id && ! self::sync_home_seo( $home_id ) ) {
			throw new RuntimeException( 'Yoast-SEO-Inhalte konnten nicht synchronisiert werden.' );
		}
	}

	/**
	 * Synchronize structured Leadwerk content with Yoast's post analysis model.
	 *
	 * The theme renders structured fields rather than post_content. Keeping a
	 * semantic content mirror lets Yoast analyze the same headings, copy, images
	 * and links that visitors receive without rendering the content twice.
	 */
	public static function sync_home_seo( $post_id ) {
		$post_id = absint( $post_id );
		if ( self::$syncing_home_seo || ! $post_id || 'nw-t2med-home-v2' !== get_post_meta( $post_id, 'leadwerk_source_key', true ) ) {
			return true;
		}

		$focus       = sanitize_text_field( leadwerk_get_field( 'seo_focus_keyphrase', $post_id, 'T2med Wechsel Karlsruhe' ) );
		$title       = sanitize_text_field( leadwerk_get_field( 'seo_title', $post_id, '' ) );
		$description = sanitize_text_field( leadwerk_get_field( 'seo_description', $post_id, '' ) );
		update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus );
		update_post_meta( $post_id, '_yoast_wpseo_title', $title );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );

		foreach (
			array(
				'hero_image'   => 'hero_image_alt',
				'video_poster' => 'video_poster_alt',
			) as $image_field => $alt_field
		) {
			$image_id = absint( leadwerk_get_field( $image_field, $post_id, 0 ) );
			$alt      = sanitize_text_field( leadwerk_get_field( $alt_field, $post_id, '' ) );
			if ( $image_id && $alt ) {
				update_post_meta( $image_id, '_wp_attachment_image_alt', $alt );
			}
		}

		$content = self::build_home_analysis_content( $post_id, $focus );
		if ( (string) get_post_field( 'post_content', $post_id ) === $content ) {
			return true;
		}

		self::$syncing_home_seo = true;
		$result                 = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			),
			true
		);
		self::$syncing_home_seo = false;
		return ! is_wp_error( $result );
	}

	/**
	 * Get the semantic homepage content supplied to YoastSEO.js.
	 *
	 * @param int $post_id Homepage post ID.
	 * @return string
	 */
	public static function get_home_analysis_content( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || 'nw-t2med-home-v2' !== get_post_meta( $post_id, 'leadwerk_source_key', true ) ) {
			return '';
		}

		$focus = sanitize_text_field( leadwerk_get_field( 'seo_focus_keyphrase', $post_id, 'T2med Wechsel Karlsruhe' ) );
		return self::build_home_analysis_content( $post_id, $focus );
	}

	private static function build_home_analysis_content( $post_id, $focus ) {
		$field = static function ( $name, $default = '' ) use ( $post_id ) {
			return leadwerk_get_field( $name, $post_id, $default );
		};
		$rows  = static function ( $name ) use ( $field ) {
			$value = $field( $name, array() );
			return is_array( $value ) ? $value : array();
		};

		$parts      = array();
		$hero_image = absint( $field( 'hero_image', 0 ) );
		$hero_url   = $hero_image ? wp_get_attachment_image_url( $hero_image, 'full' ) : '';
		$hero_alt   = sanitize_text_field( $field( 'hero_image_alt', $focus ) );
		$parts[]    = '<h1>' . esc_html( $focus ) . '</h1>';
		$parts[]    = '<p><strong>' . esc_html( $focus ) . '</strong> steht für einen planbaren Wechsel der Praxissoftware mit persönlicher Begleitung im Raum Karlsruhe. ' . esc_html( $field( 'hero_text' ) ) . '</p>';
		if ( $hero_url ) {
			$parts[] = '<figure><img src="' . esc_url( $hero_url ) . '" alt="' . esc_attr( $hero_alt ) . '"></figure>';
		}

		$parts[] = '<h2>' . esc_html( $focus ) . ': Praxissoftware, IT und Telefonie gemeinsam planen</h2>';
		$parts[] = '<p>' . esc_html( $field( 'problem_intro' ) ) . '</p>';
		foreach ( $rows( 'problem_items' ) as $item ) {
			$parts[] = '<h3>' . esc_html( $item['title'] ?? '' ) . '</h3><p>' . esc_html( $item['text'] ?? '' ) . '</p>';
		}

		$parts[] = '<h2>' . esc_html( trim( $field( 'solution_title' ) . ' ' . $field( 'solution_title_bold' ) ) ) . '</h2>';
		$parts[] = '<p>' . esc_html( $field( 'solution_intro' ) ) . '</p><ul>';
		foreach ( $rows( 'solution_items' ) as $item ) {
			$parts[] = '<li><strong>' . esc_html( $item['title'] ?? '' ) . ':</strong> ' . esc_html( $item['text'] ?? '' ) . '</li>';
		}
		$parts[] = '</ul>';

		$parts[] = '<h2>' . esc_html( trim( $field( 'video_title' ) . ' ' . $field( 'video_title_bold' ) ) ) . '</h2>';
		$parts[] = '<p>' . esc_html( $field( 'video_intro' ) ) . '</p><ul>';
		foreach ( $rows( 'video_items' ) as $item ) {
			$parts[] = '<li>' . esc_html( $item['text'] ?? '' ) . '</li>';
		}
		$parts[]    = '</ul>';
		$poster_id  = absint( $field( 'video_poster', 0 ) );
		$poster_url = $poster_id ? wp_get_attachment_image_url( $poster_id, 'full' ) : '';
		if ( $poster_url ) {
			$parts[] = '<figure><img src="' . esc_url( $poster_url ) . '" alt="' . esc_attr( sanitize_text_field( $field( 'video_poster_alt', $focus ) ) ) . '"></figure>';
		}

		$parts[] = '<h2>' . esc_html( trim( $field( 'services_title' ) . ' ' . $field( 'services_title_bold' ) ) ) . '</h2>';
		$parts[] = '<p>' . esc_html( $field( 'services_intro' ) ) . '</p>';
		foreach ( $rows( 'services_items' ) as $item ) {
			$parts[] = '<h3>' . esc_html( $item['title'] ?? '' ) . '</h3><p>' . esc_html( $item['text'] ?? '' ) . '</p>';
		}

		$parts[] = '<h2>' . esc_html( trim( $field( 'process_title' ) . ' ' . $field( 'process_title_bold' ) ) ) . '</h2>';
		$parts[] = '<p>' . esc_html( $field( 'process_intro' ) ) . '</p><ol>';
		foreach ( $rows( 'process_items' ) as $item ) {
			$parts[] = '<li><strong>' . esc_html( $item['title'] ?? '' ) . ':</strong> ' . esc_html( $item['text'] ?? '' ) . '</li>';
		}
		$parts[] = '</ol>';

		$parts[] = '<h2>' . esc_html( trim( $field( 'trust_title' ) . ' ' . $field( 'trust_title_bold' ) ) ) . '</h2>';
		$parts[] = '<p>' . esc_html( $field( 'trust_intro' ) ) . '</p><ul>';
		foreach ( $rows( 'trust_items' ) as $item ) {
			$parts[] = '<li><strong>' . esc_html( $item['title'] ?? '' ) . ':</strong> ' . esc_html( $item['text'] ?? '' ) . '</li>';
		}
		$parts[] = '</ul>';

		$parts[] = '<h2>' . esc_html( trim( $field( 'faq_title' ) . ' ' . $field( 'faq_title_bold' ) ) ) . '</h2>';
		foreach ( $rows( 'faq_items' ) as $item ) {
			$parts[] = '<h3>' . esc_html( $item['question'] ?? '' ) . '</h3><p>' . esc_html( $item['answer'] ?? '' ) . '</p>';
		}

		$home_url       = home_url( '/' );
		$privacy_url    = function_exists( 'leadwerk_get_page_url' ) ? leadwerk_get_page_url( 'nw-datenschutz-v1' ) : home_url( '/datenschutz/' );
		$imprint_url    = function_exists( 'leadwerk_get_page_url' ) ? leadwerk_get_page_url( 'nw-impressum-v1' ) : home_url( '/impressum/' );
		$external       = esc_url_raw( $field( 'video_external_url', 'https://t2med.de/' ) );
		$external_label = sanitize_text_field( $field( 'video_external_label', 'Offizielle T2med-Website' ) );
		$parts[]        = '<p>Weitere Informationen: <a href="' . esc_url( $home_url . '#leistungen' ) . '">Leistungen zum T2med-Wechsel</a>, <a href="' . esc_url( $home_url . '#ablauf' ) . '">Ablauf der Einführung</a>, <a href="' . esc_url( $privacy_url ) . '">Datenschutz</a> und <a href="' . esc_url( $imprint_url ) . '">Impressum</a>.</p>';
		if ( $external ) {
			$parts[] = '<p><a href="' . esc_url( $external ) . '" rel="noopener noreferrer">' . esc_html( $external_label ) . '</a></p>';
		}
		$parts[] = '<p>' . esc_html( $focus ) . ' wird von der ersten Vorprüfung bis zum stabilen Praxisbetrieb persönlich begleitet.</p>';

		return '<div data-leadwerk-yoast="t2med-home">' . "\n" . implode( "\n", $parts ) . "\n</div>";
	}

	private static function validate_manifest_pages( $manifest ) {
		$keys = array_column( $manifest['pages'] ?? array(), 'source_key' );
		if ( count( $keys ) !== count( array_unique( $keys ) ) || 5 !== count( $keys ) ) {
			throw new RuntimeException( 'Manifest muss genau fünf eindeutige T2med-Seiten enthalten.' );
		}
		$allowed = array( 'nw-t2med-home-v2', 'nw-danke-v1', 'nw-impressum-v1', 'nw-datenschutz-v1', 'nw-404-v1' );
		if ( array_diff( $keys, $allowed ) || array_diff( $allowed, $keys ) ) {
			throw new RuntimeException( 'Manifest enthält unerwartete Seiten.' );
		}
	}

	private static function validate_seed_schema() {
		foreach ( Leadwerk_T2med_Seed::pages() as $key => $values ) {
			$group = Leadwerk_Content_Schema::get_group( $key );
			if ( ! $group ) {
				throw new RuntimeException( 'Feldschema fehlt: ' . $key );
			}
			$unknown = array_diff( array_keys( $values ), array_keys( $group['fields'] ) );
			if ( $unknown ) {
				throw new RuntimeException( $key . ' enthält unbekannte Felder: ' . implode( ', ', $unknown ) );
			}
		}
	}

	private static function validate_seed_references() {
		$allowed = array_keys( Leadwerk_T2med_Seed::pages() );
		$check   = static function ( $value ) use ( &$check, $allowed ) {
			if ( ! is_array( $value ) ) {
				return;
			}
			if ( isset( $value['source_key'] ) && ! in_array( $value['source_key'], $allowed, true ) ) {
				throw new RuntimeException( 'Unbekanntes Linkziel: ' . $value['source_key'] );
			}
			foreach ( $value as $child ) {
				$check( $child );
			}
		};
		$check( Leadwerk_T2med_Seed::pages() );
		$check( Leadwerk_T2med_Seed::options() );
	}

	private static function validate_result( &$journal, $dry_run ) {
		self::validate_manifest_pages( self::manifest() );
		self::validate_seed_schema();
		self::validate_seed_references();
		if ( $dry_run ) {
			return;
		}
		foreach ( array_keys( Leadwerk_T2med_Seed::pages() ) as $source_key ) {
			$post_id = absint( $journal['page_map'][ $source_key ] ?? 0 );
			if ( ! $post_id || get_post_meta( $post_id, 'leadwerk_source_key', true ) !== $source_key ) {
				throw new RuntimeException( 'Importierte Seite ist nicht auflösbar: ' . $source_key );
			}
		}
		foreach ( array( 'brand_logo', 'site_favicon', 'hero_image', 'video_poster' ) as $key ) {
			if ( empty( $journal['media_map'][ $key ] ) || ! get_post( $journal['media_map'][ $key ] ) ) {
				throw new RuntimeException( 'Importiertes Medium fehlt: ' . $key );
			}
		}
		$form_id = absint( leadwerk_get_option( 'wpforms_form_id', 0 ) );
		if ( ! $form_id || 'wpforms' !== get_post_type( $form_id ) ) {
			throw new RuntimeException( 'T2med-WPForms-Formular fehlt.' );
		}
		$home_id = absint( $journal['page_map']['nw-t2med-home-v2'] ?? 0 );
		if ( 'page' !== get_option( 'show_on_front' ) || absint( get_option( 'page_on_front' ) ) !== $home_id ) {
			throw new RuntimeException( 'T2med-Seite wurde nicht als Startseite gesetzt.' );
		}
	}

	private static function post_identity_hash( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		return self::value_hash( array( $post->post_title, $post->post_name, $post->post_status ) );
	}

	private static function value_hash( $value ) {
		return hash( 'sha256', wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	private static function snapshot_post( $post_id, &$journal ) {
		if ( isset( $journal['post_snapshots'][ $post_id ] ) || in_array( $post_id, $journal['created_posts'], true ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( $post ) {
			$journal['post_snapshots'][ $post_id ] = array(
				'post' => array(
					'post_title'   => $post->post_title,
					'post_name'    => $post->post_name,
					'post_status'  => $post->post_status,
					'post_content' => $post->post_content,
				),
				'meta' => get_post_meta( $post_id ),
			);
		}
	}

	private static function snapshot_option( $key, &$journal ) {
		if ( isset( $journal['option_snapshots'][ $key ] ) ) {
			return;
		}
		$exists                              = false !== get_option( $key, false );
		$journal['option_snapshots'][ $key ] = array(
			'exists' => $exists,
			'value'  => $exists ? get_option( $key ) : null,
		);
	}

	public static function rollback( $journal = null ) {
		$journal = is_array( $journal ) ? $journal : self::current();
		if ( ! is_array( $journal ) ) {
			return array();
		}
		foreach ( array_reverse( array_unique( array_map( 'absint', $journal['created_posts'] ?? array() ) ) ) as $post_id ) {
			if ( $post_id ) {
				wp_delete_post( $post_id, true );
			}
		}
		foreach ( array_reverse( array_unique( array_map( 'absint', $journal['created_attachments'] ?? array() ) ) ) as $attachment_id ) {
			if ( $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}
		}
		foreach ( $journal['post_snapshots'] ?? array() as $post_id => $snapshot ) {
			wp_update_post( array_merge( array( 'ID' => absint( $post_id ) ), $snapshot['post'] ) );
			foreach ( array_keys( get_post_meta( $post_id ) ) as $key ) {
				delete_post_meta( $post_id, $key );
			}
			foreach ( $snapshot['meta'] as $key => $values ) {
				foreach ( $values as $value ) {
					add_post_meta( $post_id, $key, maybe_unserialize( $value ) );
				}
			}
		}
		foreach ( $journal['option_snapshots'] ?? array() as $key => $snapshot ) {
			if ( ! empty( $snapshot['exists'] ) ) {
				update_option( $key, $snapshot['value'], false );
			} else {
				delete_option( $key );
			}
		}
		$journal['status'] = 'rolled_back';
		$journal['logs'][] = sprintf( '[%s] Rollback abgeschlossen.', current_time( 'H:i:s' ) );
		update_option( self::JOURNAL_OPTION, $journal, false );
		return $journal;
	}

	public static function reset_journal() {
		delete_option( self::JOURNAL_OPTION );
	}
}
