<?php
/**
 * Leadwerk Migration security helpers.
 *
 * Copyright (C) 2026 Leadwerk.
 * Licensed under GPLv3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access is not allowed.' );
}

class Leadwerk_Migration_Security {

	const REST_TOKEN_SCOPE_IMPORT_READ = 'import_read';
	const REST_TOKEN_PARAM             = 'leadwerk_migration_rest_token_record';
	const REST_TOKEN_TTL               = 21600;
	const PIPELINE_TOKEN_PARAM         = 'leadwerk_migration_continuation_token';
	const PIPELINE_TOKEN_TTL           = 21600;

	public static function generate_secret() {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( Exception $e ) {
			return hash( 'sha256', wp_generate_password( 64, true, true ) . microtime( true ) . wp_rand() );
		}
	}

	public static function verify_secret( $provided ) {
		$expected = get_option( LEADWERK_MIGRATION_SECRET_KEY, '' );
		if ( ! is_string( $provided ) || ! is_string( $expected ) || $provided === '' || $expected === '' ) {
			return false;
		}

		return hash_equals( $expected, $provided );
	}

	/**
	 * Derive a purpose-bound secret from the site-wide pipeline master key.
	 *
	 * @param  string $scope One of export, import, backups, or status
	 * @return string 64-character lowercase hexadecimal secret, or an empty
	 *                string when the scope/master key is unavailable
	 */
	public static function scoped_secret( $scope ) {
		if ( ! self::valid_secret_scope( $scope ) ) {
			return '';
		}

		$master = get_option( LEADWERK_MIGRATION_SECRET_KEY, '' );
		if ( ! is_string( $master ) || $master === '' ) {
			return '';
		}

		return hash_hmac( 'sha256', 'leadwerk-migration:scoped-secret:v1:' . $scope, $master );
	}

	/**
	 * Timing-safe verification for a purpose-bound secret.
	 *
	 * @param  mixed  $provided Candidate secret
	 * @param  string $scope    Required scope
	 * @return boolean
	 */
	public static function verify_scoped_secret( $provided, $scope ) {
		$expected = self::scoped_secret( $scope );
		if ( ! is_string( $provided ) || $provided === '' || $expected === '' || strlen( $provided ) !== strlen( $expected ) ) {
			return false;
		}

		return hash_equals( $expected, $provided );
	}

	public static function authorize_pipeline( $operation, $params ) {
		if ( ! in_array( $operation, array( 'export', 'import' ), true ) || ! is_array( $params ) ) {
			return false;
		}

		$priority    = isset( $params['priority'] ) ? (int) $params['priority'] : 5;
		$provided    = isset( $params['secret_key'] ) ? $params['secret_key'] : '';
		$is_internal = ! empty( $params['leadwerk_migration_internal'] );
		$capability  = $operation === 'import' ? 'leadwerk_migration_import_site' : 'manage_options';


		if ( $is_internal ) {
			// Trusted scheduler, CLI and REST pipelines use the master credential.
			$authorized = self::verify_secret( $provided );
			if ( $authorized && $operation === 'import' && isset( $params[ self::REST_TOKEN_PARAM ] ) ) {
				// Import replaces wp_options. Rehydrate the hashed, expiring token
				// record from authenticated pipeline state after that replacement so
				// the initiating client can keep reading this job's status and log.
				self::remember_job_token_record( $params[ self::REST_TOKEN_PARAM ], isset( $params['storage'] ) ? $params['storage'] : '' );
			}


			return $authorized;
		}

		$nonce = isset( $params['_leadwerk_migration_nonce'] ) ? $params['_leadwerk_migration_nonce'] : '';
		$logged_in = function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : false;
		$can_cap   = current_user_can( $capability );
		$nonce_ok  = is_string( $nonce ) && $nonce !== '' && wp_verify_nonce( $nonce, 'leadwerk_migration_' . $operation );
		$rest_req  = ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		$user_authorized = $can_cap && ( $rest_req || $nonce_ok );


		if ( $user_authorized ) {
			// Browser imports use priority 5 for the multipart upload request, then
			// begin the archive pipeline at priority 10 after that upload succeeds.
			// Neither request has a prior continuation ticket, so both are explicit
			// authenticated entry points. Exports have only the priority-5 entry.
			$initial_priorities = $operation === 'import' ? array( 5, 10 ) : array( 5 );
			if ( in_array( $priority, $initial_priorities, true ) ) {
				return true;
			}

			// Ordinary browser continuations must retain the exact signed state
			// returned by the preceding pipeline stage. A prompt intentionally
			// starts a fresh request, so only narrowly defined, job-scoped resume
			// actions are permitted without a continuation ticket.
			$ticket     = isset( $params[ self::PIPELINE_TOKEN_PARAM ] ) ? $params[ self::PIPELINE_TOKEN_PARAM ] : '';
			$ticket_ok  = self::verify_pipeline_token( $operation, $params, $ticket );
			if ( $ticket_ok ) {
				return true;
			}

			$resume = self::authorize_interactive_resume( $operation, $params, $priority );
			return $resume;
		}

		// Server-to-server continuations have no browser cookie. Require both the
		// operation-scoped credential and a short-lived ticket bound to this job.
		if ( $priority > 5 ) {
			$ticket = isset( $params[ self::PIPELINE_TOKEN_PARAM ] ) ? $params[ self::PIPELINE_TOKEN_PARAM ] : '';
			$ok     = self::verify_scoped_secret( $provided, $operation ) && self::verify_pipeline_token( $operation, $params, $ticket );
			return $ok;
		}


		return false;
	}

	/**
	 * Authorize a browser action that intentionally resumes without the prior
	 * continuation ticket (confirm, decrypt, multisite selection, or cancel).
	 *
	 * The current job status is the server-side challenge. It binds the action
	 * to an existing job and, for imports, to the exact archive shown to the
	 * user. This prevents a valid admin nonce from being used to jump directly
	 * into an arbitrary destructive pipeline priority.
	 *
	 * @param string  $operation export or import.
	 * @param array   $params Pipeline parameters.
	 * @param integer $priority Requested pipeline priority.
	 * @return boolean
	 */
	private static function authorize_interactive_resume( $operation, $params, $priority ) {
		$job_id = isset( $params['storage'] ) && is_string( $params['storage'] ) ? $params['storage'] : '';
		if ( ! preg_match( '/\A[A-Za-z0-9_-]{8,64}\z/D', $job_id ) ) {
			return false;
		}

		$status      = get_option( 'leadwerk_migration_status_' . $job_id, array() );
		$has_status  = is_array( $status ) && ! empty( $status['type'] ) && is_string( $status['type'] );
		$status_type = $has_status ? $status['type'] : '';
		$terminal    = array( 'done', 'error', 'canceled', 'download' );

		// Early cancel: the browser may stop before the first status write.
		// Late cancel: refuse terminal jobs, and bind imports to the archive
		// already shown when that name is known.
		if ( $operation === 'export' && $priority === 300 && ! empty( $params['leadwerk_migration_export_cancel'] ) ) {
			return ! $has_status || ! in_array( $status_type, $terminal, true );
		}

		if ( $operation === 'import' && $priority === 400 && ! empty( $params['leadwerk_migration_import_cancel'] ) ) {
			if ( $has_status && in_array( $status_type, $terminal, true ) ) {
				return false;
			}

			$status_archive  = ( $has_status && isset( $status['archive'] ) && is_string( $status['archive'] ) ) ? $status['archive'] : '';
			$request_archive = isset( $params['archive'] ) && is_string( $params['archive'] ) ? $params['archive'] : '';
			if ( $status_archive !== '' && ( $request_archive === '' || ! hash_equals( $status_archive, $request_archive ) ) ) {
				return false;
			}

			return true;
		}

		if ( ! $has_status ) {
			return false;
		}

		$status_archive  = isset( $status['archive'] ) && is_string( $status['archive'] ) ? $status['archive'] : '';
		$request_archive = isset( $params['archive'] ) && is_string( $params['archive'] ) ? $params['archive'] : '';
		if ( $status_archive !== '' && ( $request_archive === '' || ! hash_equals( $status_archive, $request_archive ) ) ) {
			return false;
		}

		// Interactive import resumes must always be bound to the archive that
		// produced the prompt. Cancellation remains available for early errors
		// whose status may have been written before the archive name was known.
		if ( $status_archive === '' || $request_archive === '' ) {
			return false;
		}

		if ( $priority === 75 ) {
			return $status_type === 'backup_is_encrypted';
		}

		if ( $priority === 150 && in_array( $status_type, array( 'confirm', 'blogs' ), true ) ) {
			if ( $status_type === 'confirm' && ! empty( $status['legacy_unauthenticated'] ) ) {
				return ! empty( $params['leadwerk_migration_allow_legacy_unauthenticated'] );
			}

			return true;
		}

		return false;
	}

	/**
	 * Issue a signed, short-lived continuation ticket bound to one pipeline job.
	 * The ticket is carried in POST bodies only; it is not stored in options or logs.
	 *
	 * @param string $operation export or import.
	 * @param array  $params Pipeline parameters.
	 * @return string|false
	 */
	public static function issue_pipeline_token( $operation, $params ) {
		if ( ! in_array( $operation, array( 'export', 'import' ), true ) || ! is_array( $params ) ) {
			return false;
		}

		$job_id = isset( $params['storage'] ) && is_string( $params['storage'] ) ? $params['storage'] : '';
		if ( ! preg_match( '/\A[A-Za-z0-9_-]{8,64}\z/D', $job_id ) ) {
			return false;
		}

		$master = get_option( LEADWERK_MIGRATION_SECRET_KEY, '' );
		if ( ! is_string( $master ) || $master === '' ) {
			return false;
		}

		$now     = time();
		$archive = $operation === 'import' && isset( $params['archive'] ) && is_string( $params['archive'] ) ? $params['archive'] : '';
		$state   = self::pipeline_state_digest( $params, $master );
		if ( $state === false ) {
			return false;
		}
		$claims  = array(
			'v'       => 1,
			'op'      => $operation,
			'job'     => $job_id,
			'archive' => hash( 'sha256', $archive ),
			'restore' => empty( $params['leadwerk_migration_manual_restore'] ) ? 0 : 1,
			'priority' => isset( $params['priority'] ) && is_scalar( $params['priority'] ) ? (int) $params['priority'] : 5,
			'state'   => $state,
			'owner'   => get_current_user_id(),
			'iat'     => $now,
			'exp'     => $now + self::PIPELINE_TOKEN_TTL,
			'nonce'   => substr( self::generate_secret(), 0, 32 ),
		);

		$payload = self::base64url_encode( wp_json_encode( $claims ) );
		if ( $payload === '' ) {
			return false;
		}

		$signature = hash_hmac( 'sha256', 'leadwerk-migration:pipeline-ticket:v1:' . $payload, $master );
		return $payload . '.' . $signature;
	}

	/**
	 * Verify a continuation ticket and its immutable job/source bindings.
	 *
	 * @param string $operation export or import.
	 * @param array  $params Pipeline parameters.
	 * @param mixed  $ticket Candidate ticket.
	 * @return boolean
	 */
	public static function verify_pipeline_token( $operation, $params, $ticket ) {
		if ( ! is_string( $operation ) || ! in_array( $operation, array( 'export', 'import' ), true ) || ! is_array( $params ) || ! is_string( $ticket ) || ! preg_match( '/\A([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D', $ticket, $matches ) ) {
			return false;
		}

		$master = get_option( LEADWERK_MIGRATION_SECRET_KEY, '' );
		if ( ! is_string( $master ) || $master === '' ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', 'leadwerk-migration:pipeline-ticket:v1:' . $matches[1], $master );
		if ( ! hash_equals( $expected, $matches[2] ) ) {
			return false;
		}

		$decoded = self::base64url_decode( $matches[1] );
		$claims  = json_decode( $decoded, true );
		if (
			! is_array( $claims ) ||
			! isset( $claims['v'], $claims['op'], $claims['job'], $claims['archive'], $claims['restore'], $claims['priority'], $claims['state'], $claims['iat'], $claims['exp'], $claims['nonce'] ) ||
			(int) $claims['v'] !== 1 ||
			! is_string( $claims['op'] ) ||
			! hash_equals( $operation, $claims['op'] ) ||
			! is_string( $claims['job'] ) ||
			! preg_match( '/\A[A-Za-z0-9_-]{8,64}\z/D', $claims['job'] ) ||
			! is_string( $claims['archive'] ) ||
			! preg_match( '/\A[a-f0-9]{64}\z/D', $claims['archive'] ) ||
			! is_numeric( $claims['priority'] ) ||
			! is_string( $claims['state'] ) ||
			! preg_match( '/\A[a-f0-9]{64}\z/D', $claims['state'] ) ||
			! is_string( $claims['nonce'] ) ||
			! preg_match( '/\A[a-f0-9]{32}\z/D', $claims['nonce'] ) ||
			! is_numeric( $claims['iat'] ) ||
			! is_numeric( $claims['exp'] ) ||
			(int) $claims['iat'] > ( time() + 300 ) ||
			(int) $claims['exp'] <= time() ||
			(int) $claims['exp'] > ( (int) $claims['iat'] + DAY_IN_SECONDS )
		) {
			return false;
		}

		$job_id  = isset( $params['storage'] ) && is_string( $params['storage'] ) ? $params['storage'] : '';
		$archive = $operation === 'import' && isset( $params['archive'] ) && is_string( $params['archive'] ) ? $params['archive'] : '';
		$restore = empty( $params['leadwerk_migration_manual_restore'] ) ? 0 : 1;
		$priority = isset( $params['priority'] ) && is_scalar( $params['priority'] ) ? (int) $params['priority'] : 5;
		$state    = self::pipeline_state_digest( $params, $master );

		return hash_equals( $claims['job'], $job_id )
			&& hash_equals( $claims['archive'], hash( 'sha256', $archive ) )
			&& (int) $claims['restore'] === $restore
			&& (int) $claims['priority'] === $priority
			&& is_string( $state )
			&& hash_equals( $claims['state'], $state );
	}

	public static function authorize_admin_action( $capability, $nonce_action = '', $nonce = '' ) {
		if ( ! is_user_logged_in() || ! current_user_can( $capability ) ) {
			return false;
		}

		if ( $nonce_action === '' ) {
			return true;
		}

		return is_string( $nonce ) && wp_verify_nonce( $nonce, $nonce_action );
	}

	/**
	 * Return true only when a filesystem path is outside known web roots.
	 * Both lexical and resolved paths are checked to catch existing symlinks.
	 *
	 * @param mixed $path Candidate directory.
	 * @return boolean
	 */
	public static function path_is_private( $path ) {
		if ( ! is_string( $path ) || trim( $path ) === '' ) {
			return false;
		}
		$path = rtrim( trim( $path ), "/\\" );
		if ( $path === '' || ! preg_match( '#^(?:/|[A-Za-z]:[\\\\/]|\\\\\\\\)#', $path ) ) {
			return false;
		}

		$normalize = function ( $candidate ) {
			$candidate = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $candidate ) : str_replace( '\\', '/', $candidate );
			$candidate = rtrim( $candidate, '/' );
			if ( DIRECTORY_SEPARATOR === '\\' ) {
				$candidate = strtolower( $candidate );
			}
			return $candidate === '' ? '/' : $candidate;
		};

		$candidates = array( $normalize( $path ) );
		$resolved   = realpath( $path );
		if ( $resolved === false ) {
			$resolved_parent = realpath( dirname( $path ) );
			if ( $resolved_parent === false ) {
				return false;
			}
			$resolved = $resolved_parent . DIRECTORY_SEPARATOR . basename( $path );
		}
		$candidates[] = $normalize( $resolved );

		$roots = array( ABSPATH );
		if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) && $_SERVER['DOCUMENT_ROOT'] !== '' ) {
			$roots[] = $_SERVER['DOCUMENT_ROOT'];
		} elseif ( defined( 'LEADWERK_MIGRATION_VALIDATED_DOCUMENT_ROOT' ) && is_string( LEADWERK_MIGRATION_VALIDATED_DOCUMENT_ROOT ) && LEADWERK_MIGRATION_VALIDATED_DOCUMENT_ROOT !== '' ) {
			// Reuse the document root recorded when the automatic private root was
			// validated by an HTTP request. This keeps WP-CLI/system-cron on the
			// same private volume without trusting an unverified ABSPATH parent.
			$roots[] = LEADWERK_MIGRATION_VALIDATED_DOCUMENT_ROOT;
		} else {
			// Without web-server context (for example WP-CLI), the WordPress
			// parent cannot be proven non-public. Treat it as a web root.
			$roots[] = dirname( rtrim( ABSPATH, "/\\" ) );
		}

		foreach ( $roots as $root ) {
			$root_resolved = realpath( $root );
			$root          = $normalize( $root_resolved !== false ? $root_resolved : $root );
			foreach ( $candidates as $candidate ) {
				if ( $root === '/' || $candidate === $root || strpos( $candidate . '/', $root . '/' ) === 0 ) {
					return false;
				}
			}
		}

		return true;
	}

	public static function job_id() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			return strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
		}
	}

	/**
	 * Issue a high-entropy, single-job REST polling token.
	 *
	 * Only the keyed digest is persisted. The caller must return the raw token
	 * once and must not put it in pipeline parameters, options, or logs.
	 *
	 * @param  string  $job_id   Job identifier
	 * @param  string  $scope    Token scope
	 * @param  integer $owner_id User that initiated the job
	 * @param  integer $ttl      Lifetime in seconds
	 * @return array<string, mixed>|false
	 */
	public static function issue_job_token( $job_id, $scope, $owner_id = 0, $ttl = self::REST_TOKEN_TTL ) {
		if ( ! self::valid_job_id( $job_id ) || ! self::valid_job_token_scope( $scope ) ) {
			return false;
		}

		$ttl = (int) apply_filters( 'leadwerk_migration_rest_job_token_ttl', $ttl, $job_id, $scope );
		$ttl = max( 300, min( DAY_IN_SECONDS, $ttl ) );

		try {
			$token = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $e ) {
			$token = hash( 'sha256', wp_generate_password( 64, true, true ) . microtime( true ) . wp_rand() );
		}
		if ( self::verify_secret( $token ) ) {
			$token = hash( 'sha256', $token . '|rest-job-token|' . $job_id . '|' . microtime( true ) );
		}

		$now    = time();
		$record = array(
			'version'    => 1,
			'job_id'     => $job_id,
			'scope'      => $scope,
			'token_hash' => self::job_token_digest( $token ),
			'owner_id'   => max( 0, (int) $owner_id ),
			'issued_at'  => $now,
			'expires_at' => $now + $ttl,
		);

		if ( ! set_transient( self::job_token_key( $job_id, $scope ), $record, $ttl ) ) {
			return false;
		}

		return array(
			'token'      => $token,
			'expires_at' => $record['expires_at'],
			'record'     => $record,
		);
	}

	/**
	 * Verify a raw token against its job-and-scope-bound digest.
	 *
	 * @param  string $job_id Job identifier
	 * @param  string $scope  Token scope
	 * @param  string $token  Raw token supplied by the client
	 * @return boolean
	 */
	public static function verify_job_token( $job_id, $scope, $token ) {
		if ( ! self::valid_job_id( $job_id ) || ! self::valid_job_token_scope( $scope ) || ! is_string( $token ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $token ) ) {
			return false;
		}

		$record = self::get_job_token_record( $job_id, $scope );
		if ( $record === false || (int) $record['expires_at'] <= time() ) {
			self::revoke_job_token( $job_id, $scope );
			return false;
		}

		return hash_equals( $record['token_hash'], self::job_token_digest( $token ) );
	}

	/**
	 * Return the safe-to-propagate token record (never the raw token).
	 *
	 * @param  string $job_id Job identifier
	 * @param  string $scope  Token scope
	 * @return array<string, mixed>|false
	 */
	public static function get_job_token_record( $job_id, $scope ) {
		if ( ! self::valid_job_id( $job_id ) || ! self::valid_job_token_scope( $scope ) ) {
			return false;
		}

		$record = get_transient( self::job_token_key( $job_id, $scope ) );
		if ( ! self::valid_job_token_record( $record, $job_id, $scope ) ) {
			return false;
		}

		return $record;
	}

	/**
	 * Restore a hashed token record carried by an authenticated import loopback.
	 *
	 * @param  mixed  $record         Candidate token record
	 * @param  string $expected_job_id Expected pipeline job identifier
	 * @return boolean
	 */
	public static function remember_job_token_record( $record, $expected_job_id ) {
		if ( ! is_array( $record ) || ! isset( $record['scope'] ) || ! self::valid_job_token_record( $record, $expected_job_id, $record['scope'] ) ) {
			return false;
		}

		$ttl = (int) $record['expires_at'] - time();
		if ( $ttl <= 0 ) {
			return false;
		}

		return (bool) set_transient( self::job_token_key( $expected_job_id, $record['scope'] ), $record, $ttl );
	}

	/**
	 * Revoke a job polling token.
	 *
	 * @param  string $job_id Job identifier
	 * @param  string $scope  Token scope
	 * @return void
	 */
	public static function revoke_job_token( $job_id, $scope ) {
		if ( self::valid_job_id( $job_id ) && self::valid_job_token_scope( $scope ) ) {
			delete_transient( self::job_token_key( $job_id, $scope ) );
		}
	}

	/**
	 * @param  mixed $job_id Job identifier
	 * @return boolean
	 */
	public static function valid_job_id( $job_id ) {
		return is_string( $job_id ) && (bool) preg_match( '/\A[a-f0-9]{32}\z/D', $job_id );
	}

	/**
	 * @param  mixed  $record Candidate record
	 * @param  string $job_id Expected job identifier
	 * @param  string $scope  Expected scope
	 * @return boolean
	 */
	private static function valid_job_token_record( $record, $job_id, $scope ) {
		return self::valid_job_id( $job_id )
			&& self::valid_job_token_scope( $scope )
			&& is_array( $record )
			&& isset( $record['version'], $record['job_id'], $record['scope'], $record['token_hash'], $record['owner_id'], $record['issued_at'], $record['expires_at'] )
			&& (int) $record['version'] === 1
			&& is_string( $record['job_id'] )
			&& hash_equals( $job_id, $record['job_id'] )
			&& is_string( $record['scope'] )
			&& hash_equals( $scope, $record['scope'] )
			&& is_string( $record['token_hash'] )
			&& (bool) preg_match( '/\A[a-f0-9]{64}\z/D', $record['token_hash'] )
			&& is_numeric( $record['owner_id'] )
			&& is_numeric( $record['issued_at'] )
			&& is_numeric( $record['expires_at'] )
			&& (int) $record['issued_at'] > 0
			&& (int) $record['issued_at'] <= ( time() + 300 )
			&& (int) $record['issued_at'] <= (int) $record['expires_at']
			&& (int) $record['expires_at'] > time()
			&& (int) $record['expires_at'] <= ( time() + DAY_IN_SECONDS );
	}

	/**
	 * @param  mixed $scope Token scope
	 * @return boolean
	 */
	private static function valid_job_token_scope( $scope ) {
		return is_string( $scope ) && in_array( $scope, array( self::REST_TOKEN_SCOPE_IMPORT_READ ), true );
	}

	/**
	 * @param  mixed $scope Pipeline/admin secret scope
	 * @return boolean
	 */
	private static function valid_secret_scope( $scope ) {
		return is_string( $scope ) && in_array( $scope, array( 'export', 'import', 'backups', 'status' ), true );
	}

	/**
	 * @param  string $job_id Job identifier
	 * @param  string $scope  Token scope
	 * @return string
	 */
	private static function job_token_key( $job_id, $scope ) {
		return 'leadwerk_migration_jt_' . hash( 'sha256', $job_id . '|' . $scope );
	}

	/**
	 * @param  string $token Raw token
	 * @return string
	 */
	private static function job_token_digest( $token ) {
		$master = get_option( LEADWERK_MIGRATION_SECRET_KEY, '' );
		if ( is_string( $master ) && $master !== '' ) {
			$key = hash_hmac( 'sha256', 'leadwerk-migration:rest-job-token-digest:v1', $master );
		} else {
			$key = wp_salt( 'auth' );
		}

		return hash_hmac( 'sha256', $token, $key );
	}

	/**
	 * Create a type-stable digest of every continuation parameter except the
	 * transport credential and the ticket itself. This binds confirmations,
	 * offsets, priority and every other pipeline state value without exposing
	 * passwords or other parameter contents in the ticket payload.
	 *
	 * @param array  $params Pipeline parameters.
	 * @param string $master Site master key.
	 * @return string|false
	 */
	private static function pipeline_state_digest( $params, $master ) {
		if ( ! is_array( $params ) || ! is_string( $master ) || $master === '' ) {
			return false;
		}

		unset( $params['secret_key'], $params[ self::PIPELINE_TOKEN_PARAM ] );

		// Volatile file-archiving progress fields: these values change on every
		// continuation chunk and are already implicitly bound to the job by the
		// ticket's job, priority and archive claims.  Including them in the
		// state digest causes a mismatch when null/boolean values round-trip
		// through JSON → jQuery $.param() → PHP $_POST (null becomes the
		// string "null" or empty string). The completed flag remains bound because
		// normalize_pipeline_state() safely normalizes browser boolean strings and
		// changing completion state must invalidate an existing continuation ticket.
		$volatile_keys = array(
			'archive_bytes_offset',
			'file_bytes_offset',
			'file_bytes_written',
			'content_bytes_offset',
			'media_bytes_offset',
			'plugins_bytes_offset',
			'themes_bytes_offset',
			'processed_files_size',
			'file_crc',
			'archive_crc_value',
			'archive_bytes_read',
			'file_bytes_read',
			'total_content_files_count',
			'total_content_files_size',
			'total_media_files_count',
			'total_media_files_size',
			'total_plugins_files_count',
			'total_plugins_files_size',
			'total_themes_files_count',
			'total_themes_files_size',
			'total_tables_count',
		);
		foreach ( $volatile_keys as $vk ) {
			unset( $params[ $vk ] );
		}

		$valid      = true;
		$normalized = self::normalize_pipeline_state( $params, $valid );
		if ( ! $valid ) {
			return false;
		}
		if ( $normalized === null ) {
			$normalized = array();
		}

		$encoded = wp_json_encode( $normalized );
		if ( ! is_string( $encoded ) ) {
			return false;
		}

		$key = hash_hmac( 'sha256', 'leadwerk-migration:pipeline-state:v1', $master );
		return hash_hmac( 'sha256', $encoded, $key );
	}

	/**
	 * Normalize request data so a POST round trip does not change its digest.
	 *
	 * Nulls and recursively empty arrays are omitted because PHP form encoding
	 * drops them before the next request reaches $_POST.
	 *
	 * Browser continuations JSON-encode pipeline params and jQuery re-posts them.
	 * Native PHP booleans become the strings "true"/"false" in that round trip, so
	 * those spellings are coerced to the same "1"/"0" tokens used for real booleans.
	 *
	 * @param mixed   $value State value.
	 * @param boolean $valid Set false when an unsupported value is encountered.
	 * @return mixed|null
	 */
	private static function normalize_pipeline_state( $value, &$valid ) {
		if ( $value === null ) {
			return null;
		}

		if ( is_array( $value ) ) {
			$normalized = array();
			$keys       = array_keys( $value );
			usort(
				$keys,
				function ( $left, $right ) {
					return strcmp( (string) $left, (string) $right );
				}
			);

			foreach ( $keys as $key ) {
				$item = self::normalize_pipeline_state( $value[ $key ], $valid );
				if ( ! $valid ) {
					return null;
				}
				if ( $item === null ) {
					continue;
				}
				$normalized[ (string) $key ] = $item;
			}

			return $normalized === array() ? null : $normalized;
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( is_scalar( $value ) ) {
			$string = (string) $value;
			$lower  = strtolower( $string );
			if ( in_array( $lower, array( 'true', 'on', 'yes' ), true ) ) {
				return '1';
			}
			if ( in_array( $lower, array( 'false', 'off', 'no' ), true ) ) {
				return '0';
			}

			return $string;
		}

		$valid = false;
		return null;
	}

	private static function base64url_encode( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private static function base64url_decode( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$padding = strlen( $value ) % 4;
		if ( $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}

		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true );
		return is_string( $decoded ) ? $decoded : '';
	}
}
