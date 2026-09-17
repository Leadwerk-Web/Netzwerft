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

class Leadwerk_Migration_Export_Download {

	public static function execute( $params ) {

		// Get archive CRC value
		$archive_crc_value = null;
		if ( isset( $params['archive_crc_value'] ) ) {
			$archive_crc_value = $params['archive_crc_value'];
		}

		// Set progress
		Leadwerk_Migration_Status::info( __( 'Renaming export file...', 'leadwerk-migration' ) );

		// Seal encrypted archives with a keyed manifest that covers every
		// preceding header and payload byte. The manifest must be the final
		// archive entry, immediately before the EOF block.
		if ( ! empty( $params['options']['encrypt_backups'] ) ) {
			$password   = isset( $params['options']['encrypt_password'] ) ? $params['options']['encrypt_password'] : '';
			$archive_id = isset( $params['encryption_archive_id'] ) ? $params['encryption_archive_id'] : '';
			$manifest   = leadwerk_migration_create_encrypted_archive_manifest( leadwerk_migration_archive_path( $params ), $password, $archive_id );
			$temp_file  = @tempnam( leadwerk_migration_storage_path( $params ), '.leadwerk-auth-' );
			if ( $temp_file === false || @file_put_contents( $temp_file, $manifest, LOCK_EX ) !== strlen( $manifest ) ) {
				if ( is_string( $temp_file ) && file_exists( $temp_file ) ) {
					@unlink( $temp_file );
				}
				throw new Leadwerk_Migration_Not_Writable_Exception( __( 'Could not write the authenticated archive manifest.', 'leadwerk-migration' ) );
			}

			try {
				$manifest_archive = new Leadwerk_Migration_Compressor( leadwerk_migration_archive_path( $params ) );
				$file_bytes_read = $file_bytes_offset = $file_bytes_written = 0;
				$file_crc = null;
				if ( ! $manifest_archive->add_file( $temp_file, LEADWERK_MIGRATION_AUTH_MANIFEST_NAME, $file_bytes_read, $file_bytes_offset, $file_bytes_written, $file_crc ) ) {
					throw new Leadwerk_Migration_Not_Writable_Exception( __( 'Could not append the authenticated archive manifest.', 'leadwerk-migration' ) );
				}
				$manifest_archive->close();
			} finally {
				@unlink( $temp_file );
			}

			// The precomputed CRC predates the manifest; force a complete final CRC.
			$archive_crc_value = null;
		}

		// Open the archive file for writing
		$archive = new Leadwerk_Migration_Compressor( leadwerk_migration_archive_path( $params ) );

		// Append EOF block
		$archive->close( true, $archive_crc_value );

		// Rename archive file
		if ( rename( leadwerk_migration_archive_path( $params ), leadwerk_migration_backup_path( $params ) ) ) {
			$blog_id = null;

			// Get subsite Blog ID
			if ( isset( $params['options']['sites'] ) && ( $sites = $params['options']['sites'] ) ) {
				if ( count( $sites ) === 1 ) {
					$blog_id = array_shift( $sites );
				}
			}

			// Set archive details
			$file = leadwerk_migration_archive_name( $params );
			$link = Leadwerk_Migration_Dashboard_Controller::download_url( $file );
			$size = leadwerk_migration_backup_size( $params );
			$name = leadwerk_migration_site_name( $blog_id );

			// Set progress
			if ( leadwerk_migration_direct_download_supported() ) {
				Leadwerk_Migration_Status::download(
					sprintf(
						/* translators: 1: Link to archive, 2: Archive title, 3: File name, 4: Archive title, 5: File size. */
						__(
							'<a href="%1$s" class="leadwerk_migration-button-green leadwerk_migration-emphasize leadwerk_migration-button-download" title="%2$s" download="%3$s">
							<span>Download %2$s</span>
							<em>Size: %4$s</em>
							</a>',
							'leadwerk-migration'
						),
						$link,
						$name,
						$file,
						$size
					)
				);
			} else {
				Leadwerk_Migration_Status::download(
					sprintf(
						/* translators: 1: Archive title, 2: File name, 3: Archive title, 4: File size. */
						__(
							'<a href="#" class="leadwerk_migration-button-green leadwerk_migration-emphasize leadwerk_migration-direct-download" title="%1$s" download="%2$s">
							<span>Download %3$s</span>
							<em>Size: %4$s</em>
							</a>',
							'leadwerk-migration'
						),
						$name,
						$file,
						$name,
						$size
					)
				);
			}
		}

		do_action( 'leadwerk_migration_status_export_done', $params );

		// Run manual on backup created hook
		if ( isset( $params['leadwerk_migration_manual_backup'] ) ) {
			do_action( 'leadwerk_migration_status_backup_created', $params );
		}

		return $params;
	}
}
