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

class Leadwerk_Migration_Export_Enumerate_Tables {

	public static function execute( $params ) {
		// Set exclude database
		if ( isset( $params['options']['no_database'] ) ) {
			return $params;
		}

		// Get total tables count
		if ( isset( $params['total_tables_count'] ) ) {
			$total_tables_count = (int) $params['total_tables_count'];
		} else {
			$total_tables_count = 1;
		}

		// Set progress
		Leadwerk_Migration_Status::info( __( 'Gathering database tables...', 'leadwerk-migration' ) );

		// Get database client
		$db_client = Leadwerk_Migration_Database_Utility::get_client();

		// Include table prefixes
		if ( leadwerk_migration_table_prefix() ) {
			$db_client->add_table_prefix_filter( leadwerk_migration_table_prefix() );

			// Include table prefixes (Webba Booking and CiviCRM)
			foreach ( array( 'wbk_', 'civicrm_' ) as $table_name ) {
				$db_client->add_table_prefix_filter( $table_name );
			}
		}

		// Create tables list file
		$tables_list = leadwerk_migration_open( leadwerk_migration_tables_list_path( $params ), 'w' );

		// Exclude selected db tables
		$excluded_db_tables = array();
		if ( isset( $params['options']['exclude_db_tables'], $params['excluded_db_tables'] ) ) {
			$excluded_db_tables = explode( ',', $params['excluded_db_tables'] );
		}

		// Write table line
		foreach ( $db_client->get_tables() as $table_name ) {
			if ( ! in_array( $table_name, $excluded_db_tables ) && leadwerk_migration_putcsv( $tables_list, array( $table_name ) ) ) {
				$total_tables_count++;
			}
		}

		// Include selected db tables
		if ( isset( $params['options']['include_db_tables'] ) && ! empty( $params['included_db_tables'] ) ) {
			foreach ( explode( ',', $params['included_db_tables'] )  as $table_name ) {
				if ( leadwerk_migration_putcsv( $tables_list, array( $table_name ) ) ) {
					$total_tables_count++;
				}
			}
		}

		// Set progress
		Leadwerk_Migration_Status::info( __( 'Database tables gathered.', 'leadwerk-migration' ) );

		// Set total tables count
		$params['total_tables_count'] = $total_tables_count;

		// Close the tables list file
		leadwerk_migration_close( $tables_list );

		return $params;
	}
}
