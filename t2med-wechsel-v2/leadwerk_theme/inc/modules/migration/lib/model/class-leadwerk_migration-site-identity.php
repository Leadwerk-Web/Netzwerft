<?php
/**
 * Project-family and per-environment identity management for Site Sync.
 *
 * Copyright (C) 2026 Leadwerk.
 * Licensed under GPLv3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access is not allowed.' );
}

class Leadwerk_Migration_Site_Identity {

	const SNAPSHOT_NAME = 'leadwerk-sync-target.json';
	const MIN_ORIGIN_RECORDS = 5;

	/**
	 * Return the stored Site Family without creating one.
	 *
	 * @return array<string, mixed>
	 */
	public static function current_family() {
		$family = get_option( LEADWERK_MIGRATION_SYNC_FAMILY, array() );
		return is_array( $family ) ? $family : array();
	}

	/**
	 * Maintain an existing project identity without silently creating one.
	 *
	 * New identities must pass originate_family(). This separation prevents a
	 * freshly installed, empty restore target from becoming an unrelated family
	 * before it receives the trusted source database.
	 *
	 * @param string $project_name Optional project label.
	 * @return array<string, mixed>
	 */
	public static function ensure_family( $project_name = '' ) {
		$family = self::current_family();

		if ( ! self::valid_id( isset( $family['family_id'] ) ? $family['family_id'] : '' ) ) {
			return array();
		}

		$changed = false;
		if ( empty( $family['family_secret'] ) || ! is_string( $family['family_secret'] ) ) {
			$family['family_secret'] = self::random_secret();
			$changed                 = true;
		}
		if ( $project_name !== '' ) {
			$clean_name = self::project_name( $project_name );
			if ( ! isset( $family['project_name'] ) || $clean_name !== $family['project_name'] ) {
				$family['project_name'] = $clean_name;
				$changed                 = true;
			}
		}
		if ( $changed ) {
			update_option( LEADWERK_MIGRATION_SYNC_FAMILY, $family, false );
		}

		self::ensure_environment();

		return $family;
	}

	/**
	 * Create a new Site Family only after an explicit administrator action.
	 *
	 * Plugin activation and background workers never call this method. Keeping
	 * creation behind the Site Sync button lets blank WordPress installations
	 * register only as available transfer targets without polluting the Hub with
	 * unrelated Site Families.
	 *
	 * @param string $project_name Optional project label.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function originate_family( $project_name = '' ) {
		$family = self::ensure_family( $project_name );
		if ( self::valid_id( isset( $family['family_id'] ) ? $family['family_id'] : '' ) ) {
			return $family;
		}
		$environment = self::ensure_environment();
		if ( self::valid_id( isset( $environment['claimed_family_id'] ) ? $environment['claimed_family_id'] : '' ) ) {
			return new WP_Error( 'leadwerk_sync_target_already_claimed', __( 'This available target has already been assigned to a source Site Family and is waiting for that synchronization.', 'leadwerk-migration' ) );
		}

		$family = array(
			'family_id'     => self::random_id(),
			'family_secret' => self::random_secret(),
			'project_name'  => self::project_name( $project_name ),
			'zone_zero_url' => self::normalized_site_url(),
			'origin_mode'   => 'manual',
			'created_at'    => gmdate( 'c' ),
			'version'       => 2,
		);
		update_option( LEADWERK_MIGRATION_SYNC_FAMILY, $family, false );
		self::ensure_environment();

		return $family;
	}

	/**
	 * Reuse an inherited family without ever originating one implicitly.
	 *
	 * @param string $project_name Optional project label.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function maybe_originate_family( $project_name = '' ) {
		$family = self::ensure_family( $project_name );
		if ( self::valid_id( isset( $family['family_id'] ) ? $family['family_id'] : '' ) ) {
			return $family;
		}

		return new WP_Error(
			'leadwerk_sync_manual_family_required',
			__( 'Create the Site Family explicitly from the Site Sync screen.', 'leadwerk-migration' )
		);
	}

	/**
	 * A generic plugin ZIP cannot know which family an empty target belongs to.
	 * Only meaningful user content qualifies an installation as a new origin.
	 * Advanced non-content origins can opt in explicitly from wp-config.php.
	 *
	 * @return bool
	 */
	public static function site_can_originate_family() {
		if ( defined( 'LEADWERK_MIGRATION_ALLOW_FAMILY_ORIGIN' ) && true === LEADWERK_MIGRATION_ALLOW_FAMILY_ORIGIN ) {
			return true;
		}
		if ( '1' === (string) get_option( 'fresh_site', '1' ) ) {
			return false;
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) || empty( $wpdb->posts ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		// Ignore WordPress' first three sample/privacy records and generated editor
		// state. Requiring several real records also prevents a default form/plugin
		// seed from turning an otherwise empty restore target into a new family.
		$sql = "SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_status <> 'trash'
			AND (
				post_type = 'attachment'
				OR (
					post_status NOT IN ('auto-draft', 'inherit')
					AND post_type NOT IN ('revision', 'custom_css', 'customize_changeset', 'wp_global_styles')
					AND NOT (ID <= 3 AND post_type IN ('post', 'page'))
				)
			)";

		return (int) $wpdb->get_var( $sql ) >= self::MIN_ORIGIN_RECORDS; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Remove a local-only provisional family made by pre-1.5.8 on a blank target.
	 *
	 * Approved Hub families and inherited families whose Zone Zero URL belongs to
	 * another installation are never touched. This is intentionally a narrow,
	 * recoverable migration for identities that never left the empty target.
	 *
	 * @return bool
	 */
	public static function discard_legacy_blank_family() {
		$family = self::current_family();
		if (
			! self::valid_id( isset( $family['family_id'] ) ? $family['family_id'] : '' ) ||
			isset( $family['origin_mode'] ) ||
			self::site_can_originate_family() ||
			empty( $family['zone_zero_url'] ) ||
			! hash_equals( self::normalized_site_url(), self::normalize_url( $family['zone_zero_url'] ) )
		) {
			return false;
		}

		$environment  = get_option( LEADWERK_MIGRATION_SYNC_ENVIRONMENT, array() );
		$environment  = is_array( $environment ) ? $environment : array();
		$registration = isset( $environment['registration'] ) ? sanitize_key( $environment['registration'] ) : 'unregistered';
		if ( ! in_array( $registration, array( '', 'unregistered', 'awaiting_family' ), true ) ) {
			return false;
		}

		delete_option( LEADWERK_MIGRATION_SYNC_FAMILY );
		$environment['registration'] = 'awaiting_family';
		unset( $environment['family_status'], $environment['last_registered_at'] );
		update_option( LEADWERK_MIGRATION_SYNC_ENVIRONMENT, $environment, false );

		return true;
	}

	/**
	 * Ensure this installation has credentials distinct from its siblings.
	 *
	 * @param bool $rotate Force a new identity and credential.
	 * @return array<string, mixed>
	 */
	public static function ensure_environment( $rotate = false ) {
		$environment = get_option( LEADWERK_MIGRATION_SYNC_ENVIRONMENT, array() );
		$environment = is_array( $environment ) ? $environment : array();
		$settings    = self::settings();
		$current_url = self::normalized_site_url();
		$fingerprint = self::environment_fingerprint( $current_url );
		$installation_id = self::installation_id();
		$stored_installation_id = isset( $environment['installation_id'] ) && self::valid_id( $environment['installation_id'] ) ? $environment['installation_id'] : '';

		// A private installation marker is not part of WordPress exports. Therefore
		// a cloned database receives a new environment identity, while a domain
		// change on the same physical installation preserves its identity and can
		// safely promote staging to live.
		$url_changed = ! empty( $environment['fingerprint'] ) && ! hash_equals( (string) $environment['fingerprint'], $fingerprint );
		$installation_changed = self::valid_id( $installation_id ) && self::valid_id( $stored_installation_id ) && ! hash_equals( $stored_installation_id, $installation_id );
		$marker_unavailable = ! self::valid_id( $installation_id );

		if (
			$rotate ||
			$installation_changed ||
			( $marker_unavailable && $url_changed ) ||
			! self::valid_id( isset( $environment['environment_id'] ) ? $environment['environment_id'] : '' ) ||
			empty( $environment['environment_secret'] )
		) {
			$environment = array(
				'environment_id'     => self::random_id(),
				'environment_secret' => self::random_secret(),
				'url'                => $current_url,
				'type'               => self::environment_type( $current_url, isset( $settings['environment_type'] ) ? $settings['environment_type'] : 'auto' ),
				'fingerprint'        => $fingerprint,
				'installation_id'    => $installation_id,
				'registration'       => 'unregistered',
				'created_at'         => gmdate( 'c' ),
				'last_registered_at' => '',
				'version'            => 1,
			);
		} else {
			$previous_url  = isset( $environment['url'] ) ? self::normalize_url( $environment['url'] ) : '';
			$previous_type = isset( $environment['type'] ) ? sanitize_key( $environment['type'] ) : '';
			$environment['url']         = $current_url;
			$environment['type']        = self::environment_type( $current_url, isset( $settings['environment_type'] ) ? $settings['environment_type'] : 'auto' );
			$environment['fingerprint'] = $fingerprint;
			if ( self::valid_id( $installation_id ) ) {
				$environment['installation_id'] = $installation_id;
			}
			if ( $previous_url !== '' && $previous_url !== $current_url ) {
				$history = isset( $environment['url_history'] ) && is_array( $environment['url_history'] ) ? $environment['url_history'] : array();
				array_unshift( $history, array( 'url' => $previous_url, 'type' => $previous_type, 'changed_at' => gmdate( 'c' ) ) );
				$environment['url_history'] = array_slice( $history, 0, 8 );
			}
			if ( 'staging' === $previous_type && 'live' === $environment['type'] ) {
				$environment['promoted_from'] = 'staging';
				$environment['promoted_at']   = gmdate( 'c' );
			}
		}

		update_option( LEADWERK_MIGRATION_SYNC_ENVIRONMENT, $environment, false );

		return $environment;
	}

	/** Explicitly promote a custom-domain staging installation to live. */
	public static function promote_to_live() {
		$settings = self::settings();
		$settings['environment_type'] = 'live';
		update_option( LEADWERK_MIGRATION_SYNC_SETTINGS, self::sanitize_settings( $settings ), false );
		return self::ensure_environment();
	}

	/**
	 * Public, non-secret metadata embedded in package.json.
	 *
	 * @param array $family Family record.
	 * @return array<string, mixed>
	 */
	public static function archive_metadata( $family = array() ) {
		$family      = is_array( $family ) && ! empty( $family ) ? $family : self::current_family();
		$environment = self::ensure_environment();

		return array(
			'Version'         => 1,
			'FamilyId'        => isset( $family['family_id'] ) ? $family['family_id'] : '',
			'ProjectName'     => isset( $family['project_name'] ) ? $family['project_name'] : '',
			'ZoneZeroURL'     => isset( $family['zone_zero_url'] ) ? $family['zone_zero_url'] : '',
			'EnvironmentId'   => isset( $environment['environment_id'] ) ? $environment['environment_id'] : '',
			'EnvironmentType' => isset( $environment['type'] ) ? $environment['type'] : '',
			'HubURL'          => self::hub_url(),
			'CreatedAt'       => gmdate( 'c' ),
		);
	}

	/**
	 * Validate the package family and preserve target credentials outside the
	 * database before destructive import stages begin.
	 *
	 * @param array $params  Import parameters.
	 * @param array $package Parsed package.json.
	 * @return true|WP_Error
	 */
	public static function prepare_import_identity( $params, $package ) {
		$family       = get_option( LEADWERK_MIGRATION_SYNC_FAMILY, array() );
		$environment  = self::ensure_environment();
		$settings     = self::settings();
		$metadata     = isset( $package['LeadwerkSync'] ) && is_array( $package['LeadwerkSync'] ) ? $package['LeadwerkSync'] : array();
		$source_id    = isset( $metadata['FamilyId'] ) && is_string( $metadata['FamilyId'] ) ? strtolower( trim( $metadata['FamilyId'] ) ) : '';
		$current_id   = is_array( $family ) && isset( $family['family_id'] ) && is_string( $family['family_id'] ) ? strtolower( trim( $family['family_id'] ) ) : '';
		$is_sync      = ! empty( $params['leadwerk_migration_sync_job_id'] );

		if ( $is_sync && ! self::valid_id( $source_id ) ) {
			return new WP_Error( 'leadwerk_sync_family_missing', __( 'Site Sync import blocked: the archive has no valid Zone Zero project identity.', 'leadwerk-migration' ) );
		}

		$adopting_family = self::valid_id( $source_id ) && self::valid_id( $current_id ) && ! hash_equals( $current_id, $source_id );
		if ( $is_sync && $adopting_family ) {
			return new WP_Error( 'leadwerk_sync_family_mismatch', __( 'Import blocked: this backup belongs to a different Leadwerk Site Family.', 'leadwerk-migration' ) );
		}

		// A pre-1.5.8 installation or an explicitly initialized destination may have
		// a different local family before its first manual restore. When it adopts a
		// trusted backup, preserve the physical target but rotate its environment
		// credential so two families can never share one Hub identity.
		if ( ! $is_sync && $adopting_family ) {
			$environment = array(
				'environment_id'     => self::random_id(),
				'environment_secret' => self::random_secret(),
				'installation_id'    => self::installation_id(),
				'url'                => self::normalized_site_url(),
				'type'               => self::environment_type( self::normalized_site_url(), isset( $settings['environment_type'] ) ? $settings['environment_type'] : 'auto' ),
				'fingerprint'        => self::environment_fingerprint( self::normalized_site_url() ),
				'registration'       => 'unregistered',
				'created_at'         => gmdate( 'c' ),
				'last_registered_at' => '',
				'version'            => 1,
			);
		}

		$snapshot = array(
			'environment' => $environment,
			'settings'    => $settings,
			// These records describe files that remain on the destination's private
			// volume. Preserve them when the source database is restored so the
			// automatic pre-sync safety backup remains visible and verifiable.
			'backup_manifests' => get_option( LEADWERK_MIGRATION_BACKUP_MANIFESTS, array() ),
			'sync_state'  => get_option( LEADWERK_MIGRATION_SYNC_STATE, array() ),
			'target_url'  => self::normalized_site_url(),
			'family_id'   => self::valid_id( $source_id ) ? $source_id : $current_id,
			'sync_job_id' => $is_sync && is_string( $params['leadwerk_migration_sync_job_id'] ) ? $params['leadwerk_migration_sync_job_id'] : '',
			'created_at'  => gmdate( 'c' ),
		);

		$path    = leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . self::SNAPSHOT_NAME;
		$encoded = wp_json_encode( $snapshot );
		if ( ! is_string( $encoded ) || @file_put_contents( $path, $encoded, LOCK_EX ) !== strlen( $encoded ) ) {
			return new WP_Error( 'leadwerk_sync_identity_snapshot_failed', __( 'Import blocked: the destination environment identity could not be preserved safely.', 'leadwerk-migration' ) );
		}

		@chmod( $path, 0600 );

		return true;
	}

	/**
	 * Restore target-specific credentials after the imported database replaced
	 * the source options. Runs before the sync completion callback.
	 *
	 * @param array $params Import parameters.
	 * @return void
	 */
	public static function restore_after_import( $params ) {
		$path = leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . self::SNAPSHOT_NAME;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			self::ensure_environment( true );
			return;
		}

		$contents = @file_get_contents( $path );
		$snapshot = is_string( $contents ) ? json_decode( $contents, true ) : null;
		if ( ! is_array( $snapshot ) || empty( $snapshot['environment'] ) || ! is_array( $snapshot['environment'] ) ) {
			self::ensure_environment( true );
			return;
		}

		$environment = $snapshot['environment'];
		$target_url  = isset( $snapshot['target_url'] ) && is_string( $snapshot['target_url'] ) ? self::normalize_url( $snapshot['target_url'] ) : self::normalized_site_url();
		$settings    = isset( $snapshot['settings'] ) && is_array( $snapshot['settings'] ) ? self::sanitize_settings( $snapshot['settings'] ) : self::settings();

		$environment['url']         = $target_url;
		$environment['type']        = self::environment_type( $target_url, isset( $settings['environment_type'] ) ? $settings['environment_type'] : 'auto' );
		$environment['fingerprint'] = self::environment_fingerprint( $target_url );
		update_option( LEADWERK_MIGRATION_SYNC_ENVIRONMENT, $environment, false );
		update_option( LEADWERK_MIGRATION_SYNC_SETTINGS, $settings, false );

		// A local or staging source commonly has Discourage search engines enabled.
		// Never copy that flag onto a live destination: after the target-specific
		// URL/type has been restored, force the canonical WordPress visibility
		// option on before the synchronization is reported as complete.
		if ( 'live' === $environment['type'] ) {
			update_option( 'blog_public', '1' );
		}
		if ( isset( $snapshot['backup_manifests'] ) && is_array( $snapshot['backup_manifests'] ) ) {
			update_option( LEADWERK_MIGRATION_BACKUP_MANIFESTS, $snapshot['backup_manifests'], false );
		}
		if ( isset( $snapshot['sync_state'] ) && is_array( $snapshot['sync_state'] ) ) {
			update_option( LEADWERK_MIGRATION_SYNC_STATE, $snapshot['sync_state'], false );
		}
	}

	/** @return array<string, mixed> */
	public static function settings() {
		$settings = get_option( LEADWERK_MIGRATION_SYNC_SETTINGS, array() );
		return self::sanitize_settings( is_array( $settings ) ? $settings : array() );
	}

	/** @param array $settings @return array<string, mixed> */
	public static function sanitize_settings( $settings ) {
		$hub_url = isset( $settings['hub_url'] ) ? self::normalize_url( $settings['hub_url'] ) : 'https://www.leadwerk.de';
		if ( $hub_url === '' ) {
			$hub_url = 'https://www.leadwerk.de';
		}

		$type = isset( $settings['environment_type'] ) ? sanitize_key( $settings['environment_type'] ) : 'auto';
		if ( ! in_array( $type, array( 'auto', 'local', 'staging', 'live' ), true ) ) {
			$type = 'auto';
		}

		$php_webhook = isset( $settings['php_update_webhook_url'] ) ? self::normalize_url( $settings['php_update_webhook_url'] ) : '';
		if ( '' !== $php_webhook && ( 'https' !== wp_parse_url( $php_webhook, PHP_URL_SCHEME ) || ! wp_http_validate_url( $php_webhook ) ) ) {
			$php_webhook = '';
		}
		$php_secret = isset( $settings['php_update_webhook_secret'] ) && is_string( $settings['php_update_webhook_secret'] ) ? trim( $settings['php_update_webhook_secret'] ) : '';

		return array(
			'hub_url'          => $hub_url,
			'environment_type' => $type,
			'hub_mode'         => ! empty( $settings['hub_mode'] ),
			'php_update_webhook_url' => $php_webhook,
			// Preserve opaque HMAC key bytes represented as printable text. HTML is
			// never rendered from this value and the settings view never exposes it.
			'php_update_webhook_secret' => substr( preg_replace( '/[\x00-\x1F\x7F]/', '', $php_secret ), 0, 256 ),
		);
	}

	/** @return string */
	public static function hub_url() {
		$settings = self::settings();
		return $settings['hub_url'];
	}

	/**
	 * Environment detection. A hostname ending in .local is always local.
	 *
	 * @param string $url      Site URL.
	 * @param string $override auto/local/staging/live.
	 * @return string
	 */
	public static function environment_type( $url, $override = 'auto' ) {
		$override = sanitize_key( (string) $override );
		if ( in_array( $override, array( 'local', 'staging', 'live' ), true ) ) {
			return $override;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if (
			$host === 'localhost' ||
			$host === '127.0.0.1' ||
			$host === '::1' ||
			preg_match( '/\.(?:local|localhost|test)\z/i', $host )
		) {
			return 'local';
		}

		if ( strpos( $host, 'myrdbx.io' ) !== false || preg_match( '/(?:^|[.-])(?:staging|stage|stg|dev)(?:[.-]|$)/i', $host ) ) {
			return 'staging';
		}

		return 'live';
	}

	/** @param mixed $value @return bool */
	public static function valid_id( $value ) {
		return is_string( $value ) && (bool) preg_match( '/\A[a-f0-9]{32}\z/D', $value );
	}

	/** Return the compatibility branch (major.minor) for a PHP version. */
	public static function php_branch( $version ) {
		$version = is_string( $version ) ? trim( $version ) : '';
		if ( ! preg_match( '/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:\.(0|[1-9][0-9]*)(?:[A-Za-z0-9._+~-]*))?\z/D', $version, $matches ) ) {
			return '';
		}
		return (int) $matches[1] . '.' . (int) $matches[2];
	}

	/** Patch releases may differ, but PHP major.minor must be identical. */
	public static function php_versions_compatible( $source, $target ) {
		$source_branch = self::php_branch( $source );
		$target_branch = self::php_branch( $target );
		return '' !== $source_branch && '' !== $target_branch && hash_equals( $source_branch, $target_branch );
	}

	/** @return string */
	public static function random_id() {
		return Leadwerk_Migration_Security::job_id();
	}

	/** @return string */
	public static function random_secret() {
		return Leadwerk_Migration_Security::generate_secret();
	}

	/** @return string */
	public static function normalized_site_url() {
		return self::normalize_url( home_url( '/' ) );
	}

	/**
	 * Return a physical-installation marker kept outside the WordPress export.
	 *
	 * @return string Empty only when private storage is unavailable.
	 */
	public static function installation_id() {
		if ( ! defined( 'LEADWERK_MIGRATION_PRIVATE_ROOT' ) || ! is_dir( LEADWERK_MIGRATION_PRIVATE_ROOT ) && ! wp_mkdir_p( LEADWERK_MIGRATION_PRIVATE_ROOT ) ) {
			return '';
		}
		if ( ! Leadwerk_Migration_Security::path_is_private( LEADWERK_MIGRATION_PRIVATE_ROOT ) ) {
			return '';
		}

		$scope = ABSPATH . '|' . ( defined( 'DB_NAME' ) ? DB_NAME : '' );
		$path  = LEADWERK_MIGRATION_PRIVATE_ROOT . DIRECTORY_SEPARATOR . '.leadwerk-installation-' . substr( hash( 'sha256', $scope ), 0, 20 );
		if ( is_file( $path ) && is_readable( $path ) ) {
			$stored = strtolower( trim( (string) @file_get_contents( $path ) ) );
			if ( self::valid_id( $stored ) ) {
				return $stored;
			}
		}

		$id = self::random_id();
		$handle = @fopen( $path, 'x' );
		if ( false !== $handle ) {
			$written = fwrite( $handle, $id );
			fclose( $handle );
			@chmod( $path, 0600 );
			return strlen( $id ) === $written ? $id : '';
		}

		// A concurrent request may have won the exclusive create race.
		$stored = is_readable( $path ) ? strtolower( trim( (string) @file_get_contents( $path ) ) ) : '';
		return self::valid_id( $stored ) ? $stored : '';
	}

	/** @param string $url @return string */
	public static function normalize_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( $url === '' ) {
			return '';
		}
		return untrailingslashit( $url );
	}

	/** @param string $url @return string */
	public static function environment_fingerprint( $url ) {
		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? '/' . trim( $parts['path'], '/' ) : '';
		return hash( 'sha256', $host . $path . '|' . ABSPATH );
	}

	/** @param string $provided @return string */
	private static function project_name( $provided ) {
		$name = trim( sanitize_text_field( $provided ) );
		if ( $name === '' ) {
			$name = sanitize_text_field( get_bloginfo( 'name' ) );
		}
		return substr( $name !== '' ? $name : 'Leadwerk Project', 0, 190 );
	}
}
