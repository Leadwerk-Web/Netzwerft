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

class Leadwerk_Migration_Backups_Controller {

	private static function authorize( $capability ) {
		$nonce = isset( $_REQUEST['_leadwerk_migration_backup_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_leadwerk_migration_backup_nonce'] ) ) : '';
		if ( ! Leadwerk_Migration_Security::authorize_admin_action( $capability, 'leadwerk_migration_backups', $nonce ) ) {
			status_header( 403 );
			exit;
		}
	}

	/**
	 * Require the purpose-bound backup credential after the user/nonce check.
	 * Export credentials are accepted only by the final archive download route.
	 *
	 * @param mixed   $secret_key Candidate credential.
	 * @param boolean $allow_export Whether the export scope is also accepted.
	 * @return void
	 */
	private static function verify_scope( $secret_key, $allow_export = false ) {
		$authorized = Leadwerk_Migration_Security::verify_scoped_secret( $secret_key, 'backups' );
		if ( $allow_export ) {
			$authorized = $authorized || Leadwerk_Migration_Security::verify_scoped_secret( $secret_key, 'export' );
		}

		if ( ! $authorized ) {
			status_header( 403 );
			exit;
		}
	}

	public static function index() {
		Leadwerk_Migration_Template::render(
			'backups/index',
			array(
				'backups'      => Leadwerk_Migration_Backups::get_files(),
				'labels'       => Leadwerk_Migration_Backups::get_labels(),
				'downloadable' => Leadwerk_Migration_Backups::are_downloadable(),
			)
		);
	}

	/**
	 * Render the bulk delete button below the backups list
	 *
	 * Hooked to leadwerk_migration_backups_left_end, which is fired by both the Backups page of the
	 * plugin and the Reset page of the paid extensions, so that the button follows the
	 * backups list wherever the list is rendered.
	 *
	 * @return void
	 */
	public static function bulk_delete_button() {
		Leadwerk_Migration_Template::render( 'backups/backups-bulk-delete' );
	}

	public static function clean( $params = array() ) {
		leadwerk_migration_setup_environment();
		self::authorize( 'leadwerk_migration_import_site' );

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( $_POST );
		}

		// Set secret key
		$secret_key = null;
		if ( isset( $params['secret_key'] ) ) {
			$secret_key = trim( $params['secret_key'] );
		}

		self::verify_scope( $secret_key );

		// Delete storage files
		Leadwerk_Migration_Directory::delete( leadwerk_migration_storage_path( $params ) );
		exit;
	}

	public static function delete( $params = array() ) {
		leadwerk_migration_setup_environment();
		self::authorize( 'leadwerk_migration_import_site' );

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( $_POST );
		}

		// Set secret key
		$secret_key = null;
		if ( isset( $params['secret_key'] ) ) {
			$secret_key = trim( $params['secret_key'] );
		}

		// Set archives
		$archives = array();
		if ( isset( $params['archives'] ) && is_array( $params['archives'] ) ) {
			$archives = array_map( 'trim', $params['archives'] );
		} elseif ( isset( $params['archive'] ) ) {
			$archives = array( trim( $params['archive'] ) );
		}

		self::verify_scope( $secret_key );

		$deleted = array();
		$failed  = array();

		try {
			foreach ( $archives as $archive ) {
				if ( Leadwerk_Migration_Backups::delete_file( $archive ) ) {
					$deleted[] = $archive;
				} else {
					$failed[] = $archive;
				}
			}

			Leadwerk_Migration_Backups::delete_labels( $deleted );
		} catch ( Leadwerk_Migration_Backups_Exception $e ) {
			leadwerk_migration_json_response( array( 'deleted' => $deleted, 'errors' => array( $e->getMessage() ) ) );
			exit;
		}

		$errors = array();
		if ( $failed ) {
			$errors[] = sprintf(
				_n(
					'%d backup could not be deleted. Please verify the file permissions of your backups folder.',
					'%d backups could not be deleted. Please verify the file permissions of your backups folder.',
					count( $failed ),
					'leadwerk-migration'
				),
				count( $failed )
			);
		}

		leadwerk_migration_json_response( array( 'deleted' => $deleted, 'errors' => $errors ) );
		exit;
	}

	public static function add_label( $params = array() ) {
		leadwerk_migration_setup_environment();
		self::authorize( 'leadwerk_migration_import_site' );

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( $_POST );
		}

		// Set secret key
		$secret_key = null;
		if ( isset( $params['secret_key'] ) ) {
			$secret_key = trim( $params['secret_key'] );
		}

		// Set archive
		$archive = null;
		if ( isset( $params['archive'] ) ) {
			$archive = trim( $params['archive'] );
		}

		// Set backup label
		$label = null;
		if ( isset( $params['label'] ) ) {
			$label = trim( $params['label'] );
		}

		self::verify_scope( $secret_key );

		try {
			Leadwerk_Migration_Backups::set_label( $archive, $label );
		} catch ( Leadwerk_Migration_Backups_Exception $e ) {
			leadwerk_migration_json_response( array( 'errors' => array( $e->getMessage() ) ) );
			exit;
		}

		leadwerk_migration_json_response( array( 'errors' => array() ) );
		exit;
	}

	public static function backup_list( $params = array() ) {
		leadwerk_migration_setup_environment();
		self::authorize( 'leadwerk_migration_import_site' );

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( $_GET );
		}

		// Set secret key
		$secret_key = null;
		if ( isset( $params['secret_key'] ) ) {
			$secret_key = trim( $params['secret_key'] );
		}

		self::verify_scope( $secret_key );

		Leadwerk_Migration_Template::render(
			'backups/backups-list',
			array(
				'backups'      => Leadwerk_Migration_Backups::get_files(),
				'labels'       => Leadwerk_Migration_Backups::get_labels(),
				'downloadable' => Leadwerk_Migration_Backups::are_downloadable(),
			)
		);
		exit;
	}

	public static function backup_get_config( $params = array() ) {
		leadwerk_migration_setup_environment();
		self::authorize( 'leadwerk_migration_import_site' );

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( $_POST );
		}

		// Set secret key
		$secret_key = null;
		if ( isset( $params['secret_key'] ) ) {
			$secret_key = trim( $params['secret_key'] );
		}

		self::verify_scope( $secret_key );

		try {
			// Open the archive file for reading
			$archive = new Leadwerk_Migration_Extractor( leadwerk_migration_backup_path( $params ) );
			$archive->extract_by_files_array( leadwerk_migration_storage_path( $params ), array( LEADWERK_MIGRATION_PACKAGE_NAME ) );
			$archive->close();
		} catch ( Exception $e ) {
			leadwerk_migration_json_response( array( 'errors' => array( $e->getMessage() ) ) );
			exit;
		}

		leadwerk_migration_json_response( array( 'errors' => array() ) );
		exit;
	}

	public static function backup_check_encryption( $params = array() ) {
		leadwerk_migration_setup_environment();
		self::authorize( 'leadwerk_migration_import_site' );

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( $_POST );
		}

		// Set secret key
		$secret_key = null;
		if ( isset( $params['secret_key'] ) ) {
			$secret_key = trim( $params['secret_key'] );
		}

		self::verify_scope( $secret_key );

		// Read package.json file
		$handle = leadwerk_migration_open( leadwerk_migration_package_path( $params ), 'r' );

		// Parse package.json file
		$package = leadwerk_migration_read( $handle, filesize( leadwerk_migration_package_path( $params ) ) );
		$package = json_decode( $package, true );

		// Close handle
		leadwerk_migration_close( $handle );

		// No encryption provided
		if ( empty( $package['Encrypted'] ) || empty( $package['EncryptedSignature'] ) ) {
			leadwerk_migration_json_response( array( 'errors' => array() ) );
			exit;
		}

		// Check decryption support
		if ( ! leadwerk_migration_can_decrypt() ) {
			leadwerk_migration_json_response( array( 'errors' => array( __( 'Download a file from encrypted backup is not supported on this server. The process cannot continue. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ) ) ) );
			exit;
		}

		// Validate decryption password
		if ( ! empty( $params['decryption_password'] ) ) {
			$encryption_format     = isset( $package['EncryptionFormat'] ) ? $package['EncryptionFormat'] : null;
			$encryption_archive_id = isset( $package['EncryptionArchiveId'] ) ? $package['EncryptionArchiveId'] : '';
			if ( ! leadwerk_migration_is_decryption_password_valid( $package['EncryptedSignature'], $params['decryption_password'], $encryption_format, $encryption_archive_id ) ) {
				leadwerk_migration_json_response( array( 'errors' => array( __( 'The decryption password is not valid. The process cannot continue.', 'leadwerk-migration' ) ) ) );
				exit;
			}

			try {
				leadwerk_migration_verify_encrypted_archive_manifest( leadwerk_migration_backup_path( $params ), $package, $params['decryption_password'] );
			} catch ( Leadwerk_Migration_Not_Decryptable_Exception $error ) {
				leadwerk_migration_json_response( array( 'errors' => array( $error->getMessage() ) ) );
				exit;
			}

			leadwerk_migration_json_response( array( 'errors' => array() ) );
			exit;
		}

		leadwerk_migration_json_response( array( 'check' => true, 'errors' => array() ) );
		exit;
	}

	public static function backup_list_content( $params = array() ) {
		leadwerk_migration_setup_environment();
		self::authorize( 'leadwerk_migration_import_site' );

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( $_POST );
		}

		// Set secret key
		$secret_key = null;
		if ( isset( $params['secret_key'] ) ) {
			$secret_key = trim( $params['secret_key'] );
		}

		self::verify_scope( $secret_key );

		$files = array();

		try {
			$archive = new Leadwerk_Migration_Extractor( leadwerk_migration_backup_path( $params ) );
			if ( ! $archive->is_valid() ) {
				throw new Leadwerk_Migration_Backups_Exception(
					__( 'Could not list the backup content. Please ensure the backup file is accessible and not corrupted.', 'leadwerk-migration' )
				);
			}

			$files = $archive->list_files();
			$archive->close();
		} catch ( Exception $e ) {
			leadwerk_migration_json_response( array( 'errors' => $e->getMessage() ) );
			exit;
		}

		leadwerk_migration_json_response( $files );
		exit;
	}

	public static function download_file( $params = array() ) {
		leadwerk_migration_setup_environment();
		self::authorize( 'leadwerk_migration_import_site' );

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( $_POST );
		}

		// Set secret key
		$secret_key = null;
		if ( isset( $params['secret_key'] ) ) {
			$secret_key = trim( $params['secret_key'] );
		}

		// Set decryption password
		$decryption_password = null;
		if ( isset( $params['decryption_password'] ) ) {
			$decryption_password = $params['decryption_password'];
		}

		// Set file name
		$file_name = null;
		if ( isset( $params['file_name'] ) ) {
			$file_name = trim( $params['file_name'] );
		}

		// Set file offset
		if ( isset( $params['file_offset'] ) ) {
			$file_offset = (int) $params['file_offset'];
		} else {
			$file_offset = 0;
		}

		self::verify_scope( $secret_key );

		// Read package.json file
		$handle = leadwerk_migration_open( leadwerk_migration_package_path( $params ), 'r' );

		// Parse package.json file
		$config = leadwerk_migration_read( $handle, filesize( leadwerk_migration_package_path( $params ) ) );
		$config = json_decode( $config, true );

		// Close handle
		leadwerk_migration_close( $handle );

		// Get compression type
		$compression_type = null;
		if ( ! empty( $config['Compression']['Enabled'] ) ) {
			$compression_type = $config['Compression']['Type'];
		}
		$encryption_format = isset( $config['EncryptionFormat'] ) && is_string( $config['EncryptionFormat'] ) ? $config['EncryptionFormat'] : null;
		$encryption_archive_id = isset( $config['EncryptionArchiveId'] ) && is_string( $config['EncryptionArchiveId'] ) ? $config['EncryptionArchiveId'] : null;

		// Open the archive file for reading
		$archive = new Leadwerk_Migration_Extractor( leadwerk_migration_backup_path( $params ), $decryption_password, $compression_type, $encryption_format, $encryption_archive_id );
		$archive->set_file_pointer( $file_offset );
		$archive->extract_one_file_to( leadwerk_migration_storage_path( $params ) );
		$archive->close();

		try {
			// Validate file name and file path for directory traversal
			if ( path_is_absolute( $file_name ) || validate_file( $file_name ) !== 0 ) {
				exit;
			}

			// Download file
			if ( ( $file_handle = leadwerk_migration_open( leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . $file_name, 'rb' ) ) ) {
				while ( ! feof( $file_handle ) ) {
					$file_buffer = leadwerk_migration_read( $file_handle, 1024 * 1024 );
					echo $file_buffer;
					ob_flush();
					flush();
				}

				leadwerk_migration_close( $file_handle );
			}
		} catch ( Exception $e ) {
		}

		exit;
	}

	public static function download_backup( $params = array() ) {
		leadwerk_migration_setup_environment();
		// Backup-page users have import capability; export-only users must also be
		// able to retrieve the archive they just created.
		self::authorize( current_user_can( 'leadwerk_migration_import_site' ) ? 'leadwerk_migration_import_site' : 'manage_options' );

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( $_POST );
		}

		// Set secret key
		$secret_key = null;
		if ( isset( $params['secret_key'] ) ) {
			$secret_key = trim( $params['secret_key'] );
		}

		self::verify_scope( $secret_key, true );

		try {
			// Download file
			if ( ( $file_handle = leadwerk_migration_open( leadwerk_migration_backup_path( $params ), 'rb' ) ) ) {
				while ( ! feof( $file_handle ) ) {
					$file_buffer = leadwerk_migration_read( $file_handle, 1024 * 1024 );
					echo $file_buffer;
					ob_flush();
					flush();
				}

				leadwerk_migration_close( $file_handle );
			}
		} catch ( Exception $e ) {
		}

		exit;
	}
}
