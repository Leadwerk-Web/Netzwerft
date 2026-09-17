<?php
/** Publisher-signed Leadwerk Migration release packages. */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access is not allowed.' );
}

class Leadwerk_Migration_Release {

	const PUBLIC_KEY = 'H5OBWQBL7hNIvWLvxnHGYasxLsJ+ZQSh+ut2CKsXGW4=';
	const MANIFEST_FILE = 'release-manifest.json';
	const SIGNING_CONTEXT = "leadwerk-migration-release-v1\n";

	/** Return the publisher-signed manifest used to admit an available target. */
	public static function installed_attestation() {
		$verified = self::verify_installed();
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$path = LEADWERK_MIGRATION_PATH . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
		$manifest = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
		return is_array( $manifest ) ? $manifest : new WP_Error( 'leadwerk_release_manifest_missing', __( 'The installed Migration release has no publisher signature.', 'leadwerk-migration' ) );
	}

	/** Verify a detached official-release attestation without trusting its sender. */
	public static function verify_attestation( $manifest, $expected_version = '' ) {
		$tree = is_array( $manifest ) && isset( $manifest['tree_sha256'] ) ? strtolower( trim( (string) $manifest['tree_sha256'] ) ) : '';
		return self::verify_manifest( is_array( $manifest ) ? $manifest : array(), $tree, $expected_version );
	}

	/** Verify the currently installed release before allowing it to publish itself. */
	public static function verify_installed() {
		$path = LEADWERK_MIGRATION_PATH . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
		$manifest = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
		if ( ! is_array( $manifest ) ) {
			return new WP_Error( 'leadwerk_release_manifest_missing', __( 'The installed Migration release has no publisher signature.', 'leadwerk-migration' ) );
		}
		$tree = self::directory_tree_hash( LEADWERK_MIGRATION_PATH );
		return is_wp_error( $tree ) ? $tree : self::verify_manifest( $manifest, $tree, LEADWERK_MIGRATION_VERSION );
	}

	/** Build a package only from an intact publisher-signed installation. */
	public static function build_package( $path ) {
		if ( defined( 'LEADWERK_MIGRATION_EMBEDDED' ) && LEADWERK_MIGRATION_EMBEDDED ) {
			return new WP_Error( 'leadwerk_embedded_release_publish', __( 'Embedded Migration is distributed only as part of the signed Leadwerk theme suite.', 'leadwerk-migration' ) );
		}
		$verified = self::verify_installed();
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'leadwerk_release_zip_missing', __( 'PHP ZipArchive is required to publish Migration releases.', 'leadwerk-migration' ) );
		}
		$tmp = $path . '.building-' . Leadwerk_Migration_Site_Identity::random_id();
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'leadwerk_release_package_open', __( 'The signed Migration package could not be created.', 'leadwerk-migration' ) );
		}
		$root = realpath( LEADWERK_MIGRATION_PATH );
		$iterator = false !== $root ? new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY ) : array();
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->isLink() || '.DS_Store' === $file->getFilename() ) {
				continue;
			}
			$real = $file->getRealPath();
			if ( false === $real || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
				$zip->close();
				@unlink( $tmp );
				return new WP_Error( 'leadwerk_release_package_path', __( 'Release package traversal was blocked.', 'leadwerk-migration' ) );
			}
			$relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $real, strlen( $root ) + 1 ) );
			$zip->addFile( $real, 'leadwerk-migration/' . $relative );
		}
		$zip->close();
		@chmod( $tmp, 0600 );
		if ( ! is_file( $tmp ) || ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return new WP_Error( 'leadwerk_release_package_finalize', __( 'The signed Migration package could not be finalized.', 'leadwerk-migration' ) );
		}
		return self::package_metadata( $path, LEADWERK_MIGRATION_VERSION );
	}

	/** Verify archive layout, file tree and detached Ed25519 publisher signature. */
	public static function verify_package( $path, $expected_version = '' ) {
		if ( ! class_exists( 'ZipArchive' ) || ! is_file( $path ) ) {
			return new WP_Error( 'leadwerk_release_package_missing', __( 'The Migration release package is unavailable.', 'leadwerk-migration' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'leadwerk_release_package_invalid', __( 'The Migration release archive cannot be opened.', 'leadwerk-migration' ) );
		}
		$hashes = array();
		$seen = array();
		$manifest = null;
		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$name = (string) $zip->getNameIndex( $index );
			if ( '' === $name || false !== strpos( $name, "\0" ) || 0 !== strpos( $name, 'leadwerk-migration/' ) || false !== strpos( $name, '../' ) || '/' === substr( $name, 0, 1 ) ) {
				$zip->close();
				return new WP_Error( 'leadwerk_release_package_layout', __( 'The Migration release archive contains an unsafe path.', 'leadwerk-migration' ) );
			}
			if ( '/' === substr( $name, -1 ) ) {
				continue;
			}
			$relative = substr( $name, strlen( 'leadwerk-migration/' ) );
			if ( '' === $relative || isset( $seen[ $relative ] ) ) {
				$zip->close();
				return new WP_Error( 'leadwerk_release_package_duplicate', __( 'The Migration release archive contains a duplicate path.', 'leadwerk-migration' ) );
			}
			$seen[ $relative ] = true;
			$attributes = 0;
			$operations = 0;
			if ( $zip->getExternalAttributesIndex( $index, $operations, $attributes ) && 0120000 === ( ( $attributes >> 16 ) & 0170000 ) ) {
				$zip->close();
				return new WP_Error( 'leadwerk_release_package_symlink', __( 'Symbolic links are not permitted in Migration releases.', 'leadwerk-migration' ) );
			}
			$content = $zip->getFromIndex( $index );
			if ( ! is_string( $content ) ) {
				$zip->close();
				return new WP_Error( 'leadwerk_release_package_read', __( 'A Migration release file could not be verified.', 'leadwerk-migration' ) );
			}
			if ( self::MANIFEST_FILE === $relative ) {
				$manifest = json_decode( $content, true );
			} elseif ( '.DS_Store' !== basename( $relative ) ) {
				$hashes[ $relative ] = hash( 'sha256', $content );
			}
		}
		$zip->close();
		$tree = self::hash_entries( $hashes );
		return is_array( $manifest ) ? self::verify_manifest( $manifest, $tree, $expected_version ) : new WP_Error( 'leadwerk_release_manifest_missing', __( 'The Migration release signature manifest is missing.', 'leadwerk-migration' ) );
	}

	public static function install_package( $path, $expected_version = '' ) {
		if ( defined( 'LEADWERK_MIGRATION_EMBEDDED' ) && LEADWERK_MIGRATION_EMBEDDED ) {
			return new WP_Error( 'leadwerk_embedded_release_update', __( 'Embedded Migration can be updated only by installing a signed Leadwerk theme-suite update.', 'leadwerk-migration' ) );
		}
		$verified = self::verify_package( $path, $expected_version );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		$upgrader = new WP_Upgrader( new Automatic_Upgrader_Skin() );
		$result = $upgrader->run( array( 'package' => $path, 'destination' => WP_PLUGIN_DIR, 'clear_destination' => true, 'clear_working' => true, 'abort_if_destination_exists' => false, 'hook_extra' => array( 'plugin' => LEADWERK_MIGRATION_PLUGIN_BASENAME, 'type' => 'plugin', 'action' => 'update' ) ) );
		return is_wp_error( $result ) || false === $result ? $result : $verified;
	}

	private static function directory_tree_hash( $root ) {
		$root = realpath( $root );
		if ( false === $root || ! is_dir( $root ) ) {
			return new WP_Error( 'leadwerk_release_root_missing', __( 'The installed Migration plugin directory is unavailable.', 'leadwerk-migration' ) );
		}
		$hashes = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->isLink() || '.DS_Store' === $file->getFilename() ) {
				continue;
			}
			$real = $file->getRealPath();
			$relative = false !== $real ? str_replace( DIRECTORY_SEPARATOR, '/', substr( $real, strlen( $root ) + 1 ) ) : '';
			if ( '' === $relative || self::MANIFEST_FILE === $relative ) {
				continue;
			}
			$hash = hash_file( 'sha256', $real );
			if ( ! is_string( $hash ) ) {
				return new WP_Error( 'leadwerk_release_tree_read', __( 'The installed Migration release could not be verified.', 'leadwerk-migration' ) );
			}
			$hashes[ $relative ] = $hash;
		}
		return self::hash_entries( $hashes );
	}

	private static function hash_entries( $hashes ) {
		ksort( $hashes, SORT_STRING );
		$context = hash_init( 'sha256' );
		foreach ( $hashes as $path => $hash ) {
			hash_update( $context, $path . "\0" . strtolower( $hash ) . "\n" );
		}
		return hash_final( $context );
	}

	private static function verify_manifest( $manifest, $tree, $expected_version ) {
		$version = isset( $manifest['version'] ) ? trim( (string) $manifest['version'] ) : '';
		$declared_tree = isset( $manifest['tree_sha256'] ) ? strtolower( trim( (string) $manifest['tree_sha256'] ) ) : '';
		$signature = isset( $manifest['signature'] ) ? base64_decode( (string) $manifest['signature'], true ) : false;
		$public_key = base64_decode( self::PUBLIC_KEY, true );
		if ( '' === $version || ( '' !== $expected_version && ! hash_equals( $expected_version, $version ) ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $declared_tree ) || ! hash_equals( $declared_tree, strtolower( $tree ) ) || ! is_string( $signature ) || ! is_string( $public_key ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) || ! sodium_crypto_sign_verify_detached( $signature, self::SIGNING_CONTEXT . $version . "\n" . $declared_tree, $public_key ) ) {
			return new WP_Error( 'leadwerk_release_signature_invalid', __( 'The Migration release publisher signature is invalid.', 'leadwerk-migration' ) );
		}
		return array( 'version' => $version, 'tree_sha256' => $declared_tree );
	}

	private static function package_metadata( $path, $version ) {
		$hash = is_file( $path ) ? hash_file( 'sha256', $path ) : false;
		$size = is_file( $path ) ? filesize( $path ) : false;
		return is_string( $hash ) && false !== $size && $size > 0 ? array( 'version' => $version, 'sha256' => strtolower( $hash ), 'size' => (int) $size, 'path' => $path, 'created_at' => gmdate( 'c' ) ) : new WP_Error( 'leadwerk_release_package_integrity', __( 'Migration release integrity metadata could not be calculated.', 'leadwerk-migration' ) );
	}
}
