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

class Leadwerk_Migration_Export_Database_File {

	public static function execute( $params ) {

		// Set exclude database
		if ( isset( $params['options']['no_database'] ) ) {
			return $params;
		}

		$database_bytes_read = 0;

		// Set encrypt password
		$encrypt_password = null;
		if ( isset( $params['options']['encrypt_backups'], $params['options']['encrypt_password'] ) ) {
			$encrypt_password = $params['options']['encrypt_password'];
		}

		// Set compression type
		$compression_type = null;
		if ( isset( $params['options']['compression_type'] ) ) {
			$compression_type = $params['options']['compression_type'];
		}

		// Set archive bytes offset
		if ( isset( $params['archive_bytes_offset'] ) ) {
			$archive_bytes_offset = (int) $params['archive_bytes_offset'];
		} else {
			$archive_bytes_offset = leadwerk_migration_archive_bytes( $params );
		}

		// Set database bytes offset
		if ( isset( $params['database_bytes_offset'] ) ) {
			$database_bytes_offset = (int) $params['database_bytes_offset'];
		} else {
			$database_bytes_offset = 0;
		}

		// Set database bytes written
		if ( isset( $params['database_bytes_written'] ) ) {
			$database_bytes_written = (int) $params['database_bytes_written'];
		} else {
			$database_bytes_written = 0;
		}

		// Set database CRC
		if ( isset( $params['database_crc'] ) ) {
			$database_crc = $params['database_crc'];
		} else {
			$database_crc = null;
		}

		// Get total database size
		if ( isset( $params['total_database_size'] ) ) {
			$total_database_size = (int) $params['total_database_size'];
		} else {
			$total_database_size = leadwerk_migration_database_bytes( $params );
		}

		// What percent of database have we processed?
		$progress = (int) min( ( $database_bytes_offset / $total_database_size ) * 100, 100 );

		// Set progress
		/* translators: Progress. */
		Leadwerk_Migration_Status::info( sprintf( __( 'Archiving database...<br />%d%% complete', 'leadwerk-migration' ), $progress ) );

		// Open the archive file for writing
		$archive = new Leadwerk_Migration_Compressor( leadwerk_migration_archive_path( $params ), $encrypt_password, $compression_type, isset( $params['encryption_archive_id'] ) ? $params['encryption_archive_id'] : null );

		// Set the file pointer to the one that we have saved
		$archive->set_file_pointer( $archive_bytes_offset );

		// Add database.sql to archive
		if ( $archive->add_file( leadwerk_migration_database_path( $params ), LEADWERK_MIGRATION_DATABASE_NAME, $database_bytes_read, $database_bytes_offset, $database_bytes_written, $database_crc ) ) {
			$smtp_sidecar = Leadwerk_Migration_Mail_Smtp::export_sidecar_path( $params );
			if ( is_file( $smtp_sidecar ) ) {
				$smtp_bytes_read = $smtp_bytes_offset = $smtp_bytes_written = 0;
				$smtp_crc        = null;
				$archive->add_file( $smtp_sidecar, Leadwerk_Migration_Mail_Smtp::SNAPSHOT_NAME, $smtp_bytes_read, $smtp_bytes_offset, $smtp_bytes_written, $smtp_crc );
			}

			// Set progress
			Leadwerk_Migration_Status::info( __( 'Database archived.', 'leadwerk-migration' ) );

			// Unset archive bytes offset
			unset( $params['archive_bytes_offset'] );

			// Unset database bytes offset
			unset( $params['database_bytes_offset'] );

			// Unset database bytes written
			unset( $params['database_bytes_written'] );

			// Unset database CRC
			unset( $params['database_crc'] );

			// Unset total database size
			unset( $params['total_database_size'] );

			// Unset completed flag
			unset( $params['completed'] );

		} else {

			// Get archive bytes offset
			$archive_bytes_offset = $archive->get_file_pointer();

			// What percent of database have we processed?
			$progress = (int) min( ( $database_bytes_offset / $total_database_size ) * 100, 100 );

			// Set progress
			/* translators: Progress. */
			Leadwerk_Migration_Status::info( sprintf( __( 'Archiving database...<br />%d%% complete', 'leadwerk-migration' ), $progress ) );

			// Set archive bytes offset
			$params['archive_bytes_offset'] = $archive_bytes_offset;

			// Set database bytes offset
			$params['database_bytes_offset'] = $database_bytes_offset;

			// Set database bytes written
			$params['database_bytes_written'] = $database_bytes_written;

			// Set database CRC
			$params['database_crc'] = $database_crc;

			// Set total database size
			$params['total_database_size'] = $total_database_size;

			// Set completed flag
			$params['completed'] = false;
		}

		// Truncate the archive file
		$archive->truncate();

		// Close the archive file
		$archive->close();

		return $params;
	}
}
