<?php
/**
 * Standalone smoke test for Leadwerk pipeline continuation tickets.
 * Run with: php tests/pipeline-security-smoke.php
 */

define( 'ABSPATH', '/srv/www/site/' );
define( 'LEADWERK_MIGRATION_SECRET_KEY', 'leadwerk_migration_secret_key' );
define( 'DAY_IN_SECONDS', 86400 );
$_SERVER['DOCUMENT_ROOT'] = '/srv/www';
$GLOBALS['leadwerk_pipeline_options'] = array();
$GLOBALS['leadwerk_pipeline_caps'] = array( 'manage_options', 'leadwerk_migration_import_site' );

function get_option( $name, $default = false ) {
	if ( $name === LEADWERK_MIGRATION_SECRET_KEY ) {
		return str_repeat( 'a', 64 );
	}
	if ( array_key_exists( $name, $GLOBALS['leadwerk_pipeline_options'] ) ) {
		return $GLOBALS['leadwerk_pipeline_options'][ $name ];
	}
	return $default;
}

function wp_generate_password( $length ) {
	return str_repeat( 'b', $length );
}

function wp_rand() {
	return 42;
}

function wp_salt() {
	return str_repeat( 'c', 64 );
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function get_current_user_id() {
	return 7;
}

function current_user_can( $capability ) {
	return in_array( $capability, $GLOBALS['leadwerk_pipeline_caps'], true );
}

function wp_verify_nonce( $nonce, $action ) {
	return $nonce === 'valid-nonce' && in_array( $action, array( 'leadwerk_migration_export', 'leadwerk_migration_import' ), true );
}

function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', $path );
}

require dirname( __DIR__ ) . '/lib/model/class-leadwerk_migration-security.php';

function leadwerk_pipeline_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$params = array(
	'storage'                            => 'Job_12345678',
	'archive'                            => 'trusted.wpress',
	'priority'                           => 100,
	'leadwerk_migration_manual_restore' => 1,
);

$ticket = Leadwerk_Migration_Security::issue_pipeline_token( 'import', $params );
leadwerk_pipeline_assert( is_string( $ticket ), 'ticket should be issued' );
leadwerk_pipeline_assert( Leadwerk_Migration_Security::verify_pipeline_token( 'import', $params, $ticket ), 'valid ticket should verify' );

$changed_job            = $params;
$changed_job['storage'] = 'Job_87654321';
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::verify_pipeline_token( 'import', $changed_job, $ticket ), 'ticket must be job-bound' );

$changed_archive            = $params;
$changed_archive['archive'] = 'other.wpress';
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::verify_pipeline_token( 'import', $changed_archive, $ticket ), 'ticket must be archive-bound' );

$changed_mode = $params;
unset( $changed_mode['leadwerk_migration_manual_restore'] );
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::verify_pipeline_token( 'import', $changed_mode, $ticket ), 'ticket must be source-mode-bound' );

$changed_priority             = $params;
$changed_priority['priority'] = 150;
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::verify_pipeline_token( 'import', $changed_priority, $ticket ), 'ticket must be priority-bound' );

$changed_confirmation                                  = $params;
$changed_confirmation['leadwerk_migration_confirmed'] = 1;
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::verify_pipeline_token( 'import', $changed_confirmation, $ticket ), 'ticket must bind confirmation state' );
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::verify_pipeline_token( 'export', $params, $ticket ), 'ticket must be operation-bound' );

$tampered = substr( $ticket, 0, -1 ) . ( substr( $ticket, -1 ) === '0' ? '1' : '0' );
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::verify_pipeline_token( 'import', $params, $tampered ), 'tampered signature must fail' );

$ticketed_browser_params                                = $params;
$ticketed_browser_params['_leadwerk_migration_nonce']  = 'valid-nonce';
$ticketed_browser_ticket                                = Leadwerk_Migration_Security::issue_pipeline_token( 'import', $ticketed_browser_params );
$ticketed_browser_params[ Leadwerk_Migration_Security::PIPELINE_TOKEN_PARAM ] = $ticketed_browser_ticket;
leadwerk_pipeline_assert( Leadwerk_Migration_Security::authorize_pipeline( 'import', $ticketed_browser_params ), 'authenticated continuation with an exact ticket should be authorized' );

$browser_params = array(
	'storage'                     => 'Interactive_123',
	'archive'                     => 'trusted.wpress',
	'priority'                    => 10,
	'_leadwerk_migration_nonce'   => 'valid-nonce',
);
leadwerk_pipeline_assert( Leadwerk_Migration_Security::authorize_pipeline( 'import', $browser_params ), 'initial authenticated import priority should be authorized' );

$upload_params             = $browser_params;
$upload_params['priority'] = 5;
leadwerk_pipeline_assert( Leadwerk_Migration_Security::authorize_pipeline( 'import', $upload_params ), 'authenticated multipart upload priority should be authorized' );

$arbitrary_jump             = $browser_params;
$arbitrary_jump['priority'] = 200;
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::authorize_pipeline( 'import', $arbitrary_jump ), 'nonce alone must not authorize an arbitrary import priority' );

$status_option = 'leadwerk_migration_status_' . $browser_params['storage'];
$GLOBALS['leadwerk_pipeline_options'][ $status_option ] = array(
	'type'                   => 'confirm',
	'archive'                => 'trusted.wpress',
	'legacy_unauthenticated' => true,
);
$legacy_resume             = $browser_params;
$legacy_resume['priority'] = 150;
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::authorize_pipeline( 'import', $legacy_resume ), 'legacy confirmation must require the explicit acknowledgement parameter' );
$legacy_resume['leadwerk_migration_allow_legacy_unauthenticated'] = 1;
leadwerk_pipeline_assert( Leadwerk_Migration_Security::authorize_pipeline( 'import', $legacy_resume ), 'legacy confirmation should resume after explicit acknowledgement' );

$GLOBALS['leadwerk_pipeline_options'][ $status_option ] = array(
	'type'                   => 'confirm',
	'archive'                => 'trusted.wpress',
	'legacy_unauthenticated' => false,
);
$modern_resume             = $browser_params;
$modern_resume['priority'] = 150;
leadwerk_pipeline_assert( Leadwerk_Migration_Security::authorize_pipeline( 'import', $modern_resume ), 'authenticated archive confirmation should remain usable without the legacy acknowledgement' );

$mismatched_archive            = $modern_resume;
$mismatched_archive['archive'] = 'other.wpress';
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::authorize_pipeline( 'import', $mismatched_archive ), 'interactive resume must match the job-scoped archive' );

$GLOBALS['leadwerk_pipeline_options'][ $status_option ] = array(
	'type'    => 'blogs',
	'archive' => 'trusted.wpress',
);
leadwerk_pipeline_assert( Leadwerk_Migration_Security::authorize_pipeline( 'import', $modern_resume ), 'multisite selection should remain resumable from its matching status' );

$GLOBALS['leadwerk_pipeline_options'][ $status_option ] = array(
	'type'    => 'backup_is_encrypted',
	'archive' => 'trusted.wpress',
);
$decrypt_resume             = $browser_params;
$decrypt_resume['priority'] = 75;
leadwerk_pipeline_assert( Leadwerk_Migration_Security::authorize_pipeline( 'import', $decrypt_resume ), 'decrypt prompt should authorize only its matching resume priority' );
$decrypt_resume['priority'] = 150;
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::authorize_pipeline( 'import', $decrypt_resume ), 'decrypt status must not authorize confirmation priority' );

$GLOBALS['leadwerk_pipeline_options'][ $status_option ] = array(
	'type'    => 'info',
	'archive' => 'trusted.wpress',
);
$cancel_import                                      = $browser_params;
$cancel_import['priority']                          = 400;
$cancel_import['leadwerk_migration_import_cancel'] = 1;
leadwerk_pipeline_assert( Leadwerk_Migration_Security::authorize_pipeline( 'import', $cancel_import ), 'active import should permit an explicit cancel action' );
unset( $cancel_import['leadwerk_migration_import_cancel'] );
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::authorize_pipeline( 'import', $cancel_import ), 'cleanup priority without a cancel marker must fail' );

$early_cancel_import = array(
	'storage'                             => 'EarlyJob_123456',
	'priority'                            => 400,
	'_leadwerk_migration_nonce'           => 'valid-nonce',
	'leadwerk_migration_import_cancel'    => 1,
);
leadwerk_pipeline_assert( Leadwerk_Migration_Security::authorize_pipeline( 'import', $early_cancel_import ), 'early import cancel must work before the first status write' );

$GLOBALS['leadwerk_pipeline_options'][ $status_option ] = array(
	'type'    => 'done',
	'archive' => 'trusted.wpress',
);
$late_terminal_cancel                                      = $browser_params;
$late_terminal_cancel['priority']                          = 400;
$late_terminal_cancel['leadwerk_migration_import_cancel'] = 1;
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::authorize_pipeline( 'import', $late_terminal_cancel ), 'terminal import jobs must refuse further cancel resumes' );

$export_params = array(
	'storage'                             => 'ExportJob_123',
	'priority'                            => 300,
	'_leadwerk_migration_nonce'           => 'valid-nonce',
	'leadwerk_migration_export_cancel'    => 1,
);
$GLOBALS['leadwerk_pipeline_options'][ 'leadwerk_migration_status_' . $export_params['storage'] ] = array( 'type' => 'progress' );
leadwerk_pipeline_assert( Leadwerk_Migration_Security::authorize_pipeline( 'export', $export_params ), 'active export should permit an explicit cancel action' );
unset( $export_params['leadwerk_migration_export_cancel'] );
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::authorize_pipeline( 'export', $export_params ), 'export cleanup priority without a cancel marker must fail' );
$early_export_cancel = array(
	'storage'                          => 'ExportEarly_123',
	'priority'                         => 300,
	'_leadwerk_migration_nonce'        => 'valid-nonce',
	'leadwerk_migration_export_cancel' => 1,
);
leadwerk_pipeline_assert( Leadwerk_Migration_Security::authorize_pipeline( 'export', $early_export_cancel ), 'early export cancel must work before the first status write' );
$GLOBALS['leadwerk_pipeline_options'][ 'leadwerk_migration_status_' . $export_params['storage'] ] = array( 'type' => 'download' );
$export_params['leadwerk_migration_export_cancel'] = 1;
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::authorize_pipeline( 'export', $export_params ), 'terminal export jobs must refuse further cancel resumes' );
$initial_export             = $export_params;
$initial_export['priority'] = 5;
unset( $initial_export['leadwerk_migration_export_cancel'] );
leadwerk_pipeline_assert( Leadwerk_Migration_Security::authorize_pipeline( 'export', $initial_export ), 'initial authenticated export priority should remain authorized' );
$GLOBALS['leadwerk_pipeline_caps'] = array( 'export' );
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::authorize_pipeline( 'export', $initial_export ), 'an editor-level export capability must not authorize full-site export' );
$GLOBALS['leadwerk_pipeline_caps'] = array( 'manage_options', 'leadwerk_migration_import_site' );

$form_state = $params;
$form_state['archive_crc'] = null;
$form_state['archive_size'] = null;
$form_state['empty'] = array();
$form_state['nested'] = array( 'ignored' => null, 'kept' => true );
$form_ticket = Leadwerk_Migration_Security::issue_pipeline_token( 'import', $form_state );
parse_str( http_build_query( $form_state ), $posted_state );
leadwerk_pipeline_assert( Leadwerk_Migration_Security::verify_pipeline_token( 'import', $posted_state, $form_ticket ), 'ticket state must survive PHP form encoding' );

// Browser JSON → jQuery.param turns PHP false/true into the strings "false"/"true".
$chunk_state = $params;
$chunk_state['priority'] = 170;
$chunk_state['completed'] = false;
$chunk_state['file_bytes_offset'] = 4096;
$chunk_state['plugins_bytes_offset'] = 8192;
$chunk_ticket = Leadwerk_Migration_Security::issue_pipeline_token( 'import', $chunk_state );
$jquery_state = $chunk_state;
$jquery_state['completed'] = 'false';
$jquery_state['file_bytes_offset'] = '4096';
$jquery_state['plugins_bytes_offset'] = '8192';
leadwerk_pipeline_assert( Leadwerk_Migration_Security::verify_pipeline_token( 'import', $jquery_state, $chunk_ticket ), 'ticket state must survive jQuery boolean string round trips' );
$jquery_state['completed'] = 'true';
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::verify_pipeline_token( 'import', $jquery_state, $chunk_ticket ), 'changed completion flag must invalidate the ticket' );

leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::path_is_private( '/srv/www/site/wp-content/private' ), 'site path must not be considered private' );
leadwerk_pipeline_assert( Leadwerk_Migration_Security::path_is_private( sys_get_temp_dir() . '/leadwerk-private-smoke' ), 'path outside document root should be private' );
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::path_is_private( 'relative/private' ), 'relative path must fail closed' );
leadwerk_pipeline_assert( ! Leadwerk_Migration_Security::path_is_private( sys_get_temp_dir() . '/missing-parent-' . uniqid() . '/private' ), 'unresolvable parent must fail closed' );

fwrite( STDOUT, "Leadwerk pipeline security smoke test passed.\n" );
