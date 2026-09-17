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

class Leadwerk_Migration_Import_Confirm {

	public static function execute( $params ) {

		// Automated confirmation may skip the prompt for authenticated v3 archives.
		// Legacy archives require a separate, explicit risk acknowledgement so a
		// stripped authentication manifest cannot silently downgrade the restore.
		$is_legacy = ! empty( $params['leadwerk_migration_legacy_unauthenticated'] );
		if (
			! empty( $params['leadwerk_migration_confirmed'] ) &&
			( ! $is_legacy || ! empty( $params['leadwerk_migration_allow_legacy_unauthenticated'] ) )
		) {
			return $params;
		}

		$messages = array();

		// Read package.json file
		$handle = leadwerk_migration_open( leadwerk_migration_package_path( $params ), 'r' );

		// Parse package.json file
		$package = leadwerk_migration_read( $handle, filesize( leadwerk_migration_package_path( $params ) ) );
		$package = json_decode( $package, true );

		// Close handle
		leadwerk_migration_close( $handle );

		if ( ! empty( $params['leadwerk_migration_legacy_unauthenticated'] ) ) {
			$messages[] = __(
				'Security warning: this legacy archive has no keyed whole-archive authentication manifest. Leadwerk cannot prove that its headers and contents were not modified. Continue only if you trust its source and have verified it by an independent checksum.',
				'leadwerk-migration'
			);
		}

		// Confirm the destructive operation without linking to third-party offers.
		$messages[] = __(
			'Importing this archive replaces matching site content and may overwrite database records, themes, plugins, and uploads. Keep an independent current backup before continuing. Proceed?',
			'leadwerk-migration'
		);

		// Check compatibility of PHP versions
		if ( isset( $package['PHP']['Version'] ) ) {
			// Extract major and minor version numbers
			$source_versions = explode( '.', $package['PHP']['Version'] );
			$target_versions = explode( '.', PHP_VERSION );

			$source_major_version = intval( $source_versions[0] );
			$source_minor_version = intval( isset( $source_versions[1] ) ? $source_versions[1] : 0 );

			$target_major_version = intval( $target_versions[0] );
			$target_minor_version = intval( isset( $target_versions[1] ) ? $target_versions[1] : 0 );

			if ( $source_major_version !== $target_major_version ) {
				$from_php = $source_major_version;
				$to_php   = $target_major_version;
			} elseif ( $source_minor_version !== $target_minor_version ) {
				$from_php = sprintf( '%s.%s', $source_major_version, $source_minor_version );
				$to_php   = sprintf( '%s.%s', $target_major_version, $target_minor_version );
			}

			if ( isset( $from_php, $to_php ) ) {
				if ( defined( 'WP_CLI' ) ) {
					$messages[] = sprintf(
						/* translators: 1: Source PHP version, 2: Target PHP version. */
						__(
							'Your backup is from PHP %1$s but the site that you are importing to uses PHP %2$s.
							This version difference could cause the import to fail. Test the archive on a staging site first.',
							'leadwerk-migration'
						),
						$from_php,
						$to_php
					);
				} else {
					$messages[] = sprintf(
						'<i class="leadwerk_migration-import-info">' .
						/* translators: 1: Source PHP version, 2: Target PHP version. */
						__(
							'Your backup is from PHP %1$s but the site that you are importing to uses PHP %2$s. This version difference could cause the import to fail. Test the archive on a staging site first.',
							'leadwerk-migration'
						) . '</i>',
						$from_php,
						$to_php
					);
				}
			}
		}

		if ( defined( 'WP_CLI' ) ) {
			$assoc_args = array();
			if ( isset( $params['cli_args'] ) ) {
				$assoc_args = $params['cli_args'];
			}

			WP_CLI::confirm( implode( PHP_EOL, $messages ), $assoc_args );

			return $params;
		}

		// Set progress
		Leadwerk_Migration_Status::confirm( implode( $messages ), $is_legacy );

		// REST API: throw to halt the pipeline; controller preserves the status option
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			throw new Leadwerk_Migration_Import_Halted_Exception( 'awaiting_confirmation' );
		}

		exit;
	}
}
