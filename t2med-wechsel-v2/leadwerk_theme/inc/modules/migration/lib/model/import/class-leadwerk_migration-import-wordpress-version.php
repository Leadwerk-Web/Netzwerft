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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

/**
 * Prevent an archive created on one WordPress version from being restored
 * over a different WordPress core version.
 *
 * The package metadata is extracted by Import_Validate at priority 50. This
 * check runs at priority 52, before CRC validation, confirmation, extraction,
 * or any destructive database/filesystem stage.
 */
class Leadwerk_Migration_Import_WordPress_Version {

	/**
	 * Verify that the source and destination WordPress versions are identical.
	 *
	 * @param array<string, mixed> $params Import pipeline parameters.
	 * @return array<string, mixed>
	 * @throws Leadwerk_Migration_Compatibility_Exception When version metadata is missing or differs.
	 */
	public static function execute( $params ) {
		global $wp_version;

		Leadwerk_Migration_Status::info( __( 'Checking WordPress version compatibility...', 'leadwerk-migration' ) );

		$package_path = leadwerk_migration_package_path( $params );
		$handle       = leadwerk_migration_open( $package_path, 'r' );
		$package_json = leadwerk_migration_read( $handle, filesize( $package_path ) );
		leadwerk_migration_close( $handle );

		$package        = json_decode( $package_json, true );
		$source_version = '';

		if ( is_array( $package ) && isset( $package['WordPress']['Version'] ) && is_string( $package['WordPress']['Version'] ) ) {
			$source_version = trim( $package['WordPress']['Version'] );
		}

		$target_version = is_string( $wp_version ) ? trim( $wp_version ) : '';

		if ( $source_version === '' ) {
			throw new Leadwerk_Migration_Compatibility_Exception(
				__( 'Import blocked: the backup does not contain a valid source WordPress version. Create a new backup with Leadwerk Migration before importing.', 'leadwerk-migration' )
			);
		}

		if ( $target_version === '' ) {
			throw new Leadwerk_Migration_Compatibility_Exception(
				__( 'Import blocked: the destination WordPress version could not be determined.', 'leadwerk-migration' )
			);
		}

		if ( $source_version !== $target_version ) {
			throw new Leadwerk_Migration_Compatibility_Exception(
				wp_kses(
					sprintf(
						/* translators: 1: WordPress version in the backup, 2: destination WordPress version. */
						__( 'Import blocked: WordPress versions must match exactly. Backup version: <strong>%1$s</strong>. Destination version: <strong>%2$s</strong>. Update or downgrade the destination to the backup version and try again.', 'leadwerk-migration' ),
						esc_html( $source_version ),
						esc_html( $target_version )
					),
					leadwerk_migration_allowed_html_tags()
				)
			);
		}

		// A Site Sync job also locks the PHP major.minor branch and exact plugin
		// version. This repeats
		// the Hub preflight immediately before destructive import stages, so a
		// runtime change between heartbeat and transfer cannot slip through.
		if ( ! empty( $params['leadwerk_migration_sync_job_id'] ) ) {
			$source_php = is_array( $package ) && isset( $package['PHP']['Version'] ) && is_string( $package['PHP']['Version'] ) ? trim( $package['PHP']['Version'] ) : '';
			$source_plugin = is_array( $package ) && isset( $package['Plugin']['Version'] ) && is_string( $package['Plugin']['Version'] ) ? trim( $package['Plugin']['Version'] ) : '';
			if ( ! Leadwerk_Migration_Site_Identity::php_versions_compatible( $source_php, PHP_VERSION ) ) {
				throw new Leadwerk_Migration_Compatibility_Exception(
					sprintf(
						__( 'Site Sync blocked: PHP major.minor branches must match. Backup version: %1$s. Destination version: %2$s. Patch releases within the same branch are allowed.', 'leadwerk-migration' ),
						esc_html( $source_php !== '' ? $source_php : __( 'unknown', 'leadwerk-migration' ) ),
						esc_html( PHP_VERSION )
					)
				);
			}
			if ( $source_plugin === '' || ! hash_equals( $source_plugin, LEADWERK_MIGRATION_VERSION ) ) {
				throw new Leadwerk_Migration_Compatibility_Exception(
					sprintf(
						__( 'Site Sync blocked: Leadwerk Migration versions must match exactly. Backup version: %1$s. Destination version: %2$s.', 'leadwerk-migration' ),
						esc_html( $source_plugin !== '' ? $source_plugin : __( 'unknown', 'leadwerk-migration' ) ),
						esc_html( LEADWERK_MIGRATION_VERSION )
					)
				);
			}
		}

		// Bind restores to the same project family and preserve the destination's
		// environment-specific credential outside the database replacement.
		$identity_check = Leadwerk_Migration_Site_Identity::prepare_import_identity( $params, $package );
		if ( is_wp_error( $identity_check ) ) {
			throw new Leadwerk_Migration_Compatibility_Exception( esc_html( $identity_check->get_error_message() ) );
		}

		Leadwerk_Migration_Status::info(
			sprintf(
				/* translators: WordPress version shared by the backup and destination. */
				__( 'WordPress version %s matches the backup.', 'leadwerk-migration' ),
				esc_html( $target_version )
			)
		);

		return $params;
	}
}
