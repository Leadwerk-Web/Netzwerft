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

class Leadwerk_Migration_Handler {

	/**
	 * Error handler
	 *
	 * @param  integer $errno   Error level
	 * @param  string  $errstr  Error message
	 * @param  string  $errfile Error file
	 * @param  integer $errline Error line
	 * @return void
	 */
	public static function error( $errno, $errstr, $errfile, $errline ) {
		global $leadwerk_migration_params;
		if ( ! empty( $leadwerk_migration_params['storage'] ) ) {
			Leadwerk_Migration_Log::error( $leadwerk_migration_params['storage'], array( 'Number' => $errno, 'Message' => $errstr, 'File' => $errfile, 'Line' => $errline ) );
		}
	}

	/**
	 * Shutdown handler
	 *
	 * @return void
	 */
	public static function shutdown() {
		global $leadwerk_migration_params;
		if ( ! empty( $leadwerk_migration_params['storage'] ) ) {
			if ( ( $error = error_get_last() ) ) {
				Leadwerk_Migration_Log::error( $leadwerk_migration_params['storage'], $error );
			}
		}
	}
}
