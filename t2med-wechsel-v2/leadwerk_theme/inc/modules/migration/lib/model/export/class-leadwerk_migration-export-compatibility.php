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

class Leadwerk_Migration_Export_Compatibility {

	public static function execute( $params ) {

		// Set progress
		Leadwerk_Migration_Status::info( __( 'Checking for compatibility...', 'leadwerk-migration' ) );

		if ( ! empty( $params['options']['encrypt_backups'] ) ) {
			$password = isset( $params['options']['encrypt_password'] ) ? (string) $params['options']['encrypt_password'] : '';
			if ( ! leadwerk_migration_can_encrypt() ) {
				throw new Leadwerk_Migration_Compatibility_Exception( __( 'Authenticated archive encryption requires OpenSSL with AES-256-GCM support.', 'leadwerk-migration' ) );
			}
			if ( strlen( $password ) < 12 || strlen( $password ) > 4096 ) {
				throw new Leadwerk_Migration_Compatibility_Exception( __( 'Encrypted backups require a password between 12 and 4096 characters.', 'leadwerk-migration' ) );
			}
			if ( isset( $params['options']['encrypt_password_confirmation'] ) && ! hash_equals( $password, (string) $params['options']['encrypt_password_confirmation'] ) ) {
				throw new Leadwerk_Migration_Compatibility_Exception( __( 'The backup encryption passwords do not match.', 'leadwerk-migration' ) );
			}
		}

		// Get messages
		$messages = Leadwerk_Migration_Compatibility::get( $params );

		// Set messages
		if ( empty( $messages ) ) {
			return $params;
		}

		// Error message
		throw new Leadwerk_Migration_Compatibility_Exception( wp_kses( implode( $messages ), leadwerk_migration_allowed_html_tags() ) );
	}
}
