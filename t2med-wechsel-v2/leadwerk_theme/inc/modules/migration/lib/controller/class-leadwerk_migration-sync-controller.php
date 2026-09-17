<?php
/**
 * Leadwerk Site Sync administration.
 *
 * Copyright (C) 2026 Leadwerk.
 * Licensed under GPLv3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access is not allowed.' );
}

class Leadwerk_Migration_Sync_Controller {

	const NOTICE_PREFIX = 'leadwerk_migration_sync_notice_';

	public static function index() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Site Sync.', 'leadwerk-migration' ) );
		}

		$bootstrap = Leadwerk_Migration_Sync::bootstrap_registration();
		$family      = get_option( LEADWERK_MIGRATION_SYNC_FAMILY, array() );
		$family      = is_array( $family ) ? $family : array();
		$environment = Leadwerk_Migration_Site_Identity::ensure_environment();
		$settings    = Leadwerk_Migration_Site_Identity::settings();
		$directory   = array();
		$jobs        = array();
		$commands    = array();
		$is_available_target = is_wp_error( $bootstrap ) && 'leadwerk_sync_available_target' === $bootstrap->get_error_code();
		$remote_error = is_wp_error( $bootstrap ) && ! $is_available_target ? $bootstrap->get_error_message() : '';

		if ( '' === $remote_error && Leadwerk_Migration_Site_Identity::valid_id( isset( $family['family_id'] ) ? $family['family_id'] : '' ) && 'unregistered' !== $environment['registration'] ) {
			$snapshot = Leadwerk_Migration_Sync::status_snapshot();
			if ( is_wp_error( $snapshot ) ) {
				$remote_error = $snapshot->get_error_message();
			} else {
				$directory = $snapshot['environments'];
				$jobs = $snapshot['jobs'];
				$commands = $snapshot['commands'];
			}
		}

		$hub_data = Leadwerk_Migration_Sync_Hub::enabled() ? Leadwerk_Migration_Sync_Hub::admin_data() : array( 'families' => array(), 'environments' => array(), 'jobs' => array(), 'commands' => array() );
		if ( Leadwerk_Migration_Sync_Hub::enabled() ) {
			$directory = isset( $hub_data['environments'] ) ? $hub_data['environments'] : array();
			$jobs = isset( $hub_data['jobs'] ) ? $hub_data['jobs'] : array();
			$commands = isset( $hub_data['commands'] ) ? $hub_data['commands'] : array();
		}

		Leadwerk_Migration_Template::render(
			'sync/index',
			array(
				'family'       => $family,
				'environment'  => $environment,
				'settings'     => $settings,
				'directory'    => $directory,
				'jobs'         => $jobs,
				'commands'     => $commands,
				'remote_error' => $remote_error,
				'notice'       => self::consume_notice(),
				'hub_data'     => $hub_data,
			)
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( 'leadwerk-migration_page_leadwerk_migration_sync' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'leadwerk-migration-dashboard', Leadwerk_Migration_Template::asset_link( 'css/leadwerk-dashboard.css' ), array(), LEADWERK_MIGRATION_VERSION );
		wp_enqueue_style( 'leadwerk-migration-sync', Leadwerk_Migration_Template::asset_link( 'css/leadwerk-sync.css' ), array( 'leadwerk-migration-dashboard' ), LEADWERK_MIGRATION_VERSION );
		wp_enqueue_script( 'leadwerk-migration-sync', Leadwerk_Migration_Template::asset_link( 'javascript/leadwerk-sync.js' ), array(), LEADWERK_MIGRATION_VERSION, true );
		wp_localize_script(
			'leadwerk-migration-sync',
			'leadwerkMigrationSync',
			array(
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'adminPostUrl'        => admin_url( 'admin-post.php' ),
				'requestSyncNonce'    => wp_create_nonce( 'leadwerk_migration_sync_request' ),
				'statusNonce'         => wp_create_nonce( 'leadwerk_migration_sync_status' ),
				'cancelJobNonce'      => wp_create_nonce( 'leadwerk_migration_sync_cancel_job' ),
				'retryJobNonce'       => wp_create_nonce( 'leadwerk_migration_sync_retry_job' ),
				'cancelCommandNonce'  => wp_create_nonce( 'leadwerk_migration_runtime_cancel_command' ),
				'retryCommandNonce'   => wp_create_nonce( 'leadwerk_migration_runtime_retry_command' ),
				'hubApproveNonce'     => wp_create_nonce( 'leadwerk_migration_hub_approve' ),
				'hubRevokeNonce'      => wp_create_nonce( 'leadwerk_migration_hub_revoke' ),
				'hubMode'              => Leadwerk_Migration_Sync_Hub::enabled(),
				'activeInterval'      => 1000,
				'idleInterval'        => 5000,
				'labels'              => array(
					'local'       => __( 'Local', 'leadwerk-migration' ),
					'staging'     => __( 'Staging', 'leadwerk-migration' ),
					'online'      => __( 'Online', 'leadwerk-migration' ),
					'offline'     => __( 'Offline', 'leadwerk-migration' ),
					'never'       => __( 'Never', 'leadwerk-migration' ),
					'secondsAgo'  => __( '%s seconds ago', 'leadwerk-migration' ),
					'minutesAgo'  => __( '%s minutes ago', 'leadwerk-migration' ),
					'hoursAgo'    => __( '%s hours ago', 'leadwerk-migration' ),
					'daysAgo'     => __( '%s days ago', 'leadwerk-migration' ),
					'cancel'      => __( 'Cancel', 'leadwerk-migration' ),
					'retry'       => __( 'Retry', 'leadwerk-migration' ),
					'reactivate'  => __( 'Reactivate', 'leadwerk-migration' ),
					'revoke'      => __( 'Emergency revoke', 'leadwerk-migration' ),
					'active'      => __( 'Active', 'leadwerk-migration' ),
					'available'   => __( 'Available target', 'leadwerk-migration' ),
					'refreshing'  => __( 'Refreshing…', 'leadwerk-migration' ),
					'live'        => __( 'Live', 'leadwerk-migration' ),
					'paused'      => __( 'Paused while tab is hidden', 'leadwerk-migration' ),
					'connection'  => __( 'Live update temporarily unavailable', 'leadwerk-migration' ),
					'noJobs'      => __( 'No sync jobs', 'leadwerk-migration' ),
					'noCommands'  => __( 'No automatic family updates yet', 'leadwerk-migration' ),
					'recordCount' => __( '%s records', 'leadwerk-migration' ),
					'noEnvironments' => __( 'Waiting for environment heartbeats', 'leadwerk-migration' ),
					'environmentCount' => __( '%s environments', 'leadwerk-migration' ),
					'environment' => __( 'Environment', 'leadwerk-migration' ),
					'status'      => __( 'Status', 'leadwerk-migration' ),
					'lastHeartbeat' => __( 'Last heartbeat', 'leadwerk-migration' ),
					'created'     => __( 'Created', 'leadwerk-migration' ),
					'direction'   => __( 'Direction', 'leadwerk-migration' ),
					'progress'    => __( 'Progress', 'leadwerk-migration' ),
					'direct'      => __( 'Peer-to-peer', 'leadwerk-migration' ),
					'message'     => __( 'Message', 'leadwerk-migration' ),
					'manage'      => __( 'Manage', 'leadwerk-migration' ),
					'target'      => __( 'Target', 'leadwerk-migration' ),
					'desiredRuntime' => __( 'Target version', 'leadwerk-migration' ),
					'stage'       => __( 'Stage', 'leadwerk-migration' ),
					'project'     => __( 'Project', 'leadwerk-migration' ),
					'familyId'    => __( 'Family ID', 'leadwerk-migration' ),
					'noFamily'    => __( 'No Family ID', 'leadwerk-migration' ),
					'type'        => __( 'Type', 'leadwerk-migration' ),
					'heartbeat'   => __( 'Heartbeat', 'leadwerk-migration' ),
					'versions'    => __( 'Versions', 'leadwerk-migration' ),
					'upload'      => __( 'Blocked legacy route', 'leadwerk-migration' ),
					'download'    => __( 'Blocked legacy route', 'leadwerk-migration' ),
					'preparing'   => __( 'Preparing backup', 'leadwerk-migration' ),
					'preparingTarget' => __( 'Creating target safety backup', 'leadwerk-migration' ),
					'approvedJob' => __( 'Preparing source archive', 'leadwerk-migration' ),
					'transferring' => __( 'Transferring archive', 'leadwerk-migration' ),
					'peerReady'   => __( 'Source archive ready', 'leadwerk-migration' ),
					'receiving'   => __( 'Receiving archive', 'leadwerk-migration' ),
					'ready'       => __( 'Transfer complete', 'leadwerk-migration' ),
					'verifying'   => __( 'Verifying archive', 'leadwerk-migration' ),
					'preparingImport' => __( 'Preparing import', 'leadwerk-migration' ),
					'importing'   => __( 'Importing WordPress', 'leadwerk-migration' ),
					'finalizing'  => __( 'Finalizing import', 'leadwerk-migration' ),
					'complete'    => __( 'Complete', 'leadwerk-migration' ),
					'failed'      => __( 'Failed', 'leadwerk-migration' ),
					'canceled'    => __( 'Canceled', 'leadwerk-migration' ),
					'startingSync' => __( 'Starting synchronization…', 'leadwerk-migration' ),
					'syncStarted'  => __( 'Version and online checks passed. Full-site synchronization started.', 'leadwerk-migration' ),
					'syncStartFailed' => __( 'Synchronization could not be started.', 'leadwerk-migration' ),
				),
			)
		);
	}

	/** AJAX read model for every dynamic Site Sync panel region. */
	public static function ajax_status() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'leadwerk_migration_sync_status' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'leadwerk-migration' ) ), 403 );
		}

		$family = get_option( LEADWERK_MIGRATION_SYNC_FAMILY, array() );
		$environment = Leadwerk_Migration_Site_Identity::ensure_environment();
		$snapshot = array( 'environments' => array(), 'jobs' => array(), 'commands' => array(), 'hub_time' => '' );
		if (
			is_array( $family ) &&
			Leadwerk_Migration_Site_Identity::valid_id( isset( $family['family_id'] ) ? $family['family_id'] : '' ) &&
			isset( $environment['registration'] ) &&
			'unregistered' !== $environment['registration']
		) {
			$snapshot = Leadwerk_Migration_Sync::status_snapshot();
			if ( is_wp_error( $snapshot ) ) {
				wp_send_json_error( array( 'message' => $snapshot->get_error_message() ), 502 );
			}
		}

		$hub = Leadwerk_Migration_Sync_Hub::enabled()
			? Leadwerk_Migration_Sync_Hub::admin_data()
			: array( 'families' => array(), 'environments' => array(), 'jobs' => array(), 'commands' => array() );
		if ( Leadwerk_Migration_Sync_Hub::enabled() ) {
			$snapshot['environments'] = isset( $hub['environments'] ) ? $hub['environments'] : array();
			$snapshot['jobs'] = isset( $hub['jobs'] ) ? $hub['jobs'] : array();
			$snapshot['commands'] = isset( $hub['commands'] ) ? $hub['commands'] : array();
		}
		wp_send_json_success(
			array(
				'environments' => self::sanitize_environments( $snapshot['environments'] ),
				'jobs'         => self::sanitize_jobs( $snapshot['jobs'] ),
				'commands'     => self::sanitize_commands( $snapshot['commands'] ),
				'hub'          => array(
					'families'     => self::sanitize_families( isset( $hub['families'] ) ? $hub['families'] : array() ),
					'environments' => self::sanitize_environments( isset( $hub['environments'] ) ? $hub['environments'] : array() ),
				),
				'hubTime'      => isset( $snapshot['hub_time'] ) ? sanitize_text_field( $snapshot['hub_time'] ) : '',
				'serverTime'   => time(),
			)
		);
	}

	public static function save_settings() {
		self::require_action( 'leadwerk_migration_sync_save' );
		if ( ! Leadwerk_Migration_Sync_Hub::is_hub_site() ) {
			self::finish( new WP_Error( 'leadwerk_sync_settings_hub_only', __( 'Site Sync settings can only be changed on the configured Hub site.', 'leadwerk-migration' ) ) );
		}
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$current = Leadwerk_Migration_Site_Identity::settings();
		if ( empty( $raw['php_update_webhook_secret'] ) && ! empty( $current['php_update_webhook_secret'] ) ) {
			$raw['php_update_webhook_secret'] = $current['php_update_webhook_secret'];
		}
		$settings = Leadwerk_Migration_Site_Identity::sanitize_settings( $raw );

		if ( empty( $current['hub_mode'] ) && ! empty( $settings['hub_mode'] ) ) {
			$confirmation = isset( $_POST['hub_confirmation'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['hub_confirmation'] ) ) ) : '';
			if ( ! hash_equals( 'ENABLE HUB', $confirmation ) ) {
				self::finish( new WP_Error( 'leadwerk_sync_hub_confirmation', __( 'Hub mode was not enabled. Enter ENABLE HUB exactly to confirm the central registry role.', 'leadwerk-migration' ) ) );
			}
		}

		update_option( LEADWERK_MIGRATION_SYNC_SETTINGS, $settings, false );
		Leadwerk_Migration_Site_Identity::ensure_environment();
		Leadwerk_Migration_Sync_Hub::maybe_install();

		$family = get_option( LEADWERK_MIGRATION_SYNC_FAMILY, array() );
		$project_name = isset( $_POST['project_name'] ) ? sanitize_text_field( wp_unslash( $_POST['project_name'] ) ) : '';
		if ( is_array( $family ) && Leadwerk_Migration_Site_Identity::valid_id( isset( $family['family_id'] ) ? $family['family_id'] : '' ) && $project_name !== '' ) {
			Leadwerk_Migration_Site_Identity::ensure_family( $project_name );
		}
		self::finish( true, __( 'Site Sync settings saved.', 'leadwerk-migration' ) );
	}

	/** Explicit administrator-controlled Family creation for this installation. */
	public static function initialize() {
		self::require_action( 'leadwerk_migration_sync_initialize' );
		$existing = get_option( LEADWERK_MIGRATION_SYNC_FAMILY, array() );
		$had_family = is_array( $existing ) && Leadwerk_Migration_Site_Identity::valid_id( isset( $existing['family_id'] ) ? $existing['family_id'] : '' );
		$project_name = isset( $_POST['project_name'] ) ? sanitize_text_field( wp_unslash( $_POST['project_name'] ) ) : '';
		$identity = Leadwerk_Migration_Site_Identity::originate_family( $project_name );
		if ( is_wp_error( $identity ) ) {
			self::finish( $identity );
		}
		$result = Leadwerk_Migration_Sync::bootstrap_registration();
		if ( is_wp_error( $result ) && ! $had_family ) {
			// Keep the explicit local identity and surface the real enrollment error;
			// the worker will retry without manufacturing another Family ID.
			self::finish( $result );
		}
		self::finish( $result, __( 'Site Family identity was created and automatically enrolled with Hub. No backup was required.', 'leadwerk-migration' ) );
	}

	public static function register() {
		self::require_action( 'leadwerk_migration_sync_register' );
		self::finish( Leadwerk_Migration_Sync::bootstrap_registration(), __( 'Automatic Hub enrollment refreshed.', 'leadwerk-migration' ) );
	}

	public static function promote_live() {
		self::require_action( 'leadwerk_migration_sync_promote_live' );
		$environment = Leadwerk_Migration_Site_Identity::ensure_environment();
		if ( isset( $environment['type'] ) && 'live' === $environment['type'] ) {
			self::finish( true, __( 'This environment is already marked as live.', 'leadwerk-migration' ) );
		}
		$confirmation = isset( $_POST['promotion_confirmation'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['promotion_confirmation'] ) ) ) : '';
		if ( ! hash_equals( 'PROMOTE LIVE', $confirmation ) ) {
			self::finish( new WP_Error( 'leadwerk_sync_promotion_confirmation', __( 'Promotion was not completed. Enter PROMOTE LIVE exactly.', 'leadwerk-migration' ) ) );
		}
		Leadwerk_Migration_Site_Identity::promote_to_live();
		// Best effort: an offline Hub will receive the new URL/type on the next
		// scheduled heartbeat without rolling back the local promotion.
		Leadwerk_Migration_Sync::heartbeat();
		self::finish( true, __( 'The existing staging environment was promoted to live without changing its environment identity. The Hub will reflect it on the next heartbeat.', 'leadwerk-migration' ) );
	}

	public static function request_sync() {
		self::require_action( 'leadwerk_migration_sync_request' );
		$source_id = isset( $_POST['source_environment_id'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['source_environment_id'] ) ) ) : '';
		$target_id = isset( $_POST['target_environment_id'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['target_environment_id'] ) ) ) : '';
		if ( ! Leadwerk_Migration_Site_Identity::valid_id( $source_id ) || ! Leadwerk_Migration_Site_Identity::valid_id( $target_id ) ) {
			self::finish( new WP_Error( 'leadwerk_sync_invalid_selection', __( 'Choose a valid source and target environment.', 'leadwerk-migration' ) ) );
		}

		$result = Leadwerk_Migration_Sync_Hub::enabled()
			? Leadwerk_Migration_Sync_Hub::create_admin_job( $source_id, $target_id )
			: Leadwerk_Migration_Sync::request_sync( $source_id, $target_id );
		self::finish( $result, __( 'Version and online checks passed. Full-site synchronization started without registration or approval steps.', 'leadwerk-migration' ) );
	}

	/** Start a full-site synchronization without navigating away from Site Sync. */
	public static function ajax_request_sync() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! Leadwerk_Migration_Security::authorize_admin_action( 'manage_options', 'leadwerk_migration_sync_request', $nonce ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'leadwerk-migration' ) ), 403 );
		}

		$source_id = isset( $_POST['source_environment_id'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['source_environment_id'] ) ) ) : '';
		$target_id = isset( $_POST['target_environment_id'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['target_environment_id'] ) ) ) : '';
		if ( ! Leadwerk_Migration_Site_Identity::valid_id( $source_id ) || ! Leadwerk_Migration_Site_Identity::valid_id( $target_id ) || hash_equals( $source_id, $target_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose two different active environments.', 'leadwerk-migration' ) ), 400 );
		}

		$result = Leadwerk_Migration_Sync_Hub::enabled()
			? Leadwerk_Migration_Sync_Hub::create_admin_job( $source_id, $target_id )
			: Leadwerk_Migration_Sync::request_sync( $source_id, $target_id );
		if ( is_wp_error( $result ) || false === $result ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Synchronization could not be started.', 'leadwerk-migration' );
			wp_send_json_error( array( 'message' => $message ), 409 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Version and online checks passed. Full-site synchronization started.', 'leadwerk-migration' ),
			),
			201
		);
	}

	/** Retained for old admin links; version 1.2+ jobs never require this step. */
	public static function approve_sync() {
		self::require_action( 'leadwerk_migration_sync_approve' );
		self::finish( true, __( 'Site Sync registration and transfer approvals are automatic in Leadwerk Migration 1.2 and later.', 'leadwerk-migration' ) );
	}

	public static function process_now() {
		self::require_action( 'leadwerk_migration_sync_process' );
		self::finish( Leadwerk_Migration_Sync::process(), __( 'The Site Sync worker completed its current batch.', 'leadwerk-migration' ) );
	}

	public static function runtime_reconcile() {
		self::require_action( 'leadwerk_migration_runtime_reconcile' );
		$reference_id = isset( $_POST['reference_environment_id'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['reference_environment_id'] ) ) ) : '';
		$target_id = isset( $_POST['target_environment_id'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['target_environment_id'] ) ) ) : '';
		if ( ! Leadwerk_Migration_Site_Identity::valid_id( $reference_id ) || ! Leadwerk_Migration_Site_Identity::valid_id( $target_id ) ) {
			self::finish( new WP_Error( 'leadwerk_runtime_invalid_selection', __( 'Choose valid reference and target environments.', 'leadwerk-migration' ) ) );
		}
		self::finish( Leadwerk_Migration_Sync::request_runtime_reconcile( $reference_id, $target_id ), __( 'Runtime reconciliation request queued. The target will update Leadwerk Migration, WordPress and then request its PHP change.', 'leadwerk-migration' ) );
	}

	public static function cancel_job() {
		self::require_action( 'leadwerk_migration_sync_cancel_job' );
		$job_id = isset( $_POST['job_id'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) ) : '';
		$result = Leadwerk_Migration_Site_Identity::valid_id( $job_id )
			? ( Leadwerk_Migration_Sync_Hub::enabled() ? Leadwerk_Migration_Sync_Hub::admin_cancel_job( $job_id ) : Leadwerk_Migration_Sync::cancel_job( $job_id ) )
			: new WP_Error( 'leadwerk_sync_job_invalid', __( 'Invalid sync job.', 'leadwerk-migration' ) );
		self::finish( $result, __( 'Sync job canceled.', 'leadwerk-migration' ) );
	}

	public static function retry_job() {
		self::require_action( 'leadwerk_migration_sync_retry_job' );
		$job_id = isset( $_POST['job_id'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) ) : '';
		$result = Leadwerk_Migration_Site_Identity::valid_id( $job_id )
			? ( Leadwerk_Migration_Sync_Hub::enabled() ? Leadwerk_Migration_Sync_Hub::admin_retry_job( $job_id ) : Leadwerk_Migration_Sync::retry_job( $job_id ) )
			: new WP_Error( 'leadwerk_sync_job_invalid', __( 'Invalid sync job.', 'leadwerk-migration' ) );
		self::finish( $result, __( 'A new sync retry job was created.', 'leadwerk-migration' ) );
	}

	public static function cancel_command() {
		self::require_action( 'leadwerk_migration_runtime_cancel_command' );
		$command_id = isset( $_POST['command_id'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['command_id'] ) ) ) : '';
		self::finish( Leadwerk_Migration_Site_Identity::valid_id( $command_id ) ? Leadwerk_Migration_Sync::cancel_command( $command_id ) : new WP_Error( 'leadwerk_runtime_command_invalid', __( 'Invalid runtime command.', 'leadwerk-migration' ) ), __( 'Runtime command canceled.', 'leadwerk-migration' ) );
	}

	public static function retry_command() {
		self::require_action( 'leadwerk_migration_runtime_retry_command' );
		$command_id = isset( $_POST['command_id'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['command_id'] ) ) ) : '';
		self::finish( Leadwerk_Migration_Site_Identity::valid_id( $command_id ) ? Leadwerk_Migration_Sync::retry_command( $command_id ) : new WP_Error( 'leadwerk_runtime_command_invalid', __( 'Invalid runtime command.', 'leadwerk-migration' ) ), __( 'Runtime command retry queued.', 'leadwerk-migration' ) );
	}

	public static function hub_approve() {
		self::hub_status( 'approved', 'leadwerk_migration_hub_approve' );
	}

	public static function hub_revoke() {
		self::hub_status( 'revoked', 'leadwerk_migration_hub_revoke' );
	}

	private static function hub_status( $status, $nonce_action ) {
		self::require_action( $nonce_action );
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$id = isset( $_POST['registry_id'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['registry_id'] ) ) ) : '';
		$result = Leadwerk_Migration_Sync_Hub::set_registry_status( $kind, $id, $status );
		self::finish( $result ? true : new WP_Error( 'leadwerk_hub_status_failed', __( 'The Hub registry status could not be changed.', 'leadwerk-migration' ) ), __( 'Hub registry status updated.', 'leadwerk-migration' ) );
	}

	private static function sanitize_environments( $rows ) {
		$clean = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || ! Leadwerk_Migration_Site_Identity::valid_id( isset( $row['environment_id'] ) ? $row['environment_id'] : '' ) ) {
				continue;
			}
			$last_seen = isset( $row['last_seen_at'] ) ? sanitize_text_field( $row['last_seen_at'] ) : '';
			$last_seen_timestamp = '' !== $last_seen ? strtotime( $last_seen . ' UTC' ) : false;
			$clean[] = array(
				'environment_id'       => strtolower( $row['environment_id'] ),
				'family_id'            => Leadwerk_Migration_Site_Identity::valid_id( isset( $row['family_id'] ) ? $row['family_id'] : '' ) ? strtolower( $row['family_id'] ) : '',
				'site_url'             => esc_url_raw( isset( $row['site_url'] ) ? $row['site_url'] : '' ),
				'environment_type'     => sanitize_key( isset( $row['environment_type'] ) ? $row['environment_type'] : 'live' ),
				'wordpress_version'    => substr( sanitize_text_field( isset( $row['wordpress_version'] ) ? $row['wordpress_version'] : '' ), 0, 32 ),
				'php_version'          => substr( sanitize_text_field( isset( $row['php_version'] ) ? $row['php_version'] : '' ), 0, 32 ),
				'plugin_version'       => substr( sanitize_text_field( isset( $row['plugin_version'] ) ? $row['plugin_version'] : '' ), 0, 32 ),
				'status'               => sanitize_key( isset( $row['status'] ) ? $row['status'] : '' ),
				'is_online'            => ! empty( $row['is_online'] ),
				'last_seen_at'         => $last_seen,
				'last_seen_seconds_ago' => false !== $last_seen_timestamp ? max( 0, time() - $last_seen_timestamp ) : null,
			);
		}
		return $clean;
	}

	private static function sanitize_jobs( $rows ) {
		$clean = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || ! Leadwerk_Migration_Site_Identity::valid_id( isset( $row['job_id'] ) ? $row['job_id'] : '' ) ) {
				continue;
			}
			$clean[] = array(
				'job_id'                => strtolower( $row['job_id'] ),
				'source_environment_id' => strtolower( sanitize_text_field( isset( $row['source_environment_id'] ) ? $row['source_environment_id'] : '' ) ),
				'target_environment_id' => strtolower( sanitize_text_field( isset( $row['target_environment_id'] ) ? $row['target_environment_id'] : '' ) ),
				'status'                => sanitize_key( isset( $row['status'] ) ? $row['status'] : '' ),
				'archive_size'          => max( 0, (int) ( isset( $row['archive_size'] ) ? $row['archive_size'] : 0 ) ),
				'bytes_received'        => max( 0, (int) ( isset( $row['bytes_received'] ) ? $row['bytes_received'] : 0 ) ),
				'bytes_delivered'       => max( 0, (int) ( isset( $row['bytes_delivered'] ) ? $row['bytes_delivered'] : 0 ) ),
				'transport'             => sanitize_key( isset( $row['transport'] ) ? $row['transport'] : '' ),
				'message'               => substr( sanitize_text_field( isset( $row['message'] ) ? $row['message'] : '' ), 0, 1000 ),
				'created_at'            => substr( sanitize_text_field( isset( $row['created_at'] ) ? $row['created_at'] : '' ), 0, 32 ),
				'updated_at'            => substr( sanitize_text_field( isset( $row['updated_at'] ) ? $row['updated_at'] : '' ), 0, 32 ),
			);
		}
		return $clean;
	}

	private static function sanitize_commands( $rows ) {
		$clean = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || ! Leadwerk_Migration_Site_Identity::valid_id( isset( $row['command_id'] ) ? $row['command_id'] : '' ) ) {
				continue;
			}
			$payload = isset( $row['payload'] ) && is_array( $row['payload'] ) ? $row['payload'] : array();
			$clean[] = array(
				'command_id'           => strtolower( $row['command_id'] ),
				'target_environment_id' => strtolower( sanitize_text_field( isset( $row['target_environment_id'] ) ? $row['target_environment_id'] : '' ) ),
				'status'               => sanitize_key( isset( $row['status'] ) ? $row['status'] : '' ),
				'message'              => substr( sanitize_text_field( isset( $row['message'] ) ? $row['message'] : '' ), 0, 1000 ),
				'created_at'           => substr( sanitize_text_field( isset( $row['created_at'] ) ? $row['created_at'] : '' ), 0, 32 ),
				'updated_at'           => substr( sanitize_text_field( isset( $row['updated_at'] ) ? $row['updated_at'] : '' ), 0, 32 ),
				'payload'              => array(
					'wordpress_version' => substr( sanitize_text_field( isset( $payload['wordpress_version'] ) ? $payload['wordpress_version'] : '' ), 0, 32 ),
					'php_version'       => substr( sanitize_text_field( isset( $payload['php_version'] ) ? $payload['php_version'] : '' ), 0, 32 ),
					'plugin_version'    => substr( sanitize_text_field( isset( $payload['plugin_version'] ) ? $payload['plugin_version'] : '' ), 0, 32 ),
					'stage'             => sanitize_key( isset( $payload['stage'] ) ? $payload['stage'] : '' ),
				),
			);
		}
		return $clean;
	}

	private static function sanitize_families( $rows ) {
		$clean = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || ! Leadwerk_Migration_Site_Identity::valid_id( isset( $row['family_id'] ) ? $row['family_id'] : '' ) ) {
				continue;
			}
			$clean[] = array(
				'family_id'   => strtolower( $row['family_id'] ),
				'project_name' => substr( sanitize_text_field( isset( $row['project_name'] ) ? $row['project_name'] : '' ), 0, 190 ),
				'status'      => sanitize_key( isset( $row['status'] ) ? $row['status'] : '' ),
			);
		}
		return $clean;
	}

	private static function find_environment( $directory, $id ) {
		foreach ( is_array( $directory ) ? $directory : array() as $environment ) {
			if ( is_array( $environment ) && isset( $environment['environment_id'] ) && hash_equals( (string) $environment['environment_id'], (string) $id ) ) {
				return $environment;
			}
		}
		return null;
	}

	private static function require_action( $nonce_action ) {
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! Leadwerk_Migration_Security::authorize_admin_action( 'manage_options', $nonce_action, $nonce ) ) {
			wp_die( esc_html__( 'Security check failed.', 'leadwerk-migration' ), '', array( 'response' => 403 ) );
		}
	}

	private static function finish( $result, $success_message = '' ) {
		if ( is_wp_error( $result ) || false === $result ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'The Site Sync action failed.', 'leadwerk-migration' );
			self::store_notice( 'error', $message );
		} else {
			self::store_notice( 'success', $success_message !== '' ? $success_message : __( 'Site Sync action completed.', 'leadwerk-migration' ) );
		}
		wp_safe_redirect( add_query_arg( 'page', 'leadwerk_migration_sync', admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function store_notice( $type, $message ) {
		set_transient(
			self::NOTICE_PREFIX . get_current_user_id(),
			array( 'type' => 'success' === $type ? 'success' : 'error', 'message' => substr( wp_strip_all_tags( (string) $message ), 0, 2000 ) ),
			MINUTE_IN_SECONDS * 5
		);
	}

	private static function consume_notice() {
		$key = self::NOTICE_PREFIX . get_current_user_id();
		$notice = get_transient( $key );
		delete_transient( $key );
		return is_array( $notice ) ? $notice : array();
	}
}
