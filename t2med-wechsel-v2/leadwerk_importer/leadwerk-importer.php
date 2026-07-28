<?php
/**
 * Plugin Name: Leadwerk Importer
 * Description: Journaled, idempotent T2med importer for pages, media, fields and WPForms.
 * Version: 3.1.1
 * Author: Leadwerk
 * Text Domain: leadwerk-importer
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Requires Plugins: leadwerk-fields, leadwerk-wpml-clone
 *
 * @package Leadwerk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LEADWERK_IMPORTER_VERSION', '3.1.1' );
define( 'LEADWERK_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'LEADWERK_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-t2med-seed.php';
require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-media-importer.php';
require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-import-preflight.php';
require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-wpforms-setup.php';
require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-importer.php';

add_action(
	'save_post_page',
	static function ( $post_id, $post ) {
		if ( ! $post instanceof WP_Post || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		Leadwerk_Importer::sync_home_seo( $post_id );
	},
	100,
	2
);

add_action(
	'admin_menu',
	static function () {
		add_management_page(
			'T2med Import',
			'T2med Import',
			'manage_options',
			'leadwerk-t2med-import',
			'leadwerk_importer_admin_page'
		);
	}
);

add_action(
	'admin_enqueue_scripts',
	static function ( $hook ) {
		if ( 'tools_page_leadwerk-t2med-import' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'leadwerk-importer', LEADWERK_IMPORTER_URL . 'assets/admin.css', array(), LEADWERK_IMPORTER_VERSION );
		wp_enqueue_script( 'leadwerk-importer', LEADWERK_IMPORTER_URL . 'assets/admin.js', array(), LEADWERK_IMPORTER_VERSION, true );
		wp_localize_script(
			'leadwerk-importer',
			'leadwerkImporter',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'leadwerk_t2med_import' ),
				'state'   => Leadwerk_Importer::current(),
			)
		);
	}
);

add_action(
	'admin_enqueue_scripts',
	static function ( $hook ) {
		if ( 'post.php' !== $hook || ! wp_script_is( 'yoast-seo-analysis', 'registered' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value; no state is changed.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $post_id || 'nw-t2med-home-v2' !== get_post_meta( $post_id, 'leadwerk_source_key', true ) ) {
			return;
		}

		Leadwerk_Importer::sync_home_seo( $post_id );
		$content = Leadwerk_Importer::get_home_analysis_content( $post_id );
		if ( '' === $content ) {
			return;
		}

		wp_enqueue_script(
			'leadwerk-yoast-analysis',
			LEADWERK_IMPORTER_URL . 'assets/yoast-analysis.js',
			array( 'jquery', 'yoast-seo-analysis' ),
			LEADWERK_IMPORTER_VERSION,
			true
		);
		wp_localize_script(
			'leadwerk-yoast-analysis',
			'leadwerkYoastAnalysis',
			array(
				'content' => $content,
				'marker'  => 'data-leadwerk-yoast="t2med-home"',
			)
		);
	},
	100
);

function leadwerk_importer_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap leadwerk-importer">
		<h1>T2med → WordPress Import</h1>
		<p>Quelle ist ausschließlich der paketierte Snapshot von <code>t2med-wechsel-v2.html</code>. Die Ausführung ist in wiederaufnehmbare, protokollierte Schritte aufgeteilt.</p>
		<div class="notice notice-warning inline">
			<p><strong>Vor Live-Import:</strong> WordPress 6.9+, PHP 8.1+, Leadwerk Fields, Leadwerk WPML Clone, das Leadwerk T2med Theme und WPForms 1.10.2+ müssen aktiv sein.</p>
		</div>
		<div class="leadwerk-importer__actions">
			<button type="button" class="button" data-import-action="dry">Dry-run starten</button>
			<button type="button" class="button button-primary" data-import-action="live">Live-Import starten</button>
			<button type="button" class="button" data-import-action="resume">Fortsetzen</button>
			<button type="button" class="button" data-import-action="rollback">Letzten Lauf zurückrollen</button>
			<button type="button" class="button" data-import-action="reset">Protokoll zurücksetzen</button>
		</div>
		<details class="leadwerk-importer__force">
			<summary>Destruktiver Force reset</summary>
			<p>Überschreibt auch Werte, die Redakteure seit dem letzten Import geändert haben. Zum Bestätigen <code>RESET</code> eingeben.</p>
			<input type="text" data-force-confirm autocomplete="off">
			<button type="button" class="button" data-import-action="force">Force reset ausführen</button>
		</details>
		<div class="leadwerk-importer__status" aria-live="polite">
			<strong>Status:</strong> <span data-import-status>Noch nicht gestartet.</span>
		</div>
		<pre data-import-log></pre>
	</div>
	<?php
}

function leadwerk_importer_ajax_guard() {
	check_ajax_referer( 'leadwerk_t2med_import', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Keine Berechtigung.' ), 403 );
	}
}

add_action(
	'wp_ajax_leadwerk_import_start',
	static function () {
		leadwerk_importer_ajax_guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- leadwerk_importer_ajax_guard() verifies the nonce above.
		$dry = isset( $_POST['dry_run'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['dry_run'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- leadwerk_importer_ajax_guard() verifies the nonce above.
		$force = isset( $_POST['force'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['force'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- leadwerk_importer_ajax_guard() verifies the nonce above.
		$confirmation = isset( $_POST['confirm_force'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm_force'] ) ) : '';
		if ( $force && 'RESET' !== $confirmation ) {
			wp_send_json_error( array( 'message' => 'Force reset wurde nicht mit RESET bestätigt.' ), 400 );
		}
		wp_send_json_success( Leadwerk_Importer::start( $dry, $force ) );
	}
);

add_action(
	'wp_ajax_leadwerk_import_step',
	static function () {
		leadwerk_importer_ajax_guard();
		wp_send_json_success( Leadwerk_Importer::run_next() );
	}
);

add_action(
	'wp_ajax_leadwerk_import_rollback',
	static function () {
		leadwerk_importer_ajax_guard();
		wp_send_json_success( Leadwerk_Importer::rollback() );
	}
);

add_action(
	'wp_ajax_leadwerk_import_reset',
	static function () {
		leadwerk_importer_ajax_guard();
		Leadwerk_Importer::reset_journal();
		wp_send_json_success( array() );
	}
);

add_action(
	'admin_notices',
	static function () {
		if ( ! current_user_can( 'manage_options' ) || '1' !== get_option( Leadwerk_Importer::NOTICE_OPTION, '' ) ) {
			return;
		}
		$status = function_exists( 'leadwerk_get_option' ) ? leadwerk_get_option( 'legal_review_status', 'pending' ) : 'pending';
		if ( 'approved' === $status ) {
			delete_option( Leadwerk_Importer::NOTICE_OPTION );
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>T2med:</strong> Impressum und Datenschutz wurden als Ausgangstext importiert. Geschäftsführung, WPForms, YouTube, Consent, Hosting und Löschfristen müssen vor dem Livegang rechtlich geprüft werden.</p></div>';
	}
);
