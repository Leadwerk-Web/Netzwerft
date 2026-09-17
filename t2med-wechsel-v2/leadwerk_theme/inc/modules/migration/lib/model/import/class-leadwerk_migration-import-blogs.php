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

class Leadwerk_Migration_Import_Blogs {

	public static function execute( $params ) {

		// Set progress
		Leadwerk_Migration_Status::info( __( 'Preparing blogs...', 'leadwerk-migration' ) );

		$blogs = array();

		// Check multisite.json file
		if ( true === is_file( leadwerk_migration_multisite_path( $params ) ) ) {

			// Read multisite.json file
			$handle = leadwerk_migration_open( leadwerk_migration_multisite_path( $params ), 'r' );

			// Parse multisite.json file
			$multisite = leadwerk_migration_read( $handle, filesize( leadwerk_migration_multisite_path( $params ) ) );
			$multisite = json_decode( $multisite, true );

			// Close handle
			leadwerk_migration_close( $handle );

			// Validate
			if ( empty( $multisite['Network'] ) ) {
				if ( isset( $multisite['Sites'] ) && ( $sites = $multisite['Sites'] ) ) {
					if ( count( $sites ) === 1 && ( $subsite = current( $sites ) ) ) {

						// Set internal Site URL (backward compatibility)
						if ( empty( $subsite['InternalSiteURL'] ) ) {
							$subsite['InternalSiteURL'] = null;
						}

						// Set internal Home URL (backward compatibility)
						if ( empty( $subsite['InternalHomeURL'] ) ) {
							$subsite['InternalHomeURL'] = null;
						}

						// Set active plugins (backward compatibility)
						if ( empty( $subsite['Plugins'] ) ) {
							$subsite['Plugins'] = array();
						}

						// Set active template (backward compatibility)
						if ( empty( $subsite['Template'] ) ) {
							$subsite['Template'] = null;
						}

						// Set active stylesheet (backward compatibility)
						if ( empty( $subsite['Stylesheet'] ) ) {
							$subsite['Stylesheet'] = null;
						}

						// Set uploads path (backward compatibility)
						if ( empty( $subsite['Uploads'] ) ) {
							$subsite['Uploads'] = null;
						}

						// Set uploads URL path (backward compatibility)
						if ( empty( $subsite['UploadsURL'] ) ) {
							$subsite['UploadsURL'] = null;
						}

						// Set uploads path (backward compatibility)
						if ( empty( $subsite['WordPress']['Uploads'] ) ) {
							$subsite['WordPress']['Uploads'] = null;
						}

						// Set uploads URL path (backward compatibility)
						if ( empty( $subsite['WordPress']['UploadsURL'] ) ) {
							$subsite['WordPress']['UploadsURL'] = null;
						}

						// Set blog items
						$blogs[] = array(
							'Old' => array(
								'BlogID'          => $subsite['BlogID'],
								'SiteURL'         => $subsite['SiteURL'],
								'HomeURL'         => $subsite['HomeURL'],
								'InternalSiteURL' => $subsite['InternalSiteURL'],
								'InternalHomeURL' => $subsite['InternalHomeURL'],
								'Plugins'         => $subsite['Plugins'],
								'Template'        => $subsite['Template'],
								'Stylesheet'      => $subsite['Stylesheet'],
								'Uploads'         => $subsite['Uploads'],
								'UploadsURL'      => $subsite['UploadsURL'],
								'WordPress'       => $subsite['WordPress'],
							),
							'New' => array(
								'BlogID'          => null,
								'SiteURL'         => site_url(),
								'HomeURL'         => home_url(),
								'InternalSiteURL' => site_url(),
								'InternalHomeURL' => home_url(),
								'Plugins'         => $subsite['Plugins'],
								'Template'        => $subsite['Template'],
								'Stylesheet'      => $subsite['Stylesheet'],
								'Uploads'         => get_option( 'upload_path' ),
								'UploadsURL'      => get_option( 'upload_url_path' ),
								'WordPress'       => array(
									'UploadsURL' => leadwerk_migration_get_uploads_url(),
								),
							),
						);
					} else {
						throw new Leadwerk_Migration_Import_Exception( esc_html__( 'The archive must contain only a single WordPress site. The process cannot continue. Please revisit your export settings.', 'leadwerk-migration' ) );
					}
				} else {
					throw new Leadwerk_Migration_Import_Exception( esc_html__( 'The archive must contain at least one WordPress site. The process cannot continue. Please check your export settings.', 'leadwerk-migration' ) );
				}
			} else {
				throw new Leadwerk_Migration_Import_Exception( esc_html__( 'Could not import a WordPress Network into a single WordPress site. The process cannot continue. Please check your import settings.', 'leadwerk-migration' ) );
			}
		}

		// Write blogs.json file
		$handle = leadwerk_migration_open( leadwerk_migration_blogs_path( $params ), 'w' );
		leadwerk_migration_write( $handle, json_encode( $blogs ) );
		leadwerk_migration_close( $handle );

		// Set progress
		Leadwerk_Migration_Status::info( __( 'Blogs prepared.', 'leadwerk-migration' ) );

		return $params;
	}
}
