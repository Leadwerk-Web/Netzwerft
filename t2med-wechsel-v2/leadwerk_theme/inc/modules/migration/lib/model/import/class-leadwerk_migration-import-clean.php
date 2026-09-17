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

class Leadwerk_Migration_Import_Clean {

	public static function execute( $params ) {
		// Get database client
		$db_client = Leadwerk_Migration_Database_Utility::get_client();

		// Flush mainsite tables
		$db_client->add_table_prefix_filter( leadwerk_migration_table_prefix( 'mainsite' ) );
		$db_client->flush();

		// Trigger import cancel action
		if ( isset( $params['leadwerk_migration_import_cancel'] ) ) {
			do_action( 'leadwerk_migration_status_import_canceled', $params );
		} else {
			do_action( 'leadwerk_migration_status_import_cleaned', $params );
		}

		// Delete storage files. The leadwerk_migration_status_<job_id> option lingers so poll
		// clients still see the terminal state; the daily cron sweeps it later.
		Leadwerk_Migration_Directory::delete( leadwerk_migration_storage_path( $params ) );

		// Exit in console
		if ( defined( 'WP_CLI' ) ) {
			return $params;
		}

		exit;
	}
}
