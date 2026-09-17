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

class Leadwerk_Migration_Import_Options {

	public static function execute( $params ) {
		// Set progress
		Leadwerk_Migration_Status::info( __( 'Preparing WordPress options...', 'leadwerk-migration' ) );

		// Get database client
		$db_client = Leadwerk_Migration_Database_Utility::get_client();

		$tables = $db_client->get_tables();

		// Get base prefix
		$base_prefix = leadwerk_migration_table_prefix();

		// Get mainsite prefix
		$mainsite_prefix = leadwerk_migration_table_prefix( 'mainsite' );

		// Check WP sitemeta table exists
		if ( in_array( "{$mainsite_prefix}sitemeta", $tables ) ) {

			// Get fs_accounts option value (Freemius)
			$result = $db_client->query( "SELECT meta_value FROM `{$mainsite_prefix}sitemeta` WHERE meta_key = 'fs_accounts'" );
			if ( ( $row = $db_client->fetch_assoc( $result ) ) ) {
				$fs_accounts = get_option( 'fs_accounts', array() );
				$meta_value  = array();

				// Imported options are untrusted input. Never instantiate PHP objects
				// while reading a value from an archive-provided database.
				if ( is_string( $row['meta_value'] ) && is_serialized( $row['meta_value'] ) ) {
					$candidate = @unserialize( trim( $row['meta_value'] ), array( 'allowed_classes' => false ) );
					if ( is_array( $candidate ) ) {
						$meta_value = $candidate;
					}
				}

				// Update fs_accounts option value (Freemius)
				if ( is_array( $fs_accounts ) && ( $fs_accounts = array_merge( $fs_accounts, $meta_value ) ) ) {
					if ( isset( $fs_accounts['users'], $fs_accounts['sites'] ) ) {
						update_option( 'fs_accounts', $fs_accounts );
					} else {
						delete_option( 'fs_accounts' );
						delete_option( 'fs_dbg_accounts' );
						delete_option( 'fs_active_plugins' );
						delete_option( 'fs_api_cache' );
						delete_option( 'fs_dbg_api_cache' );
						delete_option( 'fs_debug_mode' );
					}
				}
			}
		}

		// Set progress
		Leadwerk_Migration_Status::info( __( 'WordPress options prepared.', 'leadwerk-migration' ) );

		return $params;
	}
}
