<?php
/** Standalone smoke checks for family-scoped automatic WordPress commands. */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {}

class Leadwerk_Migration_Sync {
	public static $requests = array();
	public static function request( $route, $payload ) {
		self::$requests[] = array( 'route' => $route, 'payload' => $payload );
		return $payload;
	}
}

class Leadwerk_Migration_Site_Identity {
	public static function php_versions_compatible() { return true; }
}

function __( $message ) { return $message; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function get_bloginfo() { return $GLOBALS['leadwerk_test_wordpress_version']; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

function leadwerk_wordpress_update_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

require_once dirname( __DIR__ ) . '/lib/model/class-leadwerk_migration-runtime-reconcile.php';

$environment_id = str_repeat( 'a', 32 );
$command = array(
	'command_id' => str_repeat( 'b', 32 ),
	'target_environment_id' => $environment_id,
	'command_type' => 'wordpress_update',
	'status' => 'queued',
	'payload' => array( 'wordpress_version' => '7.0.4', 'stage' => 'wordpress', 'automatic' => true ),
);
$environment = array( 'environment_id' => $environment_id );

$GLOBALS['leadwerk_test_wordpress_version'] = '7.0.4';
Leadwerk_Migration_Runtime_Reconcile::process( array( $command ), $environment );
$report = end( Leadwerk_Migration_Sync::$requests );
leadwerk_wordpress_update_assert( $report['payload']['status'] === 'complete' && $report['payload']['stage'] === 'complete', 'a target already at the family version must complete verification' );

Leadwerk_Migration_Sync::$requests = array();
$GLOBALS['leadwerk_test_wordpress_version'] = '7.0.5';
Leadwerk_Migration_Runtime_Reconcile::process( array( $command ), $environment );
$report = end( Leadwerk_Migration_Sync::$requests );
leadwerk_wordpress_update_assert( $report['payload']['status'] === 'manual_required' && $report['payload']['stage'] === 'wordpress', 'family automation must refuse a WordPress downgrade' );

fwrite( STDOUT, "Automatic family WordPress update smoke tests passed.\n" );
