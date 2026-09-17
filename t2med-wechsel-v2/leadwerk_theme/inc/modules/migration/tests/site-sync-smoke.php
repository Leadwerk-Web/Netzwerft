<?php
/** Standalone smoke checks for Site Sync detection and fail-closed preflight. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'LEADWERK_MIGRATION_VERSION', '1.5.13' );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $message ) { return $message; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

function leadwerk_sync_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

require_once dirname( __DIR__ ) . '/lib/model/class-leadwerk_migration-site-identity.php';
require_once dirname( __DIR__ ) . '/lib/model/class-leadwerk_migration-sync-hub.php';
require_once dirname( __DIR__ ) . '/lib/model/class-leadwerk_migration-sync.php';

leadwerk_sync_assert( Leadwerk_Migration_Site_Identity::environment_type( 'http://netzwerft.local' ) === 'local', 'a .local host must be local' );
leadwerk_sync_assert( Leadwerk_Migration_Site_Identity::environment_type( 'https://b9yyrjh.myrdbx.io' ) === 'staging', 'myrdbx.io must be staging' );
leadwerk_sync_assert( Leadwerk_Migration_Site_Identity::environment_type( 'https://example.com' ) === 'live', 'a public host must default to live' );

$fresh = array( 'last_seen_at' => gmdate( 'Y-m-d H:i:s', time() - 899 ) );
$stale = array( 'last_seen_at' => gmdate( 'Y-m-d H:i:s', time() - 901 ) );
leadwerk_sync_assert( Leadwerk_Migration_Sync_Hub::environment_is_online( $fresh ), 'a recent heartbeat must be online' );
leadwerk_sync_assert( ! Leadwerk_Migration_Sync_Hub::environment_is_online( $stale ), 'a heartbeat older than 15 minutes must be offline' );

$source = array( 'wordpress_version' => '7.0.4', 'php_version' => '8.4.2', 'plugin_version' => LEADWERK_MIGRATION_VERSION );
$target = $source;
leadwerk_sync_assert( Leadwerk_Migration_Sync::version_preflight( $source, $target ) === true, 'equal versions must pass' );
$target['php_version'] = '8.4.67';
leadwerk_sync_assert( Leadwerk_Migration_Sync::version_preflight( $source, $target ) === true, 'PHP patch differences in the same major.minor branch must pass' );
$target['wordpress_version'] = '7.0.3';
$error = Leadwerk_Migration_Sync::version_preflight( $source, $target );
leadwerk_sync_assert( is_wp_error( $error ) && $error->get_error_code() === 'leadwerk_sync_wordpress_version_mismatch', 'WordPress mismatch must be the first failed gate' );
$target = $source;
$source['php_version'] = '8.3.5';
$target['php_version'] = '8.1.34';
$error = Leadwerk_Migration_Sync::version_preflight( $source, $target );
leadwerk_sync_assert( is_wp_error( $error ) && $error->get_error_code() === 'leadwerk_sync_php_version_mismatch', 'different PHP major.minor branches must block sync' );
leadwerk_sync_assert( Leadwerk_Migration_Site_Identity::php_branch( '8.4.67' ) === '8.4', 'PHP branch normalization must ignore patch versions' );
leadwerk_sync_assert( Leadwerk_Migration_Site_Identity::php_branch( '8.4.67-1ubuntu.1' ) === '8.4', 'PHP branch normalization must accept a valid vendor suffix' );
leadwerk_sync_assert( Leadwerk_Migration_Site_Identity::php_branch( 'invalid' ) === '', 'invalid PHP versions must fail closed' );
leadwerk_sync_assert( Leadwerk_Migration_Site_Identity::php_branch( '8.4.' ) === '', 'incomplete PHP patch versions must fail closed' );

$family_a = str_repeat( 'a', 32 );
$family_b = str_repeat( 'b', 32 );
$environment_rows = array(
	array( 'environment_id' => str_repeat( '1', 32 ), 'family_id' => $family_a, 'wordpress_version' => '7.0.2', 'plugin_version' => LEADWERK_MIGRATION_VERSION ),
	array( 'environment_id' => str_repeat( '2', 32 ), 'family_id' => $family_a, 'wordpress_version' => '7.0.4', 'plugin_version' => LEADWERK_MIGRATION_VERSION ),
	array( 'environment_id' => str_repeat( '3', 32 ), 'family_id' => $family_b, 'wordpress_version' => '6.9.1', 'plugin_version' => LEADWERK_MIGRATION_VERSION ),
	array( 'environment_id' => str_repeat( '4', 32 ), 'family_id' => $family_b, 'wordpress_version' => '7.1.0', 'plugin_version' => '1.4.0' ),
);
$method = new ReflectionMethod( 'Leadwerk_Migration_Sync_Hub', 'family_wordpress_upgrade_targets' );
$targets = $method->invoke( null, $environment_rows );
leadwerk_sync_assert( count( $targets ) === 1, 'only lower family members with the current Migration worker may be targeted' );
leadwerk_sync_assert( $targets[0]['environment']['environment_id'] === str_repeat( '1', 32 ) && $targets[0]['target_version'] === '7.0.4', 'WordPress targets must use the highest version from the same family only' );

$canonical = Leadwerk_Migration_Sync_Hub::canonical_request( 'post', '/leadwerk/v1/hub/heartbeat', 123, str_repeat( 'a', 32 ), '{"ok":true}' );
leadwerk_sync_assert( substr_count( $canonical, "\n" ) === 4 && strpos( $canonical, 'POST' ) === 0, 'signed request canonicalization must be stable' );

$scheduler_source = file_get_contents( dirname( __DIR__ ) . '/lib/model/class-leadwerk_migration-scheduler.php' );
$sync_source      = file_get_contents( dirname( __DIR__ ) . '/lib/model/class-leadwerk_migration-sync.php' );
$controller_source = file_get_contents( dirname( __DIR__ ) . '/lib/controller/class-leadwerk_migration-sync-controller.php' );
$import_controller_source = file_get_contents( dirname( __DIR__ ) . '/lib/controller/class-leadwerk_migration-import-controller.php' );
$main_controller_source = file_get_contents( dirname( __DIR__ ) . '/lib/controller/class-leadwerk_migration-main-controller.php' );
$rest_controller_source = file_get_contents( dirname( __DIR__ ) . '/lib/controller/class-leadwerk_migration-rest-controller.php' );
$security_source = file_get_contents( dirname( __DIR__ ) . '/lib/model/class-leadwerk_migration-security.php' );
$hub_source = file_get_contents( dirname( __DIR__ ) . '/lib/model/class-leadwerk_migration-sync-hub.php' );
$runtime_source = file_get_contents( dirname( __DIR__ ) . '/lib/model/class-leadwerk_migration-runtime-reconcile.php' );
$release_source = file_get_contents( dirname( __DIR__ ) . '/lib/model/class-leadwerk_migration-release.php' );
$import_gate_source = file_get_contents( dirname( __DIR__ ) . '/lib/model/import/class-leadwerk_migration-import-wordpress-version.php' );
$sync_controller_source = file_get_contents( dirname( __DIR__ ) . '/lib/controller/class-leadwerk_migration-sync-controller.php' );
$sync_view_source = file_get_contents( dirname( __DIR__ ) . '/lib/view/sync/index.php' );
$sync_javascript_source = file_get_contents( dirname( __DIR__ ) . '/lib/view/assets/javascript/leadwerk-sync.js' );
$sync_css_source = file_get_contents( dirname( __DIR__ ) . '/lib/view/assets/css/leadwerk-sync.css' );
$constants_source = file_get_contents( dirname( __DIR__ ) . '/constants.php' );
$import_content_source = file_get_contents( dirname( __DIR__ ) . '/lib/model/import/class-leadwerk_migration-import-content.php' );
leadwerk_sync_assert( is_string( $scheduler_source ) && strpos( $scheduler_source, "'sync' === \$origin ? array()" ) !== false, 'Site Sync exports must ignore routine backup exclusions' );
leadwerk_sync_assert( is_string( $sync_source ) && substr_count( $sync_source, "Leadwerk_Migration_Scheduler::run( 'sync' )" ) === 2, 'source and target safety exports must both use full sync mode' );
leadwerk_sync_assert( is_string( $sync_source ) && strpos( $sync_source, "'complete' === \$job['status']" ) !== false && strpos( $sync_source, "'sync' !== \$manifests[ \$archive ]['source']" ) !== false && strpos( $sync_source, 'Leadwerk_Migration_Backups::delete_file( $archive )' ) !== false, 'source backup cleanup must require completed Hub state and a sync-origin manifest' );
leadwerk_sync_assert( is_string( $controller_source ) && strpos( $controller_source, 'SYNC LIVE' ) === false, 'typed live approval must not remain in the zero-touch transfer controller' );
leadwerk_sync_assert( is_string( $hub_source ) && strpos( $hub_source, 'leadwerk_runtime_bootstrap_required' ) !== false, 'pre-1.3 targets must fail with an explicit bootstrap requirement' );
leadwerk_sync_assert( is_string( $hub_source ) && strpos( $hub_source, "hash_file( 'sha256', \$path )" ) !== false, 'Hub plugin packages must publish SHA-256 integrity metadata' );
leadwerk_sync_assert( is_string( $hub_source ) && strpos( $hub_source, "public static function cancel_command" ) !== false && strpos( $hub_source, "public static function retry_command" ) !== false, 'Site Families must be able to manage their runtime command history' );
leadwerk_sync_assert( is_string( $runtime_source ) && strpos( $runtime_source, "'leadwerk_migration_php_runtime_update'" ) !== false, 'PHP runtime reconciliation must require an explicit hosting connector' );
leadwerk_sync_assert( is_string( $runtime_source ) && strpos( $runtime_source, 'sys_get_temp_dir()' ) !== false && strpos( $runtime_source, 'path_is_private( $directory )' ) !== false && strpos( $runtime_source, "fopen( \$probe, 'x' )" ) !== false, 'runtime package storage must use a verified private writable fallback' );
leadwerk_sync_assert( is_string( $import_gate_source ) && strpos( $import_gate_source, 'php_versions_compatible( $source_php, PHP_VERSION )' ) !== false, 'destination pre-import verification must use the same PHP branch rule' );
leadwerk_sync_assert( is_string( $sync_source ) && strpos( $sync_source, '$processed[] = $runtime;' ) !== false && strpos( $sync_source, 'return $processed;' ) !== false, 'runtime mutation must prevent a transfer in the same worker request while preserving completed cleanup results' );
leadwerk_sync_assert( is_string( $sync_source ) && strpos( $sync_source, "'/worker/wake'" ) !== false && strpos( $sync_source, 'X-Leadwerk-Wake-Signature' ) !== false && strpos( $sync_source, 'leadwerk_worker_wake_replay' ) !== false, 'worker wake receiver must authenticate signed, replay-resistant Hub events' );
leadwerk_sync_assert( is_string( $sync_source ) && strpos( $sync_source, 'schedule_fast_worker()' ) !== false && strpos( $sync_source, 'result_needs_followup' ) !== false, 'partial batches and running commands must schedule immediate follow-up work' );
leadwerk_sync_assert( is_string( $sync_source ) && strpos( $sync_source, "array( 'approved', 'transferring', 'receiving' )" ) !== false, 'direct-push sources must continue after Hub advances an active transfer to receiving' );
leadwerk_sync_assert( is_string( $sync_source ) && strpos( $sync_source, 'register_shutdown_function(' ) !== false && strpos( $sync_source, 'self::release_lock( $token );' ) !== false, 'Site Sync must release its token when an import pipeline exits before finally' );
leadwerk_sync_assert( is_string( $hub_source ) && strpos( $hub_source, "array( 'verifying', 'preparing_import', 'importing', 'finalizing', 'complete' )" ) !== false && strpos( $hub_source, "(int) \$job['bytes_delivered'] >= (int) \$job['archive_size']" ) !== false, 'Hub must accept an idempotent verified final progress callback after import advancement' );
leadwerk_sync_assert( is_string( $sync_source ) && strpos( $sync_source, "'preparing_import', 'importing', 'finalizing'" ) !== false && strpos( $sync_source, 'remember_import_continuation' ) !== false && strpos( $sync_source, 'resume_import_continuation' ) !== false, 'active import targets must remain watchdog-visible and support secrets-free continuation recovery' );
leadwerk_sync_assert( is_string( $import_controller_source ) && strpos( $import_controller_source, 'Leadwerk_Migration_Sync::remember_import_continuation( $params );' ) !== false, 'sync imports must checkpoint the next internal continuation before dispatch' );
leadwerk_sync_assert( is_string( $sync_source ) && strpos( $sync_source, 'LEADWERK_MIGRATION_SYNC_DIRECT_CHUNK_SIZE' ) !== false && strpos( $sync_source, 'LEADWERK_MIGRATION_SYNC_DIRECT_BATCH_CHUNKS' ) !== false, 'direct transfer must use the larger peer-only chunk and multi-chunk worker batch' );
leadwerk_sync_assert( is_string( $sync_source ) && strpos( $sync_source, "'leadwerk_migration_manual_restore'            => 1" ) === false, 'Site Sync import must not stop at the first manual-restore pipeline stage' );
leadwerk_sync_assert( is_string( $hub_source ) && strpos( $hub_source, 'private static function dispatch_wake' ) !== false && strpos( $hub_source, 'wp_safe_remote_post' ) !== false && strpos( $hub_source, "'local' === \$environment['environment_type']" ) !== false, 'Hub wake dispatch must be HTTPS-safe and must not attempt public callbacks into local environments' );
leadwerk_sync_assert( is_string( $hub_source ) && strpos( $hub_source, "'/hub/status'" ) !== false && strpos( $hub_source, 'bytes_delivered' ) !== false, 'Hub must expose one family-scoped snapshot and track target download progress independently' );
leadwerk_sync_assert( is_string( $hub_source ) && strpos( $hub_source, "'direct_push'" ) !== false && strpos( $hub_source, "'direct_pull'" ) !== false && strpos( $hub_source, "'/hub/plugin/publish'" ) !== false, 'Hub must coordinate direct peer transfer and automatic release publication' );
leadwerk_sync_assert( is_string( $hub_source ) && strpos( $hub_source, "register_rest_route( self::NAMESPACE, '/hub/jobs/(?P<job_id>[a-f0-9]{32})/chunk'" ) === false && strpos( $hub_source, "register_rest_route( self::NAMESPACE, '/hub/jobs/(?P<job_id>[a-f0-9]{32})/read'" ) === false && strpos( $hub_source, "register_rest_route( self::NAMESPACE, '/hub/jobs/(?P<job_id>[a-f0-9]{32})/fallback'" ) === false, 'Hub must not register archive relay or fallback routes' );
leadwerk_sync_assert( is_string( $sync_source ) && strpos( $sync_source, "self::request( '/hub/jobs/' . \$job_id . '/chunk'" ) === false && strpos( $sync_source, "self::request( '/hub/jobs/' . \$job_id . '/read'" ) === false && strpos( $sync_source, "self::request( '/hub/jobs/' . \$job_id . '/fallback'" ) === false, 'workers must never upload, download or fall back through Hub archive storage' );
leadwerk_sync_assert( is_string( $sync_source ) && strpos( $sync_source, "'/peer/jobs/(?P<job_id>[a-f0-9]{32})/chunk'" ) !== false && strpos( $sync_source, 'X-Leadwerk-Peer-Ticket' ) !== false, 'peer archive endpoints must require job-scoped tickets' );
leadwerk_sync_assert( is_string( $hub_source ) && strpos( $hub_source, 'authorize_new_family_enrollment' ) !== false && strpos( $hub_source, 'LEADWERK_MIGRATION_HUB_ENROLLMENT_KEY' ) !== false && strpos( $hub_source, 'leadwerk-family-enrollment-v1' ) !== false && strpos( $hub_source, 'REGISTER_RATE_PER_IP' ) !== false && strpos( $hub_source, 'MAX_ENVIRONMENTS_PER_FAMILY' ) !== false, 'unknown Site Families must require a fresh server-keyed HMAC proof, registration throttling and bounded registry capacity' );
leadwerk_sync_assert( is_string( $sync_source ) && strpos( $sync_source, 'LEADWERK_MIGRATION_ENROLLMENT_KEY' ) !== false && strpos( $sync_source, 'X-Leadwerk-Enrollment-Signature' ) !== false, 'original clients must send an HMAC enrollment proof without transmitting the key' );
leadwerk_sync_assert( is_string( $release_source ) && strpos( $release_source, 'sodium_crypto_sign_verify_detached' ) !== false && strpos( $release_source, 'SIGNING_CONTEXT' ) !== false, 'automatic releases must verify a publisher Ed25519 signature' );
leadwerk_sync_assert( is_string( $sync_view_source ) && strpos( $sync_view_source, 'Reconcile runtime versions' ) === false && strpos( $sync_view_source, 'Automatic family updates' ) !== false, 'manual runtime reconciliation UI must be replaced by automatic family update history' );
leadwerk_sync_assert( is_string( $hub_source ) && strpos( $hub_source, "'wordpress_update'" ) !== false && strpos( $hub_source, 'queue_family_wordpress_updates' ) !== false, 'Hub must automatically queue lower WordPress versions to the highest version inside each family' );
leadwerk_sync_assert( is_string( $hub_source ) && strpos( $hub_source, '$failed_version' ) !== false && strpos( $hub_source, 'hash_equals( (string) $version, $failed_version )' ) !== false, 'automatic release cooldown must apply only to the exact failed target version' );
leadwerk_sync_assert( is_string( $main_controller_source ) && strpos( $main_controller_source, "add_filter( 'rest_post_dispatch', 'Leadwerk_Migration_Sync_Hub::noindex_rest_response'" ) !== false && strpos( $main_controller_source, "add_filter( 'robots_txt', 'Leadwerk_Migration_Sync_Hub::filter_robots_txt'" ) !== false, 'Hub noindex and robots filters must be registered globally' );
leadwerk_sync_assert( is_string( $hub_source ) && strpos( $hub_source, "header( 'X-Robots-Tag', 'noindex, nofollow, noarchive' )" ) !== false && strpos( $hub_source, "header( 'Cache-Control', 'no-store, private' )" ) !== false, 'Hub REST responses must advertise noindex and prevent intermediary caching' );
leadwerk_sync_assert( is_string( $hub_source ) && strpos( $hub_source, "'Disallow: /wp-json/' . self::NAMESPACE . '/hub/'" ) !== false && strpos( $hub_source, "'Disallow: /?rest_route=/' . self::NAMESPACE . '/hub/'" ) !== false, 'robots.txt must disallow both Hub REST URL forms' );
leadwerk_sync_assert( is_string( $hub_source ) && strpos( $hub_source, '$block = "User-agent: *\\n"' ) !== false, 'Hub robots rules must remain inside a valid user-agent group when other SEO plugins also filter robots.txt' );
leadwerk_sync_assert( is_string( $runtime_source ) && strpos( $runtime_source, "'wordpress_update' === \$type" ) !== false && strpos( $runtime_source, 'Automatic WordPress downgrade' ) !== false, 'workers must execute family WordPress upgrades while continuing to prohibit downgrades' );
leadwerk_sync_assert( is_string( $rest_controller_source ) && strpos( $rest_controller_source, "current_user_can( 'manage_options' )" ) !== false && strpos( $rest_controller_source, "'export'                => self::can_export()" ) !== false && is_string( $security_source ) && strpos( $security_source, "? 'leadwerk_migration_import_site' : 'manage_options'" ) !== false, 'REST and browser full-site exports must require administrator capability' );
leadwerk_sync_assert( is_string( $main_controller_source ) && strpos( $main_controller_source, "return array( 'import', 'manage_network_plugins', 'manage_network_themes' );" ) !== false, 'multisite imports must require network-level plugin and theme capabilities' );
leadwerk_sync_assert( is_string( $constants_source ) && strpos( $constants_source, "LEADWERK_MIGRATION_UPSTREAM_VERSION', '7.109'" ) !== false && is_string( $import_content_source ) && strpos( $import_content_source, 'LEADWERK_MIGRATION_CIVICRM_SETTINGS_NAME' ) !== false, 'the fork must record and incorporate upstream 7.109 changes' );
leadwerk_sync_assert( is_string( $sync_controller_source ) && strpos( $sync_controller_source, 'public static function ajax_status' ) !== false && strpos( $sync_controller_source, "current_user_can( 'manage_options' )" ) !== false && strpos( $sync_controller_source, "wp_verify_nonce( \$nonce, 'leadwerk_migration_sync_status' )" ) !== false, 'live panel AJAX must require an administrator and dedicated nonce' );
leadwerk_sync_assert( is_string( $sync_view_source ) && strpos( $sync_view_source, 'data-leadwerk-dynamic="jobs"' ) !== false && strpos( $sync_view_source, 'data-leadwerk-dynamic="commands"' ) !== false, 'sync and runtime tables must both expose dynamic AJAX regions' );
leadwerk_sync_assert( is_string( $sync_javascript_source ) && strpos( $sync_javascript_source, "fetch( config.ajaxUrl" ) !== false && strpos( $sync_javascript_source, 'document.hidden' ) !== false && strpos( $sync_javascript_source, 'bytes_delivered' ) !== false, 'live polling must be AJAX-based, visibility-aware and render target download progress' );
leadwerk_sync_assert( is_string( $main_controller_source ) && strpos( $main_controller_source, "add_action( 'wp_ajax_leadwerk_migration_sync_request', 'Leadwerk_Migration_Sync_Controller::ajax_request_sync' )" ) !== false, 'full-site synchronization must expose an authenticated WordPress AJAX action' );
leadwerk_sync_assert( is_string( $sync_controller_source ) && strpos( $sync_controller_source, 'public static function ajax_request_sync' ) !== false && strpos( $sync_controller_source, "authorize_admin_action( 'manage_options', 'leadwerk_migration_sync_request', \$nonce )" ) !== false, 'AJAX synchronization starts must require administrator capability and the dedicated action nonce' );
leadwerk_sync_assert( is_string( $sync_view_source ) && strpos( $sync_view_source, 'data-leadwerk-sync-request' ) !== false && strpos( $sync_view_source, 'data-leadwerk-sync-request-feedback' ) !== false, 'the full-site sync form must expose AJAX and accessible inline feedback hooks' );
leadwerk_sync_assert( is_string( $sync_javascript_source ) && strpos( $sync_javascript_source, 'event.preventDefault()' ) !== false && strpos( $sync_javascript_source, "body.set( 'action', 'leadwerk_migration_sync_request' )" ) !== false && strpos( $sync_javascript_source, 'config.requestSyncNonce' ) !== false && strpos( $sync_javascript_source, 'refreshImmediately()' ) !== false, 'full-site synchronization must start without navigation and immediately refresh job status' );
leadwerk_sync_assert( is_string( $hub_source ) && strpos( $hub_source, 'public static function is_hub_site' ) !== false && strpos( $hub_source, 'hash_equals( $hub, $current )' ) !== false, 'the configured Hub URL must identify the only WordPress installation allowed to expose Hub settings' );
leadwerk_sync_assert( is_string( $sync_controller_source ) && strpos( $sync_controller_source, "if ( ! Leadwerk_Migration_Sync_Hub::is_hub_site() )" ) !== false && strpos( $sync_controller_source, 'leadwerk_sync_settings_hub_only' ) !== false, 'non-Hub installations must reject direct Site Sync settings submissions' );
leadwerk_sync_assert( is_string( $sync_view_source ) && strpos( $sync_view_source, '$is_hub_site = Leadwerk_Migration_Sync_Hub::is_hub_site();' ) !== false && strpos( $sync_view_source, '<?php if ( $is_hub_site ) : ?>' ) !== false, 'Site Sync settings must only render on the configured Hub WordPress installation' );
leadwerk_sync_assert( is_string( $sync_view_source ) && strpos( $sync_view_source, 'leadwerk-metric--identity' ) !== false && strpos( $sync_view_source, 'leadwerk-dashboard__metrics--sync' ) !== false && strpos( $sync_view_source, 'Zero-touch Hub connection' ) === false, 'Site Family identity must move into the four-card metric row and the redundant Zero-touch panel must be removed' );
leadwerk_sync_assert( is_string( $sync_css_source ) && strpos( $sync_css_source, 'grid-template-columns: repeat(2, minmax(0, 1fr))' ) !== false && strpos( $sync_css_source, '@media screen and (max-width: 900px)' ) !== false && strpos( $sync_css_source, '.leadwerk-dashboard__grid--sync-actions .leadwerk-sync__direction' ) !== false, 'compact Site Sync grids must use equal desktop columns, a single-column narrow layout and compact transfer controls' );
leadwerk_sync_assert( is_string( $sync_view_source ) && strpos( $sync_view_source, 'data-leadwerk-command-history' ) !== false && substr_count( $sync_view_source, 'leadwerk-sync__history-scroll' ) >= 2 && strpos( $sync_view_source, '$has_active_commands' ) !== false, 'long job histories must be bounded and automatic update history must collapse unless active work needs attention' );
leadwerk_sync_assert( is_string( $sync_javascript_source ) && strpos( $sync_javascript_source, "document.querySelectorAll( '[data-leadwerk-command-count]' )" ) !== false && strpos( $sync_javascript_source, 'history.open = true' ) !== false, 'AJAX updates must refresh the compact history count and reveal active runtime work' );
leadwerk_sync_assert( is_string( $sync_css_source ) && strpos( $sync_css_source, 'max-height: 340px' ) !== false && strpos( $sync_css_source, '.leadwerk-sync__history-summary::after' ) !== false, 'long history tables must scroll inside an accessible collapsible panel instead of extending the page' );
leadwerk_sync_assert( is_string( $sync_view_source ) && strpos( $sync_view_source, 'Preflight and target protection' ) === false && strpos( $sync_view_source, 'Mandatory preflight order' ) === false && strpos( $sync_view_source, 'Automatic target protection' ) === false, 'the transfer explanation block must not render while server-side preflight remains enforced' );
leadwerk_sync_assert( is_string( $sync_css_source ) && strpos( $sync_css_source, '.leadwerk-sync__compact-details' ) === false && strpos( $sync_css_source, '.leadwerk-sync__preflight' ) === false, 'unused transfer explanation styles must be removed with the panel' );
leadwerk_sync_assert( is_string( $sync_css_source ) && strpos( $sync_css_source, '.leadwerk-dashboard__grid--sync-actions .leadwerk-sync__direction { grid-template-columns: 1fr; }' ) !== false, 'the higher-specificity compact transfer layout must stack safely at the mobile breakpoint' );
leadwerk_sync_assert( is_string( $sync_css_source ) && strpos( $sync_css_source, 'grid-template-columns: fit-content(220px) fit-content(200px) fit-content(260px) minmax(360px, 1fr)' ) !== false && strpos( $sync_css_source, '.leadwerk-metric__identity-values' ) !== false, 'the four-card metrics row must use content-sized operational cards and give Site Family identity the remaining width' );
leadwerk_sync_assert( is_string( $main_controller_source ) && strpos( $main_controller_source, 'Leadwerk_Migration_Site_Identity::maybe_originate_family();' ) === false, 'plugin activation must never create a Site Family' );
leadwerk_sync_assert( is_string( $sync_source ) && strpos( $sync_source, 'register_available_environment' ) !== false && strpos( $sync_source, '$family = Leadwerk_Migration_Site_Identity::maybe_originate_family();' ) === false, 'registration bootstrap must list Family-less installations without originating a Family' );
leadwerk_sync_assert( is_string( $sync_view_source ) && strpos( $sync_view_source, 'Create Family ID for this site' ) !== false && strpos( $sync_view_source, 'List WordPress environments without a Family ID' ) !== false, 'the UI must expose explicit Family creation and the Hub-only available-target selector' );
leadwerk_sync_assert( is_string( $hub_source ) && strpos( $hub_source, 'public static function create_admin_job' ) !== false && strpos( $hub_source, "status = 'available'" ) !== false && strpos( $hub_source, 'START TRANSACTION' ) !== false && strpos( $hub_source, 'ROLLBACK' ) !== false, 'Hub must claim an available target atomically before creating its P2P job' );
leadwerk_sync_assert( is_string( $release_source ) && strpos( $release_source, 'verify_attestation' ) !== false && strpos( $hub_source, 'MAX_AVAILABLE_ENVIRONMENTS' ) !== false && strpos( $hub_source, 'AVAILABLE_RETENTION' ) !== false, 'available-target admission must require a signed official release and bounded expiring registry capacity' );
leadwerk_sync_assert( strpos( file_get_contents( dirname( __DIR__ ) . '/lib/model/class-leadwerk_migration-site-identity.php' ), "update_option( 'blog_public', '1' )" ) !== false, 'live targets must force WordPress search-engine visibility on after import' );
leadwerk_sync_assert( is_string( $import_content_source ) && strpos( file_get_contents( dirname( __DIR__ ) . '/lib/model/export/class-leadwerk_migration-export-config.php' ), 'Leadwerk_Migration_Site_Identity::current_family();' ) !== false, 'exports must preserve an existing Family identity without creating one' );

fwrite( STDOUT, "Leadwerk Site Sync smoke tests passed.\n" );
