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

class Leadwerk_Migration_Import_Check_Encryption {

	public static function execute( $params ) {
		global $leadwerk_migration_params;

		if ( ! empty( $params['leadwerk_migration_manual_restore'] ) && empty( $params['leadwerk_migration_verified_local_restore'] ) ) {
			$params = self::prepare_verified_local_restore( $params );
			// Keep the browser/REST status writer in sync with the verified copy so
			// a password or confirm prompt can rehydrate this flag after reload.
			if ( is_array( $leadwerk_migration_params ) ) {
				$leadwerk_migration_params['leadwerk_migration_verified_local_restore'] = 1;
				$leadwerk_migration_params['leadwerk_migration_manual_restore']         = 1;
			}
		}

		// Read package.json file
		$handle = leadwerk_migration_open( leadwerk_migration_package_path( $params ), 'r' );

		// Parse package.json file
		$package = leadwerk_migration_read( $handle, filesize( leadwerk_migration_package_path( $params ) ) );
		$package = json_decode( $package, true );

		// Close handle
		leadwerk_migration_close( $handle );
		if ( ! is_array( $package ) ) {
			self::fail( __( 'The archive package metadata is invalid.', 'leadwerk-migration' ) );
		}

		$archive_path       = leadwerk_migration_archive_path( $params );
		$has_auth_manifest  = leadwerk_migration_archive_has_auth_manifest( $archive_path );
		$has_modern_fields  = array_key_exists( 'EncryptionFormat', $package ) || array_key_exists( 'EncryptionArchiveId', $package );
		$has_encrypted_flag = array_key_exists( 'Encrypted', $package );
		$has_signature      = array_key_exists( 'EncryptedSignature', $package );

		// No encryption provided - if both Encrypted and EncryptedSignature are empty, it's not encrypted
		if ( empty( $package['Encrypted'] ) && empty( $package['EncryptedSignature'] ) ) {
			if ( ! $has_auth_manifest && ! $has_modern_fields && ! $has_encrypted_flag && ! $has_signature ) {
				$params['leadwerk_migration_legacy_unauthenticated'] = 1;
			}
			return $params;
		}

		// Detect v3 independently from package.json so deleting its encryption
		// flags cannot downgrade an authenticated archive to plaintext handling.
		if ( $has_auth_manifest || $has_modern_fields ) {
			if (
				! $has_auth_manifest ||
				empty( $package['Encrypted'] ) ||
				empty( $package['EncryptedSignature'] ) ||
				! isset( $package['EncryptionFormat'], $package['EncryptionArchiveId'] ) ||
				$package['EncryptionFormat'] !== LEADWERK_MIGRATION_ENCRYPTION_FORMAT ||
				! is_string( $package['EncryptionArchiveId'] ) ||
				! preg_match( '/\A[a-f0-9]{32}\z/D', $package['EncryptionArchiveId'] )
			) {
				self::fail( __( 'Authenticated archive metadata is missing, inconsistent, or damaged.', 'leadwerk-migration' ) );
			}
		} elseif ( $has_encrypted_flag xor $has_signature ) {
			self::fail( __( 'The legacy archive encryption metadata is incomplete.', 'leadwerk-migration' ) );
		} else {
			// Legacy archives have no keyed whole-archive manifest. Import_Confirm
			// presents an explicit warning before destructive extraction.
			$params['leadwerk_migration_legacy_unauthenticated'] = 1;
		}

		// Check decryption support
		if ( ! leadwerk_migration_can_decrypt() ) {
			if ( defined( 'WP_CLI' ) ) {
				WP_CLI::error( __( 'This server does not provide the OpenSSL ciphers required to decrypt the archive.', 'leadwerk-migration' ) );
			} else {
				Leadwerk_Migration_Status::server_cannot_decrypt( __( 'This server does not provide the OpenSSL ciphers required to decrypt the archive.', 'leadwerk-migration' ) );

				// REST API: throw to halt the pipeline; controller preserves the status option
				if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
					throw new Leadwerk_Migration_Not_Decryptable_Exception( 'server_cannot_decrypt' );
				}

				exit;
			}
		}

		// Get WP CLI decryption password
		if ( defined( 'WP_CLI' ) ) {
			$params['decryption_password'] = readline( __( 'Backup is encrypted. Please provide decryption password: ', 'leadwerk-migration' ) );
		}

		// Validate decryption password
		if ( ! empty( $params['decryption_password'] ) ) {
			$encryption_format   = isset( $package['EncryptionFormat'] ) ? $package['EncryptionFormat'] : null;
			$encryption_archive_id = isset( $package['EncryptionArchiveId'] ) ? $package['EncryptionArchiveId'] : '';
			if ( leadwerk_migration_is_decryption_password_valid( $package['EncryptedSignature'], $params['decryption_password'], $encryption_format, $encryption_archive_id ) ) {
				try {
					leadwerk_migration_verify_encrypted_archive_manifest( leadwerk_migration_archive_path( $params ), $package, $params['decryption_password'] );
				} catch ( Leadwerk_Migration_Not_Decryptable_Exception $error ) {
					Leadwerk_Migration_Status::backup_is_encrypted( $error->getMessage() );
					if ( defined( 'WP_CLI' ) ) {
						WP_CLI::error( $error->getMessage() );
					}
					if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
						throw $error;
					}
					exit;
				}

				// Set progress
				Leadwerk_Migration_Status::info( __( 'Decryption password validated.', 'leadwerk-migration' ) );

				return $params;
			}

			$decryption_password_error = __( 'The decryption password is not valid. The process cannot continue.', 'leadwerk-migration' );

			if ( defined( 'WP_CLI' ) ) {
				WP_CLI::error( $decryption_password_error );
			} else {
				Leadwerk_Migration_Status::backup_is_encrypted( $decryption_password_error );

				// REST API: throw to halt the pipeline; controller preserves the status option
				if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
					throw new Leadwerk_Migration_Not_Decryptable_Exception( 'invalid_password' );
				}

				exit;
			}
		}

		Leadwerk_Migration_Status::backup_is_encrypted( null );

		// REST API: throw to halt the pipeline; controller preserves the status option
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			throw new Leadwerk_Migration_Not_Decryptable_Exception( 'awaiting_password' );
		}

		exit;
	}

	/**
	 * Verify a locally selected backup and copy the exact verified bytes into
	 * the private job directory before any destructive restore stage runs.
	 *
	 * @param array $params Pipeline parameters.
	 * @return array
	 */
	private static function prepare_verified_local_restore( $params ) {
		$filename     = isset( $params['archive'] ) ? $params['archive'] : '';
		$verification = Leadwerk_Migration_Integrity::verify( $filename );
		if ( is_wp_error( $verification ) ) {
			self::fail( sprintf( __( 'Local restore blocked by the integrity policy: %s', 'leadwerk-migration' ), $verification->get_error_message() ) );
		}

		$source  = Leadwerk_Migration_Integrity::resolve_backup_path( $filename );
		$storage = leadwerk_migration_storage_path( $params );
		if ( is_wp_error( $source ) ) {
			self::fail( $source->get_error_message() );
		}

		$temp = @tempnam( $storage, '.leadwerk-restore-' );
		if ( $temp === false ) {
			self::fail( __( 'The verified local restore copy could not be created.', 'leadwerk-migration' ) );
		}

		try {
			leadwerk_migration_copy( $source, $temp );
			$copied_hash = @hash_file( 'sha256', $temp );
			if ( ! is_string( $copied_hash ) || ! hash_equals( strtolower( $verification['expected_sha256'] ), strtolower( $copied_hash ) ) ) {
				@unlink( $temp );
				self::fail( __( 'The backup changed while it was being prepared for restore. Run integrity verification again.', 'leadwerk-migration' ) );
			}

			$destination = $storage . DIRECTORY_SEPARATOR . basename( $filename );
			if ( file_exists( $destination ) && ! @unlink( $destination ) ) {
				@unlink( $temp );
				self::fail( __( 'The previous private restore copy could not be replaced.', 'leadwerk-migration' ) );
			}

			@chmod( $temp, 0600 );
			if ( ! @rename( $temp, $destination ) ) {
				@unlink( $temp );
				self::fail( __( 'The verified backup could not be moved into the private restore workspace.', 'leadwerk-migration' ) );
			}
		} catch ( Exception $exception ) {
			@unlink( $temp );
			self::fail( sprintf( __( 'The verified backup could not be prepared: %s', 'leadwerk-migration' ), $exception->getMessage() ) );
		}

		$params['leadwerk_migration_verified_local_restore'] = 1;

		// Replace metadata previously extracted from the mutable source path with
		// metadata from the verified private copy.
		$archive = new Leadwerk_Migration_Extractor( leadwerk_migration_archive_path( $params ) );
		$archive->extract_by_files_array( $storage, array( LEADWERK_MIGRATION_PACKAGE_NAME, LEADWERK_MIGRATION_MULTISITE_NAME ) );
		$archive->close();

		return $params;
	}

	private static function fail( $message ) {
		Leadwerk_Migration_Status::backup_is_encrypted( $message );
		if ( defined( 'WP_CLI' ) ) {
			WP_CLI::error( $message );
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			throw new Leadwerk_Migration_Not_Decryptable_Exception( $message );
		}
		exit;
	}
}
