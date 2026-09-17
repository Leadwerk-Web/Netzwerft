<?php
/** Standalone checks for blank-target family isolation and populated origins. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'LEADWERK_MIGRATION_SYNC_FAMILY', 'leadwerk_migration_sync_family' );
define( 'LEADWERK_MIGRATION_SYNC_ENVIRONMENT', 'leadwerk_migration_sync_environment' );
define( 'LEADWERK_MIGRATION_SYNC_SETTINGS', 'leadwerk_migration_sync_settings' );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

class Leadwerk_Migration_Security {
	private static $counter = 0;
	public static function job_id() { self::$counter++; return str_pad( dechex( self::$counter ), 32, '0', STR_PAD_LEFT ); }
	public static function generate_secret() { return str_repeat( 'a', 64 ); }
}

class Leadwerk_Fake_WPDB {
	public $posts = 'wp_posts';
	public $meaningful = false;
	public function get_var( $query ) { return $this->meaningful ? '6' : '0'; }
}

$leadwerk_options = array( 'fresh_site' => '1' );
$wpdb = new Leadwerk_Fake_WPDB();

function get_option( $key, $default = false ) { global $leadwerk_options; return array_key_exists( $key, $leadwerk_options ) ? $leadwerk_options[ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { global $leadwerk_options; $leadwerk_options[ $key ] = $value; return true; }
function delete_option( $key ) { global $leadwerk_options; unset( $leadwerk_options[ $key ] ); return true; }
function sanitize_text_field( $value ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_http_validate_url( $url ) { return false !== filter_var( $url, FILTER_VALIDATE_URL ); }
function esc_url_raw( $url ) { return filter_var( (string) $url, FILTER_SANITIZE_URL ); }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
function home_url( $path = '' ) { return 'http://blank.local' . ( $path ? '/' . ltrim( $path, '/' ) : '' ); }
function get_bloginfo( $key ) { return 'Blank Restore Target'; }
function __( $message ) { return $message; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

function leadwerk_family_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

require_once dirname( __DIR__ ) . '/lib/model/class-leadwerk_migration-site-identity.php';

$blank = Leadwerk_Migration_Site_Identity::maybe_originate_family();
leadwerk_family_assert( is_wp_error( $blank ) && 'leadwerk_sync_manual_family_required' === $blank->get_error_code(), 'a WordPress installation must not create a Family without the explicit admin action' );
leadwerk_family_assert( array() === get_option( LEADWERK_MIGRATION_SYNC_FAMILY, array() ), 'an empty WordPress must not persist a provisional family' );
$blank_environment = Leadwerk_Migration_Site_Identity::ensure_environment();
leadwerk_family_assert( Leadwerk_Migration_Site_Identity::valid_id( isset( $blank_environment['environment_id'] ) ? $blank_environment['environment_id'] : '' ), 'an empty target may prepare only its unique environment identity' );

$metadata = Leadwerk_Migration_Site_Identity::archive_metadata();
leadwerk_family_assert( '' === $metadata['FamilyId'] && array() === get_option( LEADWERK_MIGRATION_SYNC_FAMILY, array() ), 'archive metadata must not create a family as a side effect' );

$legacy_family = array( 'family_id' => str_repeat( 'f', 32 ), 'family_secret' => str_repeat( 'b', 64 ), 'zone_zero_url' => 'http://blank.local', 'version' => 1 );
update_option( LEADWERK_MIGRATION_SYNC_FAMILY, $legacy_family, false );
leadwerk_family_assert( Leadwerk_Migration_Site_Identity::discard_legacy_blank_family(), 'a local-only pre-1.5.8 provisional family must be discarded on an empty target' );
leadwerk_family_assert( array() === get_option( LEADWERK_MIGRATION_SYNC_FAMILY, array() ), 'legacy provisional-family cleanup must leave the target family-less' );

$wpdb->meaningful = true;
$still_fresh = Leadwerk_Migration_Site_Identity::maybe_originate_family();
leadwerk_family_assert( is_wp_error( $still_fresh ), 'site content must never create a Family as a side effect' );
update_option( 'fresh_site', '0', false );
$origin = Leadwerk_Migration_Site_Identity::originate_family( 'Filled Origin' );
leadwerk_family_assert( ! is_wp_error( $origin ) && Leadwerk_Migration_Site_Identity::valid_id( $origin['family_id'] ), 'the explicit admin action must create a valid family' );
leadwerk_family_assert( 'manual' === $origin['origin_mode'] && 'Filled Origin' === $origin['project_name'], 'new origins must record their explicit creation mode' );

$wpdb->meaningful = false;
$reused = Leadwerk_Migration_Site_Identity::maybe_originate_family();
leadwerk_family_assert( ! is_wp_error( $reused ) && $origin['family_id'] === $reused['family_id'], 'a restored target must reuse its inherited family even when origin content detection is unavailable' );
leadwerk_family_assert( ! Leadwerk_Migration_Site_Identity::discard_legacy_blank_family(), 'a content-qualified or inherited family must not be discarded' );

fwrite( STDOUT, "Leadwerk Site Family origin smoke tests passed.\n" );
