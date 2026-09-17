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

class Leadwerk_Migration_Import_Done {

	public static function execute( $params ) {
		global $wp_rewrite;

		// Check multisite.json file
		if ( is_file( leadwerk_migration_multisite_path( $params ) ) ) {

			// Read multisite.json file
			$handle = leadwerk_migration_open( leadwerk_migration_multisite_path( $params ), 'r' );

			// Parse multisite.json file
			$multisite = leadwerk_migration_read( $handle, filesize( leadwerk_migration_multisite_path( $params ) ) );
			$multisite = json_decode( $multisite, true );

			// Close handle
			leadwerk_migration_close( $handle );

			// Activate WordPress plugins
			if ( isset( $multisite['Plugins'] ) && ( $plugins = $multisite['Plugins'] ) ) {
				leadwerk_migration_activate_plugins( $plugins );
			}

			// Deactivate WordPress SSL plugins
			if ( ! is_ssl() ) {
				leadwerk_migration_deactivate_plugins(
					array(
						leadwerk_migration_discover_plugin_basename( 'really-simple-ssl/rlrsssl-really-simple-ssl.php' ),
						leadwerk_migration_discover_plugin_basename( 'wordpress-https/wordpress-https.php' ),
						leadwerk_migration_discover_plugin_basename( 'wp-force-ssl/wp-force-ssl.php' ),
						leadwerk_migration_discover_plugin_basename( 'force-https-littlebizzy/force-https.php' ),
					)
				);

				leadwerk_migration_woocommerce_force_ssl( false );
			}

			// Deactivate WordPress plugins
			leadwerk_migration_deactivate_plugins(
				array(
					leadwerk_migration_discover_plugin_basename( 'invisible-recaptcha/invisible-recaptcha.php' ),
					leadwerk_migration_discover_plugin_basename( 'wps-hide-login/wps-hide-login.php' ),
					leadwerk_migration_discover_plugin_basename( 'hide-my-wp/index.php' ),
					leadwerk_migration_discover_plugin_basename( 'hide-my-wordpress/index.php' ),
					leadwerk_migration_discover_plugin_basename( 'mycustomwidget/my_custom_widget.php' ),
					leadwerk_migration_discover_plugin_basename( 'lockdown-wp-admin/lockdown-wp-admin.php' ),
					leadwerk_migration_discover_plugin_basename( 'rename-wp-login/rename-wp-login.php' ),
					leadwerk_migration_discover_plugin_basename( 'wp-simple-firewall/icwp-wpsf.php' ),
					leadwerk_migration_discover_plugin_basename( 'join-my-multisite/joinmymultisite.php' ),
					leadwerk_migration_discover_plugin_basename( 'multisite-clone-duplicator/multisite-clone-duplicator.php' ),
					leadwerk_migration_discover_plugin_basename( 'wordpress-mu-domain-mapping/domain_mapping.php' ),
					leadwerk_migration_discover_plugin_basename( 'wordpress-starter/siteground-wizard.php' ),
					leadwerk_migration_discover_plugin_basename( 'pro-sites/pro-sites.php' ),
					leadwerk_migration_discover_plugin_basename( 'wpide/WPide.php' ),
					leadwerk_migration_discover_plugin_basename( 'page-optimize/page-optimize.php' ),
					leadwerk_migration_discover_plugin_basename( 'update-services/update-services.php' ),
				)
			);

			// Deactivate Swift Optimizer rules
			leadwerk_migration_deactivate_swift_optimizer_rules(
				array(
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration/leadwerk-migration.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-azure-storage-extension/leadwerk-migration-azure-storage-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-b2-extension/leadwerk-migration-b2-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-backup/leadwerk-migration-backup.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-box-extension/leadwerk-migration-box-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-digitalocean-extension/leadwerk-migration-digitalocean-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-direct-extension/leadwerk-migration-direct-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-dropbox-extension/leadwerk-migration-dropbox-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-file-extension/leadwerk-migration-file-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-ftp-extension/leadwerk-migration-ftp-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-gcloud-storage-extension/leadwerk-migration-gcloud-storage-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-gdrive-extension/leadwerk-migration-gdrive-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-glacier-extension/leadwerk-migration-glacier-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-mega-extension/leadwerk-migration-mega-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-multisite-extension/leadwerk-migration-multisite-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-onedrive-extension/leadwerk-migration-onedrive-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-pcloud-extension/leadwerk-migration-pcloud-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-pro/leadwerk-migration-pro.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-s3-client-extension/leadwerk-migration-s3-client-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-s3-extension/leadwerk-migration-s3-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-unlimited-extension/leadwerk-migration-unlimited-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-url-extension/leadwerk-migration-url-extension.php' ),
					leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-webdav-extension/leadwerk-migration-webdav-extension.php' ),
				)
			);

			// Deactivate Revolution Slider
			leadwerk_migration_deactivate_revolution_slider( leadwerk_migration_discover_plugin_basename( 'revslider/revslider.php' ) );

			// Deactivate Jetpack modules
			leadwerk_migration_deactivate_jetpack_modules( array( 'photon', 'sso' ) );

			// Flush Elementor cache
			leadwerk_migration_elementor_cache_flush();

			// Initial DB version
			leadwerk_migration_initial_db_version();

		} else {

			// Check package.json file
			if ( is_file( leadwerk_migration_package_path( $params ) ) ) {

				// Read package.json file
				$handle = leadwerk_migration_open( leadwerk_migration_package_path( $params ), 'r' );

				// Parse package.json file
				$package = leadwerk_migration_read( $handle, filesize( leadwerk_migration_package_path( $params ) ) );
				$package = json_decode( $package, true );

				// Close handle
				leadwerk_migration_close( $handle );

				// Activate WordPress plugins
				if ( isset( $package['Plugins'] ) && ( $plugins = $package['Plugins'] ) ) {
					leadwerk_migration_activate_plugins( $plugins );
				}

				// Activate WordPress template
				if ( isset( $package['Template'] ) && ( $template = $package['Template'] ) ) {
					leadwerk_migration_activate_template( $template );
				}

				// Activate WordPress stylesheet
				if ( isset( $package['Stylesheet'] ) && ( $stylesheet = $package['Stylesheet'] ) ) {
					leadwerk_migration_activate_stylesheet( $stylesheet );
				}

				// Deactivate WordPress SSL plugins
				if ( ! is_ssl() ) {
					leadwerk_migration_deactivate_plugins(
						array(
							leadwerk_migration_discover_plugin_basename( 'really-simple-ssl/rlrsssl-really-simple-ssl.php' ),
							leadwerk_migration_discover_plugin_basename( 'wordpress-https/wordpress-https.php' ),
							leadwerk_migration_discover_plugin_basename( 'wp-force-ssl/wp-force-ssl.php' ),
							leadwerk_migration_discover_plugin_basename( 'force-https-littlebizzy/force-https.php' ),
						)
					);

					leadwerk_migration_woocommerce_force_ssl( false );
				}

				// Deactivate WordPress plugins
				leadwerk_migration_deactivate_plugins(
					array(
						leadwerk_migration_discover_plugin_basename( 'invisible-recaptcha/invisible-recaptcha.php' ),
						leadwerk_migration_discover_plugin_basename( 'wps-hide-login/wps-hide-login.php' ),
						leadwerk_migration_discover_plugin_basename( 'hide-my-wp/index.php' ),
						leadwerk_migration_discover_plugin_basename( 'hide-my-wordpress/index.php' ),
						leadwerk_migration_discover_plugin_basename( 'mycustomwidget/my_custom_widget.php' ),
						leadwerk_migration_discover_plugin_basename( 'lockdown-wp-admin/lockdown-wp-admin.php' ),
						leadwerk_migration_discover_plugin_basename( 'rename-wp-login/rename-wp-login.php' ),
						leadwerk_migration_discover_plugin_basename( 'wp-simple-firewall/icwp-wpsf.php' ),
						leadwerk_migration_discover_plugin_basename( 'join-my-multisite/joinmymultisite.php' ),
						leadwerk_migration_discover_plugin_basename( 'multisite-clone-duplicator/multisite-clone-duplicator.php' ),
						leadwerk_migration_discover_plugin_basename( 'wordpress-mu-domain-mapping/domain_mapping.php' ),
						leadwerk_migration_discover_plugin_basename( 'wordpress-starter/siteground-wizard.php' ),
						leadwerk_migration_discover_plugin_basename( 'pro-sites/pro-sites.php' ),
						leadwerk_migration_discover_plugin_basename( 'wpide/WPide.php' ),
						leadwerk_migration_discover_plugin_basename( 'page-optimize/page-optimize.php' ),
						leadwerk_migration_discover_plugin_basename( 'update-services/update-services.php' ),
					)
				);

				// Deactivate Swift Optimizer rules
				leadwerk_migration_deactivate_swift_optimizer_rules(
					array(
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration/leadwerk-migration.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-azure-storage-extension/leadwerk-migration-azure-storage-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-b2-extension/leadwerk-migration-b2-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-backup/leadwerk-migration-backup.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-box-extension/leadwerk-migration-box-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-digitalocean-extension/leadwerk-migration-digitalocean-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-direct-extension/leadwerk-migration-direct-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-dropbox-extension/leadwerk-migration-dropbox-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-file-extension/leadwerk-migration-file-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-ftp-extension/leadwerk-migration-ftp-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-gcloud-storage-extension/leadwerk-migration-gcloud-storage-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-gdrive-extension/leadwerk-migration-gdrive-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-glacier-extension/leadwerk-migration-glacier-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-mega-extension/leadwerk-migration-mega-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-multisite-extension/leadwerk-migration-multisite-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-onedrive-extension/leadwerk-migration-onedrive-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-pcloud-extension/leadwerk-migration-pcloud-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-pro/leadwerk-migration-pro.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-s3-client-extension/leadwerk-migration-s3-client-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-s3-extension/leadwerk-migration-s3-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-unlimited-extension/leadwerk-migration-unlimited-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-url-extension/leadwerk-migration-url-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-webdav-extension/leadwerk-migration-webdav-extension.php' ),
					)
				);

				// Deactivate Revolution Slider
				leadwerk_migration_deactivate_revolution_slider( leadwerk_migration_discover_plugin_basename( 'revslider/revslider.php' ) );

				// Deactivate Jetpack modules
				leadwerk_migration_deactivate_jetpack_modules( array( 'photon', 'sso' ) );

				// Flush Elementor cache
				leadwerk_migration_elementor_cache_flush();

				// Initial DB version
				leadwerk_migration_initial_db_version();
			}
		}

		// Check blogs.json file
		if ( is_file( leadwerk_migration_blogs_path( $params ) ) ) {

			// Read blogs.json file
			$handle = leadwerk_migration_open( leadwerk_migration_blogs_path( $params ), 'r' );

			// Parse blogs.json file
			$blogs = leadwerk_migration_read( $handle, filesize( leadwerk_migration_blogs_path( $params ) ) );
			$blogs = json_decode( $blogs, true );

			// Close handle
			leadwerk_migration_close( $handle );

			// Loop over blogs
			foreach ( $blogs as $blog ) {

				// Activate WordPress plugins
				if ( isset( $blog['New']['Plugins'] ) && ( $plugins = $blog['New']['Plugins'] ) ) {
					leadwerk_migration_activate_plugins( $plugins );
				}

				// Activate WordPress template
				if ( isset( $blog['New']['Template'] ) && ( $template = $blog['New']['Template'] ) ) {
					leadwerk_migration_activate_template( $template );
				}

				// Activate WordPress stylesheet
				if ( isset( $blog['New']['Stylesheet'] ) && ( $stylesheet = $blog['New']['Stylesheet'] ) ) {
					leadwerk_migration_activate_stylesheet( $stylesheet );
				}

				// Deactivate WordPress SSL plugins
				if ( ! is_ssl() ) {
					leadwerk_migration_deactivate_plugins(
						array(
							leadwerk_migration_discover_plugin_basename( 'really-simple-ssl/rlrsssl-really-simple-ssl.php' ),
							leadwerk_migration_discover_plugin_basename( 'wordpress-https/wordpress-https.php' ),
							leadwerk_migration_discover_plugin_basename( 'wp-force-ssl/wp-force-ssl.php' ),
							leadwerk_migration_discover_plugin_basename( 'force-https-littlebizzy/force-https.php' ),
						)
					);

					leadwerk_migration_woocommerce_force_ssl( false );
				}

				// Deactivate WordPress plugins
				leadwerk_migration_deactivate_plugins(
					array(
						leadwerk_migration_discover_plugin_basename( 'invisible-recaptcha/invisible-recaptcha.php' ),
						leadwerk_migration_discover_plugin_basename( 'wps-hide-login/wps-hide-login.php' ),
						leadwerk_migration_discover_plugin_basename( 'hide-my-wp/index.php' ),
						leadwerk_migration_discover_plugin_basename( 'hide-my-wordpress/index.php' ),
						leadwerk_migration_discover_plugin_basename( 'mycustomwidget/my_custom_widget.php' ),
						leadwerk_migration_discover_plugin_basename( 'lockdown-wp-admin/lockdown-wp-admin.php' ),
						leadwerk_migration_discover_plugin_basename( 'rename-wp-login/rename-wp-login.php' ),
						leadwerk_migration_discover_plugin_basename( 'wp-simple-firewall/icwp-wpsf.php' ),
						leadwerk_migration_discover_plugin_basename( 'join-my-multisite/joinmymultisite.php' ),
						leadwerk_migration_discover_plugin_basename( 'multisite-clone-duplicator/multisite-clone-duplicator.php' ),
						leadwerk_migration_discover_plugin_basename( 'wordpress-mu-domain-mapping/domain_mapping.php' ),
						leadwerk_migration_discover_plugin_basename( 'wordpress-starter/siteground-wizard.php' ),
						leadwerk_migration_discover_plugin_basename( 'pro-sites/pro-sites.php' ),
						leadwerk_migration_discover_plugin_basename( 'wpide/WPide.php' ),
						leadwerk_migration_discover_plugin_basename( 'page-optimize/page-optimize.php' ),
						leadwerk_migration_discover_plugin_basename( 'update-services/update-services.php' ),
					)
				);

				// Deactivate Swift Optimizer rules
				leadwerk_migration_deactivate_swift_optimizer_rules(
					array(
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration/leadwerk-migration.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-azure-storage-extension/leadwerk-migration-azure-storage-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-b2-extension/leadwerk-migration-b2-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-backup/leadwerk-migration-backup.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-box-extension/leadwerk-migration-box-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-digitalocean-extension/leadwerk-migration-digitalocean-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-direct-extension/leadwerk-migration-direct-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-dropbox-extension/leadwerk-migration-dropbox-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-file-extension/leadwerk-migration-file-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-ftp-extension/leadwerk-migration-ftp-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-gcloud-storage-extension/leadwerk-migration-gcloud-storage-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-gdrive-extension/leadwerk-migration-gdrive-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-glacier-extension/leadwerk-migration-glacier-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-mega-extension/leadwerk-migration-mega-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-multisite-extension/leadwerk-migration-multisite-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-onedrive-extension/leadwerk-migration-onedrive-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-pcloud-extension/leadwerk-migration-pcloud-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-pro/leadwerk-migration-pro.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-s3-client-extension/leadwerk-migration-s3-client-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-s3-extension/leadwerk-migration-s3-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-unlimited-extension/leadwerk-migration-unlimited-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-url-extension/leadwerk-migration-url-extension.php' ),
						leadwerk_migration_discover_plugin_basename( 'leadwerk-migration-webdav-extension/leadwerk-migration-webdav-extension.php' ),
					)
				);

				// Deactivate Revolution Slider
				leadwerk_migration_deactivate_revolution_slider( leadwerk_migration_discover_plugin_basename( 'revslider/revslider.php' ) );

				// Deactivate Jetpack modules
				leadwerk_migration_deactivate_jetpack_modules( array( 'photon', 'sso' ) );

				// Flush Elementor cache
				leadwerk_migration_elementor_cache_flush();

				// Initial DB version
				leadwerk_migration_initial_db_version();
			}
		}

		// Clear auth cookie (WP Cerber)
		if ( leadwerk_migration_validate_plugin_basename( 'wp-cerber/wp-cerber.php' ) ) {
			wp_clear_auth_cookie();
		}

		$should_reset_permalinks = false;

		// Switch to default permalink structure
		if ( ( $should_reset_permalinks = leadwerk_migration_should_reset_permalinks( $params ) ) ) {
			$wp_rewrite->set_permalink_structure( '' );
		}

		// Set progress
		if ( leadwerk_migration_validate_plugin_basename( 'fusion-builder/fusion-builder.php' ) ) {
			Leadwerk_Migration_Status::done( __( 'Your site has been imported successfully!', 'leadwerk-migration' ), Leadwerk_Migration_Template::get_content( 'import/avada', array( 'should_reset_permalinks' => $should_reset_permalinks ) ) );
		} elseif ( leadwerk_migration_validate_plugin_basename( 'oxygen/functions.php' ) ) {
			Leadwerk_Migration_Status::done( __( 'Your site has been imported successfully!', 'leadwerk-migration' ), Leadwerk_Migration_Template::get_content( 'import/oxygen', array( 'should_reset_permalinks' => $should_reset_permalinks ) ) );
		} else {
			Leadwerk_Migration_Status::done( __( 'Your site has been imported successfully!', 'leadwerk-migration' ), Leadwerk_Migration_Template::get_content( 'import/done', array( 'should_reset_permalinks' => $should_reset_permalinks ) ) );
		}

		do_action( 'leadwerk_migration_status_import_done', $params );

		return $params;
	}
}
