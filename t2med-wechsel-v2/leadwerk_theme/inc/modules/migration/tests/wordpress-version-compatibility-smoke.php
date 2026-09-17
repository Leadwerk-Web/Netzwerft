<?php
/**
 * Standalone smoke test for strict WordPress version import compatibility.
 * Run with: php tests/wordpress-version-compatibility-smoke.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'LEADWERK_MIGRATION_VERSION', '1.5.13' );

class Leadwerk_Migration_Compatibility_Exception extends Exception {}

class Leadwerk_Migration_Status {
	public static $messages = array();

	public static function info( $message ) {
		self::$messages[] = $message;
	}
}

class Leadwerk_Migration_Site_Identity {
	public static function prepare_import_identity() {
		return true;
	}

	public static function php_versions_compatible( $source, $target ) {
		$branch = static function ( $version ) {
			return preg_match( '/\A([0-9]+)\.([0-9]+)(?:\.[0-9]+(?:[A-Za-z0-9._+~-]*))?\z/D', (string) $version, $matches ) ? (int) $matches[1] . '.' . (int) $matches[2] : '';
		};
		$source_branch = $branch( $source );
		$target_branch = $branch( $target );
		return $source_branch !== '' && $source_branch === $target_branch;
	}
}

function is_wp_error() {
	return false;
}

function __( $message ) {
	return $message;
}

function esc_html( $value ) {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

function wp_kses( $value ) {
	return $value;
}

function leadwerk_migration_allowed_html_tags() {
	return array( 'strong' => array() );
}

function leadwerk_migration_package_path( $params ) {
	return $params['package_path'];
}

function leadwerk_migration_open( $path, $mode ) {
	return fopen( $path, $mode );
}

function leadwerk_migration_read( $handle, $length ) {
	return fread( $handle, $length );
}

function leadwerk_migration_close( $handle ) {
	return fclose( $handle );
}

function leadwerk_version_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function leadwerk_version_package( $version = null, $php_version = null, $plugin_version = null ) {
	$package = array();
	if ( $version !== null ) {
		$package['WordPress'] = array( 'Version' => $version );
	}
	if ( $php_version !== null ) {
		$package['PHP'] = array( 'Version' => $php_version );
	}
	if ( $plugin_version !== null ) {
		$package['Plugin'] = array( 'Version' => $plugin_version );
	}

	$path = tempnam( sys_get_temp_dir(), 'lwm-wp-version-' );
	file_put_contents( $path, json_encode( $package ) );

	return $path;
}

require_once dirname( __DIR__ ) . '/lib/model/import/class-leadwerk_migration-import-wordpress-version.php';

$wp_version = '7.0.4';
$matching   = leadwerk_version_package( '7.0.4' );
$params     = array( 'package_path' => $matching, 'sentinel' => true );
$result     = Leadwerk_Migration_Import_WordPress_Version::execute( $params );
leadwerk_version_assert( $result === $params, 'matching versions should continue the import' );
unlink( $matching );

$runtime_patch = leadwerk_version_package( '7.0.4', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.999', LEADWERK_MIGRATION_VERSION );
$runtime_params = array( 'package_path' => $runtime_patch, 'leadwerk_migration_sync_job_id' => str_repeat( 'a', 32 ) );
leadwerk_version_assert( Leadwerk_Migration_Import_WordPress_Version::execute( $runtime_params ) === $runtime_params, 'destination import gate must allow a PHP patch difference in the same major.minor branch' );
unlink( $runtime_patch );

$other_minor = PHP_MINOR_VERSION > 0 ? PHP_MINOR_VERSION - 1 : PHP_MINOR_VERSION + 1;
$runtime_branch = leadwerk_version_package( '7.0.4', PHP_MAJOR_VERSION . '.' . $other_minor . '.999', LEADWERK_MIGRATION_VERSION );
$runtime_branch_blocked = false;
try {
	Leadwerk_Migration_Import_WordPress_Version::execute( array( 'package_path' => $runtime_branch, 'leadwerk_migration_sync_job_id' => str_repeat( 'b', 32 ) ) );
} catch ( Leadwerk_Migration_Compatibility_Exception $error ) {
	$runtime_branch_blocked = strpos( $error->getMessage(), 'major.minor branches must match' ) !== false;
}
leadwerk_version_assert( $runtime_branch_blocked, 'destination import gate must block a different PHP major.minor branch' );
unlink( $runtime_branch );

$mismatch_blocked = false;
$mismatching      = leadwerk_version_package( '7.0.2' );
try {
	Leadwerk_Migration_Import_WordPress_Version::execute( array( 'package_path' => $mismatching ) );
} catch ( Leadwerk_Migration_Compatibility_Exception $error ) {
	$mismatch_blocked = strpos( $error->getMessage(), '7.0.2' ) !== false && strpos( $error->getMessage(), '7.0.4' ) !== false;
}
leadwerk_version_assert( $mismatch_blocked, 'different source and destination versions must be blocked with both versions in the error' );
unlink( $mismatching );

$missing_blocked = false;
$missing         = leadwerk_version_package();
try {
	Leadwerk_Migration_Import_WordPress_Version::execute( array( 'package_path' => $missing ) );
} catch ( Leadwerk_Migration_Compatibility_Exception $error ) {
	$missing_blocked = strpos( $error->getMessage(), 'does not contain a valid source WordPress version' ) !== false;
}
leadwerk_version_assert( $missing_blocked, 'missing source version metadata must be blocked' );
unlink( $missing );

$wp_version          = '';
$unknown_destination = false;
$source_known        = leadwerk_version_package( '7.0.4' );
try {
	Leadwerk_Migration_Import_WordPress_Version::execute( array( 'package_path' => $source_known ) );
} catch ( Leadwerk_Migration_Compatibility_Exception $error ) {
	$unknown_destination = strpos( $error->getMessage(), 'destination WordPress version could not be determined' ) !== false;
}
leadwerk_version_assert( $unknown_destination, 'an unknown destination version must be blocked' );
unlink( $source_known );

fwrite( STDOUT, "WordPress version compatibility smoke tests passed.\n" );
