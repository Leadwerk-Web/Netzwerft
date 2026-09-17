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

class Leadwerk_Migration_Import_Check_Compression {

	public static function execute( $params ) {

		// Read package.json file
		$handle = leadwerk_migration_open( leadwerk_migration_package_path( $params ), 'r' );

		// Parse package.json file
		$package = leadwerk_migration_read( $handle, filesize( leadwerk_migration_package_path( $params ) ) );
		$package = json_decode( $package, true );

		// Close handle
		leadwerk_migration_close( $handle );

		// No compression provided
		if ( empty( $package['Compression']['Enabled'] ) || empty( $package['Compression']['Type'] ) ) {
			return $params;
		}

		// Check if server supports decompression
		if ( ! leadwerk_migration_has_compression_type( $package['Compression']['Type'] ) ) {
			throw new Leadwerk_Migration_Import_Exception(
				wp_kses(
					sprintf(
						__(
							'Importing a compressed backup is not supported on this server.
							Please ensure <strong>%s</strong> extension is enabled. Review the Leadwerk Migration health report for technical details.',
							'leadwerk-migration'
						),
						$package['Compression']['Type']
					),
					leadwerk_migration_allowed_html_tags()
				)
			);
		}

		// Set progres
		Leadwerk_Migration_Status::info( __( 'Compressed backup detected. Compression will be handled automatically.', 'leadwerk-migration' ) );

		return $params;
	}
}
