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

class Leadwerk_Migration_Import_Validate {

	public static function execute( $params ) {

		// Verify file if size > 2GB and PHP = 32-bit
		if ( ! leadwerk_migration_is_filesize_supported( leadwerk_migration_archive_path( $params ) ) ) {
			throw new Leadwerk_Migration_Import_Exception(
				wp_kses(
					__(
						'Your server uses 32-bit PHP and cannot process files larger than 2GB. Please switch to 64-bit PHP and try again. Review the Leadwerk Migration health report for technical details.',
						'leadwerk-migration'
					),
					leadwerk_migration_allowed_html_tags()
				)
			);
		}

		// Verify file name extension
		if ( ! leadwerk_migration_is_filename_supported( leadwerk_migration_archive_path( $params ) ) ) {
			throw new Leadwerk_Migration_Import_Exception(
				wp_kses(
					__(
						'Invalid file type. Please ensure your file is a <strong>.wpress</strong> backup created with Leadwerk Migration. Review the Leadwerk Migration health report for technical details.',
						'leadwerk-migration'
					),
					leadwerk_migration_allowed_html_tags()
				)
			);
		}

		// Set progress
		Leadwerk_Migration_Status::info( __( 'Unpacking configuration...', 'leadwerk-migration' ) );

		// Open the archive file for reading
		$archive = new Leadwerk_Migration_Extractor( leadwerk_migration_archive_path( $params ) );

		// Validate the archive file consistency
		if ( ! $archive->is_valid() ) {
			throw new Leadwerk_Migration_Import_Exception(
				wp_kses(
					__( 'The archive file appears to be corrupted. Review the Leadwerk Migration health report and recreate the archive at the source site if necessary.', 'leadwerk-migration' ),
					leadwerk_migration_allowed_html_tags()
				)
			);
		}

		// Unpack package.json and multisite.json files
		$archive->extract_by_files_array( leadwerk_migration_storage_path( $params ), array( LEADWERK_MIGRATION_PACKAGE_NAME, LEADWERK_MIGRATION_MULTISITE_NAME, Leadwerk_Migration_Mail_Smtp::SNAPSHOT_NAME ) );

		// Check package.json file
		if ( false === is_file( leadwerk_migration_package_path( $params ) ) ) {
			throw new Leadwerk_Migration_Import_Exception(
				wp_kses(
					__(
						'Please ensure your file was created with the Leadwerk Migration plugin. Review the Leadwerk Migration health report for technical details.',
						'leadwerk-migration'
					),
					leadwerk_migration_allowed_html_tags()
				)
			);
		}

		// Set archive CRC value
		$params['archive_crc_value'] = $archive->get_archive_crc_value();

		// Set archive CRC size
		$params['archive_crc_size'] = $archive->get_archive_crc_size();

		// Close the archive file
		$archive->close();

		return $params;
	}
}
