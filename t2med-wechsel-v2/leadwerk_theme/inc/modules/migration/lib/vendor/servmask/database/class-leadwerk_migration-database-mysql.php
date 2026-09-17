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

class Leadwerk_Migration_Database_Mysql extends Leadwerk_Migration_Database {

	/**
	 * Check whether table has auto increment attribute
	 *
	 * @param  string  $table_name Table name
	 * @return boolean
	 */
	public function has_auto_increment( $table_name ) {
		return stripos( $this->get_create_table( $table_name ), 'AUTO_INCREMENT' ) !== false;
	}

	/**
	 * Run MySQL query
	 *
	 * @param  string $input SQL query
	 * @return mixed
	 */
	public function query( $input ) {
		if ( ! ( $result = mysql_query( $input, $this->wpdb->dbh ) ) ) {
			$mysql_errno = 0;

			// Get MySQL error code
			if ( ! empty( $this->wpdb->dbh ) ) {
				if ( is_resource( $this->wpdb->dbh ) ) {
					$mysql_errno = mysql_errno( $this->wpdb->dbh );
				} else {
					$mysql_errno = 2006;
				}
			}

			// MySQL server has gone away, try to reconnect
			if ( empty( $this->wpdb->dbh ) || 2006 === $mysql_errno ) {
				if ( ! $this->wpdb->check_connection( false ) ) {
					throw new Leadwerk_Migration_Database_Exception( __( 'Error reconnecting to the database. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ), 503 );
				}

				$result = mysql_query( $input, $this->wpdb->dbh );
			}
		}

		return $result;
	}

	/**
	 * Escape string input for MySQL query
	 *
	 * @param  string $input String to escape
	 * @return string
	 */
	public function escape( $input ) {
		return mysql_real_escape_string( $input, $this->wpdb->dbh );
	}

	/**
	 * Return the error code for the most recent function call
	 *
	 * @return integer
	 */
	public function errno() {
		return mysql_errno( $this->wpdb->dbh );
	}

	/**
	 * Return a string description of the last error
	 *
	 * @return string
	 */
	public function error() {
		return mysql_error( $this->wpdb->dbh );
	}

	/**
	 * Return server info
	 *
	 * @return string
	 */
	public function server_info() {
		static $cached_result = null;

		// Cache server info on first call
		if ( $cached_result === null ) {
			$cached_result = mysql_get_server_info( $this->wpdb->dbh );
		}

		return $cached_result;
	}

	/**
	 * Return the result from MySQL query as associative array
	 *
	 * @param  mixed $result MySQL resource
	 * @return array
	 */
	public function fetch_assoc( &$result ) {
		return mysql_fetch_assoc( $result );
	}

	/**
	 * Return the result from MySQL query as row
	 *
	 * @param  mixed $result MySQL resource
	 * @return array
	 */
	public function fetch_row( &$result ) {
		return mysql_fetch_row( $result );
	}

	/**
	 * Return the number for rows from MySQL results
	 *
	 * @param  mixed $result MySQL resource
	 * @return integer
	 */
	public function num_rows( &$result ) {
		return mysql_num_rows( $result );
	}

	/**
	 * Free MySQL result memory
	 *
	 * @param  mixed $result MySQL resource
	 * @return boolean
	 */
	public function free_result( &$result ) {
		return mysql_free_result( $result );
	}
}
