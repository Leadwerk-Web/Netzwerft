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

class Leadwerk_Migration_Export_Archive_Crc {

	public static function execute( $params ) {
		$archive_bytes_read = 0;

		// Set progress
		Leadwerk_Migration_Status::info( __( 'Calculating archive checksum...', 'leadwerk-migration' ) );

		// Set archive bytes remaining
		if ( isset( $params['archive_bytes_remaining'] ) ) {
			$archive_bytes_remaining = (int) $params['archive_bytes_remaining'];
		} else {
			$archive_bytes_remaining = leadwerk_migration_archive_bytes( $params );
		}

		// Set archive bytes offset
		if ( isset( $params['archive_bytes_offset'] ) ) {
			$archive_bytes_offset = (int) $params['archive_bytes_offset'];
		} else {
			$archive_bytes_offset = 0;
		}

		// Set archive CRC value
		$archive_crc_value = null;
		if ( isset( $params['archive_crc_value'] ) ) {
			$archive_crc_value = $params['archive_crc_value'];
		}

		// Flag to hold if file data has been processed
		$completed = true;

		// Start time
		$start = microtime( true );

		// Initialize CRC context for this chunk
		$hash_ctx = Leadwerk_Migration_Crc::init_crc32();

		// Open archive for reading
		if ( ( $file_handle = leadwerk_migration_open( leadwerk_migration_archive_path( $params ), 'rb' ) ) ) {
			if ( fseek( $file_handle, $archive_bytes_offset, SEEK_SET ) !== -1 ) {

				// Process file in chunks
				while ( $archive_bytes_remaining > 0 ) {
					if ( ( $file_content = leadwerk_migration_read( $file_handle, min( Leadwerk_Migration_Archiver::READ_CHUNK_SIZE, $archive_bytes_remaining ) ) ) !== false ) {

						// Empty read indicates EOF
						if ( strlen( $file_content ) === 0 ) {
							break;
						}

						// Add the amount of bytes we read
						$archive_bytes_read += strlen( $file_content );

						// Subtract the amount of bytes we read
						$archive_bytes_remaining -= strlen( $file_content );

						// Update CRC with original content
						Leadwerk_Migration_Crc::update_crc32( $hash_ctx, $file_content );
					}

					// Time elapsed
					if ( ( $timeout = apply_filters( 'leadwerk_migration_completed_timeout', 10 ) ) ) {
						if ( ( microtime( true ) - $start ) > $timeout ) {
							$completed = false;
							break;
						}
					}
				}

				// Get archive bytes offset
				$archive_bytes_offset += $archive_bytes_read;
			}

			leadwerk_migration_close( $file_handle );
		}

		// Combine and finalize CRC
		if ( empty( $archive_crc_value ) ) {
			$params['archive_crc_value'] = Leadwerk_Migration_Crc::finalize_crc32( $hash_ctx );
		} else {
			$params['archive_crc_value'] = Leadwerk_Migration_Crc::combine_crc32( $archive_crc_value, Leadwerk_Migration_Crc::finalize_crc32( $hash_ctx ), $archive_bytes_read );
		}

		// End of the archive file?
		if ( $completed ) {

			// St archive bytes remaining
			unset( $params['archive_bytes_remaining'] );

			// Unset archive offset
			unset( $params['archive_bytes_offset'] );

			// Unset completed flag
			unset( $params['completed'] );

		} else {

			// St archive bytes remaining
			$params['archive_bytes_remaining'] = $archive_bytes_remaining;

			// Set archive offset
			$params['archive_bytes_offset'] = $archive_bytes_offset;

			// Set completed flag
			$params['completed'] = $completed;
		}

		return $params;
	}
}
