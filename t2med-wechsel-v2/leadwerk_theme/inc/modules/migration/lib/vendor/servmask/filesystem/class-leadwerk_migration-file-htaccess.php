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

class Leadwerk_Migration_File_Htaccess {

	/**
	 * Create backups .htaccess file
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
					'<IfModule mod_authz_core.c>',
					'	Require all denied',
					'</IfModule>',
					'<IfModule !mod_authz_core.c>',
					'	Order allow,deny',
					'	Deny from all',
					'</IfModule>',
				)
			)
		);
	}

	/**
	 * Create storage .htaccess file
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
					'<IfModule mod_authz_core.c>',
					'	Require all denied',
					'</IfModule>',
					'<IfModule !mod_authz_core.c>',
					'	Order allow,deny',
					'	Deny from all',
					'</IfModule>',
					'<IfModule mod_dir.c>',
					'	DirectoryIndex index.php',
					'</IfModule>',
					'<IfModule mod_autoindex.c>',
					'	Options -Indexes',
					'</IfModule>',
				)
			)
		);
	}

	/**
	 * Create LiteSpeed .htaccess file
	 *
	 * @param  string  $path Path to file
	 * @return boolean
	 */
	public static function litespeed( $path ) {
		return Leadwerk_Migration_File::insert_with_markers(
			$path,
			'LiteSpeed',
			array(
				'<IfModule Litespeed>',
				'	SetEnv noabort 1',
				'</IfModule>',
			)
		);
	}
}
