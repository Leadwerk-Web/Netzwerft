<?php
/**
 * Copyright (C) 2014-2025 ServMask Inc.
 * Modifications Copyright (C) 2026 Leadwerk.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Upstream attribution: This file derives from the All-in-One WP Migration plugin, developed by
 *
 * ███████╗███████╗██████╗ ██╗   ██╗███╗   ███╗ █████╗ ███████╗██╗  ██╗
 * ██╔════╝██╔════╝██╔══██╗██║   ██║████╗ ████║██╔══██╗██╔════╝██║ ██╔╝
 * ███████╗█████╗  ██████╔╝██║   ██║██╔████╔██║███████║███████╗█████╔╝
 * ╚════██║██╔══╝  ██╔══██╗╚██╗ ██╔╝██║╚██╔╝██║██╔══██║╚════██║██╔═██╗
 * ███████║███████╗██║  ██║ ╚████╔╝ ██║ ╚═╝ ██║██║  ██║███████║██║  ██╗
 * ╚══════╝╚══════╝╚═╝  ╚═╝  ╚═══╝  ╚═╝     ╚═╝╚═╝  ╚═╝╚══════╝╚═╝  ╚═╝
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Leadwerk_Migration_Rest_Controller {

	const REST_NAMESPACE = 'leadwerk/v1';

	/**
	 * Register all REST API routes
	 *
	 * @return void
	 */
	public static function register_routes() {
		$job_id_path = '(?P<job_id>[a-f0-9]{32})';
		$name_path   = '(?P<name>[a-zA-Z0-9][a-zA-Z0-9._-]{0,199}\.wpress)';

		$job_id_arg = array(
			'job_id' => array(
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => array( __CLASS__, 'validate_job_id' ),
			),
		);

		$name_arg = array(
			'name' => array(
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => array( __CLASS__, 'validate_backup_name' ),
			),
		);

		$poll_token_arg = array(
			'poll_token' => array(
				'type'        => 'string',
				'required'    => false,
				'description' => 'Short-lived token scoped to this import job. Prefer the X-Leadwerk-Job-Token header.',
				'validate_callback' => array( __CLASS__, 'validate_poll_token' ),
			),
		);

		$password_arg = array(
			'password' => array(
				'type'              => 'string',
				'required'          => false,
				'description'       => 'Archive password. For exports: enables encryption. For imports: used to decrypt an encrypted archive.',
				'validate_callback' => array( __CLASS__, 'validate_password' ),
			),
		);

		// Discovery
		register_rest_route(
			self::REST_NAMESPACE,
			'/capabilities',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'capabilities' ),
				'permission_callback' => array( __CLASS__, 'can_export' ),
			)
		);

		// Export operations
		register_rest_route(
			self::REST_NAMESPACE,
			'/exports',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_export' ),
				'permission_callback' => array( __CLASS__, 'can_export' ),
				'args'                => array_merge(
					$password_arg,
					array(
						'options'      => array(
							'type'              => 'object',
							'required'          => false,
							'validate_callback' => array( __CLASS__, 'validate_export_options' ),
						),
						'find_replace' => array(
							'type'              => 'array',
							'required'          => false,
							'validate_callback' => array( __CLASS__, 'validate_find_replace' ),
						),
					)
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/exports/' . $job_id_path,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_export_status' ),
				'permission_callback' => array( __CLASS__, 'can_poll_export' ),
				'args'                => $job_id_arg,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/exports/' . $job_id_path . '/log',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_export_log' ),
				'permission_callback' => array( __CLASS__, 'can_poll_export' ),
				'args'                => $job_id_arg,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/exports/' . $job_id_path,
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'cancel_job' ),
				'permission_callback' => array( __CLASS__, 'can_export' ),
				'args'                => $job_id_arg,
			)
		);

		// Import operations
		register_rest_route(
			self::REST_NAMESPACE,
			'/imports',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_import' ),
				'permission_callback' => array( __CLASS__, 'can_import' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports/' . $job_id_path . '/file',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upload_import_file' ),
				'permission_callback' => array( __CLASS__, 'can_upload_import_file' ),
				'args'                => array_merge(
					$job_id_arg,
					array(
						'auto_confirm'                 => array( 'type' => 'boolean', 'required' => false ),
						'allow_legacy_unauthenticated' => array( 'type' => 'boolean', 'required' => false ),
					)
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports/' . $job_id_path,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_import_status' ),
				'permission_callback' => array( __CLASS__, 'can_poll_import' ),
				'args'                => array_merge( $job_id_arg, $poll_token_arg ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports/' . $job_id_path . '/confirm',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'confirm_import' ),
				'permission_callback' => array( __CLASS__, 'can_manage_import_job' ),
				'args'                => array_merge(
					$job_id_arg,
					$password_arg,
					array(
						'proceed' => array( 'type' => 'boolean', 'required' => true ),
					)
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports/' . $job_id_path . '/log',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_import_log' ),
				'permission_callback' => array( __CLASS__, 'can_poll_import' ),
				'args'                => array_merge( $job_id_arg, $poll_token_arg ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports/' . $job_id_path,
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'cancel_job' ),
				'permission_callback' => array( __CLASS__, 'can_manage_import_job' ),
				'args'                => $job_id_arg,
			)
		);

		// Backup management
		register_rest_route(
			self::REST_NAMESPACE,
			'/backups',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_backups' ),
				'permission_callback' => array( __CLASS__, 'can_export' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/backups/' . $name_path,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_backup' ),
				'permission_callback' => array( __CLASS__, 'can_export' ),
				'args'                => $name_arg,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/backups/' . $name_path,
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'update_backup_label' ),
				'permission_callback' => array( __CLASS__, 'can_import' ),
				'args'                => array_merge(
					$name_arg,
					array(
						'label' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( __CLASS__, 'validate_label_length' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/backups/' . $name_path,
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_backup' ),
				'permission_callback' => array( __CLASS__, 'can_import' ),
				'args'                => $name_arg,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/backups/' . $name_path . '/download',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'download_backup' ),
				'permission_callback' => array( __CLASS__, 'can_import' ),
				'args'                => $name_arg,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/backups/' . $name_path . '/restore',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'restore_backup' ),
				'permission_callback' => array( __CLASS__, 'can_import' ),
				'args'                => $name_arg,
			)
		);
	}

	// ── Permission callbacks ────────────────────────────────────────────

	/**
	 * @return bool
	 */
	protected static function can_run_on_network() {
		return ! is_multisite() || is_super_admin();
	}

	/**
	 * @return bool
	 */
	public static function can_export() {
		return self::can_run_on_network() && current_user_can( 'manage_options' );
	}

	/**
	 * @return bool
	 */
	public static function can_import() {
		return self::can_run_on_network() && current_user_can( 'leadwerk_migration_import_site' );
	}

	/**
	 * Export polling always requires normal WordPress authentication. Exports do
	 * not replace credentials, so there is no reason to expose a fallback token.
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return bool
	 */
	public static function can_poll_export( $request ) {
		return self::can_export();
	}

	/**
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return bool
	 */
	public static function can_poll_import( $request ) {
		return self::can_import() || self::has_valid_job_token( $request, Leadwerk_Migration_Security::REST_TOKEN_SCOPE_IMPORT_READ );
	}

	/**
	 * Upload endpoint gate: require import capability AND that the job_id was
	 * issued by create_import for the current user (prevents cross-user job
	 * clobber). Returns a 404 WP_Error on unknown/foreign job ids rather than
	 * 403 — refusing to confirm whether another user's job exists.
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return bool|WP_Error
	 */
	public static function can_upload_import_file( $request ) {
		if ( ! self::can_import() ) {
			return false;
		}

		$job_id = $request->get_param( 'job_id' );
		if ( ! Leadwerk_Migration_Security::valid_job_id( $job_id ) ) {
			return new WP_Error( 'not_found', __( 'Import job not found.', 'leadwerk-migration' ), array( 'status' => 404 ) );
		}

		$issued_to   = get_transient( 'leadwerk_migration_rest_import_' . $job_id );
		$token_record = Leadwerk_Migration_Security::get_job_token_record( $job_id, Leadwerk_Migration_Security::REST_TOKEN_SCOPE_IMPORT_READ );
		if ( $issued_to === false || (int) $issued_to !== get_current_user_id() || $token_record === false || (int) $token_record['owner_id'] !== get_current_user_id() ) {
			return new WP_Error( 'not_found', __( 'Import job not found.', 'leadwerk-migration' ), array( 'status' => 404 ) );
		}

		return true;
	}

	/**
	 * Keep import mutations bound to the authenticated user who created the
	 * REST job. Polling tokens intentionally never authorize this callback.
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request REST request
	 * @return bool|WP_Error
	 */
	public static function can_manage_import_job( $request ) {
		if ( ! self::can_import() ) {
			return false;
		}

		$job_id = $request->get_param( 'job_id' );
		if ( ! Leadwerk_Migration_Security::valid_job_id( $job_id ) ) {
			return new WP_Error( 'not_found', __( 'Import job not found.', 'leadwerk-migration' ), array( 'status' => 404 ) );
		}

		$record = Leadwerk_Migration_Security::get_job_token_record( $job_id, Leadwerk_Migration_Security::REST_TOKEN_SCOPE_IMPORT_READ );
		if ( $record === false || (int) $record['owner_id'] !== get_current_user_id() ) {
			return new WP_Error( 'not_found', __( 'Import job not found.', 'leadwerk-migration' ), array( 'status' => 404 ) );
		}

		return true;
	}

	/**
	 * Cap backup label length at 255 chars to keep the wp_options row bounded.
	 *
	 * @param  mixed $label
	 * @return bool|WP_Error
	 */
	public static function validate_label_length( $label ) {
		if ( ! is_string( $label ) ) {
			return new WP_Error( 'invalid_label', __( 'Label must be text.', 'leadwerk-migration' ), array( 'status' => 400 ) );
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $label, 'UTF-8' ) : strlen( $label );
		if ( $length !== false && $length <= 255 ) {
			return true;
		}

		return new WP_Error( 'label_too_long', __( 'Label must be 255 characters or fewer.', 'leadwerk-migration' ), array( 'status' => 400 ) );
	}

	/**
	 * @param  mixed $job_id Job identifier
	 * @return bool
	 */
	public static function validate_job_id( $job_id ) {
		return Leadwerk_Migration_Security::valid_job_id( $job_id );
	}

	/**
	 * @param  mixed $name Backup filename
	 * @return bool
	 */
	public static function validate_backup_name( $name ) {
		return is_string( $name )
			&& basename( $name ) === $name
			&& strlen( $name ) <= 207
			&& (bool) preg_match( '/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,199}\.wpress\z/D', $name );
	}

	/**
	 * @param  mixed $token Polling token
	 * @return bool
	 */
	public static function validate_poll_token( $token ) {
		return is_string( $token ) && (bool) preg_match( '/\A[a-f0-9]{64}\z/D', $token );
	}

	/**
	 * @param  mixed $password Archive password
	 * @return bool
	 */
	public static function validate_password( $password ) {
		return is_string( $password ) && strlen( $password ) <= 4096;
	}

	/**
	 * @param  mixed $options Export options
	 * @return bool|WP_Error
	 */
	public static function validate_export_options( $options ) {
		if ( ! is_array( $options ) || count( $options ) > 32 ) {
			return new WP_Error( 'invalid_options', __( 'Export options must be an object with no more than 32 entries.', 'leadwerk-migration' ) );
		}

		foreach ( $options as $value ) {
			if ( ! is_bool( $value ) && ! in_array( $value, array( 0, 1, '0', '1' ), true ) ) {
				return new WP_Error( 'invalid_option_value', __( 'Export option values must be boolean.', 'leadwerk-migration' ) );
			}
		}

		return true;
	}

	/**
	 * @param  mixed $pairs Find-and-replace pairs
	 * @return bool|WP_Error
	 */
	public static function validate_find_replace( $pairs ) {
		if ( ! is_array( $pairs ) || count( $pairs ) > 100 ) {
			return new WP_Error( 'invalid_find_replace', __( 'Find and replace accepts at most 100 pairs.', 'leadwerk-migration' ) );
		}

		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair['find'], $pair['replace'] ) || ! is_string( $pair['find'] ) || ! is_string( $pair['replace'] ) || $pair['find'] === '' || strlen( $pair['find'] ) > 8192 || strlen( $pair['replace'] ) > 8192 ) {
				return new WP_Error( 'invalid_find_replace_pair', __( 'Each find and replace entry must contain bounded string values.', 'leadwerk-migration' ) );
			}
		}

		return true;
	}

	/**
	 * Validate a short-lived token against the job ID captured by this route.
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request REST request
	 * @param  string                                $scope   Required token scope
	 * @return bool
	 */
	private static function has_valid_job_token( $request, $scope ) {
		$job_id = $request->get_param( 'job_id' );
		$token  = self::request_poll_token( $request );

		return Leadwerk_Migration_Security::verify_job_token( $job_id, $scope, $token );
	}

	/**
	 * Prefer a header so credentials do not land in access logs or browser
	 * history. The query parameter remains available for simple REST clients.
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request REST request
	 * @return string
	 */
	private static function request_poll_token( $request ) {
		$token = $request->get_header( 'X-Leadwerk-Job-Token' );
		if ( ! is_string( $token ) || $token === '' ) {
			$token = $request->get_param( 'poll_token' );
		}

		return is_string( $token ) ? trim( $token ) : '';
	}

	/**
	 * Clear WordPress's authentication error only for a valid, scoped token on
	 * an import status/log GET route. This is needed after a restored users
	 * table invalidates the initiating Application Password or login cookie.
	 *
	 * The historic method name is retained because the main controller already
	 * registers it as a filter callback; global migration secrets are not used.
	 *
	 * @param  WP_Error|null|true $result Current authentication result
	 * @return WP_Error|null|true
	 */
	public static function allow_secret_key_auth( $result ) {
		if ( ! is_wp_error( $result ) || ! isset( $_SERVER['REQUEST_METHOD'] ) || strtoupper( $_SERVER['REQUEST_METHOD'] ) !== 'GET' ) {
			return $result;
		}

		$route = '';
		if ( isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = $GLOBALS['wp']->query_vars['rest_route'];
		} elseif ( isset( $_GET['rest_route'] ) ) {
			$route = wp_unslash( $_GET['rest_route'] );
		} elseif ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$route = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		}

		if ( ! is_string( $route ) || ! preg_match( '#(?:^|/)' . preg_quote( self::REST_NAMESPACE, '#' ) . '/imports/([a-f0-9]{32})(?:/log)?/?$#', rawurldecode( $route ), $matches ) ) {
			return $result;
		}

		$provided = '';
		if ( isset( $_SERVER['HTTP_X_LEADWERK_JOB_TOKEN'] ) ) {
			$provided = wp_unslash( $_SERVER['HTTP_X_LEADWERK_JOB_TOKEN'] );
		} elseif ( isset( $_GET['poll_token'] ) ) {
			$provided = wp_unslash( $_GET['poll_token'] );
		}

		if ( ! is_string( $provided ) || ! Leadwerk_Migration_Security::verify_job_token( $matches[1], Leadwerk_Migration_Security::REST_TOKEN_SCOPE_IMPORT_READ, trim( $provided ) ) ) {
			return $result;
		}

		return null;
	}

	// ── Discovery ───────────────────────────────────────────────────────

	/**
	 * GET /capabilities
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public static function capabilities( $request ) {
		$capabilities = array(
			'export'                => self::can_export(),
			'import'                => self::can_import(),
			'max_upload_size'       => apply_filters( 'leadwerk_migration_max_file_size', LEADWERK_MIGRATION_MAX_FILE_SIZE ),
			'max_upload_size_human' => size_format( apply_filters( 'leadwerk_migration_max_file_size', LEADWERK_MIGRATION_MAX_FILE_SIZE ) ),
			'wordpress_version'     => get_bloginfo( 'version' ),
			'php_version'           => PHP_VERSION,
			'plugin_version'        => LEADWERK_MIGRATION_VERSION,
			'site_url'              => site_url(),
			'storage_path_writable' => is_writable( LEADWERK_MIGRATION_STORAGE_PATH ),
			'available_space'       => leadwerk_migration_disk_free_space( LEADWERK_MIGRATION_STORAGE_PATH ),
			'available_space_human' => size_format( leadwerk_migration_disk_free_space( LEADWERK_MIGRATION_STORAGE_PATH ) ),
			'encryption_supported'  => leadwerk_migration_can_encrypt(),
		);

		return new WP_REST_Response( self::redact_response_data( apply_filters( 'leadwerk_migration_rest_capabilities', $capabilities ) ), 200 );
	}

	// ── Export operations ───────────────────────────────────────────────

	/**
	 * POST /exports
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_export( $request ) {
		$params = self::build_export_params( $request );

		try {
			$params = Leadwerk_Migration_Export_Controller::export( $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'export_failed', self::redact_response_data( $e->getMessage() ), array( 'status' => 500 ) );
		}

		$response = new WP_REST_Response(
			array(
				'job_id'  => $params['storage'],
				'status'  => 'running',
				'message' => __( 'Preparing export...', 'leadwerk-migration' ),
			),
			202
		);
		$response->header( 'Location', rest_url( self::REST_NAMESPACE . '/exports/' . $params['storage'] ) );

		return $response;
	}

	/**
	 * GET /exports/{job_id}
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public static function get_export_status( $request ) {
		return self::read_job_status( $request->get_param( 'job_id' ) );
	}

	/**
	 * GET /exports/{job_id}/log
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public static function get_export_log( $request ) {
		return self::read_job_log( $request->get_param( 'job_id' ) );
	}

	// ── Import operations ───────────────────────────────────────────────

	/**
	 * POST /imports
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public static function create_import( $request ) {
		$job_id  = leadwerk_migration_storage_folder();
		$owner_id = get_current_user_id();
		$issued   = Leadwerk_Migration_Security::issue_job_token(
			$job_id,
			Leadwerk_Migration_Security::REST_TOKEN_SCOPE_IMPORT_READ,
			$owner_id
		);

		if ( $issued === false ) {
			return new WP_Error( 'token_unavailable', __( 'Could not establish secure polling for this import.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}

		// Record that this job id was issued to the current user, so /file uploads
		// can't target a job id belonging to another in-flight export/import.
		// TTL auto-expires if the client never calls /file.
		$ownership_ttl = max( 300, (int) $issued['expires_at'] - time() );
		if ( ! set_transient( 'leadwerk_migration_rest_import_' . $job_id, $owner_id, $ownership_ttl ) ) {
			Leadwerk_Migration_Security::revoke_job_token( $job_id, Leadwerk_Migration_Security::REST_TOKEN_SCOPE_IMPORT_READ );
			return new WP_Error( 'job_unavailable', __( 'Could not create the import job.', 'leadwerk-migration' ), array( 'status' => 500 ) );
		}

		$response = new WP_REST_Response(
			array(
				'job_id'                => $job_id,
				'status'                => 'awaiting_upload',
				'poll_token'            => $issued['token'],
				'poll_token_expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $issued['expires_at'] ),
				'poll_token_scope'      => Leadwerk_Migration_Security::REST_TOKEN_SCOPE_IMPORT_READ,
			),
			201
		);
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	/**
	 * POST /imports/{job_id}/file (multipart/form-data with upload_file field)
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload_import_file( $request ) {
		$job_id = $request->get_param( 'job_id' );

		// Prefer the REST abstraction; fall back to $_FILES for WP versions /
		// request paths where get_file_params() is unavailable or empty.
		$files       = $request->get_file_params();
		$upload_file = isset( $files['upload_file'] ) ? $files['upload_file'] : ( isset( $_FILES['upload_file'] ) ? $_FILES['upload_file'] : null );

		if ( ! is_array( $upload_file ) ) {
			return new WP_Error(
				'no_file',
				__( 'No file attached. Use the "upload_file" multipart field.', 'leadwerk-migration' ),
				array( 'status' => 400 )
			);
		}

		$upload_error = isset( $upload_file['error'] ) ? (int) $upload_file['error'] : UPLOAD_ERR_OK;
		switch ( $upload_error ) {
			case UPLOAD_ERR_OK:
				break;
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return new WP_Error( 'file_too_large', self::upload_limit_message(), array( 'status' => 413 ) );
			case UPLOAD_ERR_NO_FILE:
				return new WP_Error(
					'no_file',
					__( 'No file was uploaded.', 'leadwerk-migration' ),
					array( 'status' => 400 )
				);
			case UPLOAD_ERR_PARTIAL:
				return new WP_Error(
					'upload_incomplete',
					__( 'The uploaded file was only partially received.', 'leadwerk-migration' ),
					array( 'status' => 400 )
				);
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
			case UPLOAD_ERR_EXTENSION:
			default:
				return new WP_Error(
					'upload_failed',
					__( 'The server could not accept the uploaded file. Check the server logs.', 'leadwerk-migration' ),
					array( 'status' => 500 )
				);
		}

		if ( empty( $upload_file['tmp_name'] ) || ! is_string( $upload_file['tmp_name'] ) || ! is_file( $upload_file['tmp_name'] ) || ! is_readable( $upload_file['tmp_name'] ) ) {
			return new WP_Error(
				'upload_failed',
				__( 'The upload finished without producing a readable file.', 'leadwerk-migration' ),
				array( 'status' => 500 )
			);
		}

		if ( PHP_SAPI !== 'cli' && ! is_uploaded_file( $upload_file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'The supplied file was not accepted as an HTTP upload.', 'leadwerk-migration' ), array( 'status' => 400 ) );
		}

		$archive_name = isset( $upload_file['name'] ) ? sanitize_file_name( $upload_file['name'] ) : '';
		if ( ! self::validate_backup_name( $archive_name ) ) {
			return new WP_Error( 'invalid_archive_name', __( 'The uploaded archive must have a safe .wpress filename.', 'leadwerk-migration' ), array( 'status' => 400 ) );
		}

		$upload_size = filesize( $upload_file['tmp_name'] );
		$size_limit  = apply_filters( 'leadwerk_migration_max_file_size', LEADWERK_MIGRATION_MAX_FILE_SIZE );
		if ( $upload_size === false || $upload_size <= 0 ) {
			return new WP_Error( 'empty_upload', __( 'The uploaded archive is empty.', 'leadwerk-migration' ), array( 'status' => 400 ) );
		}
		if ( $size_limit > 0 && $upload_size > $size_limit ) {
			return new WP_Error( 'file_too_large', self::upload_limit_message(), array( 'status' => 413 ) );
		}

		$token_record = Leadwerk_Migration_Security::get_job_token_record( $job_id, Leadwerk_Migration_Security::REST_TOKEN_SCOPE_IMPORT_READ );
		if ( $token_record === false ) {
			return new WP_Error( 'poll_token_expired', __( 'The import polling token expired. Create a new import job.', 'leadwerk-migration' ), array( 'status' => 410 ) );
		}

		// Ownership was validated before file handling. Consume the one-shot
		// upload grant only after the request itself has passed validation.
		delete_transient( 'leadwerk_migration_rest_import_' . $job_id );

		$params = array(
			'storage'                                => $job_id,
			'archive'                                => $archive_name,
			'leadwerk_migration_internal'            => 1,
			Leadwerk_Migration_Security::REST_TOKEN_PARAM => $token_record,
		);

		if ( $request->get_param( 'auto_confirm' ) ) {
			$params['leadwerk_migration_confirmed'] = 1;
			if ( $request->get_param( 'allow_legacy_unauthenticated' ) ) {
				$params['leadwerk_migration_allow_legacy_unauthenticated'] = 1;
			}
		}

		// Start import pipeline at priority 5 (Import_Upload handles validation + copy)
		$params['priority']   = 5;
		$params['secret_key'] = get_option( LEADWERK_MIGRATION_SECRET_KEY );

		try {
			$params = Leadwerk_Migration_Import_Controller::import( $params );
		} catch ( Leadwerk_Migration_Upload_Exception $e ) {
			$message = ( $e->getCode() === 413 ) ? self::upload_limit_message() : self::redact_response_data( $e->getMessage() );

			return new WP_Error( 'upload_error', $message, array( 'status' => $e->getCode() ) );
		} catch ( Leadwerk_Migration_Not_Decryptable_Exception $e ) {
			// Check_Encryption halted the pipeline (encrypted archive, bad password, or no decrypt support).
			// Storage preserved; client reads type/status from the status option.
			return self::read_job_status( $job_id );
		} catch ( Leadwerk_Migration_Import_Halted_Exception $e ) {
			// Destructive confirmation is required before the import continues.
			return self::read_job_status( $job_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'import_failed', self::redact_response_data( $e->getMessage() ), array( 'status' => 500 ) );
		}

		$response = new WP_REST_Response(
			array(
				'job_id'  => $job_id,
				'status'  => 'running',
				'message' => __( 'Importing...', 'leadwerk-migration' ),
			),
			202
		);
		$response->header( 'Location', rest_url( self::REST_NAMESPACE . '/imports/' . $job_id ) );

		return $response;
	}

	/**
	 * GET /imports/{job_id}
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public static function get_import_status( $request ) {
		return self::read_job_status( $request->get_param( 'job_id' ) );
	}

	/**
	 * POST /imports/{job_id}/confirm
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function confirm_import( $request ) {
		$job_id = $request->get_param( 'job_id' );

		// Verify job is actually waiting for confirmation
		$status      = get_option( 'leadwerk_migration_status_' . $job_id, array() );
		$status_type = isset( $status['type'] ) ? $status['type'] : '';
		if ( ! in_array( $status_type, array( 'confirm', 'disk_space_confirm', 'backup_is_encrypted' ), true ) ) {
			return new WP_Error(
				'conflict',
				__( 'Import is not waiting for confirmation.', 'leadwerk-migration' ),
				array( 'status' => 409 )
			);
		}

		if ( ! $request->has_param( 'proceed' ) ) {
			return new WP_Error(
				'missing_proceed',
				__( 'The proceed parameter is required. Use DELETE /imports/{id} to cancel.', 'leadwerk-migration' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $request->get_param( 'proceed' ) ) {
			// Explicit proceed=false — cancel the import
			self::cleanup_job( $job_id );
			return new WP_REST_Response(
				array(
					'job_id' => $job_id,
					'status' => 'canceled',
				),
				200
			);
		}

		$params = array(
			'storage'                     => $job_id,
			// Recover the archive name from status; the confirm halt dropped it from request params
			'archive'                     => isset( $status['archive'] ) ? $status['archive'] : '',
			'leadwerk_migration_internal' => 1,
			// Resume priority: encrypted backup resumes decrypt stage, others skip confirm
			'priority'                    => ( $status_type === 'backup_is_encrypted' ) ? 75 : 150,
			'secret_key'                  => get_option( LEADWERK_MIGRATION_SECRET_KEY ),
		);
		// Decrypt acknowledgement is not a destructive-import confirmation. Keep
		// encrypted archives on a second /confirm after the password is accepted.
		if ( $status_type !== 'backup_is_encrypted' ) {
			$params['leadwerk_migration_confirmed'] = 1;
		}
		if ( $status_type === 'confirm' && ! empty( $status['legacy_unauthenticated'] ) ) {
			// A separate proceed=true request made after the warning is the REST
			// client's explicit acknowledgement of the legacy archive risk.
			$params['leadwerk_migration_allow_legacy_unauthenticated'] = 1;
		}
		foreach ( array( 'leadwerk_migration_manual_restore', 'leadwerk_migration_verified_local_restore' ) as $restore_flag ) {
			if ( ! empty( $status[ $restore_flag ] ) ) {
				$params[ $restore_flag ] = 1;
			}
		}

		$token_record = Leadwerk_Migration_Security::get_job_token_record( $job_id, Leadwerk_Migration_Security::REST_TOKEN_SCOPE_IMPORT_READ );
		if ( $token_record !== false ) {
			$params[ Leadwerk_Migration_Security::REST_TOKEN_PARAM ] = $token_record;
		}

		$password = $request->get_param( 'password' );
		if ( ! empty( $password ) && is_string( $password ) ) {
			$params['decryption_password'] = $password;
		}

		try {
			Leadwerk_Migration_Import_Controller::import( $params );
		} catch ( Leadwerk_Migration_Not_Decryptable_Exception $e ) {
			// Wrong password (or still-missing one) on resume — status option already reflects it
			return self::read_job_status( $job_id );
		} catch ( Leadwerk_Migration_Import_Halted_Exception $e ) {
			// Password accepted; client must call /confirm again for the destructive step.
			return self::read_job_status( $job_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'import_failed', self::redact_response_data( $e->getMessage() ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'job_id'  => $job_id,
				'status'  => 'running',
				'message' => __( 'Importing...', 'leadwerk-migration' ),
			),
			200
		);
	}

	/**
	 * GET /imports/{job_id}/log
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public static function get_import_log( $request ) {
		return self::read_job_log( $request->get_param( 'job_id' ) );
	}

	// ── Shared job operations ───────────────────────────────────────────

	/**
	 * DELETE /exports/{job_id} or /imports/{job_id}
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public static function cancel_job( $request ) {
		$job_id = $request->get_param( 'job_id' );

		// Write cancel marker to status
		update_option(
			'leadwerk_migration_status_' . $job_id,
			array(
				'type'    => 'canceled',
				'title'   => __( 'Canceled', 'leadwerk-migration' ),
				'message' => __( 'Operation canceled by REST API.', 'leadwerk-migration' ),
			),
			false
		);

		// Clean up storage folder and log file
		self::cleanup_job( $job_id );

		return new WP_REST_Response(
			array(
				'job_id' => $job_id,
				'status' => 'canceled',
			),
			200
		);
	}

	// ── Backup management ───────────────────────────────────────────────

	/**
	 * GET /backups
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public static function list_backups( $request ) {
		$backups = array();
		$files   = Leadwerk_Migration_Backups::get_files();
		$labels  = Leadwerk_Migration_Backups::get_labels();

		foreach ( $files as $file ) {
			$backups[] = self::format_backup( $file, $labels );
		}

		return new WP_REST_Response( self::redact_response_data( array( 'backups' => $backups ) ), 200 );
	}

	/**
	 * GET /backups/{name}
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_backup( $request ) {
		$name = $request->get_param( 'name' );

		$backup_file = self::find_backup( $name );
		if ( $backup_file === false ) {
			return new WP_Error( 'not_found', __( 'Backup not found.', 'leadwerk-migration' ), array( 'status' => 404 ) );
		}

		$backup = self::format_backup( $backup_file, Leadwerk_Migration_Backups::get_labels() );

		// Extract config metadata from archive
		try {
			$config = self::extract_backup_config( $name );
			if ( $config !== false ) {
				$backup['config'] = $config;
			}
		} catch ( Exception $e ) {
			// Config extraction failed, continue without it
		}

		return new WP_REST_Response( self::redact_response_data( $backup ), 200 );
	}

	/**
	 * PATCH /backups/{name}
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_backup_label( $request ) {
		$name = $request->get_param( 'name' );

		// Verify backup exists
		if ( self::find_backup( $name ) === false ) {
			return new WP_Error( 'not_found', __( 'Backup not found.', 'leadwerk-migration' ), array( 'status' => 404 ) );
		}

		$label = $request->get_param( 'label' );
		$label = ! empty( $label ) ? sanitize_text_field( $label ) : '';

		if ( empty( $label ) ) {
			Leadwerk_Migration_Backups::delete_label( $name );
		} else {
			Leadwerk_Migration_Backups::set_label( $name, $label );
		}

		return new WP_REST_Response(
			self::redact_response_data(
				array(
				'name'  => $name,
				'label' => empty( $label ) ? '' : $label,
				)
			),
			200
		);
	}

	/**
	 * DELETE /backups/{name}
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_backup( $request ) {
		$name = $request->get_param( 'name' );

		// Verify backup exists
		if ( self::find_backup( $name ) === false ) {
			return new WP_Error( 'not_found', __( 'Backup not found.', 'leadwerk-migration' ), array( 'status' => 404 ) );
		}

		try {
			if ( ! Leadwerk_Migration_Backups::delete_file( $name ) ) {
				return new WP_Error( 'delete_failed', __( 'The backup could not be deleted.', 'leadwerk-migration' ), array( 'status' => 500 ) );
			}
			Leadwerk_Migration_Backups::delete_label( $name );
		} catch ( Exception $e ) {
			return new WP_Error( 'delete_failed', self::redact_response_data( $e->getMessage() ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * GET /backups/{name}/download
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function download_backup( $request ) {
		$name = $request->get_param( 'name' );

		$backup_file = self::find_backup( $name );
		if ( $backup_file === false ) {
			return new WP_Error( 'not_found', __( 'Backup not found.', 'leadwerk-migration' ), array( 'status' => 404 ) );
		}

		$path = leadwerk_migration_backup_path( array( 'archive' => $backup_file['filename'] ) );
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error(
				'not_downloadable',
				__( 'This backup cannot be read by WordPress.', 'leadwerk-migration' ),
				array( 'status' => 409 )
			);
		}

		return new WP_REST_Response(
			self::redact_response_data(
				array(
				'name'         => $backup_file['filename'],
				'download_url' => Leadwerk_Migration_Dashboard_Controller::download_url( $backup_file['filename'] ),
				'size'         => $backup_file['size'],
				'size_human'   => is_numeric( $backup_file['size'] ) ? size_format( $backup_file['size'] ) : null,
				)
			),
			200
		);
	}

	/**
	 * POST /backups/{name}/restore
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request
	 * @return WP_Error
	 */
	public static function restore_backup( $request ) {
		return new WP_Error(
			'not_implemented',
			__( 'Create an import job and upload this backup through the import endpoints to restore it.', 'leadwerk-migration' ),
			array( 'status' => 501 )
		);
	}

	// ── Private helpers ─────────────────────────────────────────────────

	/**
	 * Build export params from a REST request
	 *
	 * @param  WP_REST_Request<array<string, mixed>> $request REST request
	 * @return array<string, mixed>                  Pipeline params
	 */
	private static function build_export_params( $request ) {
		$params = array(
			'secret_key'                   => get_option( LEADWERK_MIGRATION_SECRET_KEY ),
			'leadwerk_migration_internal' => 1,
			'options'                      => array(),
		);

		// Set export options (whitelist to prevent param injection)
		$options = $request->get_param( 'options' );
		if ( is_array( $options ) ) {
			$allowed = apply_filters(
				'leadwerk_migration_rest_export_options_whitelist',
				array(
					'no_spam_comments',
					'no_post_revisions',
					'no_media',
					'no_themes',
					'no_inactive_themes',
					'no_muplugins',
					'no_plugins',
					'no_inactive_plugins',
					'no_cache',
					'no_database',
					'no_email_replace',
				)
			);
			foreach ( $allowed as $key ) {
				if ( is_string( $key ) && array_key_exists( $key, $options ) ) {
					$params['options'][ $key ] = rest_sanitize_boolean( $options[ $key ] );
				}
			}
		}

		// Set find and replace (pipeline expects columnar shape: old_value[] / new_value[])
		$find_replace = $request->get_param( 'find_replace' );
		if ( is_array( $find_replace ) ) {
			$params['options']['replace'] = array( 'old_value' => array(), 'new_value' => array() );
			foreach ( $find_replace as $pair ) {
				if ( isset( $pair['find'], $pair['replace'] ) && is_string( $pair['find'] ) && is_string( $pair['replace'] ) ) {
					$params['options']['replace']['old_value'][] = $pair['find'];
					$params['options']['replace']['new_value'][] = $pair['replace'];
				}
			}
		}

		// Set archive password — pipeline gates encryption on encrypt_backups + encrypt_password
		$password = $request->get_param( 'password' );
		if ( ! empty( $password ) && is_string( $password ) ) {
			$params['options']['encrypt_backups']  = true;
			$params['options']['encrypt_password'] = $password;
		}

		// Allow installed integrations to adjust the validated pipeline params.
		$params = apply_filters( 'leadwerk_migration_rest_export_params', $params );

		return $params;
	}

	/**
	 * Read job-scoped status and augment for REST response
	 *
	 * @param  string $job_id Job ID (storage folder name)
	 * @return WP_REST_Response
	 */
	private static function read_job_status( $job_id ) {
		$data = get_option( 'leadwerk_migration_status_' . $job_id, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		// No status yet (race condition between POST and first loopback write)
		if ( empty( $data ) ) {
			return new WP_REST_Response(
				array(
					'job_id'  => $job_id,
					'type'    => 'progress',
					'status'  => 'running',
					'percent' => 0,
					'message' => __( 'Preparing...', 'leadwerk-migration' ),
				),
				200
			);
		}

		// Augment with job_id
		$data['job_id'] = $job_id;

		// Derive simplified status from type
		$type = isset( $data['type'] ) ? $data['type'] : '';
		switch ( $type ) {
			case 'progress':
			case 'info':
				$data['status'] = 'running';
				break;
			case 'confirm':
			case 'disk_space_confirm':
			case 'backup_is_encrypted':
				$data['status'] = 'confirm';
				break;
			case 'done':
			case 'download':
				$data['status'] = 'complete';
				break;
			case 'error':
			case 'server_cannot_decrypt':
				$data['status'] = 'error';
				break;
			case 'canceled':
				$data['status'] = 'canceled';
				break;
			case 'blogs':
				$data['status']  = 'error';
				$data['message'] = __( 'Multisite blog selection is not supported via the REST API. Please use the admin interface.', 'leadwerk-migration' );
				break;
			default:
				$data['status'] = 'running';
				break;
		}

		// Augment confirm type with archive_info from package.json
		if ( $type === 'confirm' ) {
			$archive_info = self::read_archive_info( $job_id );
			if ( $archive_info !== false ) {
				$data['archive_info'] = $archive_info;
			}
		}

		return new WP_REST_Response( self::redact_response_data( $data ), 200 );
	}

	/**
	 * Read job error log
	 *
	 * @param  string $job_id Job ID (storage folder name)
	 * @return WP_REST_Response
	 */
	private static function read_job_log( $job_id ) {
		$log_path = leadwerk_migration_error_path( $job_id );

		$log       = '';
		$truncated = false;
		$root      = realpath( LEADWERK_MIGRATION_STORAGE_PATH );
		$file      = realpath( $log_path );
		if ( $root !== false && $file !== false && strpos( $file, $root . DIRECTORY_SEPARATOR ) === 0 && is_file( $file ) && is_readable( $file ) ) {
			$max_bytes = (int) apply_filters( 'leadwerk_migration_rest_log_max_bytes', 1048576, $job_id );
			$max_bytes = max( 4096, min( 5242880, $max_bytes ) );
			$file_size = filesize( $file );
			$offset    = ( $file_size !== false && $file_size > $max_bytes ) ? $file_size - $max_bytes : 0;
			$truncated = $offset > 0;
			$log       = file_get_contents( $file, false, null, $offset, $max_bytes );
		}
		if ( ! is_string( $log ) ) {
			$log = '';
		}

		return new WP_REST_Response(
			self::redact_response_data(
				array(
					'job_id'   => $job_id,
					'log'      => $log,
					'truncated' => $truncated,
				)
			),
			200
		);
	}

	/**
	 * Clean up a job's storage folder and log file
	 *
	 * @param  string $job_id Job ID (storage folder name)
	 * @return void
	 */
	private static function cleanup_job( $job_id ) {
		$params = array( 'storage' => $job_id );

		try {
			Leadwerk_Migration_Directory::delete( leadwerk_migration_storage_path( $params ) );
		} catch ( Exception $e ) {
			// Storage may already be deleted
		}

		// Delete log file
		$log_path = leadwerk_migration_error_path( $job_id );
		if ( file_exists( $log_path ) ) {
			leadwerk_migration_unlink( $log_path );
		}

		delete_transient( 'leadwerk_migration_rest_import_' . $job_id );
		Leadwerk_Migration_Security::revoke_job_token( $job_id, Leadwerk_Migration_Security::REST_TOKEN_SCOPE_IMPORT_READ );
	}

	/**
	 * Format a backup file for REST response
	 *
	 * @param  array<string, mixed>  $file   Backup file from Leadwerk_Migration_Backups::get_files()
	 * @param  array<string, string> $labels Labels from Leadwerk_Migration_Backups::get_labels()
	 * @return array<string, mixed>
	 */
	private static function format_backup( $file, $labels ) {
		$path         = leadwerk_migration_backup_path( array( 'archive' => $file['filename'] ) );
		$downloadable = self::can_import() && is_file( $path ) && is_readable( $path );
		$size    = isset( $file['size'] ) && is_numeric( $file['size'] ) ? (int) $file['size'] : null;
		$mtime   = isset( $file['mtime'] ) && is_numeric( $file['mtime'] ) ? (int) $file['mtime'] : null;
		$backup  = array(
			'name'         => $file['filename'],
			'size'         => $size,
			'size_human'   => $size !== null ? size_format( $size ) : null,
			'created_at'   => $mtime !== null ? gmdate( 'Y-m-d\TH:i:s\Z', $mtime ) : null,
			'label'        => isset( $labels[ $file['filename'] ] ) ? $labels[ $file['filename'] ] : '',
			'downloadable' => $downloadable,
		);

		if ( $downloadable ) {
			$backup['download_url'] = Leadwerk_Migration_Dashboard_Controller::download_url( $file['filename'] );
		}

		return $backup;
	}

	/**
	 * Find a backup file by name
	 *
	 * @param  string                     $name Backup filename
	 * @return array<string, mixed>|false       Backup file info or false
	 */
	private static function find_backup( $name ) {
		$files = Leadwerk_Migration_Backups::get_files();
		foreach ( $files as $file ) {
			if ( $file['filename'] === $name ) {
				return $file;
			}
		}

		return false;
	}

	/**
	 * Extract config metadata from backup's package.json
	 *
	 * @param  string                     $name Backup filename
	 * @return array<string, mixed>|false       Config data or false
	 */
	private static function extract_backup_config( $name ) {
		$params  = array( 'archive' => $name, 'storage' => leadwerk_migration_storage_folder() );
		$storage = leadwerk_migration_storage_path( $params );

		try {
			$archive = new Leadwerk_Migration_Extractor( leadwerk_migration_backup_path( $params ) );
			$archive->extract_by_files_array( $storage, array( LEADWERK_MIGRATION_PACKAGE_NAME ) );
			$archive->close();

			$config = self::parse_package( $storage . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_PACKAGE_NAME );

			Leadwerk_Migration_Directory::delete( $storage );

			return $config;
		} catch ( Exception $e ) {
			try {
				Leadwerk_Migration_Directory::delete( $storage );
			} catch ( Exception $e2 ) {
				// Ignore
			}
		}

		return false;
	}

	/**
	 * Read archive_info from the job's storage folder (package.json extracted by pipeline)
	 *
	 * @param  string                     $job_id Job ID
	 * @return array<string, mixed>|false
	 */
	private static function read_archive_info( $job_id ) {
		$params = array( 'storage' => $job_id );

		return self::parse_package( leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_PACKAGE_NAME );
	}

	/**
	 * Parse package.json file into config array
	 *
	 * @param  string                     $path Path to package.json
	 * @return array<string, mixed>|false
	 */
	private static function parse_package( $path ) {
		if ( ! file_exists( $path ) ) {
			return false;
		}

		$contents = file_get_contents( $path );
		if ( $contents === false ) {
			return false;
		}

		$package = json_decode( $contents, true );
		if ( ! is_array( $package ) ) {
			return false;
		}

		return array(
			'wordpress_version' => isset( $package['WordPress']['Version'] ) ? $package['WordPress']['Version'] : null,
			'php_version'       => isset( $package['PHP']['Version'] ) ? $package['PHP']['Version'] : null,
			'plugin_version'    => isset( $package['Plugin']['Version'] ) ? $package['Plugin']['Version'] : null,
			'site_url'          => isset( $package['SiteURL'] ) ? $package['SiteURL'] : null,
			'encrypted'         => ! empty( $package['Encrypted'] ),
		);
	}

	/**
	 * Remove pipeline credentials from data that is about to cross the REST
	 * boundary. Status filters and error logs are extensible, so this is a final
	 * defence against exposing the plugin-wide continuation secret.
	 *
	 * @param  mixed $value Response value
	 * @return mixed
	 */
	private static function redact_response_data( $value, $secrets = null ) {
		if ( $secrets === null ) {
			$secrets = array_filter(
				array(
					get_option( LEADWERK_MIGRATION_SECRET_KEY, '' ),
					Leadwerk_Migration_Security::scoped_secret( 'export' ),
					Leadwerk_Migration_Security::scoped_secret( 'import' ),
					Leadwerk_Migration_Security::scoped_secret( 'backups' ),
					Leadwerk_Migration_Security::scoped_secret( 'status' ),
				),
				'is_string'
			);
		}

		if ( is_array( $value ) ) {
			$redacted = array();
			foreach ( $value as $key => $item ) {
				$normalized_key = is_string( $key ) ? strtolower( $key ) : '';
				if ( in_array(
					$normalized_key,
					array(
						'secret_key',
						'auth_password',
						'decryption_password',
						'encrypt_password',
						'password',
						'poll_token',
						'token',
						'token_hash',
						Leadwerk_Migration_Security::REST_TOKEN_PARAM,
					),
					true
				) ) {
					continue;
				}

				$redacted[ $key ] = self::redact_response_data( $item, $secrets );
			}

			return $redacted;
		}

		if ( is_object( $value ) ) {
			return self::redact_response_data( (array) $value, $secrets );
		}

		if ( is_string( $value ) ) {
			foreach ( $secrets as $secret ) {
				if ( $secret !== '' ) {
					$value = str_replace( array( $secret, rawurlencode( $secret ), urlencode( $secret ) ), '[redacted]', $value );
				}
			}
		}

		return $value;
	}

	/**
	 * Upload limit exceeded message (matches browser UI copy)
	 *
	 * @return string
	 */
	private static function upload_limit_message() {
		return sprintf(
			/* translators: 1: Maximum upload file size configured by the host. */
			__(
				'The archive exceeds this server\'s %1$s upload limit. Increase upload_max_filesize and post_max_size, or upload a smaller archive.',
				'leadwerk-migration'
			),
			leadwerk_migration_size_format( apply_filters( 'leadwerk_migration_max_file_size', LEADWERK_MIGRATION_MAX_FILE_SIZE ) )
		);
	}
}
