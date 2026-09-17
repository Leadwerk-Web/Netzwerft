<?php
/** Translation dashboard, segment editor, list-table integration and review workflow. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Translation_Admin {
	private static $syncing = false;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'conflict_notice' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		add_action( 'add_meta_boxes_page', array( __CLASS__, 'metabox' ) );
		add_action( 'save_post_page', array( __CLASS__, 'refresh_counterpart' ), 40, 3 );
		add_action( 'updated_option', array( __CLASS__, 'refresh_option_translations' ), 40, 3 );
		add_action( 'admin_post_leadwerk_clone_english', array( __CLASS__, 'clone_action' ) );
		add_action( 'admin_post_leadwerk_clone_all_english', array( __CLASS__, 'clone_all_action' ) );
		add_filter( 'manage_pages_columns', array( __CLASS__, 'list_columns' ) );
		add_action( 'manage_pages_custom_column', array( __CLASS__, 'list_column' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_actions' ), 20, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'language_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_page_query' ) );
	}

	public static function menu() {
		add_options_page( 'Leadwerk Sprachen', 'Leadwerk Sprachen', 'manage_options', 'leadwerk-languages', array( __CLASS__, 'settings_page' ) );
		if ( ! defined( 'LEADWERK_SUITE_EMBEDDED_TRANSLATION' ) ) {
			add_management_page( 'Leadwerk Translation Dashboard', 'Übersetzungen', 'edit_pages', 'leadwerk-translations', array( __CLASS__, 'dashboard_page' ) );
			add_management_page( 'Leadwerk Übersetzungsdiagnose', 'Übersetzungsdiagnose', 'manage_options', 'leadwerk-translation-tools', array( __CLASS__, 'tools_page' ) );
		}
	}

	public static function conflict_notice() {
		if ( current_user_can( 'manage_options' ) && ( defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'pll_languages_list' ) ) ) {
			echo '<div class="notice notice-error"><p><strong>Leadwerk WPML Clone:</strong> Offizielles WPML oder Polylang ist aktiv. Bitte nur eine Routing-/Übersetzungslösung verwenden.</p></div>';
		}
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$active = Leadwerk_Translation_API::active_languages();
		?>
		<div class="wrap"><h1>Leadwerk Sprachen</h1>
		<p>Deutsch bleibt die Quelle. Eine englische Route, Sprachumschaltung und hreflang-Ausgabe entstehen erst, wenn das echte EN-Gegenstück veröffentlicht ist.</p>
		<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=leadwerk-translations' ) ); ?>">Translation Dashboard öffnen</a></p>
		<form method="post"><?php wp_nonce_field( 'leadwerk_languages_save', 'leadwerk_languages_nonce' ); ?>
		<input type="hidden" name="leadwerk_languages_submit" value="1">
		<p><label><input type="checkbox" checked disabled> Deutsch (Standard)</label></p>
		<p><label><input type="checkbox" name="leadwerk_enable_en" value="1" <?php checked( in_array( 'en', $active, true ) ); ?>> Englisch aktivieren</label></p>
		<h2>Sprachumschalter</h2>
		<p><label><input type="checkbox" name="leadwerk_auto_switcher" value="1" <?php checked( (bool) get_option( 'leadwerk_translation_auto_switcher', false ) ); ?>> Umschalter automatisch unten rechts anzeigen (nur bei veröffentlichtem DE/EN-Paar)</label></p>
		<p><label>Darstellung <select name="leadwerk_switcher_style"><option value="compact" <?php selected( get_option( 'leadwerk_translation_switcher_style', 'compact' ), 'compact' ); ?>>DE / EN</option><option value="names" <?php selected( get_option( 'leadwerk_translation_switcher_style', 'compact' ), 'names' ); ?>>Deutsch / English</option></select></label></p>
		<h2>DeepL-Browsererweiterung</h2>
		<p>Die Übersetzungswerkzeuge verwenden – wie im ACM-Projekt – die offizielle DeepL-Browsererweiterung. Es ist kein API-Schlüssel erforderlich und WordPress sendet keine Texte an eine eigene DeepL-API-Verbindung.</p>
		<p><strong>Voraussetzung:</strong> Die DeepL-Erweiterung muss im Browser installiert, bei DeepL angemeldet und für diese WordPress-Domain freigegeben sein. Der Segment-Editor erkennt das von der Erweiterung eingeblendete Übersetzungs-Icon automatisch.</p>
		<?php submit_button(); ?></form></div>
		<?php
	}

	/** Dashboard, page editor and global-string editor share one menu screen. */
	public static function dashboard_page() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'leadwerk-wpml-clone' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing to a read/edit screen only.
		$source_id = isset( $_GET['source_id'] ) ? absint( $_GET['source_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing to a read/edit screen only.
		$strings = isset( $_GET['strings'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['strings'] ) );
		if ( $source_id ) {
			self::editor_page( $source_id );
			return;
		}
		if ( $strings ) {
			self::option_editor_page();
			return;
		}
		self::dashboard_overview();
	}

	private static function dashboard_overview() {
		$pages      = self::source_pages();
		$translated = 0;
		$review     = 0;
		$complete   = 0;
		$deepl_queue = array();
		foreach ( $pages as $page ) {
			$english_id = Leadwerk_Translation_API::get_counterpart( $page->ID, 'en' );
			$status     = $english_id ? Leadwerk_Translation_API::status_of( $english_id ) : 'missing';
			if ( $english_id ) {
				$translated++;
				$review += 'needs_review' === $status ? 1 : 0;
				$complete += 'translated' === $status ? 1 : 0;
			}
			if ( in_array( $status, array( 'missing', 'not_translated', 'in_progress', 'needs_review' ), true ) ) {
				$deepl_queue[] = array(
					'sourceId' => absint( $page->ID ),
					'kind'     => 'page',
					'title'    => (string) get_the_title( $page ),
					'url'      => self::editor_url( $page->ID ),
				);
			}
		}
		if ( Leadwerk_Translation_API::is_active( 'en' ) && current_user_can( 'manage_options' ) && self::global_strings_need_translation() ) {
			$deepl_queue[] = array(
				'sourceId' => 0,
				'kind'     => 'globals',
				'title'    => 'Globale Header-/Footer-Texte',
				'url'      => admin_url( 'admin.php?page=leadwerk-translations&strings=1' ),
			);
		}
		$created = isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$failed  = isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$deepl_done = isset( $_GET['lw_deepl_complete'] ) ? absint( $_GET['lw_deepl_complete'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$deepl_pages = isset( $_GET['lw_deepl_pages'] ) ? absint( $_GET['lw_deepl_pages'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$deepl_segments = isset( $_GET['lw_deepl_segments'] ) ? absint( $_GET['lw_deepl_segments'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$deepl_globals = isset( $_GET['lw_deepl_globals'] ) ? absint( $_GET['lw_deepl_globals'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap leadwerk-translations"><h1>Leadwerk Translation Dashboard</h1>
		<p>DE ist die redaktionelle Quelle. Der Segment-Editor schützt vorhandene EN-Texte, markiert geänderte Quellen und teilt Medien ohne Duplikate.</p>
		<?php if ( $created || $failed ) : ?><div class="notice <?php echo $failed ? 'notice-warning' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( sprintf( '%d EN-Entwürfe erstellt, %d fehlgeschlagen.', $created, $failed ) ); ?></p></div><?php endif; ?>
		<?php if ( $deepl_done ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( 'DeepL-Automation abgeschlossen: %d Seiten gespeichert, globale Texte %s, %d Segmente übersetzt.', $deepl_pages, $deepl_globals ? 'gespeichert' : 'nicht geändert', $deepl_segments ) ); ?></p></div><?php endif; ?>
		<div class="lw-summary">
			<?php self::summary_card( 'DE Seiten', count( $pages ) ); ?>
			<?php self::summary_card( 'EN vorhanden', $translated ); ?>
			<?php self::summary_card( 'Vollständig', $complete ); ?>
			<?php self::summary_card( 'Prüfung nötig', $review ); ?>
		</div>
		<div class="lw-actions">
			<?php if ( current_user_can( 'manage_options' ) ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=leadwerk-languages' ) ); ?>">Spracheinstellungen</a><?php endif; ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=leadwerk-translations&strings=1' ) ); ?>">Globale Header-/Footer-Texte</a>
			<?php if ( current_user_can( 'manage_options' ) ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=leadwerk-translation-tools' ) ); ?>">Diagnose &amp; Reparatur</a><?php endif; ?>
			<?php if ( Leadwerk_Translation_API::is_active( 'en' ) && $deepl_queue ) : ?><button type="button" class="button button-primary" data-lw-machine-dashboard-all data-queue="<?php echo esc_attr( wp_json_encode( $deepl_queue ) ); ?>" data-dashboard="<?php echo esc_url( admin_url( 'admin.php?page=leadwerk-translations' ) ); ?>">DeepL Translation: offene Seiten &amp; globale Texte</button><?php endif; ?>
			<?php if ( current_user_can( 'manage_options' ) && Leadwerk_Translation_API::is_active( 'en' ) && $translated < count( $pages ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="leadwerk_clone_all_english"><?php wp_nonce_field( 'leadwerk_clone_all_english' ); ?><button type="submit" class="button button-primary">Fehlende EN-Entwürfe erstellen</button></form>
			<?php endif; ?>
		</div>
		<table class="widefat striped lw-translation-table"><thead><tr><th>Deutsche Seite</th><th>EN-Seite</th><th>Fortschritt</th><th>Status</th><th>Veröffentlichung</th><th>Aktionen</th></tr></thead><tbody>
		<?php if ( ! $pages ) : ?><tr><td colspan="6">Keine Seiten gefunden.</td></tr><?php endif; ?>
		<?php foreach ( $pages as $page ) :
			$english_id = Leadwerk_Translation_API::get_counterpart( $page->ID, 'en' );
			$status     = $english_id ? Leadwerk_Translation_API::status_of( $english_id ) : 'missing';
			$progress   = $english_id ? Leadwerk_Translation_API::completeness( $english_id ) : 0;
			$editor_url = self::editor_url( $page->ID );
			$clone_url  = wp_nonce_url( admin_url( 'admin-post.php?action=leadwerk_clone_english&post_id=' . $page->ID ), 'leadwerk_clone_english_' . $page->ID );
			?>
			<tr><td><strong><a href="<?php echo esc_url( get_edit_post_link( $page->ID ) ); ?>"><?php echo esc_html( get_the_title( $page ) ); ?></a></strong><br><code><?php echo esc_html( Leadwerk_Translation_API::group_of( $page->ID ) ); ?></code></td>
			<td><?php echo $english_id ? '<a href="' . esc_url( get_edit_post_link( $english_id ) ) . '">' . esc_html( get_the_title( $english_id ) ) . '</a>' : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
			<td><?php self::progress( $progress ); ?></td><td><?php self::status_badge( $status ); ?></td>
			<td><?php echo esc_html( $english_id ? self::post_status_label( get_post_status( $english_id ) ) : '—' ); ?></td>
			<td><?php if ( $english_id ) : ?><a class="button button-small" href="<?php echo esc_url( $editor_url ); ?>">Segment-Editor</a> <a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $english_id ) ); ?>">WP-Editor</a><?php elseif ( Leadwerk_Translation_API::is_active( 'en' ) ) : ?><a class="button button-small" href="<?php echo esc_url( $clone_url ); ?>">EN-Entwurf erstellen</a><?php else : ?>Englisch deaktiviert<?php endif; ?></td></tr>
		<?php endforeach; ?></tbody></table></div>
		<?php self::assets();
	}

	private static function editor_page( $source_id ) {
		$source = get_post( $source_id );
		if ( ! $source instanceof WP_Post || 'page' !== $source->post_type || 'de' !== Leadwerk_Translation_API::language_of( $source_id ) || ! current_user_can( 'edit_post', $source_id ) ) {
			wp_die( esc_html__( 'Ungültige Quellseite.', 'leadwerk-wpml-clone' ), '', array( 'response' => 400 ) );
		}
		$target_id = Leadwerk_Translation_API::get_counterpart( $source_id, 'en' );
		if ( ! $target_id ) {
			self::$syncing = true;
			$target_id    = Leadwerk_Translation_API::clone_to_english( $source_id );
			self::$syncing = false;
		}
		if ( is_wp_error( $target_id ) || ! $target_id ) {
			wp_die( esc_html( is_wp_error( $target_id ) ? $target_id->get_error_message() : 'EN-Ziel konnte nicht erstellt werden.' ), '', array( 'response' => 400 ) );
		}
		$notice = '';
		$type   = 'success';
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['leadwerk_translation_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['leadwerk_translation_nonce'] ) );
			if ( ! wp_verify_nonce( $nonce, 'leadwerk_save_translation_' . $source_id ) || ! current_user_can( 'edit_post', $target_id ) ) {
				wp_die( esc_html__( 'Ungültige Anfrage.', 'leadwerk-wpml-clone' ), '', array( 'response' => 403 ) );
			}
			self::$syncing = true;
			$title = Leadwerk_Translation_Text::sanitize( wp_unslash( $_POST['leadwerk_page_title'] ?? '' ), 'text' );
			$slug  = sanitize_title( (string) wp_unslash( $_POST['leadwerk_page_slug'] ?? '' ) );
			if ( $title ) {
				wp_update_post( array( 'ID' => $target_id, 'post_title' => $title ) );
				update_post_meta( $target_id, Leadwerk_Translation_API::META_TITLE_HASH, hash( 'sha256', (string) $source->post_title ) );
			}
			if ( $slug ) {
				update_post_meta( $target_id, Leadwerk_Translation_API::META_PUBLIC_SLUG, self::unique_public_slug( $slug, $target_id ) );
			}
			$bundle = Leadwerk_Translation_Sync::save_page_segments( $source_id, $target_id, $_POST['leadwerk_segments'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( class_exists( 'Leadwerk_Translation_Media' ) ) {
				Leadwerk_Translation_Media::save( $source_id, $_POST['leadwerk_media_segments'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
			if ( class_exists( 'Leadwerk_Translation_SEO' ) ) {
				Leadwerk_Translation_SEO::save( $source_id, $target_id, $_POST['leadwerk_seo_segments'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
			$action = sanitize_key( (string) wp_unslash( $_POST['leadwerk_editor_action'] ?? 'save_draft' ) );
			if ( 'publish' === $action ) {
				if ( ! $title || $source->post_title . ' (EN)' === $title || ! Leadwerk_Translation_Sync::can_publish( $bundle ) ) {
					$type   = 'error';
					$notice = 'Veröffentlichen blockiert: EN-Titel und alle geänderten Pflichtsegmente müssen vollständig sein.';
				} else {
					wp_update_post( array( 'ID' => $target_id, 'post_status' => 'publish' ) );
					$notice = 'Englische Übersetzung wurde veröffentlicht.';
				}
			} else {
				if ( 'publish' !== get_post_status( $target_id ) ) {
					wp_update_post( array( 'ID' => $target_id, 'post_status' => 'draft' ) );
				}
				$notice = 'Übersetzung wurde gespeichert.';
			}
			self::$syncing = false;
		}
		$target   = get_post( $target_id );
		$sections = Leadwerk_Translation_Sync::page_sections( $source_id, $target_id );
		if ( class_exists( 'Leadwerk_Translation_SEO' ) ) {
			$extra = Leadwerk_Translation_SEO::section( $source_id, $target_id );
			if ( $extra ) {
				foreach ( $extra['segments'] as &$segment ) { $segment['input_group'] = 'leadwerk_seo_segments'; } unset( $segment );
				$sections[] = $extra;
			}
		}
		if ( class_exists( 'Leadwerk_Translation_Media' ) ) {
			$extra = Leadwerk_Translation_Media::section( $source_id );
			if ( $extra ) {
				foreach ( $extra['segments'] as &$segment ) { $segment['input_group'] = 'leadwerk_media_segments'; } unset( $segment );
				$sections[] = $extra;
			}
		}
		$status   = Leadwerk_Translation_API::status_of( $target_id );
		$progress = Leadwerk_Translation_API::completeness( $target_id );
		$slug     = (string) get_post_meta( $target_id, Leadwerk_Translation_API::META_PUBLIC_SLUG, true );
		?>
		<div class="wrap leadwerk-translations"><h1>Leadwerk Segment-Editor</h1>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=leadwerk-translations' ) ); ?>">← Translation Dashboard</a></p>
		<?php if ( $notice ) : ?><div class="notice notice-<?php echo esc_attr( $type ); ?>"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
		<div class="lw-editor-head"><div><strong><?php echo esc_html( $source->post_title ); ?></strong> <span aria-hidden="true">→</span> <strong><?php echo esc_html( $target->post_title ); ?></strong></div><div><?php self::status_badge( $status ); ?> <?php self::progress( $progress ); ?></div></div>
		<form method="post" class="lw-segment-form"><?php wp_nonce_field( 'leadwerk_save_translation_' . $source_id, 'leadwerk_translation_nonce' ); ?>
		<div class="lw-toolbar"><label><input type="checkbox" data-lw-hide-complete> Fertige Segmente ausblenden</label><div><?php if ( class_exists( 'Leadwerk_Translation_Machine' ) && Leadwerk_Translation_Machine::is_configured() ) : ?><button type="button" class="button button-primary" data-lw-machine-all>Leere/geänderte Segmente mit DeepL übersetzen</button> <button type="button" class="button" data-lw-machine-cancel hidden>Abbrechen</button> <?php endif; ?><button type="button" class="button" data-lw-copy-all>Alle DE-Texte übernehmen</button> <button type="button" class="button" data-lw-clear-all>Alle EN-Texte leeren</button></div></div>
		<?php $title_source_hash = hash( 'sha256', (string) $source->post_title ); $title_stored_hash = (string) get_post_meta( $target_id, Leadwerk_Translation_API::META_TITLE_HASH, true ); $title_needs_review = '' !== $title_stored_hash && ! hash_equals( $title_stored_hash, $title_source_hash ); ?>
		<div class="lw-card"><h2>Seiteneinstellungen</h2><div class="lw-page-settings"><label>DE-Titel<textarea readonly data-lw-title-source><?php echo esc_textarea( $source->post_title ); ?></textarea></label><label>EN-Titel<textarea name="leadwerk_page_title" required data-lw-title-target data-needs-review="<?php echo esc_attr( $title_needs_review ? '1' : '0' ); ?>"><?php echo esc_textarea( $target->post_title ); ?></textarea></label><label>Öffentlicher EN-Slug<input type="text" name="leadwerk_page_slug" value="<?php echo esc_attr( $slug ?: preg_replace( '/-en$/', '', $target->post_name ) ); ?>"></label><div><a class="button" href="<?php echo esc_url( get_edit_post_link( $target_id ) ); ?>">Im WordPress-Editor öffnen</a><?php if ( 'publish' === get_post_status( $target_id ) ) : ?> <a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( Leadwerk_Translation_API::public_url( $target_id ) ); ?>">EN ansehen</a><?php endif; ?></div></div></div>
		<?php foreach ( $sections as $index => $section ) : ?><section class="lw-card lw-section"><header><h2><?php echo esc_html( ( $index + 1 ) . '. ' . $section['label'] ); ?></h2><div><?php if ( class_exists( 'Leadwerk_Translation_Machine' ) && Leadwerk_Translation_Machine::is_configured() ) : ?><button type="button" class="button button-small" data-lw-machine-section>Abschnitt mit DeepL übersetzen</button> <?php endif; ?><button type="button" class="button button-small" data-lw-copy-section>Abschnitt DE → EN</button></div></header>
		<?php foreach ( $section['segments'] as $segment ) : ?><div class="lw-segment" data-status="<?php echo esc_attr( $segment['status'] ); ?>" data-type="<?php echo esc_attr( $segment['type'] ?? 'text' ); ?>"><div class="lw-source"><strong><?php echo esc_html( $segment['label'] ); ?></strong><small>DE</small><div><?php echo 'richtext' === $segment['type'] ? wp_kses_post( $segment['source'] ) : nl2br( esc_html( $segment['source'] ) ); ?></div><textarea hidden readonly data-lw-source><?php echo esc_textarea( $segment['source'] ); ?></textarea></div><div class="lw-target"><strong><?php echo esc_html( $segment['label'] ); ?></strong><small>EN</small><textarea rows="<?php echo esc_attr( 'richtext' === $segment['type'] ? 6 : 3 ); ?>" name="<?php echo esc_attr( $segment['input_group'] ?? 'leadwerk_segments' ); ?>[<?php echo esc_attr( $segment['id'] ); ?>]" data-lw-target><?php echo esc_textarea( $segment['translation'] ); ?></textarea><div><?php self::status_badge( $segment['status'] ); ?><?php if ( class_exists( 'Leadwerk_Translation_Machine' ) && Leadwerk_Translation_Machine::is_configured() ) : ?> <button type="button" class="button-link" data-lw-machine>DeepL</button><?php endif; ?></div></div></div><?php endforeach; ?></section><?php endforeach; ?>
		<div class="lw-submit"><button type="submit" name="leadwerk_editor_action" value="save_draft" class="button button-secondary">Übersetzung speichern</button> <button type="submit" name="leadwerk_editor_action" value="publish" class="button button-primary"><?php echo 'publish' === get_post_status( $target_id ) ? 'Veröffentlichung aktualisieren' : 'Speichern und veröffentlichen'; ?></button></div>
		</form></div>
		<?php self::assets();
	}

	private static function option_editor_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'leadwerk-wpml-clone' ), '', array( 'response' => 403 ) );
		}
		$notice = '';
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['leadwerk_options_translation_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['leadwerk_options_translation_nonce'] ) );
			if ( ! wp_verify_nonce( $nonce, 'leadwerk_save_options_translation' ) ) {
				wp_die( esc_html__( 'Ungültige Anfrage.', 'leadwerk-wpml-clone' ), '', array( 'response' => 403 ) );
			}
			Leadwerk_Translation_Sync::save_option_segments( $_POST['leadwerk_segments'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( class_exists( 'Leadwerk_Shared_Translations' ) ) {
				Leadwerk_Shared_Translations::save( $_POST['leadwerk_runtime_segments'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
			$notice = 'Globale EN-Texte wurden gespeichert.';
		}
		$sections = Leadwerk_Translation_Sync::option_sections();
		if ( class_exists( 'Leadwerk_Shared_Translations' ) ) {
			foreach ( Leadwerk_Shared_Translations::sections() as $runtime_section ) {
				foreach ( $runtime_section['segments'] as &$segment ) { $segment['input_group'] = 'leadwerk_runtime_segments'; } unset( $segment );
				$sections[] = $runtime_section;
			}
		}
		?>
		<div class="wrap leadwerk-translations"><h1>Globale Header-/Footer-Texte</h1><p><a href="<?php echo esc_url( admin_url( 'admin.php?page=leadwerk-translations' ) ); ?>">← Translation Dashboard</a></p>
		<p>Telefon, E-Mail, Bilder und WordPress-Ziele werden geteilt. Sichtbare Menübezeichnungen und Beschreibungen können hier auf Englisch gepflegt werden.</p>
		<?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
		<form method="post" class="lw-segment-form"><?php wp_nonce_field( 'leadwerk_save_options_translation', 'leadwerk_options_translation_nonce' ); ?>
		<div class="lw-toolbar"><label><input type="checkbox" data-lw-hide-complete> Fertige Segmente ausblenden</label><div><button type="button" class="button button-primary" data-lw-machine-all>Leere/geänderte Texte mit DeepL übersetzen</button> <button type="button" class="button" data-lw-machine-cancel hidden>Abbrechen</button> <button type="button" class="button" data-lw-copy-all>Alle DE-Texte übernehmen</button> <button type="button" class="button" data-lw-clear-all>Alle EN-Texte leeren</button></div></div>
		<?php foreach ( $sections as $section ) : ?><section class="lw-card lw-section"><header><h2><?php echo esc_html( $section['label'] ); ?></h2><div><?php if ( class_exists( 'Leadwerk_Translation_Machine' ) && Leadwerk_Translation_Machine::is_configured() ) : ?><button type="button" class="button button-small" data-lw-machine-section>Abschnitt mit DeepL übersetzen</button> <?php endif; ?><button type="button" class="button button-small" data-lw-copy-section>Abschnitt DE → EN</button></div></header><?php foreach ( $section['segments'] as $segment ) : ?><div class="lw-segment" data-status="<?php echo esc_attr( $segment['status'] ); ?>" data-type="<?php echo esc_attr( $segment['type'] ?? 'text' ); ?>"><div class="lw-source"><strong><?php echo esc_html( $segment['label'] ); ?></strong><small>DE</small><div><?php echo nl2br( esc_html( $segment['source'] ) ); ?></div><textarea hidden readonly data-lw-source><?php echo esc_textarea( $segment['source'] ); ?></textarea></div><div class="lw-target"><strong><?php echo esc_html( $segment['label'] ); ?></strong><small>EN</small><textarea rows="3" name="<?php echo esc_attr( $segment['input_group'] ?? 'leadwerk_segments' ); ?>[<?php echo esc_attr( $segment['id'] ); ?>]" data-lw-target><?php echo esc_textarea( $segment['translation'] ); ?></textarea><div><?php self::status_badge( $segment['status'] ); ?><?php if ( class_exists( 'Leadwerk_Translation_Machine' ) && Leadwerk_Translation_Machine::is_configured() ) : ?> <button type="button" class="button-link" data-lw-machine>DeepL</button><?php endif; ?></div></div></div><?php endforeach; ?></section><?php endforeach; ?>
		<?php submit_button( 'Globale EN-Texte speichern' ); ?></form></div>
		<?php self::assets();
	}

	public static function tools_page() {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'Leadwerk_Translation_Tools' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'leadwerk-wpml-clone' ), '', array( 'response' => 403 ) );
		}
		$fixed = null;
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['leadwerk_translation_repair_nonce'] ) ) {
			check_admin_referer( 'leadwerk_translation_repair', 'leadwerk_translation_repair_nonce' );
			$fixed = Leadwerk_Translation_Tools::repair();
		}
		$report = Leadwerk_Translation_Tools::report();
		?>
		<div class="wrap leadwerk-translations"><h1>Übersetzungsdiagnose &amp; sichere Reparatur</h1>
		<p>Die Prüfung verändert keine Inhalte. Die Reparatur ergänzt nur fehlende Identitäten und macht doppelte öffentliche EN-Slugs eindeutig; Seiten werden niemals gelöscht oder zusammengeführt.</p>
		<?php if ( null !== $fixed ) : ?><div class="notice notice-success"><p><?php echo esc_html( sprintf( '%d Metadatenwerte wurden ergänzt oder korrigiert.', $fixed ) ); ?></p></div><?php endif; ?>
		<div class="lw-summary"><?php self::summary_card( 'Seiten geprüft', $report['pages'] ); ?><?php self::summary_card( 'Sprachgruppen', $report['groups'] ); ?><?php self::summary_card( 'Probleme', count( $report['issues'] ) ); ?></div>
		<?php if ( empty( $report['issues'] ) ) : ?><div class="notice notice-success inline"><p>Keine Zuordnungs- oder Slug-Probleme gefunden.</p></div><?php else : ?>
		<table class="widefat striped"><thead><tr><th>Problem</th><th>Details</th><th>Automatisch reparierbar</th></tr></thead><tbody><?php foreach ( $report['issues'] as $issue ) : ?><tr><td><?php echo esc_html( $issue['label'] ); ?></td><td><code><?php echo esc_html( $issue['detail'] ); ?></code></td><td><?php echo ! empty( $issue['repairable'] ) ? 'Ja' : 'Manuelle Prüfung nötig'; ?></td></tr><?php endforeach; ?></tbody></table>
		<?php endif; ?>
		<form method="post"><?php wp_nonce_field( 'leadwerk_translation_repair', 'leadwerk_translation_repair_nonce' ); ?><?php submit_button( 'Sichere Metadaten-Reparatur ausführen', 'secondary' ); ?></form></div>
		<?php self::assets();
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
		update_option( Leadwerk_Translation_API::OPTION_LANGUAGES, $languages, false );
		update_option( Leadwerk_Translation_API::OPTION_DEFAULT, 'de', false );
		update_option( 'leadwerk_translation_auto_switcher', ! empty( $_POST['leadwerk_auto_switcher'] ), false );
		update_option( 'leadwerk_translation_switcher_style', 'names' === sanitize_key( (string) wp_unslash( $_POST['leadwerk_switcher_style'] ?? 'compact' ) ) ? 'names' : 'compact', false );
		flush_rewrite_rules( false );
		wp_safe_redirect( admin_url( 'admin.php?page=leadwerk-languages&updated=1' ) );
		exit;
	}

	public static function admin_bar( $bar ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_pages' ) || ! Leadwerk_Translation_API::is_active( 'en' ) || ! $bar instanceof WP_Admin_Bar ) {
			return;
		}
		$post_id = is_admin() ? ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0 ) : ( is_page() ? get_queried_object_id() : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lang    = $post_id && 'page' === get_post_type( $post_id ) ? Leadwerk_Translation_API::language_of( $post_id ) : '';
		$title   = $lang ? 'Sprache: ' . strtoupper( $lang ) : 'Übersetzungen';
		$bar->add_node( array( 'id' => 'leadwerk-language', 'title' => '<span class="ab-icon dashicons-translation" aria-hidden="true"></span><span class="ab-label">' . esc_html( $title ) . '</span>', 'href' => admin_url( 'admin.php?page=leadwerk-translations' ) ) );
		$bar->add_node( array( 'parent' => 'leadwerk-language', 'id' => 'leadwerk-language-dashboard', 'title' => 'Translation Dashboard', 'href' => admin_url( 'admin.php?page=leadwerk-translations' ) ) );
		if ( ! $post_id || ! $lang ) {
			return;
		}
		$source = Leadwerk_Translation_API::source_id( $post_id ) ?: $post_id;
		$other  = 'en' === $lang ? Leadwerk_Translation_API::get_counterpart( $post_id, 'de' ) : Leadwerk_Translation_API::get_counterpart( $post_id, 'en' );
		if ( $other ) {
			$bar->add_node( array( 'parent' => 'leadwerk-language', 'id' => 'leadwerk-language-counterpart', 'title' => strtoupper( 'en' === $lang ? 'de' : 'en' ) . ' bearbeiten', 'href' => get_edit_post_link( $other ) ) );
			$bar->add_node( array( 'parent' => 'leadwerk-language', 'id' => 'leadwerk-language-segments', 'title' => 'Segment-Editor', 'href' => self::editor_url( $source ) ) );
		} elseif ( 'de' === $lang ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=leadwerk_clone_english&post_id=' . $post_id ), 'leadwerk_clone_english_' . $post_id );
			$bar->add_node( array( 'parent' => 'leadwerk-language', 'id' => 'leadwerk-language-clone', 'title' => 'EN-Entwurf erstellen', 'href' => $url ) );
		}
	}

	public static function metabox( $post ) {
		if ( $post instanceof WP_Post ) {
			add_meta_box( 'leadwerk-translation', 'Leadwerk Übersetzung', array( __CLASS__, 'render_metabox' ), 'page', 'side', 'high' );
		}
	}

	public static function render_metabox( $post ) {
		$lang    = Leadwerk_Translation_API::language_of( $post->ID );
		$source  = Leadwerk_Translation_API::source_id( $post->ID ) ?: $post->ID;
		$counter = Leadwerk_Translation_API::get_counterpart( $post->ID, 'de' === $lang ? 'en' : 'de' );
		echo '<p><strong>Sprache:</strong> ' . esc_html( strtoupper( $lang ) ) . '</p>';
		echo '<p><strong>Status:</strong> ' . esc_html( self::translation_status_label( Leadwerk_Translation_API::status_of( $post->ID ) ) ) . '</p>';
		if ( $counter ) {
			echo '<p><a href="' . esc_url( get_edit_post_link( $counter ) ) . '">Gegenstück bearbeiten</a></p><p><a class="button" href="' . esc_url( self::editor_url( $source ) ) . '">Segment-Editor</a></p>';
		} elseif ( 'de' === $lang && Leadwerk_Translation_API::is_active( 'en' ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=leadwerk_clone_english&post_id=' . $post->ID ), 'leadwerk_clone_english_' . $post->ID );
			echo '<p><a class="button" href="' . esc_url( $url ) . '">EN-Entwurf erstellen</a></p>';
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
		wp_safe_redirect( self::editor_url( $post_id ) );
		exit;
	}

	public static function clone_all_action() {
		if ( ! current_user_can( 'manage_options' ) || ! Leadwerk_Translation_API::is_active( 'en' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'leadwerk-wpml-clone' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'leadwerk_clone_all_english' );
		$created = 0;
		$failed  = 0;
		self::$syncing = true;
		foreach ( self::source_pages() as $page ) {
			if ( Leadwerk_Translation_API::get_counterpart( $page->ID, 'en' ) ) {
				continue;
			}
			$result = Leadwerk_Translation_API::clone_to_english( $page->ID );
			is_wp_error( $result ) ? $failed++ : $created++;
		}
		self::$syncing = false;
		wp_safe_redirect( add_query_arg( array( 'page' => 'leadwerk-translations', 'created' => $created, 'failed' => $failed ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Source changes refresh hashes and shared fields, never translated text. */
	public static function refresh_counterpart( $post_id, $post, $update ) {
		if ( self::$syncing || ! $update || ! $post instanceof WP_Post || 'de' !== Leadwerk_Translation_API::language_of( $post_id ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$target = Leadwerk_Translation_API::get_counterpart( $post_id, 'en' );
		if ( $target ) {
			self::$syncing = true;
			Leadwerk_Translation_Sync::refresh_translation( $post_id, $target, true );
			self::$syncing = false;
		}
	}

	public static function refresh_option_translations( $option, $old_value, $value ) {
		if ( self::$syncing || 0 !== strpos( (string) $option, 'leadwerk_opt_' ) ) {
			return;
		}
		Leadwerk_Translation_Sync::refresh_options_bundle();
	}

	public static function list_columns( $columns ) {
		$columns['leadwerk_language']    = 'Sprache';
		$columns['leadwerk_translation'] = 'Übersetzung';
		return $columns;
	}

	public static function list_column( $column, $post_id ) {
		if ( 'leadwerk_language' === $column ) {
			echo esc_html( strtoupper( Leadwerk_Translation_API::language_of( $post_id ) ) );
		} elseif ( 'leadwerk_translation' === $column ) {
			$source = Leadwerk_Translation_API::source_id( $post_id ) ?: $post_id;
			$target = Leadwerk_Translation_API::get_counterpart( $source, 'en' );
			if ( $target ) {
				echo '<a href="' . esc_url( self::editor_url( $source ) ) . '">' . esc_html( Leadwerk_Translation_API::completeness( $target ) . '% · ' . self::translation_status_label( Leadwerk_Translation_API::status_of( $target ) ) ) . '</a>';
			} else {
				echo '—';
			}
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( ! $post instanceof WP_Post || ! Leadwerk_Translation_API::is_active( 'en' ) ) {
			return $actions;
		}
		$source = Leadwerk_Translation_API::source_id( $post->ID ) ?: $post->ID;
		$target = Leadwerk_Translation_API::get_counterpart( $source, 'en' );
		if ( $target ) {
			$actions['leadwerk_translate'] = '<a href="' . esc_url( self::editor_url( $source ) ) . '">Übersetzen</a>';
		} elseif ( 'de' === Leadwerk_Translation_API::language_of( $post->ID ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=leadwerk_clone_english&post_id=' . $post->ID ), 'leadwerk_clone_english_' . $post->ID );
			$actions['leadwerk_translate'] = '<a href="' . esc_url( $url ) . '">EN-Entwurf erstellen</a>';
		}
		return $actions;
	}

	public static function language_filter() {
		$screen = get_current_screen();
		if ( ! $screen || 'page' !== $screen->post_type ) {
			return;
		}
		$current = isset( $_GET['lw_admin_lang'] ) ? sanitize_key( wp_unslash( $_GET['lw_admin_lang'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<select name="lw_admin_lang"><option value="all"' . selected( $current, 'all', false ) . '>Alle Sprachen</option><option value="de"' . selected( $current, 'de', false ) . '>Deutsch</option><option value="en"' . selected( $current, 'en', false ) . '>English</option></select>';
	}

	public static function filter_page_query( $query ) {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() || 'page' !== $query->get( 'post_type' ) ) {
			return;
		}
		$lang = isset( $_GET['lw_admin_lang'] ) ? sanitize_key( wp_unslash( $_GET['lw_admin_lang'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'en' === $lang ) {
			$query->set( 'meta_query', array( array( 'key' => Leadwerk_Translation_API::META_LANG, 'value' => 'en' ) ) );
		} elseif ( 'de' === $lang ) {
			$query->set( 'meta_query', array( 'relation' => 'OR', array( 'key' => Leadwerk_Translation_API::META_LANG, 'value' => 'de' ), array( 'key' => Leadwerk_Translation_API::META_LANG, 'compare' => 'NOT EXISTS' ) ) );
		}
	}

	public static function localized_attachment_attributes( $attributes, $attachment ) {
		if ( is_admin() || 'en' !== Leadwerk_Translation_API::current_language() || ! $attachment instanceof WP_Post ) {
			return $attributes;
		}
		$alt = (string) get_post_meta( $attachment->ID, '_leadwerk_attachment_alt_en', true );
		if ( $alt ) {
			$attributes['alt'] = $alt;
		}
		return $attributes;
	}

	private static function source_pages() {
		$all = get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ), 'posts_per_page' => -1, 'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC' ), 'order' => 'ASC' ) );
		return array_values( array_filter( $all, static function ( $page ) { if ( $page instanceof WP_Post && 'de' === Leadwerk_Translation_API::language_of( $page->ID ) ) { Leadwerk_Translation_API::ensure_identity( $page->ID, 'de' ); return true; } return false; } ) );
	}

	private static function editor_url( $source_id ) {
		return add_query_arg( array( 'page' => 'leadwerk-translations', 'source_id' => absint( $source_id ), 'lang' => 'en' ), admin_url( 'admin.php' ) );
	}

	/** Determine whether global option/runtime strings contain empty or stale EN values. */
	private static function global_strings_need_translation() {
		$sections = Leadwerk_Translation_Sync::option_sections();
		if ( class_exists( 'Leadwerk_Shared_Translations' ) ) {
			$sections = array_merge( $sections, Leadwerk_Shared_Translations::sections() );
		}
		foreach ( $sections as $section ) {
			foreach ( (array) ( $section['segments'] ?? array() ) as $segment ) {
				if ( in_array( (string) ( $segment['status'] ?? '' ), array( 'not_translated', 'in_progress', 'needs_review' ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function unique_public_slug( $slug, $target_id ) {
		$base = sanitize_title( $slug );
		$out  = $base ?: 'page';
		$i    = 2;
		while ( get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => 1, 'fields' => 'ids', 'post__not_in' => array( absint( $target_id ) ), 'meta_key' => Leadwerk_Translation_API::META_PUBLIC_SLUG, 'meta_value' => $out ) ) ) {
			$out = $base . '-' . $i++;
		}
		return $out;
	}

	private static function summary_card( $label, $value ) { echo '<div class="card"><h2>' . esc_html( $label ) . '</h2><strong>' . esc_html( $value ) . '</strong></div>'; }
	private static function progress( $value ) { $value = min( 100, max( 0, absint( $value ) ) ); echo '<div class="lw-progress"><span><i style="width:' . esc_attr( $value ) . '%"></i></span><b>' . esc_html( $value ) . '%</b></div>'; }
	private static function status_badge( $status ) { echo '<span class="lw-status lw-status--' . esc_attr( sanitize_key( $status ) ) . '">' . esc_html( self::translation_status_label( $status ) ) . '</span>'; }
	private static function post_status_label( $status ) { $object = get_post_status_object( $status ); return $object && isset( $object->label ) ? $object->label : ucfirst( (string) $status ); }
	private static function translation_status_label( $status ) { $labels = array( 'source' => 'Quelle', 'missing' => 'Fehlt', 'not_translated' => 'Nicht übersetzt', 'in_progress' => 'In Arbeit', 'needs_review' => 'Prüfung nötig', 'translated' => 'Übersetzt' ); return $labels[ $status ] ?? ucwords( str_replace( '_', ' ', (string) $status ) ); }

	private static function assets() {
		?>
		<style>
		.lw-summary{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;margin:18px 0}.lw-summary .card{margin:0;min-width:0}.lw-summary h2{margin:0 0 10px;font-size:13px;text-transform:uppercase;color:#64748b}.lw-summary strong{font-size:28px}.lw-actions,.lw-editor-head,.lw-toolbar,.lw-card>header,.lw-submit{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:16px 0}.lw-actions form{margin:0}.lw-progress{display:flex;align-items:center;gap:8px;min-width:130px}.lw-progress>span{display:block;flex:1;height:8px;background:#e2e8f0;border-radius:20px;overflow:hidden}.lw-progress i{display:block;height:100%;background:#2563eb}.lw-status{display:inline-flex;padding:4px 9px;border-radius:30px;font-size:12px;font-weight:700;background:#e2e8f0;color:#334155}.lw-status--translated{background:#dcfce7;color:#166534}.lw-status--needs_review{background:#fef3c7;color:#92400e}.lw-status--not_translated,.lw-status--missing{background:#fee2e2;color:#991b1b}.lw-status--in_progress{background:#dbeafe;color:#1d4ed8}.lw-card{margin:18px 0;padding:18px;background:#fff;border:1px solid #d0d7de;border-radius:10px}.lw-card>h2,.lw-card>header h2{margin:0}.lw-page-settings{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:16px}.lw-page-settings label{display:grid;gap:6px;font-weight:600}.lw-page-settings textarea{min-height:70px}.lw-segment{display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:14px 0;border-top:1px solid #e2e8f0}.lw-source,.lw-target{display:grid;gap:7px;align-content:start}.lw-source small,.lw-target small{font-weight:700;color:#64748b}.lw-target textarea{width:100%}.lw-segment[hidden],.lw-section[hidden]{display:none}.lw-submit{justify-content:flex-end}.lw-translation-table td{vertical-align:middle}@media(max-width:900px){.lw-summary,.lw-page-settings,.lw-segment{grid-template-columns:1fr 1fr}}@media(max-width:650px){.lw-summary,.lw-page-settings,.lw-segment{grid-template-columns:1fr}}
		</style>
		<script>
		(function(){function segments(scope){return Array.prototype.slice.call(scope.querySelectorAll('.lw-segment'));}function badge(segment,value){var b=segment.querySelector('.lw-status');if(!b)return;b.className='lw-status '+((value||'').trim()?'lw-status--translated':'lw-status--not_translated');b.textContent=(value||'').trim()?'Übersetzt':'Nicht übersetzt';segment.dataset.status=(value||'').trim()?'translated':'not_translated';}function copy(list){list.forEach(function(s){var a=s.querySelector('[data-lw-source]'),b=s.querySelector('[data-lw-target]');if(a&&b){b.value=a.value||'';badge(s,b.value);}});}function clear(list){list.forEach(function(s){var b=s.querySelector('[data-lw-target]');if(b){b.value='';badge(s,'');}});}function filter(form){var hide=!!form.querySelector('[data-lw-hide-complete]:checked');form.querySelectorAll('.lw-section').forEach(function(section){var shown=0;segments(section).forEach(function(s){s.hidden=hide&&'translated'===s.dataset.status;if(!s.hidden)shown++;});section.hidden=hide&&!shown;});}document.addEventListener('click',function(e){var form=e.target.closest('.lw-segment-form');if(!form)return;if(e.target.matches('[data-lw-copy-all]')){if(window.confirm('Mevcut EN metinleri DE kaynak metinleriyle değiştirilsin mi?'))copy(segments(form));}if(e.target.matches('[data-lw-clear-all]')){if(window.confirm('Tüm EN segmentleri temizlensin mi?'))clear(segments(form));}if(e.target.matches('[data-lw-copy-section]')){copy(segments(e.target.closest('.lw-section')));}filter(form);});document.addEventListener('input',function(e){if(e.target.matches('[data-lw-target]')){var s=e.target.closest('.lw-segment');badge(s,e.target.value);filter(e.target.closest('.lw-segment-form'));}});document.addEventListener('change',function(e){if(e.target.matches('[data-lw-hide-complete]'))filter(e.target.closest('.lw-segment-form'));});})();
		</script>
		<?php if ( class_exists( 'Leadwerk_Translation_Machine' ) && Leadwerk_Translation_Machine::is_configured() ) : ?>
		<script src="<?php echo esc_url( Leadwerk_Translation_Machine::script_url() ); ?>?ver=<?php echo esc_attr( defined( 'LEADWERK_WPML_CLONE_VERSION' ) ? LEADWERK_WPML_CLONE_VERSION : '1' ); ?>"></script>
		<?php endif; ?>
		<?php
	}
}
