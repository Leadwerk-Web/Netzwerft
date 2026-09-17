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

class Leadwerk_Migration_Import_Mu_Plugins {

	public static function execute( $params ) {

		// Set progress
		Leadwerk_Migration_Status::info( __( 'Activating mu-plugins...', 'leadwerk-migration' ) );

		// Set decryption password
		$decryption_password = null;
		if ( isset( $params['decryption_password'] ) ) {
			$decryption_password = $params['decryption_password'];
		}

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

		// List of must-use plugins
		$exclude_files = array(
			LEADWERK_MIGRATION_MUPLUGINS_NAME . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_ENDURANCE_PAGE_CACHE_NAME,
			LEADWERK_MIGRATION_MUPLUGINS_NAME . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_ENDURANCE_PHP_EDGE_NAME,
			LEADWERK_MIGRATION_MUPLUGINS_NAME . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_ENDURANCE_BROWSER_CACHE_NAME,
			LEADWERK_MIGRATION_MUPLUGINS_NAME . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_GD_SYSTEM_PLUGIN_NAME,
			LEADWERK_MIGRATION_MUPLUGINS_NAME . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_WP_STACK_CACHE_NAME,
			LEADWERK_MIGRATION_MUPLUGINS_NAME . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_WP_COMSH_LOADER_NAME,
			LEADWERK_MIGRATION_MUPLUGINS_NAME . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_WP_COMSH_HELPER_NAME,
			LEADWERK_MIGRATION_MUPLUGINS_NAME . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_WP_ENGINE_SYSTEM_PLUGIN_NAME,
			LEADWERK_MIGRATION_MUPLUGINS_NAME . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_WPE_SIGN_ON_PLUGIN_NAME,
			LEADWERK_MIGRATION_MUPLUGINS_NAME . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_WP_ENGINE_SECURITY_AUDITOR_NAME,
			LEADWERK_MIGRATION_MUPLUGINS_NAME . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_WP_CERBER_SECURITY_NAME,
			LEADWERK_MIGRATION_MUPLUGINS_NAME . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_SQLITE_DATABASE_INTEGRATION_NAME,
			LEADWERK_MIGRATION_MUPLUGINS_NAME . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_SQLITE_DATABASE_ZERO_NAME,
			LEADWERK_MIGRATION_MUPLUGINS_NAME . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_EOS_DEACTIVATE_PLUGINS_NAME,
		);

		// Open the archive file for reading
		$archive = new Leadwerk_Migration_Extractor( leadwerk_migration_archive_path( $params ), $decryption_password, $compression_type, $encryption_format, $encryption_archive_id );

		// Unpack mu-plugins files
		$archive->extract_by_files_array( WP_CONTENT_DIR, array( LEADWERK_MIGRATION_MUPLUGINS_NAME ), $exclude_files );

		// Close the archive file
		$archive->close();

		// Set progress
		Leadwerk_Migration_Status::info( __( 'Mu-plugins activated.', 'leadwerk-migration' ) );

		return $params;
	}
}
