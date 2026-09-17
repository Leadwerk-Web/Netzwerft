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

class Leadwerk_Migration_Feedback_Controller {

	public static function feedback( $params = array() ) {
		leadwerk_migration_setup_environment();

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( $_POST );
		}

		// Set secret key
		$secret_key = null;
		if ( isset( $params['secret_key'] ) ) {
			$secret_key = trim( $params['secret_key'] );
		}

		// Set type
		$type = null;
		if ( isset( $params['leadwerk_migration_type'] ) ) {
			$type = trim( $params['leadwerk_migration_type'] );
		}

		// Set e-mail
		$email = null;
		if ( isset( $params['leadwerk_migration_email'] ) ) {
			$email = trim( $params['leadwerk_migration_email'] );
		}

		// Set message
		$message = null;
		if ( isset( $params['leadwerk_migration_message'] ) ) {
			$message = trim( $params['leadwerk_migration_message'] );
		}

		// Set terms
		$terms = false;
		if ( isset( $params['leadwerk_migration_terms'] ) ) {
			$terms = (bool) $params['leadwerk_migration_terms'];
		}

		try {
			// Ensure that unauthorized people cannot access feedback action
			leadwerk_migration_verify_secret_key( $secret_key );
		} catch ( Leadwerk_Migration_Not_Valid_Secret_Key_Exception $e ) {
			exit;
		}

		$extensions = Leadwerk_Migration_Extensions::get();

		// Exclude File Extension
		if ( defined( 'LEADWERK_MIGRATIONTE_PLUGIN_NAME' ) ) {
			unset( $extensions[ LEADWERK_MIGRATIONTE_PLUGIN_NAME ] );
		}

		$purchases = array();
		foreach ( $extensions as $extension ) {
			$purchases[] = $extension['key'];
		}

		try {
			Leadwerk_Migration_Feedback::add( $type, $email, $message, $terms, implode( PHP_EOL, $purchases ) );
		} catch ( Leadwerk_Migration_Feedback_Exception $e ) {
			leadwerk_migration_json_response( array( 'errors' => array( $e->getMessage() ) ) );
			exit;
		}

		leadwerk_migration_json_response( array( 'errors' => array() ) );
		exit;
	}
}
