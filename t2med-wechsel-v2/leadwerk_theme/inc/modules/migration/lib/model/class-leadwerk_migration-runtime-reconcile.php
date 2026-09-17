<?php
/** Secure Hub-driven Leadwerk, WordPress and PHP runtime reconciliation. */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access is not allowed.' );
}

class Leadwerk_Migration_Runtime_Reconcile {

	public static function process( $commands, $environment ) {
		foreach ( is_array( $commands ) ? $commands : array() as $command ) {
			if ( ! is_array( $command ) || empty( $command['command_id'] ) || empty( $command['target_environment_id'] ) || ! hash_equals( (string) $command['target_environment_id'], (string) $environment['environment_id'] ) ) {
				continue;
			}
			if ( ! in_array( $command['status'], array( 'queued', 'running', 'waiting_runtime' ), true ) ) {
				continue;
			}
			return self::execute( $command );
		}
		return null;
	}

	private static function execute( $command ) {
		$payload = isset( $command['payload'] ) && is_array( $command['payload'] ) ? $command['payload'] : array();
		$type = isset( $command['command_type'] ) ? sanitize_key( $command['command_type'] ) : 'runtime_reconcile';
		$stage = isset( $payload['stage'] ) ? sanitize_key( $payload['stage'] ) : 'plugin';
		try {
			if ( 'plugin_publish' === $type ) {
				return self::publish_plugin_stage( $command, $payload );
			}
			if ( 'plugin_update' === $type ) {
				$result = self::plugin_stage( $command, $payload );
				return true === $result ? self::report( $command['command_id'], 'complete', 'complete', __( 'Leadwerk Migration was updated automatically from the signed Hub release channel.', 'leadwerk-migration' ) ) : $result;
			}
			if ( 'wordpress_update' === $type ) {
				$result = self::wordpress_stage( $command, $payload );
				return true === $result ? self::report( $command['command_id'], 'complete', 'complete', __( 'WordPress was upgraded automatically to the highest version in this Site Family.', 'leadwerk-migration' ) ) : $result;
			}
			if ( 'plugin' === $stage ) {
				$result = self::plugin_stage( $command, $payload );
				if ( true !== $result ) {
					return $result;
				}
				$stage = 'wordpress';
			}
			if ( 'wordpress' === $stage ) {
				$result = self::wordpress_stage( $command, $payload );
				if ( true !== $result ) {
					return $result;
				}
				$stage = 'php';
			}
			if ( 'php' === $stage ) {
				$result = self::php_stage( $command, $payload );
				if ( true !== $result ) {
					return $result;
				}
			}
			return self::report( $command['command_id'], 'complete', 'complete', __( 'Plugin and WordPress versions now match; PHP uses the same compatible major.minor branch as the reference environment.', 'leadwerk-migration' ) );
		} catch ( Throwable $error ) {
			return self::report( $command['command_id'], 'failed', $stage, $error->getMessage() );
		}
	}

	/** The newest approved environment publishes its signed package to Hub. */
	private static function publish_plugin_stage( $command, $payload ) {
		$desired = isset( $payload['plugin_version'] ) ? trim( (string) $payload['plugin_version'] ) : '';
		if ( '' === $desired || ! hash_equals( LEADWERK_MIGRATION_VERSION, $desired ) ) {
			return self::report( $command['command_id'], 'failed', 'publish', __( 'This environment no longer matches the Migration release requested by Hub.', 'leadwerk-migration' ) );
		}
		$path = self::partial_path( $command['command_id'], 'publisher.zip' );
		if ( ! is_file( $path ) ) {
			$manifest = Leadwerk_Migration_Release::build_package( $path );
			if ( is_wp_error( $manifest ) ) {
				return self::report( $command['command_id'], 'failed', 'publish', $manifest->get_error_message() );
			}
		}
		$size = filesize( $path );
		$sha256 = hash_file( 'sha256', $path );
		if ( false === $size || $size <= 0 || ! is_string( $sha256 ) ) {
			return self::report( $command['command_id'], 'failed', 'publish', __( 'The signed Migration release package cannot be read.', 'leadwerk-migration' ) );
		}
		$base = array( 'command_id' => $command['command_id'], 'version' => $desired, 'total' => (int) $size, 'sha256' => strtolower( $sha256 ) );
		$probe = Leadwerk_Migration_Sync::request( '/hub/plugin/publish', $base + array( 'probe' => true ), true, 60 );
		if ( is_wp_error( $probe ) ) {
			return self::report( $command['command_id'], 'failed', 'publish', $probe->get_error_message() );
		}
		$offset = isset( $probe['expected_offset'] ) ? max( 0, (int) $probe['expected_offset'] ) : 0;
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle || 0 !== fseek( $handle, $offset ) ) {
			return self::report( $command['command_id'], 'failed', 'publish', __( 'The signed Migration package cannot be opened at Hub’s requested offset.', 'leadwerk-migration' ) );
		}
		for ( $part = 0; $part < 4 && $offset < (int) $size; $part++ ) {
			$chunk = fread( $handle, LEADWERK_MIGRATION_SYNC_CHUNK_SIZE );
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				fclose( $handle );
				return self::report( $command['command_id'], 'failed', 'publish', __( 'The Migration publisher returned an empty package chunk.', 'leadwerk-migration' ) );
			}
			$response = Leadwerk_Migration_Sync::request( '/hub/plugin/publish', $base + array( 'offset' => $offset, 'data' => base64_encode( $chunk ) ), true, 120 );
			if ( is_wp_error( $response ) ) {
				fclose( $handle );
				return self::report( $command['command_id'], 'failed', 'publish', $response->get_error_message() );
			}
			$offset = isset( $response['expected_offset'] ) ? (int) $response['expected_offset'] : $offset + strlen( $chunk );
		}
		fclose( $handle );
		if ( $offset < (int) $size ) {
			return self::report( $command['command_id'], 'running', 'publish', sprintf( __( 'Publishing signed Migration release to Hub: %1$s / %2$s.', 'leadwerk-migration' ), size_format( $offset ), size_format( $size ) ) );
		}
		@unlink( $path );
		return self::report( $command['command_id'], 'complete', 'complete', __( 'Signed Migration release published to Hub. Automatic global rollout is starting.', 'leadwerk-migration' ) );
	}

	private static function plugin_stage( $command, $payload ) {
		$desired = isset( $payload['plugin_version'] ) ? trim( (string) $payload['plugin_version'] ) : '';
		if ( '' === $desired ) {
			return self::report( $command['command_id'], 'failed', 'plugin', __( 'The Hub command has no desired plugin version.', 'leadwerk-migration' ) );
		}
		if ( LEADWERK_MIGRATION_VERSION === $desired ) {
			return true;
		}
		if ( version_compare( $desired, LEADWERK_MIGRATION_VERSION, '<' ) ) {
			return self::report( $command['command_id'], 'manual_required', 'plugin', __( 'Automatic Leadwerk Migration downgrade is not allowed.', 'leadwerk-migration' ) );
		}
		$manifest = Leadwerk_Migration_Sync::request( '/hub/plugin/manifest', array(), true, 60 );
		if ( is_wp_error( $manifest ) ) {
			return self::report( $command['command_id'], 'failed', 'plugin', $manifest->get_error_message() );
		}
		if ( empty( $manifest['version'] ) || ! hash_equals( $desired, (string) $manifest['version'] ) || empty( $manifest['sha256'] ) || empty( $manifest['size'] ) ) {
			return self::report( $command['command_id'], 'failed', 'plugin', __( 'Hub plugin manifest does not match the requested version.', 'leadwerk-migration' ) );
		}
		$path = self::partial_path( $command['command_id'], 'plugin.zip' );
		$offset = is_file( $path ) ? (int) filesize( $path ) : 0;
		for ( $part = 0; $part < 4 && $offset < (int) $manifest['size']; $part++ ) {
			$response = Leadwerk_Migration_Sync::request( '/hub/plugin/read', array( 'offset' => $offset, 'length' => LEADWERK_MIGRATION_SYNC_CHUNK_SIZE ), true, 120 );
			if ( is_wp_error( $response ) ) {
				return self::report( $command['command_id'], 'failed', 'plugin', $response->get_error_message() );
			}
			$chunk = isset( $response['data'] ) ? base64_decode( $response['data'], true ) : false;
			if ( ! is_string( $chunk ) || '' === $chunk || (int) $response['offset'] !== $offset ) {
				return self::report( $command['command_id'], 'failed', 'plugin', __( 'Invalid plugin update chunk received.', 'leadwerk-migration' ) );
			}
			$handle = @fopen( $path, 0 === $offset ? 'wb' : 'ab' );
			if ( false === $handle || fwrite( $handle, $chunk ) !== strlen( $chunk ) ) {
				if ( is_resource( $handle ) ) { fclose( $handle ); }
				return self::report( $command['command_id'], 'failed', 'plugin', __( 'Plugin update package could not be saved.', 'leadwerk-migration' ) );
			}
			fclose( $handle );
			@chmod( $path, 0600 );
			$offset += strlen( $chunk );
		}
		if ( $offset < (int) $manifest['size'] ) {
			return self::report( $command['command_id'], 'running', 'plugin', sprintf( __( 'Downloading Leadwerk Migration update: %1$s / %2$s.', 'leadwerk-migration' ), size_format( $offset ), size_format( $manifest['size'] ) ) );
		}
		$actual = hash_file( 'sha256', $path );
		if ( ! is_string( $actual ) || ! hash_equals( strtolower( $manifest['sha256'] ), strtolower( $actual ) ) ) {
			@unlink( $path );
			return self::report( $command['command_id'], 'failed', 'plugin', __( 'Plugin update SHA-256 verification failed.', 'leadwerk-migration' ) );
		}
		$result = self::install_plugin_package( $path, $desired );
		if ( is_wp_error( $result ) || false === $result ) {
			return self::report( $command['command_id'], 'failed', 'plugin', is_wp_error( $result ) ? $result->get_error_message() : __( 'WordPress filesystem access rejected the plugin update.', 'leadwerk-migration' ) );
		}
		@unlink( $path );
		$next_stage = isset( $command['command_type'] ) && 'plugin_update' === $command['command_type'] ? 'plugin' : 'wordpress';
		$message = 'plugin' === $next_stage ? __( 'Leadwerk Migration updated. The automatic update will be verified on the next heartbeat.', 'leadwerk-migration' ) : __( 'Leadwerk Migration updated. WordPress reconciliation will continue on the next heartbeat.', 'leadwerk-migration' );
		return self::report( $command['command_id'], 'running', $next_stage, $message );
	}

	private static function wordpress_stage( $command, $payload ) {
		$desired = isset( $payload['wordpress_version'] ) ? trim( (string) $payload['wordpress_version'] ) : '';
		$current = get_bloginfo( 'version' );
		if ( $desired === $current ) {
			return true;
		}
		if ( '' === $desired || version_compare( $desired, $current, '<' ) ) {
			return self::report( $command['command_id'], 'manual_required', 'wordpress', sprintf( __( 'Automatic WordPress downgrade from %1$s to %2$s is not allowed. Site Family automation upgrades lower versions only.', 'leadwerk-migration' ), $current, $desired ) );
		}
		require_once ABSPATH . 'wp-includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
		wp_version_check( array(), true );
		$offer = null;
		foreach ( get_core_updates( array( 'dismissed' => false ) ) as $candidate ) {
			if ( is_object( $candidate ) && isset( $candidate->current ) && (string) $candidate->current === $desired && isset( $candidate->response ) && in_array( $candidate->response, array( 'upgrade', 'reinstall' ), true ) ) {
				$offer = $candidate;
				break;
			}
		}
		if ( ! is_object( $offer ) ) {
			return self::report( $command['command_id'], 'manual_required', 'wordpress', sprintf( __( 'WordPress.org did not offer the exact requested core version %s to this installation.', 'leadwerk-migration' ), $desired ) );
		}
		$upgrader = new Core_Upgrader( new Automatic_Upgrader_Skin() );
		$result = $upgrader->upgrade( $offer, array( 'attempt_rollback' => true ) );
		if ( is_wp_error( $result ) || false === $result ) {
			return self::report( $command['command_id'], 'failed', 'wordpress', is_wp_error( $result ) ? $result->get_error_message() : __( 'WordPress core updater could not access the filesystem.', 'leadwerk-migration' ) );
		}
		$automatic = isset( $command['command_type'] ) && 'wordpress_update' === $command['command_type'];
		return self::report( $command['command_id'], 'running', $automatic ? 'wordpress' : 'php', $automatic ? __( 'WordPress core upgraded. The family version will be verified on the next heartbeat.', 'leadwerk-migration' ) : __( 'WordPress core updated. PHP reconciliation will continue on the next heartbeat.', 'leadwerk-migration' ) );
	}

	private static function php_stage( $command, $payload ) {
		$desired = isset( $payload['php_version'] ) ? trim( (string) $payload['php_version'] ) : '';
		if ( Leadwerk_Migration_Site_Identity::php_versions_compatible( PHP_VERSION, $desired ) ) {
			return true;
		}
		if ( 'waiting_runtime' === $command['status'] ) {
			return self::report( $command['command_id'], 'waiting_runtime', 'php', sprintf( __( 'Waiting for hosting to switch PHP from %1$s to %2$s.', 'leadwerk-migration' ), PHP_VERSION, $desired ) );
		}
		$result = apply_filters( 'leadwerk_migration_php_runtime_update', null, $desired, $command );
		if ( is_wp_error( $result ) ) {
			return self::report( $command['command_id'], 'failed', 'php', $result->get_error_message() );
		}
		if ( true !== $result ) {
			$result = self::trigger_php_webhook( $desired, $command );
		}
		if ( is_wp_error( $result ) ) {
			return self::report( $command['command_id'], 'manual_required', 'php', $result->get_error_message() );
		}
		return self::report( $command['command_id'], 'waiting_runtime', 'php', sprintf( __( 'PHP update request sent to hosting. Waiting for %s.', 'leadwerk-migration' ), $desired ) );
	}

	private static function trigger_php_webhook( $desired, $command ) {
		$settings = Leadwerk_Migration_Site_Identity::settings();
		$url = isset( $settings['php_update_webhook_url'] ) ? $settings['php_update_webhook_url'] : '';
		$secret = isset( $settings['php_update_webhook_secret'] ) ? $settings['php_update_webhook_secret'] : '';
		if ( '' === $url || '' === $secret ) {
			return new WP_Error( 'leadwerk_php_connector_missing', __( 'WordPress cannot change the server PHP runtime. Configure this environment’s hosting PHP webhook or a leadwerk_migration_php_runtime_update provider connector.', 'leadwerk-migration' ) );
		}
		$body = wp_json_encode( array( 'command_id' => $command['command_id'], 'site_url' => home_url( '/' ), 'current_php' => PHP_VERSION, 'target_php' => $desired, 'requested_at' => gmdate( 'c' ) ) );
		$timestamp = time();
		$response = wp_safe_remote_post( $url, array( 'timeout' => 30, 'redirection' => 0, 'sslverify' => true, 'headers' => array( 'Content-Type' => 'application/json', 'X-Leadwerk-Timestamp' => (string) $timestamp, 'X-Leadwerk-Signature' => hash_hmac( 'sha256', $timestamp . "\n" . hash( 'sha256', $body ), $secret ) ), 'body' => $body, 'data_format' => 'body' ) );
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) < 200 || (int) wp_remote_retrieve_response_code( $response ) >= 300 ) {
			return new WP_Error( 'leadwerk_php_connector_failed', is_wp_error( $response ) ? $response->get_error_message() : sprintf( __( 'Hosting PHP webhook returned HTTP %d.', 'leadwerk-migration' ), wp_remote_retrieve_response_code( $response ) ) );
		}
		return true;
	}

	private static function install_plugin_package( $path, $expected_version ) {
		return Leadwerk_Migration_Release::install_package( $path, $expected_version );
	}

	private static function report( $command_id, $status, $stage, $message ) {
		return Leadwerk_Migration_Sync::request( '/hub/commands/' . rawurlencode( $command_id ) . '/progress', array( 'status' => $status, 'stage' => $stage, 'message' => sanitize_text_field( $message ) ), true, 60 );
	}

	private static function partial_path( $command_id, $suffix ) {
		if ( ! Leadwerk_Migration_Site_Identity::valid_id( $command_id ) ) {
			throw new RuntimeException( __( 'The runtime command identifier is invalid.', 'leadwerk-migration' ) );
		}
		$scope = 'leadwerk-runtime-' . substr( hash( 'sha256', ABSPATH . wp_salt( 'nonce' ) ), 0, 12 );
		$candidates = array(
			LEADWERK_MIGRATION_STORAGE_PATH . DIRECTORY_SEPARATOR . 'updates-' . substr( hash( 'sha256', $scope ), 0, 8 ),
			LEADWERK_MIGRATION_PRIVATE_ROOT . DIRECTORY_SEPARATOR . $scope,
			LEADWERK_MIGRATION_BACKUPS_PATH . DIRECTORY_SEPARATOR . '.' . $scope,
			rtrim( sys_get_temp_dir(), "/\\" ) . DIRECTORY_SEPARATOR . $scope,
		);
		foreach ( array_unique( $candidates ) as $directory ) {
			$parent = dirname( $directory );
			if ( ! is_dir( $parent ) || ! Leadwerk_Migration_Security::path_is_private( $parent ) ) {
				continue;
			}
			if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
				continue;
			}
			if ( ! Leadwerk_Migration_Security::path_is_private( $directory ) ) {
				continue;
			}
			@chmod( $directory, 0700 );
			$probe = $directory . DIRECTORY_SEPARATOR . '.write-' . Leadwerk_Migration_Site_Identity::random_id();
			$handle = @fopen( $probe, 'x' );
			if ( false === $handle ) {
				continue;
			}
			fclose( $handle );
			@unlink( $probe );
			return $directory . DIRECTORY_SEPARATOR . $command_id . '-' . sanitize_file_name( $suffix );
		}
		throw new RuntimeException( __( 'No private writable runtime update directory is available. Check the migration health report and server filesystem permissions.', 'leadwerk-migration' ) );
	}
}
