<?php
/**
 * Leadwerk central registry and signed peer-to-peer job queue.
 *
 * Install this same plugin on leadwerk.de and enable Hub mode on the Site Sync
 * screen. Full-site archive bytes never pass through or persist on the Hub.
 *
 * Copyright (C) 2026 Leadwerk.
 * Licensed under GPLv3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access is not allowed.' );
}

class Leadwerk_Migration_Sync_Hub {

	const NAMESPACE = 'leadwerk/v1';
	const MAX_CLOCK_SKEW = 300;
	const ONLINE_TTL = 15 * MINUTE_IN_SECONDS;
	const REGISTER_RATE_WINDOW = 5 * MINUTE_IN_SECONDS;
	const REGISTER_RATE_GLOBAL = 120;
	const REGISTER_RATE_PER_IP = 10;
	const MAX_FAMILIES = 5000;
	const MAX_ENVIRONMENTS_PER_FAMILY = 16;
	const MAX_AVAILABLE_ENVIRONMENTS = 1000;
	const AVAILABLE_RETENTION = 7 * DAY_IN_SECONDS;

	/** @return bool */
	public static function enabled() {
		$settings = Leadwerk_Migration_Site_Identity::settings();
		return ! empty( $settings['hub_mode'] );
	}

	/** Return true only on the configured Hub WordPress installation. */
	public static function is_hub_site() {
		if ( self::enabled() ) {
			return true;
		}

		$current = untrailingslashit( Leadwerk_Migration_Site_Identity::normalized_site_url() );
		$hub     = untrailingslashit( Leadwerk_Migration_Site_Identity::hub_url() );
		return '' !== $current && '' !== $hub && hash_equals( $hub, $current );
	}

	/** Install or upgrade Hub tables when Hub mode is enabled. */
	public static function maybe_install() {
		if ( ! self::enabled() || get_option( LEADWERK_MIGRATION_SYNC_DB_OPTION, '' ) === LEADWERK_MIGRATION_SYNC_DB_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$tables  = self::tables();

		dbDelta( "CREATE TABLE {$tables['families']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			family_id char(32) NOT NULL,
			secret_hash varchar(255) NOT NULL,
			project_name varchar(190) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY family_id (family_id),
			KEY status (status)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$tables['environments']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			environment_id char(32) NOT NULL,
			family_id char(32) NOT NULL,
			secret_encrypted longtext NOT NULL,
			site_url varchar(500) NOT NULL,
			environment_type varchar(20) NOT NULL,
			wordpress_version varchar(32) NOT NULL,
			php_version varchar(32) NOT NULL,
			plugin_version varchar(32) NOT NULL,
			fingerprint char(64) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			last_seen_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY environment_id (environment_id),
			KEY family_id (family_id),
			KEY status (status)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$tables['jobs']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id char(32) NOT NULL,
			family_id char(32) NOT NULL,
			source_environment_id char(32) NOT NULL,
			target_environment_id char(32) NOT NULL,
			initiator_environment_id char(32) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'awaiting_approval',
			source_approved tinyint(1) NOT NULL DEFAULT 0,
			target_approved tinyint(1) NOT NULL DEFAULT 0,
			archive_name varchar(220) NOT NULL DEFAULT '',
			archive_size bigint(20) unsigned NOT NULL DEFAULT 0,
			archive_sha256 char(64) NOT NULL DEFAULT '',
			bytes_received bigint(20) unsigned NOT NULL DEFAULT 0,
			bytes_delivered bigint(20) unsigned NOT NULL DEFAULT 0,
			transport varchar(20) NOT NULL DEFAULT 'direct_pull',
			peer_failures smallint(5) unsigned NOT NULL DEFAULT 0,
			message text NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_id (job_id),
			KEY family_id (family_id),
			KEY source_environment_id (source_environment_id),
			KEY target_environment_id (target_environment_id),
			KEY status (status)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$tables['commands']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			command_id char(32) NOT NULL,
			family_id char(32) NOT NULL,
			target_environment_id char(32) NOT NULL,
			initiator_environment_id char(32) NOT NULL,
			command_type varchar(32) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'queued',
			payload longtext NOT NULL,
			message text NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY command_id (command_id),
			KEY family_id (family_id),
			KEY target_environment_id (target_environment_id),
			KEY status (status)
		) {$charset};" );

		// Relay transport was permanently removed in 1.5.0. Fail any unfinished
		// legacy relay job and remove only its tightly validated job archive.
		$legacy_jobs = $wpdb->get_col( "SELECT job_id FROM {$tables['jobs']} WHERE transport = 'hub_relay'" );
		foreach ( is_array( $legacy_jobs ) ? $legacy_jobs : array() as $legacy_job_id ) {
			self::safe_unlink_relay( $legacy_job_id );
		}
		self::purge_legacy_relay_archives();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['jobs']} SET status = 'failed', message = %s, updated_at = %s WHERE transport = 'hub_relay' AND status NOT IN ('complete','failed','canceled')",
				__( 'Hub relay transport was retired. Start a new peer-to-peer synchronization job.', 'leadwerk-migration' ),
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		update_option( LEADWERK_MIGRATION_SYNC_DB_OPTION, LEADWERK_MIGRATION_SYNC_DB_VERSION, false );
	}

	/** Register public routes. Every route performs its own strict authorization. */
	public static function register_routes() {
		register_rest_route( self::NAMESPACE, '/hub/register', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'register_environment' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/heartbeat', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'heartbeat' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/directory', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'directory' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/status', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'status_snapshot' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/jobs', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_job' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/jobs/list', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'list_jobs' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/jobs/(?P<job_id>[a-f0-9]{32})/approve', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'approve_job' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/jobs/(?P<job_id>[a-f0-9]{32})/target-ready', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'target_ready' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/jobs/(?P<job_id>[a-f0-9]{32})/source-ready', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'source_ready' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/jobs/(?P<job_id>[a-f0-9]{32})/peer-progress', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'peer_progress' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/jobs/(?P<job_id>[a-f0-9]{32})/import-progress', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'import_progress' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/jobs/(?P<job_id>[a-f0-9]{32})/complete', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'complete_job' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/jobs/(?P<job_id>[a-f0-9]{32})/fail', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'fail_job' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/jobs/(?P<job_id>[a-f0-9]{32})/cancel', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'cancel_job' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/jobs/(?P<job_id>[a-f0-9]{32})/retry', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'retry_job' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/commands', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_command' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/commands/list', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'list_commands' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/commands/(?P<command_id>[a-f0-9]{32})/progress', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'command_progress' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/commands/(?P<command_id>[a-f0-9]{32})/cancel', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'cancel_command' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/commands/(?P<command_id>[a-f0-9]{32})/retry', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'retry_command' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/plugin/manifest', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'plugin_manifest' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/plugin/read', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'plugin_read' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NAMESPACE, '/hub/plugin/publish', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'plugin_publish' ), 'permission_callback' => '__return_true' ) );
	}

	/** Prevent crawlers and intermediary caches from retaining Hub API responses. */
	public static function noindex_rest_response( $response, $server, $request ) {
		if ( ! self::enabled() || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $response;
		}
		$route = (string) $request->get_route();
		$prefix = '/' . self::NAMESPACE . '/hub/';
		if ( 0 !== strpos( $route, $prefix ) ) {
			return $response;
		}
		$response = rest_ensure_response( $response );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow, noarchive' );
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/** Add both pretty and query-style Hub REST paths to WordPress robots.txt. */
	public static function filter_robots_txt( $output, $public ) {
		if ( ! self::enabled() ) {
			return $output;
		}
		$rules = array(
			'Disallow: /wp-json/' . self::NAMESPACE . '/hub/',
			'Disallow: /?rest_route=/' . self::NAMESPACE . '/hub/',
		);
		$missing = array();
		foreach ( $rules as $rule ) {
			if ( false === strpos( (string) $output, $rule ) ) {
				$missing[] = $rule;
			}
		}
		if ( empty( $missing ) ) {
			return $output;
		}
		$block = "User-agent: *\n" . implode( "\n", $missing );
		$output = trim( (string) $output );
		return ( '' !== $output ? $output . "\n\n" : '' ) . $block . "\n";
	}

	/** Bound public registration work before password hashing or database writes. */
	private static function registration_rate_limit() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? trim( $_SERVER['REMOTE_ADDR'] ) : '';
		$packed = '' !== $remote ? @inet_pton( $remote ) : false;
		$ip_key = 'leadwerk_hub_register_ip_' . substr( hash( 'sha256', false !== $packed ? $packed : 'unknown' ), 0, 24 );
		$global_key = 'leadwerk_hub_register_global';
		$global = max( 0, (int) get_transient( $global_key ) );
		$per_ip = max( 0, (int) get_transient( $ip_key ) );
		if ( $global >= self::REGISTER_RATE_GLOBAL || $per_ip >= self::REGISTER_RATE_PER_IP ) {
			return new WP_Error(
				'leadwerk_hub_registration_rate_limited',
				__( 'Hub registration is temporarily rate limited. Wait five minutes and try again.', 'leadwerk-migration' ),
				array( 'status' => 429, 'retry_after' => self::REGISTER_RATE_WINDOW )
			);
		}
		set_transient( $global_key, $global + 1, self::REGISTER_RATE_WINDOW );
		set_transient( $ip_key, $per_ip + 1, self::REGISTER_RATE_WINDOW );
		return true;
	}

	/** Server-only key; it must never be stored in the plugin ZIP or WordPress DB. */
	private static function hub_enrollment_key() {
		$key = defined( 'LEADWERK_MIGRATION_HUB_ENROLLMENT_KEY' ) ? LEADWERK_MIGRATION_HUB_ENROLLMENT_KEY : getenv( 'LEADWERK_MIGRATION_HUB_ENROLLMENT_KEY' );
		return is_string( $key ) && strlen( $key ) >= 32 && strlen( $key ) <= 512 ? $key : '';
	}

	public static function enrollment_key_configured() {
		return '' !== self::hub_enrollment_key();
	}

	/** Require a fresh HMAC proof before the Hub creates a previously unknown family. */
	private static function authorize_new_family_enrollment( $request ) {
		$key = self::hub_enrollment_key();
		if ( '' === $key ) {
			return new WP_Error( 'leadwerk_hub_enrollment_closed', __( 'New Site Family enrollment is closed because the Hub enrollment key is not configured.', 'leadwerk-migration' ), array( 'status' => 503 ) );
		}
		$timestamp = (int) $request->get_header( 'X-Leadwerk-Enrollment-Timestamp' );
		$nonce = strtolower( trim( (string) $request->get_header( 'X-Leadwerk-Enrollment-Nonce' ) ) );
		$signature = strtolower( trim( (string) $request->get_header( 'X-Leadwerk-Enrollment-Signature' ) ) );
		if ( abs( time() - $timestamp ) > self::MAX_CLOCK_SKEW || ! preg_match( '/\A[a-f0-9]{32}\z/D', $nonce ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $signature ) ) {
			return new WP_Error( 'leadwerk_hub_enrollment_proof_invalid', __( 'The new Site Family enrollment proof is missing, malformed or expired.', 'leadwerk-migration' ), array( 'status' => 401 ) );
		}
		$replay_key = 'leadwerk_hub_enrollment_' . hash( 'sha256', $nonce );
		if ( get_transient( $replay_key ) ) {
			return new WP_Error( 'leadwerk_hub_enrollment_replay', __( 'This Site Family enrollment proof has already been used.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		$canonical = "leadwerk-family-enrollment-v1\n" . self::canonical_request( $request->get_method(), $request->get_route(), $timestamp, $nonce, (string) $request->get_body() );
		$expected = hash_hmac( 'sha256', $canonical, $key );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'leadwerk_hub_enrollment_signature_failed', __( 'The new Site Family enrollment signature is invalid.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}
		set_transient( $replay_key, 1, self::MAX_CLOCK_SKEW * 2 );
		return true;
	}

	/** First/clone registration. The family secret travels only over HTTPS. */
	public static function register_environment( $request ) {
		$ready = self::hub_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( ! is_ssl() && wp_get_environment_type() === 'production' ) {
			return new WP_Error( 'leadwerk_hub_https_required', __( 'Hub registration requires HTTPS.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}
		$rate = self::registration_rate_limit();
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();
		$family_id = isset( $data['family_id'] ) ? strtolower( trim( (string) $data['family_id'] ) ) : '';
		$family_secret = isset( $data['family_secret'] ) ? trim( (string) $data['family_secret'] ) : '';
		$environment_id = isset( $data['environment_id'] ) ? strtolower( trim( (string) $data['environment_id'] ) ) : '';
		$environment_secret = isset( $data['environment_secret'] ) ? trim( (string) $data['environment_secret'] ) : '';
		$registration_mode = isset( $data['registration_mode'] ) ? sanitize_key( $data['registration_mode'] ) : '';

		if ( 'available' === $registration_mode && '' === $family_id && '' === $family_secret ) {
			return self::register_available_environment( $data, $environment_id, $environment_secret );
		}

		if ( ! Leadwerk_Migration_Site_Identity::valid_id( $family_id ) || ! Leadwerk_Migration_Site_Identity::valid_id( $environment_id ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $family_secret ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $environment_secret ) ) {
			return new WP_Error( 'leadwerk_hub_invalid_identity', __( 'The Site Sync identity is invalid.', 'leadwerk-migration' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$tables = self::tables();
		$family = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['families']} WHERE family_id = %s", $family_id ), ARRAY_A );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s", $environment_id ), ARRAY_A );
		$now = gmdate( 'Y-m-d H:i:s' );

		if ( ! is_array( $family ) ) {
			$available_proof = false;
			if ( is_array( $existing ) && '' === (string) $existing['family_id'] && 'available' === $existing['status'] ) {
				$stored_secret = self::open_secret( $existing['secret_encrypted'] );
				$available_proof = is_string( $stored_secret ) && hash_equals( $stored_secret, $environment_secret );
			}
			if ( ! $available_proof ) {
				$enrollment = self::authorize_new_family_enrollment( $request );
				if ( is_wp_error( $enrollment ) ) {
					return $enrollment;
				}
			}
			if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['families']}" ) >= self::MAX_FAMILIES ) {
				return new WP_Error( 'leadwerk_hub_family_capacity', __( 'Hub Site Family capacity has been reached; no new family was created.', 'leadwerk-migration' ), array( 'status' => 507 ) );
			}
			$inserted = $wpdb->insert(
				$tables['families'],
				array(
					'family_id' => $family_id,
					'secret_hash' => password_hash( $family_secret, PASSWORD_DEFAULT ),
					'project_name' => self::clean_project_name( isset( $data['project_name'] ) ? $data['project_name'] : '' ),
					'status' => 'approved',
					'created_at' => $now,
					'updated_at' => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( false === $inserted ) {
				return new WP_Error( 'leadwerk_hub_family_insert_failed', __( 'Hub could not save the automatically enrolled Site Family.', 'leadwerk-migration' ), array( 'status' => 500 ) );
			}
			$family = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['families']} WHERE family_id = %s", $family_id ), ARRAY_A );
		} elseif ( ! password_verify( $family_secret, $family['secret_hash'] ) ) {
			return new WP_Error( 'leadwerk_hub_family_proof_failed', __( 'The Zone Zero family proof is invalid.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		} elseif ( 'revoked' === $family['status'] ) {
			return new WP_Error( 'leadwerk_hub_family_revoked', __( 'This Site Family was explicitly revoked by the Hub administrator.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		} elseif ( 'approved' !== $family['status'] ) {
			$wpdb->update( $tables['families'], array( 'status' => 'approved', 'updated_at' => $now ), array( 'family_id' => $family_id ) );
			$family['status'] = 'approved';
		}

		$record = array(
			'family_id'          => $family_id,
			'site_url'           => Leadwerk_Migration_Site_Identity::normalize_url( isset( $data['site_url'] ) ? $data['site_url'] : '' ),
			'environment_type'   => self::clean_environment_type( isset( $data['environment_type'] ) ? $data['environment_type'] : '' ),
			'wordpress_version'  => substr( sanitize_text_field( isset( $data['wordpress_version'] ) ? $data['wordpress_version'] : '' ), 0, 32 ),
			'php_version'        => substr( sanitize_text_field( isset( $data['php_version'] ) ? $data['php_version'] : '' ), 0, 32 ),
			'plugin_version'     => substr( sanitize_text_field( isset( $data['plugin_version'] ) ? $data['plugin_version'] : '' ), 0, 32 ),
			'fingerprint'        => preg_match( '/\A[a-f0-9]{64}\z/D', isset( $data['fingerprint'] ) ? $data['fingerprint'] : '' ) ? $data['fingerprint'] : hash( 'sha256', $environment_id ),
			'updated_at'         => $now,
			'last_seen_at'       => $now,
		);

		if ( is_array( $existing ) ) {
			$is_available = '' === (string) $existing['family_id'] && 'available' === $existing['status'];
			if ( ! $is_available && ! hash_equals( $existing['family_id'], $family_id ) ) {
				return new WP_Error( 'leadwerk_hub_environment_conflict', __( 'This environment identity already belongs to another Site Family.', 'leadwerk-migration' ), array( 'status' => 409 ) );
			}
			$stored_secret = self::open_secret( $existing['secret_encrypted'] );
			if ( ! is_string( $stored_secret ) || ! hash_equals( $stored_secret, $environment_secret ) ) {
				return new WP_Error( 'leadwerk_hub_environment_proof_failed', __( 'The environment credential is invalid.', 'leadwerk-migration' ), array( 'status' => 403 ) );
			}
			if ( 'revoked' === $existing['status'] ) {
				return new WP_Error( 'leadwerk_hub_environment_revoked', __( 'This environment was explicitly revoked by the Hub administrator.', 'leadwerk-migration' ), array( 'status' => 403 ) );
			}
			$record['family_id'] = $family_id;
			$record['status'] = 'approved';
			if ( false === $wpdb->update( $tables['environments'], $record, array( 'environment_id' => $environment_id ) ) ) {
				return new WP_Error( 'leadwerk_hub_environment_update_failed', __( 'Hub could not refresh the automatically enrolled environment.', 'leadwerk-migration' ), array( 'status' => 500 ) );
			}
		} else {
			if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['environments']} WHERE family_id = %s", $family_id ) ) >= self::MAX_ENVIRONMENTS_PER_FAMILY ) {
				return new WP_Error( 'leadwerk_hub_environment_capacity', __( 'This Site Family has reached its environment limit.', 'leadwerk-migration' ), array( 'status' => 409 ) );
			}
			$record['environment_id']   = $environment_id;
			$record['secret_encrypted'] = self::seal_secret( $environment_secret );
			if ( '' === $record['secret_encrypted'] ) {
				return new WP_Error( 'leadwerk_hub_environment_seal_failed', __( 'Hub could not encrypt the environment credential.', 'leadwerk-migration' ), array( 'status' => 500 ) );
			}
			$record['status']           = 'approved';
			$record['created_at']       = $now;
			if ( false === $wpdb->insert( $tables['environments'], $record ) ) {
				return new WP_Error( 'leadwerk_hub_environment_insert_failed', __( 'Hub could not save the automatically enrolled environment.', 'leadwerk-migration' ), array( 'status' => 500 ) );
			}
		}

		$environment = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$tables['environments']} WHERE environment_id = %s", $environment_id ), ARRAY_A );
		Leadwerk_Migration_Sync::schedule_fast_worker();
		return new WP_REST_Response(
			array(
				'family_status'      => 'approved',
				'environment_status' => 'approved',
				'environment_id'     => $environment_id,
				'family_id'          => $family_id,
				'hub_time'           => gmdate( 'c' ),
			),
			200
		);
	}

	/** Admit an official plugin installation without creating a Site Family. */
	private static function register_available_environment( $data, $environment_id, $environment_secret ) {
		if ( ! Leadwerk_Migration_Site_Identity::valid_id( $environment_id ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $environment_secret ) ) {
			return new WP_Error( 'leadwerk_hub_invalid_available_identity', __( 'The available target identity is invalid.', 'leadwerk-migration' ), array( 'status' => 400 ) );
		}
		$plugin_version = substr( sanitize_text_field( isset( $data['plugin_version'] ) ? $data['plugin_version'] : '' ), 0, 32 );
		$attestation = Leadwerk_Migration_Release::verify_attestation( isset( $data['release_attestation'] ) ? $data['release_attestation'] : array(), $plugin_version );
		if ( is_wp_error( $attestation ) ) {
			return new WP_Error( 'leadwerk_hub_available_release_invalid', __( 'Only an intact publisher-signed Leadwerk Migration release can register an available target.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}

		global $wpdb;
		$tables = self::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::AVAILABLE_RETENTION );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['environments']} WHERE family_id = '' AND status = 'available' AND last_seen_at < %s", $cutoff ) );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s", $environment_id ), ARRAY_A );
		$record = array(
			'site_url'          => Leadwerk_Migration_Site_Identity::normalize_url( isset( $data['site_url'] ) ? $data['site_url'] : '' ),
			'environment_type'  => self::clean_environment_type( isset( $data['environment_type'] ) ? $data['environment_type'] : '' ),
			'wordpress_version' => substr( sanitize_text_field( isset( $data['wordpress_version'] ) ? $data['wordpress_version'] : '' ), 0, 32 ),
			'php_version'       => substr( sanitize_text_field( isset( $data['php_version'] ) ? $data['php_version'] : '' ), 0, 32 ),
			'plugin_version'    => $plugin_version,
			'fingerprint'       => preg_match( '/\A[a-f0-9]{64}\z/D', isset( $data['fingerprint'] ) ? $data['fingerprint'] : '' ) ? $data['fingerprint'] : hash( 'sha256', $environment_id ),
			'updated_at'        => $now,
			'last_seen_at'      => $now,
		);
		if ( is_array( $existing ) ) {
			$stored_secret = self::open_secret( $existing['secret_encrypted'] );
			if ( ! is_string( $stored_secret ) || ! hash_equals( $stored_secret, $environment_secret ) ) {
				return new WP_Error( 'leadwerk_hub_environment_proof_failed', __( 'The environment credential is invalid.', 'leadwerk-migration' ), array( 'status' => 403 ) );
			}
			if ( 'revoked' === $existing['status'] ) {
				return new WP_Error( 'leadwerk_hub_environment_revoked', __( 'This environment was explicitly revoked by the Hub administrator.', 'leadwerk-migration' ), array( 'status' => 403 ) );
			}
			if ( '' !== (string) $existing['family_id'] ) {
				return new WP_REST_Response( array( 'family_status' => 'approved', 'environment_status' => 'approved', 'status' => 'approved', 'environment_id' => $environment_id, 'family_id' => $existing['family_id'], 'hub_time' => gmdate( 'c' ) ), 200 );
			}
			$record['status'] = 'available';
			$wpdb->update( $tables['environments'], $record, array( 'environment_id' => $environment_id ) );
		} else {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['environments']} WHERE family_id = '' AND status = 'available'" );
			if ( $count >= self::MAX_AVAILABLE_ENVIRONMENTS ) {
				return new WP_Error( 'leadwerk_hub_available_capacity', __( 'Hub available-target capacity has been reached. Offline records expire automatically after seven days.', 'leadwerk-migration' ), array( 'status' => 507 ) );
			}
			$record['environment_id'] = $environment_id;
			$record['family_id'] = '';
			$record['secret_encrypted'] = self::seal_secret( $environment_secret );
			$record['status'] = 'available';
			$record['created_at'] = $now;
			if ( '' === $record['secret_encrypted'] || false === $wpdb->insert( $tables['environments'], $record ) ) {
				return new WP_Error( 'leadwerk_hub_available_insert_failed', __( 'Hub could not save the available target.', 'leadwerk-migration' ), array( 'status' => 500 ) );
			}
		}
		return new WP_REST_Response( array( 'family_status' => 'unassigned', 'environment_status' => 'available', 'status' => 'available', 'environment_id' => $environment_id, 'family_id' => '', 'hub_time' => gmdate( 'c' ) ), 200 );
	}

	public static function heartbeat( $request ) {
		$auth = self::authenticate( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();
		global $wpdb;
		$tables = self::tables();
		$wpdb->update(
			$tables['environments'],
			array(
				'site_url'          => Leadwerk_Migration_Site_Identity::normalize_url( isset( $data['site_url'] ) ? $data['site_url'] : $auth['site_url'] ),
				'environment_type'  => self::clean_environment_type( isset( $data['environment_type'] ) ? $data['environment_type'] : $auth['environment_type'] ),
				'wordpress_version' => substr( sanitize_text_field( isset( $data['wordpress_version'] ) ? $data['wordpress_version'] : $auth['wordpress_version'] ), 0, 32 ),
				'php_version'       => substr( sanitize_text_field( isset( $data['php_version'] ) ? $data['php_version'] : $auth['php_version'] ), 0, 32 ),
				'plugin_version'    => substr( sanitize_text_field( isset( $data['plugin_version'] ) ? $data['plugin_version'] : $auth['plugin_version'] ), 0, 32 ),
				'last_seen_at'      => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'        => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'environment_id' => $auth['environment_id'] )
		);
		Leadwerk_Migration_Sync::schedule_fast_worker();
		return new WP_REST_Response( array( 'status' => $auth['status'], 'family_id' => $auth['family_id'], 'hub_time' => gmdate( 'c' ) ), 200 );
	}

	public static function directory( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		global $wpdb;
		$tables = self::tables();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT environment_id, family_id, site_url, environment_type, wordpress_version, php_version, plugin_version, status, last_seen_at FROM {$tables['environments']} WHERE family_id = %s AND status IN ('approved','pending') ORDER BY environment_type, site_url", $auth['family_id'] ),
			ARRAY_A
		);
		foreach ( is_array( $rows ) ? $rows : array() as $index => $row ) {
			$rows[ $index ] = self::decorate_environment_status( $row );
		}
		return new WP_REST_Response( array( 'environments' => is_array( $rows ) ? $rows : array() ), 200 );
	}

	/** Return all family-scoped live panel data through one authenticated request. */
	public static function status_snapshot( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		global $wpdb;
		$tables = self::tables();
		self::purge_expired_canceled_jobs();
		$environments = $wpdb->get_results(
			$wpdb->prepare( "SELECT environment_id, family_id, site_url, environment_type, wordpress_version, php_version, plugin_version, status, last_seen_at FROM {$tables['environments']} WHERE family_id = %s AND status IN ('approved','pending') ORDER BY environment_type, site_url", $auth['family_id'] ),
			ARRAY_A
		);
		foreach ( is_array( $environments ) ? $environments : array() as $index => $environment ) {
			$environments[ $index ] = self::decorate_environment_status( $environment );
		}
		$jobs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['jobs']} WHERE family_id = %s ORDER BY created_at DESC LIMIT 100", $auth['family_id'] ), ARRAY_A );
		$commands = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['commands']} WHERE family_id = %s AND command_type IN ('plugin_publish','plugin_update','wordpress_update') ORDER BY created_at DESC LIMIT 100", $auth['family_id'] ), ARRAY_A );
		foreach ( is_array( $commands ) ? $commands : array() as $index => $command ) {
			$payload = json_decode( isset( $command['payload'] ) ? $command['payload'] : '', true );
			$commands[ $index ]['payload'] = is_array( $payload ) ? $payload : array();
		}
		return new WP_REST_Response(
			array(
				'environments' => is_array( $environments ) ? $environments : array(),
				'jobs'         => is_array( $jobs ) ? $jobs : array(),
				'commands'     => is_array( $commands ) ? $commands : array(),
				'hub_time'     => gmdate( 'c' ),
			),
			200
		);
	}

	public static function create_job( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();
		$source_id = isset( $data['source_environment_id'] ) ? strtolower( trim( (string) $data['source_environment_id'] ) ) : '';
		$target_id = isset( $data['target_environment_id'] ) ? strtolower( trim( (string) $data['target_environment_id'] ) ) : '';
		if ( $source_id === $target_id ) {
			return new WP_Error( 'leadwerk_sync_invalid_direction', __( 'A sync request requires two different endpoints in the same Site Family.', 'leadwerk-migration' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$tables = self::tables();
		$source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $source_id, $auth['family_id'] ), ARRAY_A );
		$target = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $target_id, $auth['family_id'] ), ARRAY_A );
		if ( ! is_array( $source ) || ! is_array( $target ) ) {
			return new WP_Error( 'leadwerk_sync_environment_not_approved', __( 'Both environments must have an active cryptographic enrollment in Leadwerk Hub.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		if ( $source['wordpress_version'] === '' || ! hash_equals( $source['wordpress_version'], $target['wordpress_version'] ) ) {
			Leadwerk_Migration_Sync::schedule_fast_worker();
			return new WP_Error( 'leadwerk_sync_wordpress_mismatch', sprintf( __( 'Sync blocked temporarily: WordPress versions differ (%1$s → %2$s). Hub automatically upgrades the lower environment to this Site Family’s highest WordPress version; retry after both heartbeats match.', 'leadwerk-migration' ), $source['wordpress_version'], $target['wordpress_version'] ), array( 'status' => 409 ) );
		}
		if ( ! Leadwerk_Migration_Site_Identity::php_versions_compatible( $source['php_version'], $target['php_version'] ) ) {
			return new WP_Error( 'leadwerk_sync_php_mismatch', sprintf( __( 'Sync blocked: PHP branches differ (%1$s → %2$s). Patch releases may differ, but major.minor must match.', 'leadwerk-migration' ), $source['php_version'], $target['php_version'] ), array( 'status' => 409 ) );
		}
		if ( $source['plugin_version'] === '' || ! hash_equals( $source['plugin_version'], $target['plugin_version'] ) ) {
			Leadwerk_Migration_Sync::schedule_fast_worker();
			return new WP_Error( 'leadwerk_sync_plugin_mismatch', sprintf( __( 'Sync blocked temporarily: Leadwerk Migration versions differ (%1$s → %2$s). Hub has queued automatic signed plugin reconciliation; wait for both heartbeats to show the same version and retry.', 'leadwerk-migration' ), $source['plugin_version'], $target['plugin_version'] ), array( 'status' => 409 ) );
		}
		if ( ! self::environment_is_online( $source ) || ! self::environment_is_online( $target ) ) {
			return new WP_Error( 'leadwerk_sync_environment_offline', __( 'Sync blocked: source and target must both have contacted Leadwerk Hub within the last 15 minutes. Bring both environments online and try again.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}

		$job_id = Leadwerk_Migration_Site_Identity::random_id();
		$now = gmdate( 'Y-m-d H:i:s' );
		$expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$peer_capable = version_compare( $source['plugin_version'], '1.4.0', '>=' ) && version_compare( $target['plugin_version'], '1.4.0', '>=' );
		if ( ! $peer_capable ) {
			return new WP_Error( 'leadwerk_sync_peer_required', __( 'Sync blocked: both environments must support authenticated peer-to-peer transfer. Hub relay storage is disabled.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		$transport = 'local' === $source['environment_type'] ? 'direct_push' : 'direct_pull';
		$status = 'preparing_target';
		$message = __( 'Preparing the target safety backup before authenticated peer-to-peer transfer.', 'leadwerk-migration' );
		$inserted = $wpdb->insert(
			$tables['jobs'],
			array(
				'job_id' => $job_id,
				'family_id' => $auth['family_id'],
				'source_environment_id' => $source_id,
				'target_environment_id' => $target_id,
				'initiator_environment_id' => $auth['environment_id'],
				'status' => $status,
				'source_approved' => 1,
				'target_approved' => 1,
				'transport' => $transport,
				'message' => $message,
				'created_at' => $now,
				'updated_at' => $now,
				'expires_at' => $expires,
			)
		);
		if ( false === $inserted ) {
			return new WP_Error( 'leadwerk_sync_job_create_failed', __( 'Hub could not create the full-site synchronization job.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}
		self::dispatch_wake( $target, 'job', $job_id );
		return new WP_REST_Response( array( 'job_id' => $job_id, 'status' => $status, 'transport' => $transport ), 201 );
	}

	/** Hub-admin transfer from any enrolled source to its family or an available target. */
	public static function create_admin_job( $source_id, $target_id ) {
		if ( ! self::enabled() || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'leadwerk_hub_sync_forbidden', __( 'Only a Hub administrator can assign an available target.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}
		if ( ! Leadwerk_Migration_Site_Identity::valid_id( $source_id ) || ! Leadwerk_Migration_Site_Identity::valid_id( $target_id ) || hash_equals( $source_id, $target_id ) ) {
			return new WP_Error( 'leadwerk_hub_sync_invalid', __( 'Choose two different valid environments.', 'leadwerk-migration' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$tables = self::tables();
		$source = $wpdb->get_row( $wpdb->prepare( "SELECT e.* FROM {$tables['environments']} e INNER JOIN {$tables['families']} f ON f.family_id = e.family_id WHERE e.environment_id = %s AND e.status = 'approved' AND f.status = 'approved'", $source_id ), ARRAY_A );
		$target = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND status IN ('approved','available')", $target_id ), ARRAY_A );
		if ( ! is_array( $source ) || ! is_array( $target ) ) {
			return new WP_Error( 'leadwerk_hub_sync_environment_unavailable', __( 'Source and target must be active Hub environments.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		$is_available = '' === (string) $target['family_id'] && 'available' === $target['status'];
		if ( ! $is_available && ( 'approved' !== $target['status'] || ! hash_equals( $source['family_id'], $target['family_id'] ) ) ) {
			return new WP_Error( 'leadwerk_hub_sync_cross_family', __( 'The target must belong to the source Site Family or be listed as an available Family-less target.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		$preflight = self::hub_version_preflight( $source, $target );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['jobs']} WHERE (source_environment_id = %s OR target_environment_id = %s) AND status NOT IN ('complete','failed','canceled')", $target_id, $target_id ) );
		if ( $active > 0 ) {
			return new WP_Error( 'leadwerk_hub_sync_target_busy', __( 'The selected target already has an active synchronization job.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}

		$wpdb->query( 'START TRANSACTION' );
		if ( $is_available ) {
			$family_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['environments']} WHERE family_id = %s", $source['family_id'] ) );
			if ( $family_count >= self::MAX_ENVIRONMENTS_PER_FAMILY ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'leadwerk_hub_environment_capacity', __( 'This Site Family has reached its environment limit.', 'leadwerk-migration' ), array( 'status' => 409 ) );
			}
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$tables['environments']} SET family_id = %s, status = 'approved', updated_at = %s WHERE environment_id = %s AND family_id = '' AND status = 'available'", $source['family_id'], gmdate( 'Y-m-d H:i:s' ), $target_id ) );
			if ( 1 !== (int) $claimed ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'leadwerk_hub_target_claim_race', __( 'The available target was claimed by another request. Refresh and try again.', 'leadwerk-migration' ), array( 'status' => 409 ) );
			}
		}
		$result = self::insert_job( $source['family_id'], $source_id, $target_id, $source_id, false, false );
		if ( is_wp_error( $result ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $result;
		}
		$wpdb->query( 'COMMIT' );
		$claimed_target = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s", $target_id ), ARRAY_A );
		$data = is_object( $result ) && method_exists( $result, 'get_data' ) ? $result->get_data() : array();
		if ( is_array( $claimed_target ) && isset( $data['job_id'] ) ) {
			self::dispatch_wake( $claimed_target, 'job', $data['job_id'] );
		}
		return $result;
	}

	/** Cancel any safe pre-import job from the central Hub panel. */
	public static function admin_cancel_job( $job_id ) {
		if ( ! self::enabled() || ! current_user_can( 'manage_options' ) || ! Leadwerk_Migration_Site_Identity::valid_id( $job_id ) ) {
			return new WP_Error( 'leadwerk_hub_job_forbidden', __( 'The Hub job action is not authorized.', 'leadwerk-migration' ) );
		}
		global $wpdb;
		$tables = self::tables();
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['jobs']} WHERE job_id = %s", $job_id ), ARRAY_A );
		if ( ! is_array( $job ) || ! in_array( $job['status'], array( 'preparing_target', 'approved', 'transferring', 'peer_ready', 'ready', 'receiving', 'failed' ), true ) ) {
			return new WP_Error( 'leadwerk_hub_job_cancel_unsafe', __( 'This job cannot be canceled safely at its current stage.', 'leadwerk-migration' ) );
		}
		$updated = $wpdb->update( $tables['jobs'], array( 'status' => 'canceled', 'message' => __( 'Canceled from the Hub management panel.', 'leadwerk-migration' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'job_id' => $job_id ) );
		return false === $updated ? new WP_Error( 'leadwerk_hub_job_cancel_failed', __( 'Hub could not cancel the synchronization job.', 'leadwerk-migration' ) ) : true;
	}

	/** Retry a global Hub job while preserving its assigned Site Family. */
	public static function admin_retry_job( $job_id ) {
		if ( ! self::enabled() || ! current_user_can( 'manage_options' ) || ! Leadwerk_Migration_Site_Identity::valid_id( $job_id ) ) {
			return new WP_Error( 'leadwerk_hub_job_forbidden', __( 'The Hub job action is not authorized.', 'leadwerk-migration' ) );
		}
		global $wpdb;
		$tables = self::tables();
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['jobs']} WHERE job_id = %s", $job_id ), ARRAY_A );
		if ( ! is_array( $job ) || ! in_array( $job['status'], array( 'failed', 'canceled' ), true ) ) {
			return new WP_Error( 'leadwerk_hub_job_retry_invalid', __( 'Only failed or canceled jobs can be retried.', 'leadwerk-migration' ) );
		}
		$source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $job['source_environment_id'], $job['family_id'] ), ARRAY_A );
		$target = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $job['target_environment_id'], $job['family_id'] ), ARRAY_A );
		$preflight = self::hub_version_preflight( $source, $target );
		return is_wp_error( $preflight ) ? $preflight : self::insert_job( $job['family_id'], $job['source_environment_id'], $job['target_environment_id'], $job['source_environment_id'] );
	}

	public static function list_jobs( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		global $wpdb;
		$tables = self::tables();
		self::purge_expired_canceled_jobs();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['jobs']} WHERE family_id = %s ORDER BY created_at DESC LIMIT 100", $auth['family_id'] ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $index => $row ) {
			$rows[ $index ] = self::decorate_job_for_environment( $row, $auth );
		}
		return new WP_REST_Response( array( 'jobs' => is_array( $rows ) ? $rows : array() ), 200 );
	}

	/** Target confirms its rollback backup exists before any peer bytes move. */
	public static function target_ready( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$job = self::job_for_environment( $request['job_id'], $auth );
		if ( is_wp_error( $job ) || ! hash_equals( $job['target_environment_id'], $auth['environment_id'] ) ) {
			return is_wp_error( $job ) ? $job : new WP_Error( 'leadwerk_peer_not_target', __( 'Only the target can confirm peer readiness.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}
		if ( 'preparing_target' !== $job['status'] ) {
			return new WP_REST_Response( array( 'job_id' => $job['job_id'], 'status' => $job['status'], 'transport' => $job['transport'] ), 200 );
		}
		global $wpdb;
		$tables = self::tables();
		$wpdb->update( $tables['jobs'], array( 'status' => 'approved', 'message' => __( 'Target safety backup ready. Direct Site Family transfer is starting.', 'leadwerk-migration' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'job_id' => $job['job_id'] ) );
		$source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $job['source_environment_id'], $job['family_id'] ), ARRAY_A );
		self::dispatch_wake( $source, 'job', $job['job_id'] );
		return new WP_REST_Response( array( 'job_id' => $job['job_id'], 'status' => 'approved', 'transport' => $job['transport'] ), 200 );
	}

	/** Source publishes archive integrity metadata before peer bytes move. */
	public static function source_ready( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$job = self::job_for_environment( $request['job_id'], $auth );
		if ( is_wp_error( $job ) || ! hash_equals( $job['source_environment_id'], $auth['environment_id'] ) || ! in_array( $job['transport'], array( 'direct_pull', 'direct_push' ), true ) ) {
			return is_wp_error( $job ) ? $job : new WP_Error( 'leadwerk_peer_not_source', __( 'Only the direct-transfer source can publish this archive.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}
		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();
		$size = isset( $data['archive_size'] ) ? (int) $data['archive_size'] : 0;
		$sha256 = isset( $data['archive_sha256'] ) ? strtolower( trim( (string) $data['archive_sha256'] ) ) : '';
		$name = isset( $data['archive_name'] ) ? sanitize_file_name( $data['archive_name'] ) : '';
		$maximum_size = (int) apply_filters( 'leadwerk_migration_sync_max_archive_size', 20 * 1024 * 1024 * 1024 );
		if ( 'approved' !== $job['status'] || $size <= 0 || $maximum_size <= 0 || $size > $maximum_size || ! preg_match( '/\A[a-f0-9]{64}\z/D', $sha256 ) || ! leadwerk_migration_is_filename_supported( $name ) ) {
			return new WP_Error( 'leadwerk_peer_manifest_invalid', __( 'The direct-transfer archive manifest is invalid.', 'leadwerk-migration' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		$tables = self::tables();
		$is_pull = 'direct_pull' === $job['transport'];
		$status = $is_pull ? 'peer_ready' : 'approved';
		$wpdb->update( $tables['jobs'], array( 'status' => $status, 'archive_name' => $name, 'archive_size' => $size, 'archive_sha256' => $sha256, 'bytes_received' => $is_pull ? $size : 0, 'message' => $is_pull ? __( 'Source archive ready. Target is downloading directly inside the Site Family.', 'leadwerk-migration' ) : __( 'Source archive manifest verified. Direct upload to the family target is starting.', 'leadwerk-migration' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'job_id' => $job['job_id'] ) );
		if ( $is_pull ) {
			$target = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $job['target_environment_id'], $job['family_id'] ), ARRAY_A );
			self::dispatch_wake( $target, 'job', $job['job_id'] );
		}
		return new WP_REST_Response( array( 'job_id' => $job['job_id'], 'status' => $status, 'transport' => $job['transport'] ), 200 );
	}

	/** Target reports direct bytes without sending archive contents through Hub. */
	public static function peer_progress( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$job = self::job_for_environment( $request['job_id'], $auth );
		if ( is_wp_error( $job ) || ! hash_equals( $job['target_environment_id'], $auth['environment_id'] ) || ! in_array( $job['transport'], array( 'direct_pull', 'direct_push' ), true ) ) {
			return is_wp_error( $job ) ? $job : new WP_Error( 'leadwerk_peer_progress_forbidden', __( 'Only the direct-transfer target can report progress.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}
		$data = $request->get_json_params();
		$delivered = is_array( $data ) && isset( $data['bytes_delivered'] ) ? max( 0, min( (int) $job['archive_size'], (int) $data['bytes_delivered'] ) ) : 0;
		$ready = is_array( $data ) && ! empty( $data['ready'] ) && $delivered === (int) $job['archive_size'];
		if ( in_array( $job['status'], array( 'verifying', 'preparing_import', 'importing', 'finalizing', 'complete' ), true ) && $ready && (int) $job['bytes_delivered'] >= (int) $job['archive_size'] ) {
			return new WP_REST_Response( array( 'job_id' => $job['job_id'], 'status' => $job['status'], 'bytes_delivered' => (int) $job['bytes_delivered'] ), 200 );
		}
		if ( in_array( $job['status'], array( 'complete', 'failed', 'canceled', 'verifying', 'preparing_import', 'importing', 'finalizing' ), true ) || (int) $job['archive_size'] <= 0 ) {
			return new WP_Error( 'leadwerk_peer_progress_terminal', __( 'This synchronization job no longer accepts direct-transfer progress.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$tables = self::tables();
		$wpdb->update( $tables['jobs'], array( 'status' => $ready ? 'ready' : 'receiving', 'bytes_delivered' => max( (int) $job['bytes_delivered'], $delivered ), 'message' => $ready ? __( 'Direct Site Family transfer verified and ready to import.', 'leadwerk-migration' ) : __( 'Archive is transferring directly between Site Family environments.', 'leadwerk-migration' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'job_id' => $job['job_id'] ) );
		if ( $ready ) {
			self::dispatch_wake( $auth, 'job', $job['job_id'] );
		}
		return new WP_REST_Response( array( 'job_id' => $job['job_id'], 'status' => $ready ? 'ready' : 'receiving', 'bytes_delivered' => $delivered ), 200 );
	}

	/** Target publishes non-byte import phases so every admin panel stays current. */
	public static function import_progress( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$job = self::job_for_environment( $request['job_id'], $auth );
		if ( is_wp_error( $job ) || ! hash_equals( $job['target_environment_id'], $auth['environment_id'] ) ) {
			return is_wp_error( $job ) ? $job : new WP_Error( 'leadwerk_import_progress_forbidden', __( 'Only the synchronization target can report import progress.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}
		if ( in_array( $job['status'], array( 'complete', 'failed', 'canceled' ), true ) ) {
			return new WP_Error( 'leadwerk_import_progress_terminal', __( 'This synchronization job no longer accepts import progress.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}

		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();
		$stage = isset( $data['stage'] ) ? sanitize_key( $data['stage'] ) : '';
		$allowed = array( 'verifying', 'preparing_import', 'importing', 'finalizing' );
		if ( ! in_array( $stage, $allowed, true ) ) {
			return new WP_Error( 'leadwerk_import_progress_stage_invalid', __( 'The reported synchronization import phase is invalid.', 'leadwerk-migration' ), array( 'status' => 400 ) );
		}
		$message = isset( $data['message'] ) ? substr( sanitize_text_field( $data['message'] ), 0, 1000 ) : '';
		if ( '' === $message ) {
			$defaults = array(
				'verifying'       => __( 'Verifying the received archive before import.', 'leadwerk-migration' ),
				'preparing_import' => __( 'Preparing the verified archive in the private import workspace.', 'leadwerk-migration' ),
				'importing'       => __( 'Importing the full WordPress site on the target environment.', 'leadwerk-migration' ),
				'finalizing'      => __( 'Finalizing the imported WordPress site and reporting completion.', 'leadwerk-migration' ),
			);
			$message = $defaults[ $stage ];
		}

		global $wpdb;
		$tables = self::tables();
		$updated = $wpdb->update(
			$tables['jobs'],
			array(
				'status'     => $stage,
				'message'    => $message,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'job_id' => $job['job_id'] )
		);
		if ( false === $updated ) {
			return new WP_Error( 'leadwerk_import_progress_failed', __( 'Hub could not persist synchronization import progress.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}
		return new WP_REST_Response( array( 'job_id' => $job['job_id'], 'status' => $stage, 'message' => $message ), 200 );
	}

	/** Legacy endpoint implementation retained only to fail old workers closed. */
	public static function peer_fallback( $request ) {
		return new WP_Error( 'leadwerk_peer_fallback_disabled', __( 'Hub relay storage is disabled. Fix peer connectivity and start a new synchronization job.', 'leadwerk-migration' ), array( 'status' => 410 ) );

		// Unreachable compatibility code below is intentionally not routed.
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$job = self::job_for_environment( $request['job_id'], $auth );
		if ( is_wp_error( $job ) || ( ! hash_equals( $job['source_environment_id'], $auth['environment_id'] ) && ! hash_equals( $job['target_environment_id'], $auth['environment_id'] ) ) ) {
			return is_wp_error( $job ) ? $job : new WP_Error( 'leadwerk_peer_fallback_forbidden', __( 'This environment cannot change the transfer route.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}
		if ( 'hub_relay' === $job['transport'] ) {
			return new WP_REST_Response( array( 'job_id' => $job['job_id'], 'status' => $job['status'], 'transport' => 'hub_relay' ), 200 );
		}
		if ( in_array( $job['status'], array( 'complete', 'failed', 'canceled', 'verifying', 'preparing_import', 'importing', 'finalizing' ), true ) ) {
			return new WP_Error( 'leadwerk_peer_fallback_terminal', __( 'This synchronization job can no longer change transfer routes.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$tables = self::tables();
		$wpdb->update( $tables['jobs'], array( 'transport' => 'hub_relay', 'status' => 'approved', 'archive_size' => 0, 'archive_sha256' => '', 'bytes_received' => 0, 'bytes_delivered' => 0, 'peer_failures' => (int) $job['peer_failures'] + 1, 'message' => __( 'Direct family connection was unavailable. Continuing through the private Hub relay.', 'leadwerk-migration' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'job_id' => $job['job_id'] ) );
		$source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $job['source_environment_id'], $job['family_id'] ), ARRAY_A );
		self::dispatch_wake( $source, 'job', $job['job_id'] );
		return new WP_REST_Response( array( 'job_id' => $job['job_id'], 'status' => 'approved', 'transport' => 'hub_relay' ), 200 );
	}

	public static function approve_job( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$job = self::job_for_environment( $request['job_id'], $auth );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( ! in_array( $job['status'], array( 'awaiting_approval', 'approved' ), true ) ) {
			return new WP_Error( 'leadwerk_sync_job_not_approvable', __( 'This sync job can no longer be approved.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$tables = self::tables();
		$changes = array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
		if ( hash_equals( $job['source_environment_id'], $auth['environment_id'] ) ) {
			$changes['source_approved'] = 1;
		}
		if ( hash_equals( $job['target_environment_id'], $auth['environment_id'] ) ) {
			$changes['target_approved'] = 1;
		}
		$source_approved = ! empty( $changes['source_approved'] ) || ! empty( $job['source_approved'] );
		$target_approved = ! empty( $changes['target_approved'] ) || ! empty( $job['target_approved'] );
		if ( $source_approved && $target_approved ) {
			$changes['status']  = 'approved';
			$changes['message'] = __( 'Approved. The source environment will create a verified backup.', 'leadwerk-migration' );
		}
		$wpdb->update( $tables['jobs'], $changes, array( 'job_id' => $job['job_id'] ) );
		return new WP_REST_Response( array( 'job_id' => $job['job_id'], 'status' => isset( $changes['status'] ) ? $changes['status'] : 'awaiting_approval' ), 200 );
	}

	public static function receive_chunk( $request ) {
		return new WP_Error( 'leadwerk_hub_relay_disabled', __( 'Hub archive relay storage is disabled; synchronization is peer-to-peer only.', 'leadwerk-migration' ), array( 'status' => 410 ) );

		// Unreachable compatibility code below is intentionally not routed.
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$job = self::job_for_environment( $request['job_id'], $auth );
		if ( is_wp_error( $job ) || ! hash_equals( $job['source_environment_id'], $auth['environment_id'] ) ) {
			return is_wp_error( $job ) ? $job : new WP_Error( 'leadwerk_sync_not_source', __( 'Only the approved source can upload archive chunks.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}
		if ( 'hub_relay' !== $job['transport'] || ! in_array( $job['status'], array( 'approved', 'transferring' ), true ) ) {
			return new WP_Error( 'leadwerk_sync_upload_not_ready', __( 'This job is not accepting archive data.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}

		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();
		$offset = isset( $data['offset'] ) ? (int) $data['offset'] : -1;
		$total  = isset( $data['total'] ) ? (int) $data['total'] : 0;
		$sha256 = isset( $data['sha256'] ) ? strtolower( trim( (string) $data['sha256'] ) ) : '';
		$name   = isset( $data['archive_name'] ) ? sanitize_file_name( $data['archive_name'] ) : '';
		$maximum_size = (int) apply_filters( 'leadwerk_migration_sync_max_archive_size', 20 * 1024 * 1024 * 1024 );
		if ( $total <= 0 || $maximum_size <= 0 || $total > $maximum_size ) {
			return new WP_Error( 'leadwerk_sync_archive_size_invalid', sprintf( __( 'The relay archive size is invalid or exceeds the Hub limit of %s.', 'leadwerk-migration' ), size_format( $maximum_size ) ), array( 'status' => 413 ) );
		}
		$chunk  = isset( $data['data'] ) && is_string( $data['data'] ) ? base64_decode( $data['data'], true ) : false;
		if ( $offset < 0 || $total <= 0 || ! preg_match( '/\A[a-f0-9]{64}\z/D', $sha256 ) || ! leadwerk_migration_is_filename_supported( $name ) || ! is_string( $chunk ) || $chunk === '' || strlen( $chunk ) > LEADWERK_MIGRATION_SYNC_CHUNK_SIZE ) {
			return new WP_Error( 'leadwerk_sync_invalid_chunk', __( 'The archive chunk metadata is invalid.', 'leadwerk-migration' ), array( 'status' => 400 ) );
		}

		$path = self::relay_path( $job['job_id'] );
		$current_size = is_file( $path ) ? (int) filesize( $path ) : 0;
		if ( $current_size !== $offset || (int) $job['bytes_received'] !== $offset ) {
			return new WP_Error( 'leadwerk_sync_chunk_offset', sprintf( __( 'Chunk offset mismatch. Expected %d bytes.', 'leadwerk-migration' ), $current_size ), array( 'status' => 409, 'expected_offset' => $current_size ) );
		}
		$handle = @fopen( $path, $offset === 0 ? 'wb' : 'ab' );
		if ( $handle === false || ! flock( $handle, LOCK_EX ) ) {
			return new WP_Error( 'leadwerk_sync_relay_write_failed', __( 'The Hub relay file could not be locked for writing.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}
		$written = fwrite( $handle, $chunk );
		fflush( $handle );
		flock( $handle, LOCK_UN );
		fclose( $handle );
		if ( $written !== strlen( $chunk ) ) {
			return new WP_Error( 'leadwerk_sync_relay_write_failed', __( 'The Hub could not write the complete archive chunk.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}
		@chmod( $path, 0600 );

		$received = $offset + $written;
		$changes = array(
			'status' => 'transferring',
			'archive_name' => $name,
			'archive_size' => $total,
			'archive_sha256' => $sha256,
			'bytes_received' => $received,
			'message' => __( 'Direct family connection was unavailable; archive transfer is continuing through the private Hub fallback.', 'leadwerk-migration' ),
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( $received === $total ) {
			$actual = hash_file( 'sha256', $path );
			if ( ! is_string( $actual ) || ! hash_equals( $sha256, strtolower( $actual ) ) ) {
				self::safe_unlink_relay( $job['job_id'] );
				self::mark_job_failed( $job['job_id'], __( 'Hub relay SHA-256 verification failed.', 'leadwerk-migration' ) );
				return new WP_Error( 'leadwerk_sync_relay_integrity_failed', __( 'The transferred archive failed SHA-256 verification.', 'leadwerk-migration' ), array( 'status' => 409 ) );
			}
			$changes['status']  = 'ready';
			$changes['message'] = __( 'Verified archive is ready for the target environment.', 'leadwerk-migration' );
		} elseif ( $received > $total ) {
			self::safe_unlink_relay( $job['job_id'] );
			return new WP_Error( 'leadwerk_sync_relay_overflow', __( 'The received archive is larger than declared.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}

		global $wpdb;
		$tables = self::tables();
		$updated = $wpdb->update( $tables['jobs'], $changes, array( 'job_id' => $job['job_id'] ) );
		if ( false === $updated ) {
			return new WP_Error( 'leadwerk_sync_relay_state_failed', __( 'Hub received the archive data but could not persist the job state.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}
		if ( 'ready' === $changes['status'] ) {
			$target = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $job['target_environment_id'], $job['family_id'] ), ARRAY_A );
			self::dispatch_wake( $target, 'job', $job['job_id'] );
		}
		return new WP_REST_Response( array( 'job_id' => $job['job_id'], 'status' => $changes['status'], 'bytes_received' => $received ), 200 );
	}

	public static function read_chunk( $request ) {
		return new WP_Error( 'leadwerk_hub_relay_disabled', __( 'Hub archive relay storage is disabled; synchronization is peer-to-peer only.', 'leadwerk-migration' ), array( 'status' => 410 ) );

		// Unreachable compatibility code below is intentionally not routed.
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$job = self::job_for_environment( $request['job_id'], $auth );
		if ( is_wp_error( $job ) || ! hash_equals( $job['target_environment_id'], $auth['environment_id'] ) ) {
			return is_wp_error( $job ) ? $job : new WP_Error( 'leadwerk_sync_not_target', __( 'Only the approved target can read archive chunks.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}
		if ( 'hub_relay' !== $job['transport'] || ! in_array( $job['status'], array( 'ready', 'receiving' ), true ) ) {
			return new WP_Error( 'leadwerk_sync_archive_not_ready', __( 'The verified archive is not ready yet.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		$data = $request->get_json_params();
		$offset = is_array( $data ) && isset( $data['offset'] ) ? max( 0, (int) $data['offset'] ) : 0;
		$length = is_array( $data ) && isset( $data['length'] ) ? max( 1, min( LEADWERK_MIGRATION_SYNC_CHUNK_SIZE, (int) $data['length'] ) ) : LEADWERK_MIGRATION_SYNC_CHUNK_SIZE;
		$path = self::relay_path( $job['job_id'] );
		if ( ! is_file( $path ) || $offset >= (int) $job['archive_size'] ) {
			return new WP_Error( 'leadwerk_sync_relay_missing', __( 'The Hub relay archive is unavailable.', 'leadwerk-migration' ), array( 'status' => 404 ) );
		}
		$handle = @fopen( $path, 'rb' );
		if ( $handle === false || fseek( $handle, $offset ) !== 0 ) {
			return new WP_Error( 'leadwerk_sync_relay_read_failed', __( 'The Hub relay archive could not be read.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}
		$chunk = fread( $handle, $length );
		fclose( $handle );
		if ( ! is_string( $chunk ) || $chunk === '' ) {
			return new WP_Error( 'leadwerk_sync_relay_read_failed', __( 'The Hub returned an empty archive chunk.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}
		global $wpdb;
		$tables = self::tables();
		$delivered = max( (int) ( isset( $job['bytes_delivered'] ) ? $job['bytes_delivered'] : 0 ), min( (int) $job['archive_size'], $offset + strlen( $chunk ) ) );
		$message = $delivered >= (int) $job['archive_size']
			? __( 'Target download complete; SHA-256 verification and import are running.', 'leadwerk-migration' )
			: __( 'Target is downloading the verified archive from Hub.', 'leadwerk-migration' );
		$wpdb->update( $tables['jobs'], array( 'status' => 'receiving', 'bytes_delivered' => $delivered, 'message' => $message, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'job_id' => $job['job_id'] ) );
		return new WP_REST_Response(
			array(
				'job_id' => $job['job_id'],
				'offset' => $offset,
				'length' => strlen( $chunk ),
				'total'  => (int) $job['archive_size'],
				'sha256' => $job['archive_sha256'],
				'archive_name' => $job['archive_name'],
				'data'   => base64_encode( $chunk ),
			),
			200
		);
	}

	public static function complete_job( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$job = self::job_for_environment( $request['job_id'], $auth );
		if ( is_wp_error( $job ) || ! hash_equals( $job['target_environment_id'], $auth['environment_id'] ) ) {
			return is_wp_error( $job ) ? $job : new WP_Error( 'leadwerk_sync_not_target', __( 'Only the target can complete this sync.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}
		global $wpdb;
		$tables = self::tables();
		$updated = $wpdb->update( $tables['jobs'], array( 'status' => 'complete', 'message' => __( 'Full-site sync completed successfully.', 'leadwerk-migration' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'job_id' => $job['job_id'] ) );
		if ( false === $updated ) {
			return new WP_Error( 'leadwerk_sync_complete_failed', __( 'Hub could not persist the completed synchronization state.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}
		self::safe_unlink_relay( $job['job_id'] );
		$source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $job['source_environment_id'], $job['family_id'] ), ARRAY_A );
		self::dispatch_wake( $source, 'job', $job['job_id'] );
		return new WP_REST_Response( array( 'job_id' => $job['job_id'], 'status' => 'complete' ), 200 );
	}

	public static function fail_job( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$job = self::job_for_environment( $request['job_id'], $auth );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$data = $request->get_json_params();
		$message = is_array( $data ) && isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : __( 'Environment reported a sync failure.', 'leadwerk-migration' );
		self::mark_job_failed( $job['job_id'], $message );
		self::safe_unlink_relay( $job['job_id'] );
		return new WP_REST_Response( array( 'job_id' => $job['job_id'], 'status' => 'failed' ), 200 );
	}

	public static function cancel_job( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$job = self::family_job( $request['job_id'], $auth['family_id'] );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( ! in_array( $job['status'], array( 'preparing_target', 'approved', 'transferring', 'peer_ready', 'ready', 'receiving', 'failed' ), true ) ) {
			return new WP_Error( 'leadwerk_sync_cancel_unsafe', __( 'This job is already importing, complete, canceled or otherwise cannot be canceled safely.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$tables = self::tables();
		$wpdb->update( $tables['jobs'], array( 'status' => 'canceled', 'message' => __( 'Canceled from the Site Family management panel.', 'leadwerk-migration' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'job_id' => $job['job_id'] ) );
		self::safe_unlink_relay( $job['job_id'] );
		return new WP_REST_Response( array( 'job_id' => $job['job_id'], 'status' => 'canceled' ), 200 );
	}

	public static function retry_job( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$job = self::family_job( $request['job_id'], $auth['family_id'] );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( ! in_array( $job['status'], array( 'failed', 'canceled' ), true ) ) {
			return new WP_Error( 'leadwerk_sync_retry_invalid', __( 'Only failed or canceled jobs can be retried.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$tables = self::tables();
		$source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $job['source_environment_id'], $auth['family_id'] ), ARRAY_A );
		$target = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $job['target_environment_id'], $auth['family_id'] ), ARRAY_A );
		$preflight = self::hub_version_preflight( $source, $target );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		return self::insert_job( $auth['family_id'], $job['source_environment_id'], $job['target_environment_id'], $auth['environment_id'] );
	}

	public static function create_command( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();
		$target_id = isset( $data['target_environment_id'] ) ? strtolower( trim( (string) $data['target_environment_id'] ) ) : '';
		$reference_id = isset( $data['reference_environment_id'] ) ? strtolower( trim( (string) $data['reference_environment_id'] ) ) : '';
		if ( $target_id === $reference_id || ! Leadwerk_Migration_Site_Identity::valid_id( $target_id ) || ! Leadwerk_Migration_Site_Identity::valid_id( $reference_id ) ) {
			return new WP_Error( 'leadwerk_runtime_invalid_endpoints', __( 'Choose different active reference and target environments.', 'leadwerk-migration' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		$tables = self::tables();
		$target = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $target_id, $auth['family_id'] ), ARRAY_A );
		$reference = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $reference_id, $auth['family_id'] ), ARRAY_A );
		if ( ! is_array( $target ) || ! is_array( $reference ) || ! self::environment_is_online( $target ) || ! self::environment_is_online( $reference ) ) {
			return new WP_Error( 'leadwerk_runtime_endpoint_unavailable', __( 'Reference and target must both be active and online.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		if ( empty( $target['plugin_version'] ) || version_compare( $target['plugin_version'], '1.3.0', '<' ) ) {
			return new WP_Error(
				'leadwerk_runtime_bootstrap_required',
				__( 'This target predates the secure runtime command worker. Install Leadwerk Migration 1.3.0 once on the target; all later plugin updates can be delivered automatically by the Hub.', 'leadwerk-migration' ),
				array( 'status' => 409 )
			);
		}
		$command_id = Leadwerk_Migration_Site_Identity::random_id();
		$payload = array(
			'reference_environment_id' => $reference_id,
			'plugin_version'            => LEADWERK_MIGRATION_VERSION,
			'wordpress_version'         => $reference['wordpress_version'],
			'php_version'               => $reference['php_version'],
			'stage'                     => 'plugin',
		);
		$now = gmdate( 'Y-m-d H:i:s' );
		$inserted = $wpdb->insert(
			$tables['commands'],
			array(
				'command_id' => $command_id, 'family_id' => $auth['family_id'], 'target_environment_id' => $target_id,
				'initiator_environment_id' => $auth['environment_id'], 'command_type' => 'runtime_reconcile', 'status' => 'queued',
				'payload' => wp_json_encode( $payload ), 'message' => __( 'Runtime reconciliation queued: plugin → WordPress → PHP.', 'leadwerk-migration' ),
				'created_at' => $now, 'updated_at' => $now, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ),
			)
		);
		if ( false === $inserted ) {
			return new WP_Error( 'leadwerk_runtime_command_failed', __( 'Hub could not create the runtime reconciliation command.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}
		self::dispatch_wake( $target, 'command', $command_id );
		return new WP_REST_Response( array( 'command_id' => $command_id, 'status' => 'queued', 'payload' => $payload ), 201 );
	}

	public static function list_commands( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		global $wpdb;
		$tables = self::tables();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['commands']} WHERE family_id = %s AND command_type IN ('plugin_publish','plugin_update','wordpress_update') ORDER BY created_at DESC LIMIT 100", $auth['family_id'] ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $index => $row ) {
			$rows[ $index ]['payload'] = json_decode( $row['payload'], true );
		}
		return new WP_REST_Response( array( 'commands' => is_array( $rows ) ? $rows : array() ), 200 );
	}

	public static function command_progress( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$command = self::family_command( $request['command_id'], $auth['family_id'] );
		if ( is_wp_error( $command ) || ! hash_equals( $command['target_environment_id'], $auth['environment_id'] ) ) {
			return is_wp_error( $command ) ? $command : new WP_Error( 'leadwerk_runtime_not_target', __( 'Only the target environment can update command progress.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}
		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();
		$status = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'running';
		if ( in_array( $command['status'], array( 'complete', 'canceled' ), true ) && $status !== $command['status'] ) {
			return new WP_Error( 'leadwerk_runtime_terminal', __( 'A completed or canceled runtime command cannot be changed by a late worker response.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		if ( ! in_array( $status, array( 'queued', 'running', 'waiting_runtime', 'manual_required', 'complete', 'failed', 'canceled' ), true ) ) {
			return new WP_Error( 'leadwerk_runtime_status_invalid', __( 'The runtime command status is invalid.', 'leadwerk-migration' ), array( 'status' => 400 ) );
		}
		$payload = json_decode( $command['payload'], true );
		$payload = is_array( $payload ) ? $payload : array();
		if ( isset( $data['stage'] ) ) {
			$stage = sanitize_key( $data['stage'] );
			if ( in_array( $stage, array( 'publish', 'plugin', 'wordpress', 'php', 'verify', 'complete' ), true ) ) {
				$payload['stage'] = $stage;
			}
		}
		global $wpdb;
		$tables = self::tables();
		$wpdb->update( $tables['commands'], array( 'status' => $status, 'payload' => wp_json_encode( $payload ), 'message' => substr( sanitize_text_field( isset( $data['message'] ) ? $data['message'] : '' ), 0, 1000 ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'command_id' => $command['command_id'] ) );
		return new WP_REST_Response( array( 'command_id' => $command['command_id'], 'status' => $status, 'payload' => $payload ), 200 );
	}

	public static function cancel_command( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$command = self::family_command( $request['command_id'], $auth['family_id'] );
		if ( is_wp_error( $command ) ) {
			return $command;
		}
		if ( ! in_array( $command['status'], array( 'queued', 'running', 'waiting_runtime' ), true ) ) {
			return new WP_Error( 'leadwerk_runtime_cancel_invalid', __( 'Only queued or running runtime commands can be canceled.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$tables = self::tables();
		$updated = $wpdb->update( $tables['commands'], array( 'status' => 'canceled', 'message' => __( 'Canceled from the Site Family management panel.', 'leadwerk-migration' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'command_id' => $command['command_id'], 'family_id' => $auth['family_id'] ) );
		return false === $updated ? new WP_Error( 'leadwerk_runtime_cancel_failed', __( 'Hub could not cancel the runtime command.', 'leadwerk-migration' ), array( 'status' => 500 ) ) : new WP_REST_Response( array( 'command_id' => $command['command_id'], 'status' => 'canceled' ), 200 );
	}

	public static function retry_command( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$command = self::family_command( $request['command_id'], $auth['family_id'] );
		if ( is_wp_error( $command ) ) {
			return $command;
		}
		if ( ! in_array( $command['status'], array( 'failed', 'canceled', 'manual_required' ), true ) ) {
			return new WP_Error( 'leadwerk_runtime_retry_invalid', __( 'Only failed, canceled or manual-required runtime commands can be retried.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$tables = self::tables();
		$target = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $command['target_environment_id'], $auth['family_id'] ), ARRAY_A );
		if ( ! is_array( $target ) || ! self::environment_is_online( $target ) ) {
			return new WP_Error( 'leadwerk_runtime_target_offline', __( 'The runtime target must be active and online before retrying.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		$payload = json_decode( $command['payload'], true );
		$payload = is_array( $payload ) ? $payload : array();
		$type = isset( $command['command_type'] ) ? sanitize_key( $command['command_type'] ) : 'runtime_reconcile';
		if ( in_array( $type, array( 'plugin_publish', 'plugin_update', 'wordpress_update' ), true ) ) {
			if ( 'plugin_update' === $type ) {
				$payload['plugin_version'] = LEADWERK_MIGRATION_VERSION;
				$payload['stage'] = 'plugin';
			} elseif ( 'plugin_publish' === $type ) {
				$payload['stage'] = 'publish';
			} else {
				$payload['stage'] = 'wordpress';
			}
			$message = __( 'Automatic family update retry queued.', 'leadwerk-migration' );
			$expiry = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		} else {
			$reference_id = isset( $payload['reference_environment_id'] ) ? $payload['reference_environment_id'] : '';
			$reference = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $reference_id, $auth['family_id'] ), ARRAY_A );
			if ( ! is_array( $reference ) || ! self::environment_is_online( $reference ) ) {
				return new WP_Error( 'leadwerk_runtime_reference_offline', __( 'The runtime reference must be active and online before retrying.', 'leadwerk-migration' ), array( 'status' => 409 ) );
			}
			$payload['plugin_version'] = LEADWERK_MIGRATION_VERSION;
			$payload['wordpress_version'] = $reference['wordpress_version'];
			$payload['php_version'] = $reference['php_version'];
			$payload['stage'] = 'plugin';
			$message = __( 'Runtime reconciliation retry queued by this Site Family.', 'leadwerk-migration' );
			$expiry = gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );
		}
		$updated = $wpdb->update( $tables['commands'], array( 'status' => 'queued', 'payload' => wp_json_encode( $payload ), 'message' => $message, 'updated_at' => gmdate( 'Y-m-d H:i:s' ), 'expires_at' => $expiry ), array( 'command_id' => $command['command_id'], 'family_id' => $auth['family_id'] ) );
		if ( false === $updated ) {
			return new WP_Error( 'leadwerk_runtime_retry_failed', __( 'Hub could not retry the runtime command.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}
		self::dispatch_wake( $target, 'command', $command['command_id'] );
		return new WP_REST_Response( array( 'command_id' => $command['command_id'], 'status' => 'queued' ), 200 );
	}

	public static function plugin_manifest( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$manifest = self::ensure_plugin_package();
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		unset( $manifest['path'] );
		return new WP_REST_Response( $manifest, 200 );
	}

	public static function plugin_read( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$manifest = self::ensure_plugin_package();
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		$data = $request->get_json_params();
		$offset = is_array( $data ) && isset( $data['offset'] ) ? max( 0, (int) $data['offset'] ) : 0;
		$length = is_array( $data ) && isset( $data['length'] ) ? max( 1, min( LEADWERK_MIGRATION_SYNC_CHUNK_SIZE, (int) $data['length'] ) ) : LEADWERK_MIGRATION_SYNC_CHUNK_SIZE;
		if ( $offset >= (int) $manifest['size'] ) {
			return new WP_Error( 'leadwerk_plugin_package_offset', __( 'Plugin package offset is outside the published file.', 'leadwerk-migration' ), array( 'status' => 416 ) );
		}
		$handle = @fopen( $manifest['path'], 'rb' );
		if ( false === $handle || 0 !== fseek( $handle, $offset ) ) {
			return new WP_Error( 'leadwerk_plugin_package_read_failed', __( 'Hub could not read the plugin update package.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}
		$chunk = fread( $handle, $length );
		fclose( $handle );
		if ( ! is_string( $chunk ) || '' === $chunk ) {
			return new WP_Error( 'leadwerk_plugin_package_read_failed', __( 'Hub returned an empty plugin update chunk.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}
		unset( $manifest['path'] );
		$manifest['offset'] = $offset;
		$manifest['data'] = base64_encode( $chunk );
		return new WP_REST_Response( $manifest, 200 );
	}

	/** Receive a publisher-signed release from the newest approved environment. */
	public static function plugin_publish( $request ) {
		$auth = self::authenticate_approved( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();
		$command_id = isset( $data['command_id'] ) ? strtolower( trim( (string) $data['command_id'] ) ) : '';
		$version = isset( $data['version'] ) ? substr( sanitize_text_field( $data['version'] ), 0, 32 ) : '';
		$total = isset( $data['total'] ) ? (int) $data['total'] : 0;
		$sha256 = isset( $data['sha256'] ) ? strtolower( trim( (string) $data['sha256'] ) ) : '';
		$maximum = (int) apply_filters( 'leadwerk_migration_release_max_size', 256 * 1024 * 1024 );
		$command = self::family_command( $command_id, $auth['family_id'] );
		$payload = is_array( $command ) ? json_decode( $command['payload'], true ) : null;
		if ( is_wp_error( $command ) || 'plugin_publish' !== $command['command_type'] || ! hash_equals( $command['target_environment_id'], $auth['environment_id'] ) || ! in_array( $command['status'], array( 'queued', 'running' ), true ) || ! is_array( $payload ) || empty( $payload['plugin_version'] ) || ! hash_equals( (string) $payload['plugin_version'], $version ) ) {
			return is_wp_error( $command ) ? $command : new WP_Error( 'leadwerk_release_publish_forbidden', __( 'This environment is not authorized to publish the requested Migration release.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}
		if ( $total <= 0 || $maximum <= 0 || $total > $maximum || ! preg_match( '/\A[a-f0-9]{64}\z/D', $sha256 ) || version_compare( $version, LEADWERK_MIGRATION_VERSION, '<=' ) ) {
			return new WP_Error( 'leadwerk_release_publish_invalid', __( 'The proposed Migration release metadata is invalid or not newer than Hub.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		$directory = self::ensure_hub_release_directory();
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}
		$path = $directory . DIRECTORY_SEPARATOR . 'leadwerk-migration-incoming-' . sanitize_file_name( $version ) . '.zip.part';
		$current = is_file( $path ) ? (int) filesize( $path ) : 0;
		if ( ! empty( $data['probe'] ) ) {
			return new WP_REST_Response( array( 'version' => $version, 'expected_offset' => $current, 'total' => $total ), 200 );
		}
		$offset = isset( $data['offset'] ) ? (int) $data['offset'] : -1;
		$chunk = isset( $data['data'] ) && is_string( $data['data'] ) ? base64_decode( $data['data'], true ) : false;
		if ( $offset !== $current || ! is_string( $chunk ) || '' === $chunk || strlen( $chunk ) > LEADWERK_MIGRATION_SYNC_CHUNK_SIZE || $current + strlen( $chunk ) > $total ) {
			return new WP_Error( 'leadwerk_release_publish_offset', sprintf( __( 'Release upload offset mismatch. Hub expects %d bytes.', 'leadwerk-migration' ), $current ), array( 'status' => 409, 'expected_offset' => $current ) );
		}
		$handle = @fopen( $path, 0 === $offset ? 'wb' : 'ab' );
		if ( false === $handle || ! flock( $handle, LOCK_EX ) || fwrite( $handle, $chunk ) !== strlen( $chunk ) ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			return new WP_Error( 'leadwerk_release_publish_write', __( 'Hub could not save the signed Migration release chunk.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}
		fflush( $handle );
		flock( $handle, LOCK_UN );
		fclose( $handle );
		@chmod( $path, 0600 );
		$current += strlen( $chunk );
		if ( $current === $total ) {
			$actual = hash_file( 'sha256', $path );
			$verified = is_string( $actual ) && hash_equals( $sha256, strtolower( $actual ) ) ? Leadwerk_Migration_Release::verify_package( $path, $version ) : new WP_Error( 'leadwerk_release_publish_hash', __( 'The published Migration release failed SHA-256 verification.', 'leadwerk-migration' ) );
			if ( is_wp_error( $verified ) ) {
				@unlink( $path );
				return new WP_Error( 'leadwerk_release_publish_signature', $verified->get_error_message(), array( 'status' => 409 ) );
			}
			$final = substr( $path, 0, -5 );
			if ( is_file( $final ) ) {
				@unlink( $final );
			}
			if ( ! @rename( $path, $final ) ) {
				return new WP_Error( 'leadwerk_release_publish_finalize', __( 'Hub could not finalize the verified Migration release.', 'leadwerk-migration' ), array( 'status' => 500 ) );
			}
			update_option( 'leadwerk_migration_hub_pending_release', array( 'version' => $version, 'sha256' => $sha256, 'size' => $total, 'path' => $final, 'publisher_environment_id' => $auth['environment_id'], 'received_at' => gmdate( 'c' ) ), false );
			Leadwerk_Migration_Sync::schedule_fast_worker();
		}
		return new WP_REST_Response( array( 'version' => $version, 'expected_offset' => $current, 'total' => $total, 'ready' => $current === $total ), 200 );
	}

	/** Verify a replay-resistant HMAC request and return the environment row. */
	private static function authenticate( $request ) {
		$ready = self::hub_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$environment_id = strtolower( trim( (string) $request->get_header( 'X-Leadwerk-Environment' ) ) );
		$timestamp      = (int) $request->get_header( 'X-Leadwerk-Timestamp' );
		$nonce          = strtolower( trim( (string) $request->get_header( 'X-Leadwerk-Nonce' ) ) );
		$signature      = strtolower( trim( (string) $request->get_header( 'X-Leadwerk-Signature' ) ) );
		if ( ! Leadwerk_Migration_Site_Identity::valid_id( $environment_id ) || abs( time() - $timestamp ) > self::MAX_CLOCK_SKEW || ! preg_match( '/\A[a-f0-9]{32}\z/D', $nonce ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $signature ) ) {
			return new WP_Error( 'leadwerk_hub_auth_invalid', __( 'The signed Hub request is invalid or expired.', 'leadwerk-migration' ), array( 'status' => 401 ) );
		}
		$nonce_key = 'leadwerk_sync_nonce_' . hash( 'sha256', $environment_id . '|' . $nonce );
		if ( get_transient( $nonce_key ) ) {
			return new WP_Error( 'leadwerk_hub_replay', __( 'This signed Hub request has already been used.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}

		global $wpdb;
		$tables = self::tables();
		$environment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s", $environment_id ), ARRAY_A );
		if ( ! is_array( $environment ) || $environment['status'] === 'revoked' ) {
			return new WP_Error( 'leadwerk_hub_environment_unknown', __( 'The environment is not registered or has been revoked.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}
		$secret = self::open_secret( $environment['secret_encrypted'] );
		$body   = (string) $request->get_body();
		$route  = (string) $request->get_route();
		$canonical = self::canonical_request( $request->get_method(), $route, $timestamp, $nonce, $body );
		$expected = is_string( $secret ) ? hash_hmac( 'sha256', $canonical, $secret ) : '';
		if ( $expected === '' || ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'leadwerk_hub_signature_failed', __( 'The Hub request signature is invalid.', 'leadwerk-migration' ), array( 'status' => 401 ) );
		}
		set_transient( $nonce_key, 1, self::MAX_CLOCK_SKEW * 2 );
		return $environment;
	}

	private static function authenticate_approved( $request ) {
		$environment = self::authenticate( $request );
		if ( is_wp_error( $environment ) ) {
			return $environment;
		}
		if ( $environment['status'] !== 'approved' ) {
			return new WP_Error( 'leadwerk_hub_environment_pending', __( 'The environment is not active yet; automatic enrollment will retry on its next heartbeat.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}
		global $wpdb;
		$tables = self::tables();
		$family_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$tables['families']} WHERE family_id = %s", $environment['family_id'] ) );
		if ( $family_status !== 'approved' ) {
			return new WP_Error( 'leadwerk_hub_family_pending', __( 'The Site Family is not active yet; automatic enrollment will retry on the next heartbeat.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}
		return $environment;
	}

	/** @return string */
	public static function canonical_request( $method, $route, $timestamp, $nonce, $body ) {
		return strtoupper( (string) $method ) . "\n" . (string) $route . "\n" . (int) $timestamp . "\n" . strtolower( (string) $nonce ) . "\n" . hash( 'sha256', (string) $body );
	}

	/** @return array<string, string> */
	public static function tables() {
		global $wpdb;
		return array(
			'families'     => $wpdb->prefix . 'leadwerk_sync_families',
			'environments' => $wpdb->prefix . 'leadwerk_sync_environments',
			'jobs'         => $wpdb->prefix . 'leadwerk_sync_jobs',
			'commands'     => $wpdb->prefix . 'leadwerk_sync_commands',
		);
	}

	/** Admin-facing registry data. */
	public static function admin_data() {
		if ( ! self::enabled() ) {
			return array( 'families' => array(), 'environments' => array(), 'jobs' => array() );
		}
		self::maybe_install();
		self::purge_expired_canceled_jobs();
		self::purge_expired_available_environments();
		global $wpdb;
		$tables = self::tables();
		$environments = $wpdb->get_results( "SELECT environment_id, family_id, site_url, environment_type, wordpress_version, php_version, plugin_version, status, last_seen_at FROM {$tables['environments']} ORDER BY last_seen_at DESC LIMIT 200", ARRAY_A );
		$commands = $wpdb->get_results( "SELECT * FROM {$tables['commands']} ORDER BY created_at DESC LIMIT 100", ARRAY_A );
		foreach ( is_array( $commands ) ? $commands : array() as $index => $command ) {
			$payload = json_decode( isset( $command['payload'] ) ? $command['payload'] : '', true );
			$commands[ $index ]['payload'] = is_array( $payload ) ? $payload : array();
		}
		return array(
			'families' => $wpdb->get_results( "SELECT family_id, project_name, status, created_at, updated_at FROM {$tables['families']} ORDER BY created_at DESC LIMIT 100", ARRAY_A ),
			'environments' => array_map( array( __CLASS__, 'decorate_environment_status' ), is_array( $environments ) ? $environments : array() ),
			'jobs' => $wpdb->get_results( "SELECT * FROM {$tables['jobs']} ORDER BY created_at DESC LIMIT 100", ARRAY_A ),
			'commands' => is_array( $commands ) ? $commands : array(),
		);
	}

	/** A heartbeat is fresh for three worker intervals. */
	public static function environment_is_online( $environment ) {
		if ( ! is_array( $environment ) || empty( $environment['last_seen_at'] ) ) {
			return false;
		}
		$last_seen = strtotime( $environment['last_seen_at'] . ' UTC' );
		return false !== $last_seen && $last_seen >= time() - self::ONLINE_TTL;
	}

	/** Add display-safe liveness data without exposing credentials. */
	public static function decorate_environment_status( $environment ) {
		$environment = is_array( $environment ) ? $environment : array();
		$last_seen = ! empty( $environment['last_seen_at'] ) ? strtotime( $environment['last_seen_at'] . ' UTC' ) : false;
		$environment['is_online'] = self::environment_is_online( $environment );
		$environment['last_seen_seconds_ago'] = false !== $last_seen ? max( 0, time() - $last_seen ) : null;
		return $environment;
	}

	public static function set_registry_status( $kind, $id, $status ) {
		if ( ! self::enabled() || ! in_array( $status, array( 'approved', 'revoked' ), true ) ) {
			return false;
		}
		global $wpdb;
		$tables = self::tables();
		if ( $kind === 'family' && Leadwerk_Migration_Site_Identity::valid_id( $id ) ) {
			$result = $wpdb->update( $tables['families'], array( 'status' => $status, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'family_id' => $id ) );
			if ( $status === 'revoked' ) {
				$wpdb->update( $tables['environments'], array( 'status' => 'revoked', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'family_id' => $id ) );
			}
			return false !== $result;
		}
		if ( $kind === 'environment' && Leadwerk_Migration_Site_Identity::valid_id( $id ) ) {
			$environment = $wpdb->get_row( $wpdb->prepare( "SELECT family_id FROM {$tables['environments']} WHERE environment_id = %s", $id ), ARRAY_A );
			if ( is_array( $environment ) && '' === (string) $environment['family_id'] && 'approved' === $status ) {
				$status = 'available';
			}
			return false !== $wpdb->update( $tables['environments'], array( 'status' => $status, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'environment_id' => $id ) );
		}
		return false;
	}

	/** Install a newer signed release on Hub, then distribute it globally. */
	public static function process_release_automation() {
		if ( ! self::enabled() ) {
			return null;
		}
		self::maybe_install();
		$pending = get_option( 'leadwerk_migration_hub_pending_release', array() );
		if ( is_array( $pending ) && ! empty( $pending['version'] ) && ! empty( $pending['path'] ) ) {
			if ( version_compare( $pending['version'], LEADWERK_MIGRATION_VERSION, '>' ) ) {
				$installed = Leadwerk_Migration_Release::install_package( $pending['path'], $pending['version'] );
				if ( is_wp_error( $installed ) || false === $installed ) {
					return is_wp_error( $installed ) ? $installed : new WP_Error( 'leadwerk_hub_release_install_failed', __( 'Hub could not install the verified Migration release.', 'leadwerk-migration' ) );
				}
				delete_option( 'leadwerk_migration_hub_plugin_manifest' );
				Leadwerk_Migration_Sync::schedule_fast_worker();
				return array( 'status' => 'hub_updated', 'version' => $pending['version'] );
			}
			delete_option( 'leadwerk_migration_hub_pending_release' );
		}

		global $wpdb;
		$tables = self::tables();
		$environments = $wpdb->get_results( "SELECT * FROM {$tables['environments']} WHERE status = 'approved' ORDER BY last_seen_at DESC", ARRAY_A );
		$newest = LEADWERK_MIGRATION_VERSION;
		$publisher = null;
		foreach ( is_array( $environments ) ? $environments : array() as $environment ) {
			$version = isset( $environment['plugin_version'] ) ? trim( (string) $environment['plugin_version'] ) : '';
			if ( '' !== $version && version_compare( $version, $newest, '>' ) && version_compare( $version, '1.4.0', '>=' ) && self::environment_is_online( $environment ) ) {
				$newest = $version;
				$publisher = $environment;
			}
		}
		if ( is_array( $publisher ) ) {
			return self::queue_automatic_update_command( 'plugin_publish', $publisher, $newest );
		}

		$manifest = self::ensure_plugin_package();
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		$queued = 0;
		foreach ( is_array( $environments ) ? $environments : array() as $environment ) {
			$version = isset( $environment['plugin_version'] ) ? trim( (string) $environment['plugin_version'] ) : '';
			if ( '' !== $version && version_compare( $version, LEADWERK_MIGRATION_VERSION, '<' ) && version_compare( $version, '1.3.0', '>=' ) ) {
				$result = self::queue_automatic_update_command( 'plugin_update', $environment, LEADWERK_MIGRATION_VERSION );
				if ( ! is_wp_error( $result ) && ! empty( $result['queued'] ) ) {
					$queued++;
				}
			}
		}
		$wordpress_queued = self::queue_family_wordpress_updates( $environments );
		return array( 'status' => 'release_current', 'version' => LEADWERK_MIGRATION_VERSION, 'plugin_updates_queued' => $queued, 'wordpress_updates_queued' => $wordpress_queued );
	}

	/** Queue upgrades from each family's lower WordPress versions to its highest. */
	private static function queue_family_wordpress_updates( $environments ) {
		$queued = 0;
		foreach ( self::family_wordpress_upgrade_targets( $environments ) as $target ) {
			$result = self::queue_automatic_update_command( 'wordpress_update', $target['environment'], $target['target_version'] );
			if ( ! is_wp_error( $result ) && ! empty( $result['queued'] ) ) {
				$queued++;
			}
		}
		return $queued;
	}

	/** Pure family-scoped target calculation, kept separate for deterministic tests. */
	private static function family_wordpress_upgrade_targets( $environments ) {
		$highest = array();
		foreach ( is_array( $environments ) ? $environments : array() as $environment ) {
			$family_id = isset( $environment['family_id'] ) ? (string) $environment['family_id'] : '';
			$version = isset( $environment['wordpress_version'] ) ? trim( (string) $environment['wordpress_version'] ) : '';
			$plugin = isset( $environment['plugin_version'] ) ? trim( (string) $environment['plugin_version'] ) : '';
			if ( ! Leadwerk_Migration_Site_Identity::valid_id( $family_id ) || ! self::valid_wordpress_version( $version ) || ! hash_equals( LEADWERK_MIGRATION_VERSION, $plugin ) ) {
				continue;
			}
			if ( ! isset( $highest[ $family_id ] ) || version_compare( $version, $highest[ $family_id ], '>' ) ) {
				$highest[ $family_id ] = $version;
			}
		}
		$targets = array();
		foreach ( is_array( $environments ) ? $environments : array() as $environment ) {
			$family_id = isset( $environment['family_id'] ) ? (string) $environment['family_id'] : '';
			$current = isset( $environment['wordpress_version'] ) ? trim( (string) $environment['wordpress_version'] ) : '';
			$plugin = isset( $environment['plugin_version'] ) ? trim( (string) $environment['plugin_version'] ) : '';
			if ( ! isset( $highest[ $family_id ] ) || ! self::valid_wordpress_version( $current ) || ! hash_equals( LEADWERK_MIGRATION_VERSION, $plugin ) || ! version_compare( $current, $highest[ $family_id ], '<' ) ) {
				continue;
			}
			$targets[] = array( 'environment' => $environment, 'target_version' => $highest[ $family_id ] );
		}
		return $targets;
	}

	private static function queue_automatic_update_command( $type, $environment, $version ) {
		if ( ! in_array( $type, array( 'plugin_publish', 'plugin_update', 'wordpress_update' ), true ) || ! is_array( $environment ) || empty( $environment['environment_id'] ) || empty( $environment['family_id'] ) ) {
			return new WP_Error( 'leadwerk_release_command_invalid', __( 'Automatic family update command is invalid.', 'leadwerk-migration' ) );
		}
		global $wpdb;
		$tables = self::tables();
		$active = $wpdb->get_var( $wpdb->prepare( "SELECT command_id FROM {$tables['commands']} WHERE target_environment_id = %s AND command_type = %s AND status IN ('queued','running','waiting_runtime') LIMIT 1", $environment['environment_id'], $type ) );
		if ( is_string( $active ) && Leadwerk_Migration_Site_Identity::valid_id( $active ) ) {
			return array( 'queued' => false, 'command_id' => $active );
		}
		$recent_failures = $wpdb->get_results( $wpdb->prepare( "SELECT command_id, payload FROM {$tables['commands']} WHERE target_environment_id = %s AND command_type = %s AND status IN ('failed','manual_required') AND updated_at >= %s ORDER BY updated_at DESC", $environment['environment_id'], $type, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), ARRAY_A );
		$version_key = 'wordpress_update' === $type ? 'wordpress_version' : 'plugin_version';
		foreach ( is_array( $recent_failures ) ? $recent_failures : array() as $recent_failure ) {
			$failed_payload = json_decode( isset( $recent_failure['payload'] ) ? $recent_failure['payload'] : '', true );
			$failed_version = is_array( $failed_payload ) && isset( $failed_payload[ $version_key ] ) ? trim( (string) $failed_payload[ $version_key ] ) : '';
			if ( '' !== $failed_version && hash_equals( (string) $version, $failed_version ) && Leadwerk_Migration_Site_Identity::valid_id( isset( $recent_failure['command_id'] ) ? $recent_failure['command_id'] : '' ) ) {
				return array( 'queued' => false, 'command_id' => $recent_failure['command_id'], 'cooldown' => true );
			}
		}
		$command_id = Leadwerk_Migration_Site_Identity::random_id();
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( 'plugin_publish' === $type ) {
			$message = __( 'Newest signed Migration release selected for automatic Hub publication.', 'leadwerk-migration' );
			$payload = array( 'plugin_version' => $version, 'stage' => 'publish', 'automatic' => true );
		} elseif ( 'plugin_update' === $type ) {
			$message = __( 'Automatic signed Migration update queued by Hub.', 'leadwerk-migration' );
			$payload = array( 'plugin_version' => $version, 'stage' => 'plugin', 'automatic' => true );
		} else {
			$message = sprintf( __( 'Automatic Site Family WordPress upgrade to %s queued by Hub.', 'leadwerk-migration' ), $version );
			$payload = array( 'wordpress_version' => $version, 'stage' => 'wordpress', 'automatic' => true );
		}
		$inserted = $wpdb->insert( $tables['commands'], array( 'command_id' => $command_id, 'family_id' => $environment['family_id'], 'target_environment_id' => $environment['environment_id'], 'initiator_environment_id' => $environment['environment_id'], 'command_type' => $type, 'status' => 'queued', 'payload' => wp_json_encode( $payload ), 'message' => $message, 'created_at' => $now, 'updated_at' => $now, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ) );
		if ( false === $inserted ) {
			return new WP_Error( 'leadwerk_release_command_failed', __( 'Hub could not queue the automatic Migration release command.', 'leadwerk-migration' ) );
		}
		self::dispatch_wake( $environment, 'command', $command_id );
		return array( 'queued' => true, 'command_id' => $command_id, 'type' => $type, 'version' => $version );
	}

	private static function valid_wordpress_version( $version ) {
		return is_string( $version ) && preg_match( '/\A[0-9]+(?:\.[0-9]+){1,2}(?:[-+._A-Za-z0-9]*)?\z/D', $version );
	}

	public static function cleanup() {
		if ( ! self::enabled() ) {
			return;
		}
		global $wpdb;
		$tables = self::tables();
		$expired = $wpdb->get_col( $wpdb->prepare( "SELECT job_id FROM {$tables['jobs']} WHERE expires_at < %s OR status IN ('complete','failed','canceled')", gmdate( 'Y-m-d H:i:s' ) ) );
		foreach ( is_array( $expired ) ? $expired : array() as $job_id ) {
			self::safe_unlink_relay( $job_id );
		}
		self::purge_expired_canceled_jobs();
		self::purge_expired_available_environments();
		$wpdb->query( $wpdb->prepare( "UPDATE {$tables['commands']} SET status = 'failed', message = %s, updated_at = %s WHERE expires_at < %s AND status IN ('queued','running','waiting_runtime')", __( 'Runtime reconciliation expired before completion.', 'leadwerk-migration' ), gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/** Keep the opt-in target pool bounded; online sites re-enroll automatically. */
	private static function purge_expired_available_environments() {
		if ( ! self::enabled() ) {
			return 0;
		}
		global $wpdb;
		$tables = self::tables();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::AVAILABLE_RETENTION );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['environments']} WHERE family_id = '' AND status = 'available' AND last_seen_at < %s", $cutoff ) );
	}

	/** Keep canceled jobs visible for one hour, then remove their Hub rows. */
	private static function purge_expired_canceled_jobs() {
		if ( ! self::enabled() ) {
			return 0;
		}
		global $wpdb;
		$tables = self::tables();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$job_ids = $wpdb->get_col( $wpdb->prepare( "SELECT job_id FROM {$tables['jobs']} WHERE status = 'canceled' AND updated_at <= %s", $cutoff ) );
		foreach ( is_array( $job_ids ) ? $job_ids : array() as $job_id ) {
			self::safe_unlink_relay( $job_id );
		}
		if ( empty( $job_ids ) ) {
			return 0;
		}
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['jobs']} WHERE status = 'canceled' AND updated_at <= %s", $cutoff ) );
	}

	private static function family_job( $job_id, $family_id ) {
		if ( ! Leadwerk_Migration_Site_Identity::valid_id( $job_id ) || ! Leadwerk_Migration_Site_Identity::valid_id( $family_id ) ) {
			return new WP_Error( 'leadwerk_sync_job_invalid', __( 'The sync job identifier is invalid.', 'leadwerk-migration' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		$tables = self::tables();
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['jobs']} WHERE job_id = %s AND family_id = %s", $job_id, $family_id ), ARRAY_A );
		return is_array( $job ) ? $job : new WP_Error( 'leadwerk_sync_job_not_found', __( 'The sync job was not found in this Site Family.', 'leadwerk-migration' ), array( 'status' => 404 ) );
	}

	/** Add a short-lived, job-scoped peer capability only to the participating side. */
	private static function decorate_job_for_environment( $job, $requesting_environment ) {
		if ( ! is_array( $job ) || ! is_array( $requesting_environment ) || empty( $job['transport'] ) || ! in_array( $job['transport'], array( 'direct_push', 'direct_pull' ), true ) ) {
			return $job;
		}
		$action = '';
		$endpoint_id = '';
		if ( 'direct_push' === $job['transport'] && hash_equals( $job['source_environment_id'], $requesting_environment['environment_id'] ) ) {
			$action = 'push';
			$endpoint_id = $job['target_environment_id'];
		} elseif ( 'direct_pull' === $job['transport'] && hash_equals( $job['target_environment_id'], $requesting_environment['environment_id'] ) ) {
			$action = 'pull';
			$endpoint_id = $job['source_environment_id'];
		}
		if ( '' === $action ) {
			return $job;
		}
		global $wpdb;
		$tables = self::tables();
		$endpoint = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $endpoint_id, $job['family_id'] ), ARRAY_A );
		if ( ! is_array( $endpoint ) ) {
			return $job;
		}
		$secret = self::open_secret( $endpoint['secret_encrypted'] );
		if ( ! is_string( $secret ) ) {
			return $job;
		}
		$payload = array(
			'job_id'                  => $job['job_id'],
			'family_id'               => $job['family_id'],
			'source_environment_id'   => $job['source_environment_id'],
			'target_environment_id'   => $job['target_environment_id'],
			'endpoint_environment_id' => $endpoint_id,
			'action'                  => $action,
			'expires_at'              => min( strtotime( $job['expires_at'] . ' UTC' ), time() + HOUR_IN_SECONDS ),
		);
		$json = wp_json_encode( $payload );
		if ( ! is_string( $json ) ) {
			return $job;
		}
		$encoded = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
		$job['peer_endpoint'] = trailingslashit( Leadwerk_Migration_Site_Identity::normalize_url( $endpoint['site_url'] ) ) . 'wp-json/' . self::NAMESPACE . '/peer/jobs/' . $job['job_id'] . '/' . ( 'push' === $action ? 'chunk' : 'read' );
		$job['peer_ticket'] = $encoded . '.' . hash_hmac( 'sha256', $encoded, $secret );
		return $job;
	}

	private static function family_command( $command_id, $family_id ) {
		if ( ! Leadwerk_Migration_Site_Identity::valid_id( $command_id ) || ! Leadwerk_Migration_Site_Identity::valid_id( $family_id ) ) {
			return new WP_Error( 'leadwerk_runtime_command_invalid', __( 'The runtime command identifier is invalid.', 'leadwerk-migration' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		$tables = self::tables();
		$command = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['commands']} WHERE command_id = %s AND family_id = %s", $command_id, $family_id ), ARRAY_A );
		return is_array( $command ) ? $command : new WP_Error( 'leadwerk_runtime_command_not_found', __( 'The runtime command was not found in this Site Family.', 'leadwerk-migration' ), array( 'status' => 404 ) );
	}

	private static function hub_version_preflight( $source, $target ) {
		if ( ! is_array( $source ) || ! is_array( $target ) ) {
			return new WP_Error( 'leadwerk_sync_environment_unavailable', __( 'Source or target environment is unavailable.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		foreach ( array( 'wordpress_version' => 'WordPress', 'php_version' => 'PHP', 'plugin_version' => 'Leadwerk Migration' ) as $key => $label ) {
			$from = isset( $source[ $key ] ) ? (string) $source[ $key ] : '';
			$to = isset( $target[ $key ] ) ? (string) $target[ $key ] : '';
			$compatible = 'php_version' === $key
				? Leadwerk_Migration_Site_Identity::php_versions_compatible( $from, $to )
				: ( '' !== $from && '' !== $to && hash_equals( $from, $to ) );
			if ( ! $compatible ) {
				$message = 'plugin_version' === $key ? __( 'Sync retry blocked: %1$s versions differ (%2$s → %3$s). Hub will reconcile Migration automatically; retry after both heartbeats report the same version.', 'leadwerk-migration' ) : ( 'wordpress_version' === $key ? __( 'Sync retry blocked: %1$s versions differ (%2$s → %3$s). Hub automatically upgrades the lower WordPress version inside this Site Family; retry after both heartbeats match.', 'leadwerk-migration' ) : __( 'Sync retry blocked: %1$s versions differ (%2$s → %3$s). Make the runtime versions compatible and retry.', 'leadwerk-migration' ) );
				return new WP_Error( 'leadwerk_sync_runtime_mismatch', sprintf( $message, $label, isset( $source[ $key ] ) ? $source[ $key ] : '', isset( $target[ $key ] ) ? $target[ $key ] : '' ), array( 'status' => 409 ) );
			}
		}
		if ( ! self::environment_is_online( $source ) || ! self::environment_is_online( $target ) ) {
			return new WP_Error( 'leadwerk_sync_environment_offline', __( 'Source and target must both be online to retry.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		return true;
	}

	private static function insert_job( $family_id, $source_id, $target_id, $initiator_id, $dispatch = true, $is_retry = true ) {
		global $wpdb;
		$tables = self::tables();
		$job_id = Leadwerk_Migration_Site_Identity::random_id();
		$now = gmdate( 'Y-m-d H:i:s' );
		$expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $source_id, $family_id ), ARRAY_A );
		$target = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['environments']} WHERE environment_id = %s AND family_id = %s AND status = 'approved'", $target_id, $family_id ), ARRAY_A );
		$peer_capable = is_array( $source ) && is_array( $target ) && version_compare( $source['plugin_version'], '1.4.0', '>=' ) && version_compare( $target['plugin_version'], '1.4.0', '>=' );
		if ( ! $peer_capable ) {
			return new WP_Error( 'leadwerk_sync_peer_required', __( 'Retry blocked: both environments must support authenticated peer-to-peer transfer.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		$transport = 'local' === $source['environment_type'] ? 'direct_push' : 'direct_pull';
		$status = 'preparing_target';
		$message = $is_retry ? __( 'Retry is preparing the target safety backup before authenticated peer-to-peer transfer.', 'leadwerk-migration' ) : __( 'Preparing the target safety backup before authenticated peer-to-peer transfer.', 'leadwerk-migration' );
		$inserted = $wpdb->insert( $tables['jobs'], array( 'job_id' => $job_id, 'family_id' => $family_id, 'source_environment_id' => $source_id, 'target_environment_id' => $target_id, 'initiator_environment_id' => $initiator_id, 'status' => $status, 'source_approved' => 1, 'target_approved' => 1, 'transport' => $transport, 'message' => $message, 'created_at' => $now, 'updated_at' => $now, 'expires_at' => $expires ) );
		if ( false === $inserted ) {
			return new WP_Error( 'leadwerk_sync_retry_create_failed', __( 'Hub could not create the retry job.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}
		if ( $dispatch ) {
			self::dispatch_wake( $target, 'job', $job_id );
		}
		return new WP_REST_Response( array( 'job_id' => $job_id, 'status' => $status, 'transport' => $transport ), 201 );
	}

	/** Best-effort signed event dispatch. Five-minute polling remains the fallback. */
	private static function dispatch_wake( $environment, $subject_type, $subject_id ) {
		if (
			! is_array( $environment ) ||
			! isset( $environment['environment_id'], $environment['secret_encrypted'], $environment['site_url'], $environment['environment_type'], $environment['status'] ) ||
			'approved' !== $environment['status'] ||
			'local' === $environment['environment_type'] ||
			! in_array( $subject_type, array( 'job', 'command' ), true ) ||
			! Leadwerk_Migration_Site_Identity::valid_id( $environment['environment_id'] ) ||
			! Leadwerk_Migration_Site_Identity::valid_id( $subject_id )
		) {
			return false;
		}
		$site_url = Leadwerk_Migration_Site_Identity::normalize_url( $environment['site_url'] );
		$parts = wp_parse_url( $site_url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}
		$secret = self::open_secret( $environment['secret_encrypted'] );
		if ( ! is_string( $secret ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $secret ) ) {
			return false;
		}
		$route = '/' . self::NAMESPACE . '/worker/wake';
		$body = wp_json_encode(
			array(
				'environment_id' => $environment['environment_id'],
				'subject_type'   => $subject_type,
				'subject_id'     => $subject_id,
			)
		);
		if ( ! is_string( $body ) ) {
			return false;
		}
		$timestamp = time();
		$nonce = Leadwerk_Migration_Site_Identity::random_id();
		$signature = hash_hmac( 'sha256', self::canonical_request( 'POST', $route, $timestamp, $nonce, $body ), $secret );
		$endpoint = trailingslashit( $site_url ) . 'wp-json/' . self::NAMESPACE . '/worker/wake';
		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout'     => 2,
				'blocking'    => false,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Content-Type'                      => 'application/json',
					'Accept'                            => 'application/json',
					'X-Leadwerk-Wake-Timestamp'         => (string) $timestamp,
					'X-Leadwerk-Wake-Nonce'             => $nonce,
					'X-Leadwerk-Wake-Signature'         => $signature,
				),
				'body'        => $body,
				'data_format' => 'body',
			)
		);
		return ! is_wp_error( $response );
	}

	private static function ensure_plugin_package() {
		$directory = self::ensure_hub_release_directory();
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}
		$path = $directory . DIRECTORY_SEPARATOR . 'leadwerk-migration-' . sanitize_file_name( LEADWERK_MIGRATION_VERSION ) . '.zip';
		$cached = get_option( 'leadwerk_migration_hub_plugin_manifest', array() );
		if ( is_array( $cached ) && isset( $cached['version'], $cached['path'], $cached['sha256'], $cached['size'] ) && $cached['version'] === LEADWERK_MIGRATION_VERSION && $cached['path'] === $path && is_file( $path ) && (int) filesize( $path ) === (int) $cached['size'] ) {
			$actual = hash_file( 'sha256', $path );
			$verified = is_string( $actual ) && hash_equals( strtolower( $cached['sha256'] ), strtolower( $actual ) ) ? Leadwerk_Migration_Release::verify_package( $path, LEADWERK_MIGRATION_VERSION ) : new WP_Error( 'leadwerk_hub_plugin_package_integrity', __( 'The cached Hub release package changed unexpectedly.', 'leadwerk-migration' ) );
			if ( ! is_wp_error( $verified ) ) {
				return $cached;
			}
			@unlink( $path );
		}
		$manifest = Leadwerk_Migration_Release::build_package( $path );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		update_option( 'leadwerk_migration_hub_plugin_manifest', $manifest, false );
		return $manifest;
	}

	private static function hub_ready() {
		if ( ! self::enabled() ) {
			return new WP_Error( 'leadwerk_hub_disabled', __( 'Leadwerk Hub mode is disabled on this server.', 'leadwerk-migration' ), array( 'status' => 404 ) );
		}
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return new WP_Error( 'leadwerk_hub_openssl_missing', __( 'Leadwerk Hub requires the PHP OpenSSL extension to protect environment credentials at rest.', 'leadwerk-migration' ), array( 'status' => 503 ) );
		}
		self::maybe_install();
		$release_storage = self::ensure_hub_release_directory();
		if ( is_wp_error( $release_storage ) ) {
			return $release_storage;
		}
		return true;
	}

	private static function job_for_environment( $job_id, $environment ) {
		if ( ! Leadwerk_Migration_Site_Identity::valid_id( $job_id ) ) {
			return new WP_Error( 'leadwerk_sync_job_invalid', __( 'The sync job identifier is invalid.', 'leadwerk-migration' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		$tables = self::tables();
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['jobs']} WHERE job_id = %s AND family_id = %s", $job_id, $environment['family_id'] ), ARRAY_A );
		if ( ! is_array( $job ) || ! in_array( $environment['environment_id'], array( $job['source_environment_id'], $job['target_environment_id'] ), true ) ) {
			return new WP_Error( 'leadwerk_sync_job_not_found', __( 'The sync job was not found.', 'leadwerk-migration' ), array( 'status' => 404 ) );
		}
		return $job;
	}

	private static function relay_directory() {
		return LEADWERK_MIGRATION_PRIVATE_ROOT . DIRECTORY_SEPARATOR . 'leadwerk-sync-relay-' . substr( hash( 'sha256', ABSPATH . wp_salt( 'auth' ) ), 0, 12 );
	}

	/** Small publisher-signed plugin packages; never used for WordPress backups. */
	private static function hub_release_directory() {
		return LEADWERK_MIGRATION_PRIVATE_ROOT . DIRECTORY_SEPARATOR . 'leadwerk-hub-releases-' . substr( hash( 'sha256', ABSPATH . wp_salt( 'auth' ) ), 0, 12 );
	}

	private static function ensure_hub_release_directory() {
		$directory = self::hub_release_directory();
		if ( ! Leadwerk_Migration_Security::path_is_private( $directory ) ) {
			return new WP_Error( 'leadwerk_hub_release_not_private', __( 'Hub release storage is not outside the web root.', 'leadwerk-migration' ) );
		}
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'leadwerk_hub_release_unavailable', __( 'Hub release storage could not be created.', 'leadwerk-migration' ) );
		}
		@chmod( $directory, 0700 );
		return $directory;
	}

	private static function ensure_relay_directory() {
		$directory = self::relay_directory();
		if ( ! Leadwerk_Migration_Security::path_is_private( $directory ) ) {
			return new WP_Error( 'leadwerk_sync_relay_not_private', __( 'Hub relay storage is not outside the web root.', 'leadwerk-migration' ) );
		}
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'leadwerk_sync_relay_unavailable', __( 'Hub relay storage could not be created.', 'leadwerk-migration' ) );
		}
		@chmod( $directory, 0700 );
		return $directory;
	}

	private static function relay_path( $job_id ) {
		$directory = self::ensure_relay_directory();
		return is_wp_error( $directory ) ? '' : $directory . DIRECTORY_SEPARATOR . $job_id . '.wpress';
	}

	private static function safe_unlink_relay( $job_id ) {
		if ( ! Leadwerk_Migration_Site_Identity::valid_id( $job_id ) ) {
			return false;
		}
		$path = self::relay_path( $job_id );
		return $path !== '' && is_file( $path ) ? @unlink( $path ) : true;
	}

	/** Remove only old relay job archives; signed plugin release ZIPs are untouched. */
	private static function purge_legacy_relay_archives() {
		$directory = self::relay_directory();
		if ( ! is_dir( $directory ) || ! Leadwerk_Migration_Security::path_is_private( $directory ) ) {
			return 0;
		}
		$root = realpath( $directory );
		if ( false === $root ) {
			return 0;
		}
		$deleted = 0;
		foreach ( new DirectoryIterator( $root ) as $file ) {
			if ( $file->isDot() || ! $file->isFile() || $file->isLink() || ! preg_match( '/\A[a-f0-9]{32}\.wpress\z/D', $file->getFilename() ) ) {
				continue;
			}
			$path = $file->getRealPath();
			if ( false !== $path && hash_equals( $root, dirname( $path ) ) && @unlink( $path ) ) {
				$deleted++;
			}
		}
		return $deleted;
	}

	private static function mark_job_failed( $job_id, $message ) {
		global $wpdb;
		$tables = self::tables();
		$wpdb->update( $tables['jobs'], array( 'status' => 'failed', 'message' => substr( sanitize_text_field( $message ), 0, 1000 ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'job_id' => $job_id ) );
	}

	private static function seal_secret( $secret ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( $secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'leadwerk-sync-hub-v1' );
		return is_string( $ciphertext ) ? base64_encode( $iv . $tag . $ciphertext ) : '';
	}

	private static function open_secret( $sealed ) {
		$raw = is_string( $sealed ) ? base64_decode( $sealed, true ) : false;
		if ( ! is_string( $raw ) || strlen( $raw ) < 29 || ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		return openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'leadwerk-sync-hub-v1' );
	}

	private static function clean_project_name( $name ) {
		$name = trim( sanitize_text_field( (string) $name ) );
		return substr( $name !== '' ? $name : 'Leadwerk Project', 0, 190 );
	}

	private static function clean_environment_type( $type ) {
		$type = sanitize_key( (string) $type );
		return in_array( $type, array( 'local', 'staging', 'live' ), true ) ? $type : 'live';
	}
}
