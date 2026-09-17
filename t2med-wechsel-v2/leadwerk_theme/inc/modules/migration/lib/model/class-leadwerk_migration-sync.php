<?php
/**
 * Outbound Site Sync client and background transfer worker.
 *
 * Hub is the authenticated control plane. Archive bytes move only between the
 * two authorized peers and are never uploaded to Hub storage.
 *
 * Copyright (C) 2026 Leadwerk.
 * Licensed under GPLv3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access is not allowed.' );
}

class Leadwerk_Migration_Sync {

	const LOCK_OPTION = 'leadwerk_migration_sync_lock';
	const LOCK_TTL    = 1800;
	const WAKE_CLOCK_SKEW = 300;
	private static $fast_spawn_registered = false;

	public static function cron_schedules( $schedules ) {
		$schedules = is_array( $schedules ) ? $schedules : array();
		$schedules['leadwerk_one_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (Leadwerk active-job watchdog)', 'leadwerk-migration' ),
		);
		$schedules['leadwerk_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Leadwerk Site Sync)', 'leadwerk-migration' ),
		);
		return $schedules;
	}

	public static function activate() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ), 10000 );
		self::ensure_schedule();
		Leadwerk_Migration_Site_Identity::ensure_environment();
		self::schedule_fast_worker();
	}

	/** Restore the heartbeat worker after plugin upgrades or cleared cron data. */
	public static function ensure_schedule() {
		$event = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( LEADWERK_MIGRATION_SYNC_HOOK ) : false;
		if ( $event && isset( $event->schedule ) && 'leadwerk_one_minute' !== $event->schedule ) {
			wp_clear_scheduled_hook( LEADWERK_MIGRATION_SYNC_HOOK );
			$event = false;
		}
		if ( ! $event ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'leadwerk_one_minute', LEADWERK_MIGRATION_SYNC_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( LEADWERK_MIGRATION_SYNC_HOOK );
		wp_clear_scheduled_hook( LEADWERK_MIGRATION_SYNC_FAST_HOOK );
		delete_option( self::LOCK_OPTION );
	}

	/** Public wake receiver. It only schedules work after verifying Hub HMAC. */
	public static function register_routes() {
		register_rest_route(
			Leadwerk_Migration_Sync_Hub::NAMESPACE,
			'/worker/wake',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive_wake' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			Leadwerk_Migration_Sync_Hub::NAMESPACE,
			'/peer/jobs/(?P<job_id>[a-f0-9]{32})/chunk',
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'receive_peer_chunk' ), 'permission_callback' => '__return_true' )
		);
		register_rest_route(
			Leadwerk_Migration_Sync_Hub::NAMESPACE,
			'/peer/jobs/(?P<job_id>[a-f0-9]{32})/read',
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'read_peer_chunk' ), 'permission_callback' => '__return_true' )
		);
	}

	/** Target receives archive bytes directly from a local/private source. */
	public static function receive_peer_chunk( $request ) {
		$ticket = self::validate_peer_ticket( $request, 'push' );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}
		$state = self::state();
		$job_id = $ticket['job_id'];
		$local = isset( $state['jobs'][ $job_id ] ) && is_array( $state['jobs'][ $job_id ] ) ? $state['jobs'][ $job_id ] : array();
		if ( empty( $local['safety_backup'] ) || ! isset( $local['role'] ) || 'target' !== $local['role'] ) {
			return new WP_Error( 'leadwerk_peer_target_not_ready', __( 'Target safety backup is not ready for direct transfer.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();
		$offset = isset( $data['offset'] ) ? (int) $data['offset'] : -1;
		$total = isset( $data['total'] ) ? (int) $data['total'] : 0;
		$sha256 = isset( $data['sha256'] ) ? strtolower( trim( (string) $data['sha256'] ) ) : '';
		$name = isset( $data['archive_name'] ) ? sanitize_file_name( $data['archive_name'] ) : '';
		$chunk = isset( $data['data'] ) && is_string( $data['data'] ) ? base64_decode( $data['data'], true ) : false;
		$maximum_size = (int) apply_filters( 'leadwerk_migration_sync_max_archive_size', 20 * 1024 * 1024 * 1024 );
		if ( $offset < 0 || $total <= 0 || $total > $maximum_size || ! preg_match( '/\A[a-f0-9]{64}\z/D', $sha256 ) || ! leadwerk_migration_is_filename_supported( $name ) || ! is_string( $chunk ) || '' === $chunk || strlen( $chunk ) > LEADWERK_MIGRATION_SYNC_DIRECT_CHUNK_SIZE ) {
			return new WP_Error( 'leadwerk_peer_chunk_invalid', __( 'The direct-transfer chunk is invalid.', 'leadwerk-migration' ), array( 'status' => 400 ) );
		}
		$partial = self::target_partial_path( $job_id );
		if ( isset( $local['transport'] ) && 'direct_push' !== $local['transport'] ) {
			return new WP_Error( 'leadwerk_peer_transport_changed', __( 'This synchronization job no longer accepts direct uploads.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		$local['transport'] = 'direct_push';
		$state['jobs'][ $job_id ] = $local;
		self::save_state( $state );
		$current = is_file( $partial ) ? (int) filesize( $partial ) : 0;
		if ( $current !== $offset ) {
			return new WP_Error( 'leadwerk_peer_chunk_offset', sprintf( __( 'Direct chunk offset mismatch. Expected %d bytes.', 'leadwerk-migration' ), $current ), array( 'status' => 409, 'expected_offset' => $current ) );
		}
		$handle = @fopen( $partial, 0 === $offset ? 'wb' : 'ab' );
		if ( false === $handle || ! flock( $handle, LOCK_EX ) || fwrite( $handle, $chunk ) !== strlen( $chunk ) ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			return new WP_Error( 'leadwerk_peer_chunk_write', __( 'Target could not save the direct-transfer chunk.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}
		fflush( $handle );
		flock( $handle, LOCK_UN );
		fclose( $handle );
		@chmod( $partial, 0600 );
		$local['offset'] = $offset + strlen( $chunk );
		$local['total'] = $total;
		$local['sha256'] = $sha256;
		$local['source_archive'] = $name;
		$state['jobs'][ $job_id ] = $local;
		self::save_state( $state );
		$ready = $local['offset'] === $total;
		if ( $local['offset'] > $total ) {
			return new WP_Error( 'leadwerk_peer_chunk_overflow', __( 'Direct transfer exceeded its declared archive size.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		if ( $ready ) {
			$actual = hash_file( 'sha256', $partial );
			if ( ! is_string( $actual ) || ! hash_equals( $sha256, strtolower( $actual ) ) ) {
				return self::fail_remote( $job_id, __( 'Direct Site Family transfer failed SHA-256 verification.', 'leadwerk-migration' ) );
			}
		}
		$progress = self::request( '/hub/jobs/' . $job_id . '/peer-progress', array( 'bytes_delivered' => $local['offset'], 'ready' => $ready ), true );
		if ( is_wp_error( $progress ) ) {
			// Hub wakes the target importer as soon as it accepts the final byte.
			// That worker may already have advanced the job to verification before
			// this request receives its response. The local SHA-256 check above is
			// authoritative for the received copy, so keep the final update idempotent.
			if ( ! $ready ) {
				return $progress;
			}
			self::schedule_fast_worker();
		}
		return new WP_REST_Response( array( 'job_id' => $job_id, 'offset' => $local['offset'], 'total' => $total, 'ready' => $ready ), 200 );
	}

	/** Source serves a verified archive chunk to the authorized family target. */
	public static function read_peer_chunk( $request ) {
		$ticket = self::validate_peer_ticket( $request, 'pull' );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}
		$state = self::state();
		$local = isset( $state['jobs'][ $ticket['job_id'] ] ) && is_array( $state['jobs'][ $ticket['job_id'] ] ) ? $state['jobs'][ $ticket['job_id'] ] : array();
		if ( empty( $local['path'] ) || empty( $local['size'] ) || ! isset( $local['role'] ) || 'source' !== $local['role'] || ! is_file( $local['path'] ) ) {
			return new WP_Error( 'leadwerk_peer_source_not_ready', __( 'Source archive is not ready for direct download.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		$data = $request->get_json_params();
		$offset = is_array( $data ) && isset( $data['offset'] ) ? max( 0, (int) $data['offset'] ) : 0;
		$length = is_array( $data ) && isset( $data['length'] ) ? max( 1, min( LEADWERK_MIGRATION_SYNC_DIRECT_CHUNK_SIZE, (int) $data['length'] ) ) : LEADWERK_MIGRATION_SYNC_DIRECT_CHUNK_SIZE;
		if ( $offset >= (int) $local['size'] ) {
			return new WP_Error( 'leadwerk_peer_source_offset', __( 'Requested direct-transfer offset is outside the source archive.', 'leadwerk-migration' ), array( 'status' => 416 ) );
		}
		if ( isset( $local['transport'] ) && 'direct_pull' !== $local['transport'] ) {
			return new WP_Error( 'leadwerk_peer_transport_changed', __( 'This synchronization job no longer serves direct downloads.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}

		$handle = @fopen( $local['path'], 'rb' );
		if ( false === $handle || 0 !== fseek( $handle, $offset ) ) {
			return new WP_Error( 'leadwerk_peer_source_read', __( 'Source could not read the requested archive chunk.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}
		$chunk = fread( $handle, $length );
		fclose( $handle );
		if ( ! is_string( $chunk ) || '' === $chunk ) {
			return new WP_Error( 'leadwerk_peer_source_read', __( 'Source returned an empty direct-transfer chunk.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}
		return new WP_REST_Response( array( 'job_id' => $ticket['job_id'], 'offset' => $offset, 'total' => (int) $local['size'], 'sha256' => $local['sha256'], 'archive_name' => $local['archive'], 'data' => base64_encode( $chunk ) ), 200 );
	}

	private static function validate_peer_ticket( $request, $action ) {
		$token = trim( (string) $request->get_header( 'X-Leadwerk-Peer-Ticket' ) );
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || ! preg_match( '/\A[A-Za-z0-9_-]+\z/D', $parts[0] ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $parts[1] ) ) {
			return new WP_Error( 'leadwerk_peer_ticket_invalid', __( 'Direct-transfer ticket is invalid.', 'leadwerk-migration' ), array( 'status' => 401 ) );
		}
		$environment = Leadwerk_Migration_Site_Identity::ensure_environment();
		$expected = hash_hmac( 'sha256', $parts[0], (string) $environment['environment_secret'] );
		$padding = ( 4 - strlen( $parts[0] ) % 4 ) % 4;
		$json = base64_decode( strtr( $parts[0] . str_repeat( '=', $padding ), '-_', '+/' ), true );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		$family = get_option( LEADWERK_MIGRATION_SYNC_FAMILY, array() );
		$expected_endpoint = 'push' === $action ? ( isset( $data['target_environment_id'] ) ? $data['target_environment_id'] : '' ) : ( isset( $data['source_environment_id'] ) ? $data['source_environment_id'] : '' );
		$family_matches = is_array( $data ) && isset( $data['family_id'] ) && is_array( $family ) && ! empty( $family['family_id'] ) && hash_equals( (string) $family['family_id'], (string) $data['family_id'] );
		$claimed_target_matches = is_array( $data ) && 'push' === $action && isset( $data['family_id'] ) && empty( $family['family_id'] ) && isset( $environment['registration'], $environment['claimed_family_id'] ) && 'approved' === $environment['registration'] && hash_equals( (string) $environment['claimed_family_id'], (string) $data['family_id'] );
		if (
			! hash_equals( $expected, $parts[1] ) || ! is_array( $data ) ||
			! isset( $data['job_id'], $data['family_id'], $data['source_environment_id'], $data['target_environment_id'], $data['endpoint_environment_id'], $data['action'], $data['expires_at'] ) ||
			! hash_equals( (string) $request['job_id'], (string) $data['job_id'] ) ||
			! hash_equals( (string) $environment['environment_id'], (string) $data['endpoint_environment_id'] ) ||
			! hash_equals( (string) $environment['environment_id'], (string) $expected_endpoint ) ||
			! hash_equals( (string) $action, (string) $data['action'] ) || (int) $data['expires_at'] < time() ||
			! Leadwerk_Migration_Site_Identity::valid_id( $data['family_id'] ) ||
			( ! $family_matches && ! $claimed_target_matches )
		) {
			return new WP_Error( 'leadwerk_peer_ticket_forbidden', __( 'Direct-transfer ticket is expired or does not authorize this endpoint.', 'leadwerk-migration' ), array( 'status' => 403 ) );
		}
		return $data;
	}

	public static function receive_wake( $request ) {
		$environment = Leadwerk_Migration_Site_Identity::ensure_environment();
		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();
		$environment_id = isset( $data['environment_id'] ) ? strtolower( trim( (string) $data['environment_id'] ) ) : '';
		$subject_type = isset( $data['subject_type'] ) ? sanitize_key( $data['subject_type'] ) : '';
		$subject_id = isset( $data['subject_id'] ) ? strtolower( trim( (string) $data['subject_id'] ) ) : '';
		$timestamp = (int) $request->get_header( 'X-Leadwerk-Wake-Timestamp' );
		$nonce = strtolower( trim( (string) $request->get_header( 'X-Leadwerk-Wake-Nonce' ) ) );
		$signature = strtolower( trim( (string) $request->get_header( 'X-Leadwerk-Wake-Signature' ) ) );
		if (
			! Leadwerk_Migration_Site_Identity::valid_id( $environment_id ) ||
			! isset( $environment['environment_id'], $environment['environment_secret'] ) ||
			! hash_equals( (string) $environment['environment_id'], $environment_id ) ||
			! in_array( $subject_type, array( 'job', 'command' ), true ) ||
			! Leadwerk_Migration_Site_Identity::valid_id( $subject_id ) ||
			abs( time() - $timestamp ) > self::WAKE_CLOCK_SKEW ||
			! preg_match( '/\A[a-f0-9]{32}\z/D', $nonce ) ||
			! preg_match( '/\A[a-f0-9]{64}\z/D', $signature )
		) {
			return new WP_Error( 'leadwerk_worker_wake_invalid', __( 'The worker wake request is invalid or expired.', 'leadwerk-migration' ), array( 'status' => 401 ) );
		}
		$replay_key = 'leadwerk_worker_wake_' . hash( 'sha256', $environment_id . '|' . $nonce );
		if ( get_transient( $replay_key ) ) {
			return new WP_Error( 'leadwerk_worker_wake_replay', __( 'This worker wake request has already been used.', 'leadwerk-migration' ), array( 'status' => 409 ) );
		}
		$canonical = Leadwerk_Migration_Sync_Hub::canonical_request( $request->get_method(), $request->get_route(), $timestamp, $nonce, (string) $request->get_body() );
		$expected = hash_hmac( 'sha256', $canonical, (string) $environment['environment_secret'] );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'leadwerk_worker_wake_signature', __( 'The worker wake signature is invalid.', 'leadwerk-migration' ), array( 'status' => 401 ) );
		}
		set_transient( $replay_key, 1, self::WAKE_CLOCK_SKEW * 2 );
		self::schedule_fast_worker();
		return new WP_REST_Response( array( 'status' => 'scheduled', 'subject_type' => $subject_type, 'subject_id' => $subject_id ), 202 );
	}

	/** Schedule an immediate single worker and spawn cron after this response. */
	public static function schedule_fast_worker() {
		if ( ! wp_next_scheduled( LEADWERK_MIGRATION_SYNC_FAST_HOOK ) ) {
			wp_schedule_single_event( time(), LEADWERK_MIGRATION_SYNC_FAST_HOOK );
		}
		if ( ! self::$fast_spawn_registered ) {
			self::$fast_spawn_registered = true;
			register_shutdown_function( array( __CLASS__, 'spawn_fast_worker' ) );
		}
	}

	/** Internal shutdown callback; non-blocking loopback starts due fast work. */
	public static function spawn_fast_worker() {
		$url = add_query_arg( 'doing_wp_cron', sprintf( '%.22F', microtime( true ) ), site_url( 'wp-cron.php' ) );
		wp_remote_post( $url, array( 'timeout' => 0.01, 'blocking' => false, 'redirection' => 0, 'sslverify' => true ) );
	}

	/** Register or refresh this environment at the configured Hub. */
	public static function register_environment() {
		$family = get_option( LEADWERK_MIGRATION_SYNC_FAMILY, array() );
		if ( ! is_array( $family ) || ! Leadwerk_Migration_Site_Identity::valid_id( isset( $family['family_id'] ) ? $family['family_id'] : '' ) || empty( $family['family_secret'] ) ) {
			return new WP_Error( 'leadwerk_sync_zone_zero_missing', __( 'Create the Zone Zero identity before registering this environment.', 'leadwerk-migration' ) );
		}
		$environment = Leadwerk_Migration_Site_Identity::ensure_environment();
		$payload = self::environment_payload( $family, $environment );
		$payload['family_secret']      = $family['family_secret'];
		$payload['environment_secret'] = $environment['environment_secret'];
		$attestation = Leadwerk_Migration_Release::installed_attestation();
		if ( is_wp_error( $attestation ) ) {
			return $attestation;
		}
		$payload['release_attestation'] = $attestation;
		$response = self::request( '/hub/register', $payload, false );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$environment['registration']       = isset( $response['environment_status'] ) ? sanitize_key( $response['environment_status'] ) : 'pending';
		$environment['family_status']      = isset( $response['family_status'] ) ? sanitize_key( $response['family_status'] ) : 'pending';
		$environment['last_registered_at'] = gmdate( 'c' );
		update_option( LEADWERK_MIGRATION_SYNC_ENVIRONMENT, $environment, false );

		return $response;
	}

	/** Register a family-less installation as an available Hub transfer target. */
	public static function register_available_environment() {
		$environment = Leadwerk_Migration_Site_Identity::ensure_environment();
		$attestation = Leadwerk_Migration_Release::installed_attestation();
		if ( is_wp_error( $attestation ) ) {
			return $attestation;
		}
		$payload = self::environment_payload( array(), $environment );
		$payload['registration_mode']  = 'available';
		$payload['environment_secret'] = $environment['environment_secret'];
		$payload['release_attestation'] = $attestation;
		$response = self::request( '/hub/register', $payload, false );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$environment['registration']       = isset( $response['environment_status'] ) ? sanitize_key( $response['environment_status'] ) : 'available';
		$environment['last_registered_at'] = gmdate( 'c' );
		unset( $environment['family_status'] );
		if ( Leadwerk_Migration_Site_Identity::valid_id( isset( $response['family_id'] ) ? $response['family_id'] : '' ) ) {
			$environment['claimed_family_id'] = strtolower( $response['family_id'] );
		} else {
			unset( $environment['claimed_family_id'] );
		}
		update_option( LEADWERK_MIGRATION_SYNC_ENVIRONMENT, $environment, false );
		return $response;
	}

	/**
	 * Refresh an inherited/explicit family or keep this installation visible as
	 * an available target. This method never creates a Site Family.
	 */
	public static function bootstrap_registration() {
		if ( Leadwerk_Migration_Sync_Hub::enabled() ) {
			return true;
		}
		Leadwerk_Migration_Site_Identity::discard_legacy_blank_family();
		$family = Leadwerk_Migration_Site_Identity::current_family();
		$environment = Leadwerk_Migration_Site_Identity::ensure_environment();
		if ( ! Leadwerk_Migration_Site_Identity::valid_id( isset( $family['family_id'] ) ? $family['family_id'] : '' ) ) {
			if ( ! isset( $environment['registration'] ) || ! in_array( $environment['registration'], array( 'available', 'approved' ), true ) ) {
				$response = self::register_available_environment();
			} else {
				$response = self::heartbeat();
			}
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			if ( isset( $response['status'] ) && 'approved' === $response['status'] && Leadwerk_Migration_Site_Identity::valid_id( isset( $response['family_id'] ) ? $response['family_id'] : '' ) ) {
				return $response;
			}
			return new WP_Error( 'leadwerk_sync_available_target', __( 'This WordPress installation is listed on Hub as an available target and has no Site Family yet.', 'leadwerk-migration' ) );
		}
		if (
			! isset( $environment['registration'] ) || 'approved' !== $environment['registration'] ||
			! isset( $environment['family_status'] ) || 'approved' !== $environment['family_status']
		) {
			return self::register_environment();
		}
		$response = self::heartbeat();
		if ( ! is_wp_error( $response ) && isset( $response['status'] ) && 'approved' !== $response['status'] ) {
			return self::register_environment();
		}
		return $response;
	}

	public static function heartbeat() {
		$family = get_option( LEADWERK_MIGRATION_SYNC_FAMILY, array() );
		$environment = Leadwerk_Migration_Site_Identity::ensure_environment();
		$family = is_array( $family ) ? $family : array();
		$response = self::request( '/hub/heartbeat', self::environment_payload( $family, $environment ), true );
		if ( ! is_wp_error( $response ) && isset( $response['status'] ) ) {
			$environment['registration']       = sanitize_key( $response['status'] );
			$environment['last_registered_at'] = gmdate( 'c' );
			if ( Leadwerk_Migration_Site_Identity::valid_id( isset( $response['family_id'] ) ? $response['family_id'] : '' ) ) {
				$environment['claimed_family_id'] = strtolower( $response['family_id'] );
			} elseif ( 'available' === $environment['registration'] ) {
				unset( $environment['claimed_family_id'] );
			}
			update_option( LEADWERK_MIGRATION_SYNC_ENVIRONMENT, $environment, false );
		}
		return $response;
	}

	/** @return array|WP_Error */
	public static function directory() {
		$response = self::request( '/hub/directory', array(), true );
		return is_wp_error( $response ) ? $response : ( isset( $response['environments'] ) && is_array( $response['environments'] ) ? $response['environments'] : array() );
	}

	/** @return array|WP_Error */
	public static function jobs() {
		$response = self::request( '/hub/jobs/list', array(), true );
		return is_wp_error( $response ) ? $response : ( isset( $response['jobs'] ) && is_array( $response['jobs'] ) ? $response['jobs'] : array() );
	}

	/** @return array|WP_Error */
	public static function commands() {
		$response = self::request( '/hub/commands/list', array(), true );
		return is_wp_error( $response ) ? $response : ( isset( $response['commands'] ) && is_array( $response['commands'] ) ? $response['commands'] : array() );
	}

	/** Fetch all dynamic Site Sync panel data in one Hub round trip. */
	public static function status_snapshot() {
		$response = self::request( '/hub/status', array(), true );
		if ( ! is_wp_error( $response ) ) {
			return array(
				'environments' => isset( $response['environments'] ) && is_array( $response['environments'] ) ? $response['environments'] : array(),
				'jobs'         => isset( $response['jobs'] ) && is_array( $response['jobs'] ) ? $response['jobs'] : array(),
				'commands'     => isset( $response['commands'] ) && is_array( $response['commands'] ) ? $response['commands'] : array(),
				'hub_time'     => isset( $response['hub_time'] ) ? sanitize_text_field( $response['hub_time'] ) : '',
			);
		}
		$error_data = $response->get_error_data();
		if ( ! is_array( $error_data ) || 404 !== (int) ( isset( $error_data['status'] ) ? $error_data['status'] : 0 ) ) {
			return $response;
		}

		// Rolling-upgrade compatibility with a Hub that predates the combined route.
		$environments = self::directory();
		$jobs = self::jobs();
		$commands = self::commands();
		foreach ( array( $environments, $jobs, $commands ) as $legacy_result ) {
			if ( is_wp_error( $legacy_result ) ) {
				return $legacy_result;
			}
		}
		return array( 'environments' => $environments, 'jobs' => $jobs, 'commands' => $commands, 'hub_time' => '' );
	}

	public static function request_runtime_reconcile( $reference_id, $target_id ) {
		$response = self::request( '/hub/commands', array( 'reference_environment_id' => $reference_id, 'target_environment_id' => $target_id, 'command_type' => 'runtime_reconcile' ), true );
		$environment = Leadwerk_Migration_Site_Identity::ensure_environment();
		if ( ! is_wp_error( $response ) && isset( $environment['environment_id'] ) && hash_equals( (string) $environment['environment_id'], (string) $target_id ) ) {
			self::schedule_fast_worker();
		}
		return $response;
	}

	public static function cancel_command( $command_id ) {
		return self::request( '/hub/commands/' . rawurlencode( $command_id ) . '/cancel', array(), true );
	}

	public static function retry_command( $command_id ) {
		$response = self::request( '/hub/commands/' . rawurlencode( $command_id ) . '/retry', array(), true );
		if ( ! is_wp_error( $response ) ) {
			self::schedule_fast_worker();
		}
		return $response;
	}

	public static function cancel_job( $job_id ) {
		$response = self::request( '/hub/jobs/' . rawurlencode( $job_id ) . '/cancel', array(), true );
		if ( ! is_wp_error( $response ) ) {
			$state = self::state();
			if ( isset( $state['jobs'][ $job_id ] ) && is_array( $state['jobs'][ $job_id ] ) ) {
				$state['jobs'][ $job_id ]['status']      = 'canceled';
				$state['jobs'][ $job_id ]['canceled_at'] = gmdate( 'c' );
				self::save_state( $state );
			}
		}
		return $response;
	}

	public static function retry_job( $job_id ) {
		$response = self::request( '/hub/jobs/' . rawurlencode( $job_id ) . '/retry', array(), true );
		if ( ! is_wp_error( $response ) ) {
			self::schedule_fast_worker();
		}
		return $response;
	}

	/**
	 * Create a full-site sync after local preflight. The Hub repeats every check.
	 *
	 * @param string $source_id Source environment ID.
	 * @param string $target_id Target environment ID.
	 * @return array|WP_Error
	 */
	public static function request_sync( $source_id, $target_id ) {
		// Refresh the initiating environment before reading the shared directory.
		// This is a metadata-only preflight request and does not create a backup or
		// alter either WordPress installation.
		$registration = self::bootstrap_registration();
		if ( is_wp_error( $registration ) ) {
			return $registration;
		}
		$directory = self::directory();
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}
		$source = self::find_environment( $directory, $source_id );
		$target = self::find_environment( $directory, $target_id );
		if ( ! is_array( $source ) || ! is_array( $target ) || $source_id === $target_id ) {
			return new WP_Error( 'leadwerk_sync_invalid_environments', __( 'Choose two different active environments.', 'leadwerk-migration' ) );
		}

		// This is deliberately the first sync operation. No export, disk mutation,
		// or peer job is created before WordPress/plugin match exactly and the
		// two PHP runtimes share the same major.minor compatibility branch.
		$preflight = self::version_preflight( $source, $target );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		if ( ! Leadwerk_Migration_Sync_Hub::environment_is_online( $source ) || ! Leadwerk_Migration_Sync_Hub::environment_is_online( $target ) ) {
			return new WP_Error( 'leadwerk_sync_environment_offline', __( 'Sync blocked: source and target must both be online and must have contacted Leadwerk Hub within the last 15 minutes.', 'leadwerk-migration' ) );
		}

		$response = self::request(
			'/hub/jobs',
			array(
				'source_environment_id' => $source_id,
				'target_environment_id' => $target_id,
				'mode'                  => 'full',
			),
			true
		);
		$environment = Leadwerk_Migration_Site_Identity::ensure_environment();
		if (
			! is_wp_error( $response ) &&
			isset( $environment['environment_id'] ) &&
			( hash_equals( (string) $environment['environment_id'], (string) $source_id ) || hash_equals( (string) $environment['environment_id'], (string) $target_id ) )
		) {
			self::schedule_fast_worker();
		}
		return $response;
	}

	public static function approve_job( $job_id ) {
		return self::request( '/hub/jobs/' . rawurlencode( $job_id ) . '/approve', array(), true );
	}

	/** Process source uploads and target downloads/imports. */
	public static function process() {
		$token = self::acquire_lock();
		if ( is_wp_error( $token ) ) {
			// A Hub wake and the recurring heartbeat can reach the same worker
			// almost simultaneously. The cron event that lost the lock has already
			// been consumed, so leave a fresh immediate event behind instead of
			// letting an active transfer wait for the periodic watchdog.
			self::schedule_fast_worker();
			return $token;
		}

		// Import pipeline stages may terminate the request with exit before PHP
		// reaches the finally block below. Always release only this worker's token
		// at shutdown so a completed import cannot block the next job for 30 minutes.
		register_shutdown_function(
			static function () use ( $token ) {
				self::release_lock( $token );
			}
		);

		try {
			if ( Leadwerk_Migration_Sync_Hub::enabled() ) {
				$release = Leadwerk_Migration_Sync_Hub::process_release_automation();
				Leadwerk_Migration_Sync_Hub::cleanup();
				return array( 'hub_cleanup' => true, 'release_automation' => $release );
			}
			$registration = self::bootstrap_registration();
			if ( is_wp_error( $registration ) ) {
				return $registration;
			}
			$jobs = self::jobs();
			if ( is_wp_error( $jobs ) ) {
				return $jobs;
			}
			self::cleanup_canceled_local_state( $jobs );
			$environment = Leadwerk_Migration_Site_Identity::ensure_environment();
			$processed = array();
			// A source archive remains the recovery/transfer copy until the target
			// has reported a genuinely completed import. The source's next worker
			// then removes only that job-bound sync backup and its integrity data.
			foreach ( $jobs as $job ) {
				if (
					is_array( $job ) &&
					isset( $job['status'], $job['source_environment_id'] ) &&
					'complete' === $job['status'] &&
					hash_equals( (string) $job['source_environment_id'], (string) $environment['environment_id'] )
				) {
					$cleanup = self::cleanup_completed_source_backup( $job );
					if ( null !== $cleanup ) {
						$processed[] = $cleanup;
					}
				}
			}
			$commands = self::commands();
			if ( is_wp_error( $commands ) ) {
				return $commands;
			}
			$runtime = Leadwerk_Migration_Runtime_Reconcile::process( $commands, $environment );
			if ( null !== $runtime ) {
				// Never combine a plugin/core/runtime mutation with a full-site transfer
				// in the same request. A running command gets an immediate fresh worker;
				// the one-minute watchdog remains a recovery fallback.
				$processed[] = $runtime;
				if ( self::result_needs_followup( $runtime ) ) {
					self::schedule_fast_worker();
				}
				return $processed;
			}
			foreach ( array_slice( $jobs, 0, 10 ) as $job ) {
				if ( ! is_array( $job ) || empty( $job['job_id'] ) ) {
					continue;
				}
				if ( in_array( $job['status'], array( 'complete', 'failed', 'canceled', 'awaiting_approval' ), true ) ) {
					continue;
				}
				if ( hash_equals( $job['source_environment_id'], $environment['environment_id'] ) && in_array( $job['status'], array( 'approved', 'transferring', 'receiving' ), true ) ) {
					$result = self::process_source( $job );
					$processed[] = $result;
					if ( self::result_needs_followup( $result ) ) {
						self::schedule_fast_worker();
					}
				} elseif ( hash_equals( $job['target_environment_id'], $environment['environment_id'] ) && in_array( $job['status'], array( 'preparing_target', 'peer_ready', 'ready', 'receiving', 'verifying', 'preparing_import', 'importing', 'finalizing' ), true ) ) {
					$result = self::process_target( $job );
					$processed[] = $result;
					if ( self::result_needs_followup( $result ) ) {
						self::schedule_fast_worker();
					}
				}
			}
			return $processed;
		} finally {
			self::release_lock( $token );
		}
	}

	/** A successful partial batch or running command should continue immediately. */
	private static function result_needs_followup( $result ) {
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return false;
		}
		if ( isset( $result['status'] ) && in_array( $result['status'], array( 'queued', 'running' ), true ) ) {
			return true;
		}
		return isset( $result['offset'], $result['total'] ) && (int) $result['total'] > 0 && (int) $result['offset'] < (int) $result['total'];
	}

	/** Called before import cleanup while target credentials are available. */
	public static function complete_import_job( $params ) {
		$job_id = isset( $params['leadwerk_migration_sync_job_id'] ) ? strtolower( trim( (string) $params['leadwerk_migration_sync_job_id'] ) ) : '';
		if ( ! Leadwerk_Migration_Site_Identity::valid_id( $job_id ) ) {
			return;
		}
		self::request( '/hub/jobs/' . $job_id . '/complete', array(), true );
		$state = self::state();
		if ( isset( $state['jobs'][ $job_id ] ) ) {
			$state['jobs'][ $job_id ]['status']      = 'complete';
			$state['jobs'][ $job_id ]['completed_at'] = gmdate( 'c' );
			self::save_state( $state );
		}
	}

	/** Relay a concrete import pipeline message to Hub without blocking the import. */
	public static function report_import_progress( $job_id, $message, $stage = 'importing' ) {
		$job_id = strtolower( trim( (string) $job_id ) );
		$stage = sanitize_key( $stage );
		if ( ! Leadwerk_Migration_Site_Identity::valid_id( $job_id ) || ! in_array( $stage, array( 'verifying', 'preparing_import', 'importing', 'finalizing' ), true ) ) {
			return new WP_Error( 'leadwerk_sync_import_progress_invalid', __( 'The local import progress report is invalid.', 'leadwerk-migration' ) );
		}
		$message = str_ireplace( array( '<br>', '<br/>', '<br />' ), ' — ', (string) $message );
		$message = substr( sanitize_text_field( wp_strip_all_tags( $message ) ), 0, 1000 );
		if ( '' === $message ) {
			return true;
		}

		$fingerprint = hash( 'sha256', $stage . "\n" . $message );
		$cache_key = 'leadwerk_sync_progress_' . substr( $job_id, 0, 20 );
		if ( hash_equals( (string) get_transient( $cache_key ), $fingerprint ) ) {
			return true;
		}
		set_transient( $cache_key, $fingerprint, 10 * MINUTE_IN_SECONDS );
		$response = self::request(
			'/hub/jobs/' . rawurlencode( $job_id ) . '/import-progress',
			array( 'stage' => $stage, 'message' => $message ),
			true,
			15
		);
		return is_wp_error( $response ) ? $response : true;
	}

	/** Persist a secrets-free import continuation so the watchdog can replay a dropped loopback. */
	public static function remember_import_continuation( $params ) {
		$job_id = is_array( $params ) && isset( $params['leadwerk_migration_sync_job_id'] ) ? strtolower( trim( (string) $params['leadwerk_migration_sync_job_id'] ) ) : '';
		if ( ! Leadwerk_Migration_Site_Identity::valid_id( $job_id ) || empty( $params['leadwerk_migration_internal'] ) ) {
			return false;
		}
		$continuation = $params;
		foreach ( array( 'secret_key', 'decryption_password', Leadwerk_Migration_Security::PIPELINE_TOKEN_PARAM, Leadwerk_Migration_Security::REST_TOKEN_PARAM ) as $sensitive_key ) {
			unset( $continuation[ $sensitive_key ] );
		}
		$encoded = wp_json_encode( $continuation );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 1024 * 1024 ) {
			return false;
		}
		$state = self::state();
		$previous = isset( $state['jobs'][ $job_id ]['continuation_fingerprint'] ) ? (string) $state['jobs'][ $job_id ]['continuation_fingerprint'] : '';
		$fingerprint = hash( 'sha256', $encoded );
		$state['jobs'][ $job_id ]['continuation']             = $continuation;
		$state['jobs'][ $job_id ]['continuation_saved_at']    = time();
		$state['jobs'][ $job_id ]['continuation_fingerprint'] = $fingerprint;
		if ( ! hash_equals( $previous, $fingerprint ) ) {
			$state['jobs'][ $job_id ]['continuation_attempts'] = 0;
		}
		return self::save_state( $state );
	}

	/** @return true|WP_Error */
	public static function version_preflight( $source, $target ) {
		$checks = array(
			'wordpress_version' => __( 'WordPress', 'leadwerk-migration' ),
			'php_version'       => __( 'PHP', 'leadwerk-migration' ),
			'plugin_version'    => __( 'Leadwerk Migration', 'leadwerk-migration' ),
		);
		foreach ( $checks as $key => $label ) {
			$from = isset( $source[ $key ] ) ? trim( (string) $source[ $key ] ) : '';
			$to   = isset( $target[ $key ] ) ? trim( (string) $target[ $key ] ) : '';
			$compatible = 'php_version' === $key
				? Leadwerk_Migration_Site_Identity::php_versions_compatible( $from, $to )
				: ( '' !== $from && '' !== $to && hash_equals( $from, $to ) );
			if ( ! $compatible ) {
				$message = 'php_version' === $key
					? __( 'Sync blocked: PHP branches differ (%2$s → %3$s). PHP patch releases may differ, but major.minor must match (for example, 8.4.x with 8.4.x).', 'leadwerk-migration' )
					: ( 'plugin_version' === $key ? __( 'Sync blocked temporarily: %1$s versions differ (%2$s → %3$s). Hub reconciles signed Migration releases automatically; retry after both heartbeats match.', 'leadwerk-migration' ) : __( 'Sync blocked temporarily: %1$s versions differ (%2$s → %3$s). Hub upgrades the lower WordPress version to the highest version in this Site Family; retry after both heartbeats match.', 'leadwerk-migration' ) );
				return new WP_Error(
					'leadwerk_sync_' . $key . '_mismatch',
					sprintf(
						$message,
						$label,
						$from !== '' ? $from : __( 'unknown', 'leadwerk-migration' ),
						$to !== '' ? $to : __( 'unknown', 'leadwerk-migration' )
					)
				);
			}
		}
		return true;
	}

	private static function process_source( $job ) {
		$state = self::state();
		$job_id = $job['job_id'];
		$local = isset( $state['jobs'][ $job_id ] ) && is_array( $state['jobs'][ $job_id ] ) ? $state['jobs'][ $job_id ] : array();
		$transport = isset( $job['transport'] ) ? sanitize_key( $job['transport'] ) : '';
		if ( ! in_array( $transport, array( 'direct_pull', 'direct_push' ), true ) ) {
			return self::fail_remote( $job_id, __( 'Synchronization stopped because Hub relay transport is disabled and this job has no valid peer route.', 'leadwerk-migration' ) );
		}

		if ( empty( $local['archive'] ) ) {
			$result = Leadwerk_Migration_Scheduler::run( 'sync' );
			if ( is_wp_error( $result ) ) {
				return self::fail_remote( $job_id, $result->get_error_message() );
			}
			$archive = isset( $result['archive'] ) ? $result['archive'] : '';
			$path = Leadwerk_Migration_Integrity::resolve_backup_path( $archive );
			if ( is_wp_error( $path ) || ! is_file( $path ) ) {
				return self::fail_remote( $job_id, __( 'The source backup could not be resolved after export.', 'leadwerk-migration' ) );
			}
			$hash = hash_file( 'sha256', $path );
			$size = filesize( $path );
			if ( ! is_string( $hash ) || $size === false || $size <= 0 ) {
				return self::fail_remote( $job_id, __( 'The source backup integrity metadata could not be calculated.', 'leadwerk-migration' ) );
			}
			$local = array( 'role' => 'source', 'archive' => $archive, 'path' => $path, 'sha256' => $hash, 'size' => (int) $size, 'offset' => 0, 'created_at' => gmdate( 'c' ) );
			$state['jobs'][ $job_id ] = $local;
			self::save_state( $state );
		}
		if ( isset( $local['transport'] ) && $local['transport'] !== $transport ) {
			$local['offset'] = 0;
			unset( $local['published'] );
		}
		$local['transport'] = $transport;
		$state['jobs'][ $job_id ] = $local;
		self::save_state( $state );

		if ( empty( $local['published'] ) ) {
			$response = self::request( '/hub/jobs/' . $job_id . '/source-ready', array( 'archive_name' => $local['archive'], 'archive_size' => $local['size'], 'archive_sha256' => $local['sha256'] ), true, 60 );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$local['published'] = true;
			$state['jobs'][ $job_id ] = $local;
			self::save_state( $state );
		}

		if ( 'direct_pull' === $transport ) {
			return array( 'job_id' => $job_id, 'role' => 'source', 'status' => 'peer_ready', 'offset' => (int) $local['size'], 'total' => (int) $local['size'], 'transport' => $transport );
		}

		$handle = @fopen( $local['path'], 'rb' );
		if ( $handle === false || fseek( $handle, (int) $local['offset'] ) !== 0 ) {
			return self::fail_remote( $job_id, __( 'The source backup could not be opened at the saved transfer offset.', 'leadwerk-migration' ) );
		}
		$batch_chunks = LEADWERK_MIGRATION_SYNC_DIRECT_BATCH_CHUNKS;
		$chunk_size   = LEADWERK_MIGRATION_SYNC_DIRECT_CHUNK_SIZE;
		for ( $part = 0; $part < $batch_chunks && (int) $local['offset'] < (int) $local['size']; $part++ ) {
			$chunk = fread( $handle, $chunk_size );
			if ( ! is_string( $chunk ) || $chunk === '' ) {
				fclose( $handle );
				return self::fail_remote( $job_id, __( 'The source backup returned an empty transfer chunk.', 'leadwerk-migration' ) );
			}
			$payload = array(
				'offset'       => (int) $local['offset'],
				'total'        => (int) $local['size'],
				'sha256'       => $local['sha256'],
				'archive_name' => $local['archive'],
				'data'         => base64_encode( $chunk ),
			);
			$response = self::peer_request( $job, $payload, 120 );
			if ( is_wp_error( $response ) ) {
				fclose( $handle );
				return self::fail_remote( $job_id, sprintf( __( 'Authenticated peer-to-peer upload failed: %s', 'leadwerk-migration' ), $response->get_error_message() ) );
			}
			$local['offset'] += strlen( $chunk );
			$state['jobs'][ $job_id ] = $local;
			self::save_state( $state );
		}
		fclose( $handle );
		return array( 'job_id' => $job_id, 'role' => 'source', 'offset' => (int) $local['offset'], 'total' => (int) $local['size'], 'transport' => $transport );
	}

	/**
	 * Delete a source-generated sync backup only after Hub reports target import
	 * completion. Failed/canceled jobs deliberately keep their source recovery
	 * copy for diagnosis and manual recovery.
	 */
	private static function cleanup_completed_source_backup( $job ) {
		$job_id = isset( $job['job_id'] ) ? strtolower( trim( (string) $job['job_id'] ) ) : '';
		if ( 'complete' !== ( isset( $job['status'] ) ? $job['status'] : '' ) || ! Leadwerk_Migration_Site_Identity::valid_id( $job_id ) ) {
			return null;
		}
		$state = self::state();
		$local = isset( $state['jobs'][ $job_id ] ) && is_array( $state['jobs'][ $job_id ] ) ? $state['jobs'][ $job_id ] : array();
		if ( empty( $local ) || ! isset( $local['role'] ) || 'source' !== $local['role'] ) {
			return null;
		}
		$archive = isset( $local['archive'] ) ? trim( (string) $local['archive'] ) : '';
		$path = Leadwerk_Migration_Integrity::resolve_backup_path( $archive );
		if ( is_wp_error( $path ) ) {
			return new WP_Error( 'leadwerk_sync_source_cleanup_invalid', $path->get_error_message() );
		}
		if ( is_file( $path ) ) {
			$manifests = Leadwerk_Migration_Integrity::get_manifests();
			if ( ! isset( $manifests[ $archive ]['source'] ) || 'sync' !== $manifests[ $archive ]['source'] ) {
				return new WP_Error( 'leadwerk_sync_source_cleanup_untracked', __( 'The completed source archive was not deleted because it is not tracked as a Site Sync backup.', 'leadwerk-migration' ) );
			}
			if ( ! Leadwerk_Migration_Backups::delete_file( $archive ) ) {
				return new WP_Error( 'leadwerk_sync_source_cleanup_failed', __( 'The completed source Site Sync backup could not be deleted. The worker will retry.', 'leadwerk-migration' ) );
			}
		}
		$removed = Leadwerk_Migration_Integrity::remove( $archive );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}
		Leadwerk_Migration_Backups::delete_label( $archive );
		unset( $state['jobs'][ $job_id ] );
		if ( ! self::save_state( $state ) ) {
			$stored = self::state();
			if ( isset( $stored['jobs'][ $job_id ] ) ) {
				return new WP_Error( 'leadwerk_sync_source_cleanup_state_failed', __( 'The source backup was deleted, but its local Site Sync state could not be cleared. The worker will retry safely.', 'leadwerk-migration' ) );
			}
		}
		return array( 'job_id' => $job_id, 'role' => 'source', 'status' => 'source_backup_deleted', 'archive' => $archive );
	}

	private static function process_target( $job ) {
		$state  = self::state();
		$job_id = $job['job_id'];
		$local  = isset( $state['jobs'][ $job_id ] ) && is_array( $state['jobs'][ $job_id ] ) ? $state['jobs'][ $job_id ] : array();
		$transport = isset( $job['transport'] ) ? sanitize_key( $job['transport'] ) : '';
		if ( ! in_array( $transport, array( 'direct_pull', 'direct_push' ), true ) ) {
			return self::fail_remote( $job_id, __( 'Synchronization stopped because Hub relay transport is disabled and this job has no valid peer route.', 'leadwerk-migration' ) );
		}
		if ( isset( $local['status'] ) && 'importing' === $local['status'] ) {
			$last_progress = isset( $local['continuation_saved_at'] ) ? (int) $local['continuation_saved_at'] : 0;
			if ( 0 === $last_progress && ! empty( $job['updated_at'] ) ) {
				$parsed = strtotime( (string) $job['updated_at'] . ' UTC' );
				$last_progress = false === $parsed ? 0 : (int) $parsed;
			}
			$stalled = $last_progress > 0 && time() - $last_progress >= 5 * MINUTE_IN_SECONDS;
			if ( $stalled && ! empty( $local['continuation'] ) && (int) ( isset( $local['continuation_attempts'] ) ? $local['continuation_attempts'] : 0 ) < 2 ) {
				return self::resume_import_continuation( $job_id, $local );
			}
			if ( $stalled && empty( $local['continuation'] ) && empty( $local['import_restart_count'] ) && ! empty( $local['archive'] ) ) {
				$local['import_restart_count'] = 1;
				$local['status'] = 'preparing_import';
				$state['jobs'][ $job_id ] = $local;
				self::save_state( $state );
				self::report_import_progress( $job_id, __( 'A stalled import continuation was detected. Restarting once from the verified local archive.', 'leadwerk-migration' ), 'preparing_import' );
				return self::start_verified_import( $job_id, $local['archive'] );
			}
			self::report_import_progress( $job_id, __( 'The target WordPress import is continuing in the background.', 'leadwerk-migration' ), 'importing' );
			return array( 'job_id' => $job_id, 'role' => 'target', 'status' => 'importing' );
		}

		// Always create a verified local recovery point before receiving bytes for
		// a destructive full-site import.
		if ( empty( $local['safety_backup'] ) ) {
			$safety = Leadwerk_Migration_Scheduler::run( 'sync' );
			if ( is_wp_error( $safety ) ) {
				return self::fail_remote( $job_id, sprintf( __( 'Target safety backup failed: %s', 'leadwerk-migration' ), $safety->get_error_message() ) );
			}
			$local['role']          = 'target';
			$local['safety_backup'] = isset( $safety['archive'] ) ? $safety['archive'] : '';
			$local['offset']        = 0;
			$local['created_at']    = gmdate( 'c' );
			$state['jobs'][ $job_id ] = $local;
			self::save_state( $state );
		}
		if ( 'preparing_target' === $job['status'] ) {
			$response = self::request( '/hub/jobs/' . $job_id . '/target-ready', array(), true, 60 );
			return is_wp_error( $response ) ? $response : array( 'job_id' => $job_id, 'role' => 'target', 'status' => 'target_ready' );
		}

		$partial = self::target_partial_path( $job_id );
		$local['transport'] = $transport;
		$state['jobs'][ $job_id ] = $local;
		self::save_state( $state );
		$current = is_file( $partial ) ? (int) filesize( $partial ) : 0;
		if ( $current !== (int) $local['offset'] ) {
			$local['offset'] = $current;
		}

		if ( 'direct_pull' === $transport ) {
			$batch_chunks = LEADWERK_MIGRATION_SYNC_DIRECT_BATCH_CHUNKS;
			$chunk_size   = LEADWERK_MIGRATION_SYNC_DIRECT_CHUNK_SIZE;
			for ( $part = 0; $part < $batch_chunks; $part++ ) {
				$payload = array( 'offset' => (int) $local['offset'], 'length' => $chunk_size );
				$response = self::peer_request( $job, $payload, 120 );
				if ( is_wp_error( $response ) ) {
					return self::fail_remote( $job_id, sprintf( __( 'Authenticated peer-to-peer download failed: %s', 'leadwerk-migration' ), $response->get_error_message() ) );
				}
				$chunk = isset( $response['data'] ) ? base64_decode( $response['data'], true ) : false;
				if ( ! is_string( $chunk ) || $chunk === '' || (int) $response['offset'] !== (int) $local['offset'] ) {
					return self::fail_remote( $job_id, __( 'The target received an invalid transfer chunk.', 'leadwerk-migration' ) );
				}
				$handle = @fopen( $partial, (int) $local['offset'] === 0 ? 'wb' : 'ab' );
				if ( $handle === false || fwrite( $handle, $chunk ) !== strlen( $chunk ) ) {
					if ( is_resource( $handle ) ) {
						fclose( $handle );
					}
					return self::fail_remote( $job_id, __( 'The target could not save the complete transfer chunk.', 'leadwerk-migration' ) );
				}
				fclose( $handle );
				@chmod( $partial, 0600 );
				$local['offset'] += strlen( $chunk );
				$local['total']   = (int) $response['total'];
				$local['sha256']  = strtolower( (string) $response['sha256'] );
				$state['jobs'][ $job_id ] = $local;
				self::save_state( $state );
				self::request( '/hub/jobs/' . $job_id . '/peer-progress', array( 'bytes_delivered' => $local['offset'], 'ready' => $local['offset'] >= $local['total'] ), true, 60 );
				if ( $local['offset'] >= $local['total'] ) {
					break;
				}
			}
		}

		if ( ! empty( $local['total'] ) && (int) $local['offset'] === (int) $local['total'] ) {
			self::report_import_progress( $job_id, __( 'Transfer complete. Verifying the received archive SHA-256 checksum.', 'leadwerk-migration' ), 'verifying' );
			$actual = hash_file( 'sha256', $partial );
			if ( ! is_string( $actual ) || ! hash_equals( $local['sha256'], strtolower( $actual ) ) ) {
				return self::fail_remote( $job_id, __( 'Target SHA-256 verification failed. The downloaded copy was not imported.', 'leadwerk-migration' ) );
			}
			$archive = 'leadwerk-sync-' . $job_id . '.wpress';
			$backup_path = leadwerk_migration_backup_path( array( 'archive' => $archive ) );
			if ( is_file( $backup_path ) && ! @unlink( $backup_path ) ) {
				return self::fail_remote( $job_id, __( 'A previous target sync archive could not be replaced.', 'leadwerk-migration' ) );
			}
			if ( ! @rename( $partial, $backup_path ) ) {
				return self::fail_remote( $job_id, __( 'The verified target archive could not be moved into private backup storage.', 'leadwerk-migration' ) );
			}
			$manifest = Leadwerk_Migration_Integrity::record_file( $archive, 'sync' );
			if ( is_wp_error( $manifest ) ) {
				return self::fail_remote( $job_id, $manifest->get_error_message() );
			}
			$local['archive'] = $archive;
			$local['status']  = 'preparing_import';
			$state['jobs'][ $job_id ] = $local;
			self::save_state( $state );
			self::report_import_progress( $job_id, __( 'Archive integrity verified. Preparing the private WordPress import workspace.', 'leadwerk-migration' ), 'preparing_import' );
			return self::start_verified_import( $job_id, $archive );
		}

		return array( 'job_id' => $job_id, 'role' => 'target', 'offset' => (int) $local['offset'], 'total' => isset( $local['total'] ) ? (int) $local['total'] : 0 );
	}

	private static function start_verified_import( $job_id, $archive ) {
		self::report_import_progress( $job_id, __( 'Rechecking the signed archive manifest before import.', 'leadwerk-migration' ), 'verifying' );
		$verification = Leadwerk_Migration_Integrity::verify( $archive );
		if ( is_wp_error( $verification ) ) {
			return self::fail_remote( $job_id, $verification->get_error_message() );
		}
		$source = Leadwerk_Migration_Integrity::resolve_backup_path( $archive );
		$storage = leadwerk_migration_storage_folder();
		$params = array( 'storage' => $storage, 'archive' => $archive );
		$destination = leadwerk_migration_archive_path( $params );
		self::report_import_progress( $job_id, __( 'Copying the verified archive into the private WordPress import workspace.', 'leadwerk-migration' ), 'preparing_import' );
		try {
			leadwerk_migration_copy( $source, $destination );
		} catch ( Exception $error ) {
			return self::fail_remote( $job_id, $error->getMessage() );
		}
		$copied_hash = hash_file( 'sha256', $destination );
		if ( ! is_string( $copied_hash ) || ! hash_equals( strtolower( $verification['expected_sha256'] ), strtolower( $copied_hash ) ) ) {
			return self::fail_remote( $job_id, __( 'The verified archive changed while preparing the private import workspace.', 'leadwerk-migration' ) );
		}

		$params = array(
			'storage'                                      => $storage,
			'archive'                                      => $archive,
			'priority'                                     => 10,
			'secret_key'                                   => get_option( LEADWERK_MIGRATION_SECRET_KEY, '' ),
			'leadwerk_migration_internal'                  => 1,
			'leadwerk_migration_verified_local_restore'   => 1,
			'leadwerk_migration_confirmed'                 => 1,
			'leadwerk_migration_allow_legacy_unauthenticated' => 1,
			'leadwerk_migration_sync_job_id'               => $job_id,
		);
		$state = self::state();
		$state['jobs'][ $job_id ]['status']  = 'importing';
		$state['jobs'][ $job_id ]['message'] = __( 'Starting the WordPress import pipeline.', 'leadwerk-migration' );
		$state['jobs'][ $job_id ]['import_started_at'] = time();
		unset( $state['jobs'][ $job_id ]['continuation'], $state['jobs'][ $job_id ]['continuation_saved_at'], $state['jobs'][ $job_id ]['continuation_fingerprint'], $state['jobs'][ $job_id ]['continuation_attempts'] );
		self::save_state( $state );
		self::report_import_progress( $job_id, __( 'Starting the WordPress import pipeline.', 'leadwerk-migration' ), 'importing' );

		// The asynchronous importer can terminate the current PHP request. Release
		// the worker lock first; Hub's importing state prevents a duplicate import.
		delete_option( self::LOCK_OPTION );

		try {
			return Leadwerk_Migration_Import_Controller::import( $params );
		} catch ( Throwable $error ) {
			return self::fail_remote( $job_id, $error->getMessage() );
		}
	}

	/** Replay one stale, job-bound import continuation without persisting credentials. */
	private static function resume_import_continuation( $job_id, $local ) {
		$params = isset( $local['continuation'] ) && is_array( $local['continuation'] ) ? $local['continuation'] : array();
		if ( empty( $params ) || empty( $params['storage'] ) || empty( $params['archive'] ) ) {
			return new WP_Error( 'leadwerk_sync_import_continuation_missing', __( 'The stalled import continuation could not be recovered.', 'leadwerk-migration' ) );
		}
		$params['secret_key'] = get_option( LEADWERK_MIGRATION_SECRET_KEY, '' );
		$params['leadwerk_migration_internal'] = 1;
		$response = wp_remote_request(
			add_query_arg( array( 'leadwerk_migration_import' => 1 ), admin_url( 'admin-ajax.php?action=leadwerk_migration_import' ) ),
			array( 'method' => 'POST', 'timeout' => 10, 'blocking' => false, 'sslverify' => true, 'body' => $params )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$state = self::state();
		$state['jobs'][ $job_id ]['continuation_attempts'] = 1 + (int) ( isset( $local['continuation_attempts'] ) ? $local['continuation_attempts'] : 0 );
		$state['jobs'][ $job_id ]['continuation_saved_at'] = time();
		self::save_state( $state );
		self::report_import_progress( $job_id, __( 'The stalled WordPress import continuation was re-dispatched safely.', 'leadwerk-migration' ), 'importing' );
		return array( 'job_id' => $job_id, 'role' => 'target', 'status' => 'import_continuation_replayed' );
	}

	private static function fail_remote( $job_id, $message ) {
		self::request( '/hub/jobs/' . rawurlencode( $job_id ) . '/fail', array( 'message' => sanitize_text_field( $message ) ), true );
		$state = self::state();
		$state['jobs'][ $job_id ]['status']  = 'failed';
		$state['jobs'][ $job_id ]['message'] = substr( sanitize_text_field( $message ), 0, 1000 );
		self::save_state( $state );
		return new WP_Error( 'leadwerk_sync_failed', $message );
	}

	/** Remove canceled target partials/local state after their one-hour audit window. */
	private static function cleanup_canceled_local_state( $jobs ) {
		$state = self::state();
		$changed = false;
		$cutoff = time() - HOUR_IN_SECONDS;
		foreach ( is_array( $jobs ) ? $jobs : array() as $job ) {
			if ( ! is_array( $job ) || 'canceled' !== ( isset( $job['status'] ) ? $job['status'] : '' ) || ! Leadwerk_Migration_Site_Identity::valid_id( isset( $job['job_id'] ) ? $job['job_id'] : '' ) ) {
				continue;
			}
			$job_id = $job['job_id'];
			if ( isset( $state['jobs'][ $job_id ] ) && is_array( $state['jobs'][ $job_id ] ) ) {
				$state['jobs'][ $job_id ]['status'] = 'canceled';
				if ( empty( $state['jobs'][ $job_id ]['canceled_at'] ) ) {
					$state['jobs'][ $job_id ]['canceled_at'] = isset( $job['updated_at'] ) ? $job['updated_at'] . ' UTC' : gmdate( 'c' );
				}
				$changed = true;
			}
		}
		foreach ( $state['jobs'] as $job_id => $local ) {
			if ( ! is_array( $local ) || 'target' !== ( isset( $local['role'] ) ? $local['role'] : '' ) || 'canceled' !== ( isset( $local['status'] ) ? $local['status'] : '' ) || empty( $local['canceled_at'] ) ) {
				continue;
			}
			$canceled_at = strtotime( (string) $local['canceled_at'] );
			if ( false !== $canceled_at && $canceled_at <= $cutoff ) {
				$partial = self::target_partial_path( $job_id );
				if ( is_file( $partial ) ) {
					@unlink( $partial );
				}
				unset( $state['jobs'][ $job_id ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			self::save_state( $state );
		}
	}

	/** Send one job-scoped request directly to the sibling environment. */
	private static function peer_request( $job, $payload, $timeout = 120 ) {
		$endpoint = isset( $job['peer_endpoint'] ) ? esc_url_raw( $job['peer_endpoint'] ) : '';
		$ticket = isset( $job['peer_ticket'] ) ? trim( (string) $job['peer_ticket'] ) : '';
		$parts = wp_parse_url( $endpoint );
		if ( '' === $endpoint || '' === $ticket || ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return new WP_Error( 'leadwerk_peer_endpoint_invalid', __( 'Hub did not provide a valid HTTPS peer endpoint.', 'leadwerk-migration' ) );
		}
		$body = wp_json_encode( is_array( $payload ) ? $payload : array() );
		if ( ! is_string( $body ) ) {
			return new WP_Error( 'leadwerk_peer_payload_invalid', __( 'Direct-transfer payload could not be encoded.', 'leadwerk-migration' ) );
		}
		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout'     => max( 10, min( 180, (int) $timeout ) ),
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Leadwerk-Peer-Ticket' => $ticket ),
				'body'        => $body,
				'data_format' => 'body',
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'leadwerk_peer_unreachable', sprintf( __( 'Direct family connection failed: %s', 'leadwerk-migration' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : sprintf( __( 'Direct family endpoint returned HTTP %d.', 'leadwerk-migration' ), $code );
			return new WP_Error( 'leadwerk_peer_error', $message, array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/** Signed JSON request. */
	public static function request( $route, $payload, $signed = true, $timeout = 30 ) {
		$hub = Leadwerk_Migration_Site_Identity::hub_url();
		if ( $hub === '' ) {
			return new WP_Error( 'leadwerk_sync_hub_missing', __( 'Configure the Leadwerk Hub URL first.', 'leadwerk-migration' ) );
		}
		$body = wp_json_encode( is_array( $payload ) ? $payload : array() );
		if ( ! is_string( $body ) ) {
			return new WP_Error( 'leadwerk_sync_payload_failed', __( 'The Hub request could not be encoded.', 'leadwerk-migration' ) );
		}
		$headers = array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' );
		if ( $signed ) {
			$environment = Leadwerk_Migration_Site_Identity::ensure_environment();
			$timestamp = time();
			$nonce = Leadwerk_Migration_Site_Identity::random_id();
			$api_route = '/' . Leadwerk_Migration_Sync_Hub::NAMESPACE . $route;
			$canonical = Leadwerk_Migration_Sync_Hub::canonical_request( 'POST', $api_route, $timestamp, $nonce, $body );
			$headers['X-Leadwerk-Environment'] = $environment['environment_id'];
			$headers['X-Leadwerk-Timestamp']   = (string) $timestamp;
			$headers['X-Leadwerk-Nonce']       = $nonce;
			$headers['X-Leadwerk-Signature']   = hash_hmac( 'sha256', $canonical, $environment['environment_secret'] );
		} elseif ( '/hub/register' === $route ) {
			$enrollment_key = self::enrollment_key();
			if ( '' !== $enrollment_key ) {
				$timestamp = time();
				$nonce = Leadwerk_Migration_Site_Identity::random_id();
				$api_route = '/' . Leadwerk_Migration_Sync_Hub::NAMESPACE . $route;
				$canonical = "leadwerk-family-enrollment-v1\n" . Leadwerk_Migration_Sync_Hub::canonical_request( 'POST', $api_route, $timestamp, $nonce, $body );
				$headers['X-Leadwerk-Enrollment-Timestamp'] = (string) $timestamp;
				$headers['X-Leadwerk-Enrollment-Nonce'] = $nonce;
				$headers['X-Leadwerk-Enrollment-Signature'] = hash_hmac( 'sha256', $canonical, $enrollment_key );
			}
		}

		$url = trailingslashit( $hub ) . 'wp-json/' . Leadwerk_Migration_Sync_Hub::NAMESPACE . $route;
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => max( 10, min( 180, (int) $timeout ) ),
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => $headers,
				'body'        => $body,
				'data_format' => 'body',
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'leadwerk_sync_hub_unreachable', sprintf( __( 'Leadwerk Hub could not be reached: %s', 'leadwerk-migration' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : sprintf( __( 'Leadwerk Hub returned HTTP %d.', 'leadwerk-migration' ), $code );
			return new WP_Error( 'leadwerk_sync_hub_error', $message, array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/** Enrollment admission key supplied outside the distributable plugin. */
	private static function enrollment_key() {
		$key = defined( 'LEADWERK_MIGRATION_ENROLLMENT_KEY' ) ? LEADWERK_MIGRATION_ENROLLMENT_KEY : getenv( 'LEADWERK_MIGRATION_ENROLLMENT_KEY' );
		return is_string( $key ) && strlen( $key ) >= 32 && strlen( $key ) <= 512 ? $key : '';
	}

	public static function enrollment_key_configured() {
		return '' !== self::enrollment_key();
	}

	private static function environment_payload( $family, $environment ) {
		return array(
			'family_id'          => isset( $family['family_id'] ) ? $family['family_id'] : '',
			'project_name'       => isset( $family['project_name'] ) ? $family['project_name'] : '',
			'environment_id'     => isset( $environment['environment_id'] ) ? $environment['environment_id'] : '',
			'site_url'           => Leadwerk_Migration_Site_Identity::normalized_site_url(),
			'environment_type'   => isset( $environment['type'] ) ? $environment['type'] : 'live',
			'fingerprint'        => isset( $environment['fingerprint'] ) ? $environment['fingerprint'] : '',
			'wordpress_version'  => get_bloginfo( 'version' ),
			'php_version'        => PHP_VERSION,
			'plugin_version'     => LEADWERK_MIGRATION_VERSION,
		);
	}

	private static function find_environment( $directory, $id ) {
		foreach ( $directory as $environment ) {
			if ( is_array( $environment ) && isset( $environment['environment_id'] ) && hash_equals( (string) $environment['environment_id'], (string) $id ) ) {
				return $environment;
			}
		}
		return null;
	}

	private static function target_partial_path( $job_id ) {
		$directory = LEADWERK_MIGRATION_PRIVATE_ROOT . DIRECTORY_SEPARATOR . 'leadwerk-sync-target-' . substr( hash( 'sha256', ABSPATH . wp_salt( 'secure_auth' ) ), 0, 12 );
		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
			@chmod( $directory, 0700 );
		}
		return $directory . DIRECTORY_SEPARATOR . $job_id . '.part';
	}

	private static function state() {
		$state = get_option( LEADWERK_MIGRATION_SYNC_STATE, array() );
		$state = is_array( $state ) ? $state : array();
		$state['jobs'] = isset( $state['jobs'] ) && is_array( $state['jobs'] ) ? $state['jobs'] : array();
		return $state;
	}

	private static function save_state( $state ) {
		return update_option( LEADWERK_MIGRATION_SYNC_STATE, $state, false );
	}

	private static function acquire_lock() {
		$token = Leadwerk_Migration_Site_Identity::random_id();
		$lock = array( 'token' => $token, 'expires_at' => time() + self::LOCK_TTL );
		if ( add_option( self::LOCK_OPTION, $lock, '', false ) ) {
			return $token;
		}
		$existing = get_option( self::LOCK_OPTION, array() );
		if ( ! is_array( $existing ) || empty( $existing['expires_at'] ) || (int) $existing['expires_at'] < time() ) {
			delete_option( self::LOCK_OPTION );
			if ( add_option( self::LOCK_OPTION, $lock, '', false ) ) {
				return $token;
			}
		}
		return new WP_Error( 'leadwerk_sync_locked', __( 'Another Site Sync worker is already running.', 'leadwerk-migration' ) );
	}

	private static function release_lock( $token ) {
		$lock = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $lock ) && isset( $lock['token'] ) && is_string( $lock['token'] ) && hash_equals( $lock['token'], $token ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}
}
