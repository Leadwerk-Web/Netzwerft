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

class Leadwerk_Migration_File_Webconfig {

	/**
	 * Create backups web.config file
	 *
	 * @param  string  $path Path to file
	 * @return boolean
	 */
	public static function backups( $path ) {
		return Leadwerk_Migration_File::create(
			$path,
			implode(
				PHP_EOL,
				array(
					'<?xml version="1.0" encoding="utf-8"?>',
					'<configuration>',
					'	<system.webServer>',
					'		<security>',
					'			<authorization>',
					'				<deny users="*" />',
					'			</authorization>',
					'		</security>',
					'	</system.webServer>',
					'</configuration>',
				)
			)
		);
	}

	/**
	 * Create storage web.config file
	 *
	 * @param  string  $path Path to file
	 * @return boolean
	 */
	public static function storage( $path ) {
		return Leadwerk_Migration_File::create(
			$path,
			implode(
				PHP_EOL,
				array(
					'<?xml version="1.0" encoding="utf-8"?>',
					'<configuration>',
					'	<system.webServer>',
					'		<security>',
					'			<authorization>',
					'				<deny users="*" />',
					'			</authorization>',
					'		</security>',
					'		<defaultDocument>',
					'			<files>',
					'				<add value="index.php" />',
					'			</files>',
					'		</defaultDocument>',
					'		<directoryBrowse enabled="false" />',
					'	</system.webServer>',
					'</configuration>',
				)
			)
		);
	}
}
