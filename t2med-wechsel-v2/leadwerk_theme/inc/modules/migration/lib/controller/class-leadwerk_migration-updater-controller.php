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

class Leadwerk_Migration_Updater_Controller {

	public static function plugins_api( $result, $action = null, $args = null ) {
		return Leadwerk_Migration_Updater::plugins_api( $result, $action, $args );
	}

	public static function pre_update_plugins( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Check for updates every 11 hours
		if ( ( $last_check_for_updates = get_site_transient( LEADWERK_MIGRATION_LAST_CHECK_FOR_UPDATES ) ) ) {
			if ( ( time() - $last_check_for_updates ) < 11 * HOUR_IN_SECONDS ) {
				return $transient;
			}
		}

		// Set last check for updates
		set_site_transient( LEADWERK_MIGRATION_LAST_CHECK_FOR_UPDATES, time() );

		// Check for updates
		Leadwerk_Migration_Updater::check_for_updates();

		return $transient;
	}

	public static function update_plugins( $transient ) {
		return Leadwerk_Migration_Updater::update_plugins( $transient );
	}

	public static function check_for_updates() {
		return Leadwerk_Migration_Updater::check_for_updates();
	}

	public static function plugin_row_meta( $plugin_meta, $plugin_file ) {
		return Leadwerk_Migration_Updater::plugin_row_meta( $plugin_meta, $plugin_file );
	}

	public static function in_plugin_update_message( $plugin_data, $response ) {
		$updater = get_option( LEADWERK_MIGRATION_UPDATER, array() );

		// Get updater details
		if ( isset( $updater[ $plugin_data['slug'] ]['update_message'] ) ) {
			Leadwerk_Migration_Template::render( 'updater/update', array( 'message' => $updater[ $plugin_data['slug'] ]['update_message'] ) );
		}
	}
}
