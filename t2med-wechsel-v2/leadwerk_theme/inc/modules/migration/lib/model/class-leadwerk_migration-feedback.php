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

class Leadwerk_Migration_Feedback {

	/**
	 * Submit customer feedback to the configured endpoint.
	 *
	 * @param  string  $type      Feedback type
	 * @param  string  $email     User e-mail
	 * @param  string  $message   User message
	 * @param  integer $terms     User accept terms
	 * @param  string  $purchases Purchases IDs
	 *
	 * @return array
	 */
	public static function add( $type, $email, $message, $terms, $purchases ) {
		// Validate email
		if ( filter_var( $email, FILTER_VALIDATE_EMAIL ) === false ) {
			throw new Leadwerk_Migration_Feedback_Exception( esc_html__( 'Please enter a valid email address.', 'leadwerk-migration' ) );
		}

		// Validate type
		if ( empty( $type ) ) {
			throw new Leadwerk_Migration_Feedback_Exception( esc_html__( 'Please select a feedback type.', 'leadwerk-migration' ) );
		}

		// Validate message
		if ( empty( $message ) ) {
			throw new Leadwerk_Migration_Feedback_Exception( esc_html__( 'Please describe your issue or feedback.', 'leadwerk-migration' ) );
		}

		// Validate terms
		if ( empty( $terms ) ) {
			throw new Leadwerk_Migration_Feedback_Exception( esc_html__( 'Please check the consent box so we can use your email to respond to you.', 'leadwerk-migration' ) );
		}

		$response = wp_remote_post(
			LEADWERK_MIGRATION_FEEDBACK_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'type'      => $type,
					'email'     => $email,
					'message'   => $message,
					'purchases' => $purchases,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: Error message. */
			throw new Leadwerk_Migration_Feedback_Exception( esc_html( sprintf( __( 'An error occurred while submitting your request: %s', 'leadwerk-migration' ), $response->get_error_message() ) ) );
		}

		return $response;
	}
}
