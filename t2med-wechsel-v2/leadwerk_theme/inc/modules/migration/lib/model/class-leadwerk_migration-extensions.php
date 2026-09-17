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

class Leadwerk_Migration_Extensions {

	/**
	 * Get active extensions
	 *
	 * @return array
	 */
	public static function get() {
		$extensions = array();

		// Add Microsoft Azure Extension
		if ( defined( 'LEADWERK_MIGRATIONZE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONZE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONZE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONZE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONZE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONZE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONZE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONZE_VERSION,
				'requires' => '1.53',
				'short'    => LEADWERK_MIGRATIONZE_PLUGIN_SHORT,
			);
		}

		// Add Backblaze B2 Extension
		if ( defined( 'LEADWERK_MIGRATIONAE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONAE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONAE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONAE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONAE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONAE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONAE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONAE_VERSION,
				'requires' => '1.59',
				'short'    => LEADWERK_MIGRATIONAE_PLUGIN_SHORT,
			);
		}

		// Add Backup Plugin
		if ( defined( 'LEADWERK_MIGRATIONVE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONVE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONVE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONVE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONVE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONVE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONVE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONVE_VERSION,
				'requires' => '1.0',
				'short'    => LEADWERK_MIGRATIONVE_PLUGIN_SHORT,
			);
		}

		// Add Box Extension
		if ( defined( 'LEADWERK_MIGRATIONBE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONBE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONBE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONBE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONBE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONBE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONBE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONBE_VERSION,
				'requires' => '1.69',
				'short'    => LEADWERK_MIGRATIONBE_PLUGIN_SHORT,
			);
		}

		// Add DigitalOcean Spaces Extension
		if ( defined( 'LEADWERK_MIGRATIONIE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONIE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONIE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONIE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONIE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONIE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONIE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONIE_VERSION,
				'requires' => '1.69',
				'short'    => LEADWERK_MIGRATIONIE_PLUGIN_SHORT,
			);
		}

		// Add Direct Extension
		if ( defined( 'LEADWERK_MIGRATIONXE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONXE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONXE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONXE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONXE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONXE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONXE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONXE_VERSION,
				'requires' => '1.38',
				'short'    => LEADWERK_MIGRATIONXE_PLUGIN_SHORT,
			);
		}

		// Add Dropbox Extension
		if ( defined( 'LEADWERK_MIGRATIONDE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONDE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONDE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONDE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONDE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONDE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONDE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONDE_VERSION,
				'requires' => '3.94',
				'short'    => LEADWERK_MIGRATIONDE_PLUGIN_SHORT,
			);
		}

		// Add File Extension
		if ( defined( 'LEADWERK_MIGRATIONTE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONTE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONTE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONTE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONTE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONTE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONTE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONTE_VERSION,
				'requires' => '1.5',
				'short'    => LEADWERK_MIGRATIONTE_PLUGIN_SHORT,
			);
		}

		// Add FTP Extension
		if ( defined( 'LEADWERK_MIGRATIONFE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONFE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONFE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONFE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONFE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONFE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONFE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONFE_VERSION,
				'requires' => '2.93',
				'short'    => LEADWERK_MIGRATIONFE_PLUGIN_SHORT,
			);
		}

		// Add Google Cloud Storage Extension
		if ( defined( 'LEADWERK_MIGRATIONCE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONCE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONCE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONCE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONCE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONCE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONCE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONCE_VERSION,
				'requires' => '1.62',
				'short'    => LEADWERK_MIGRATIONCE_PLUGIN_SHORT,
			);
		}

		// Add Google Drive Extension
		if ( defined( 'LEADWERK_MIGRATIONGE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONGE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONGE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONGE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONGE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONGE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONGE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONGE_VERSION,
				'requires' => '2.100',
				'short'    => LEADWERK_MIGRATIONGE_PLUGIN_SHORT,
			);
		}

		// Add Amazon Glacier Extension
		if ( defined( 'LEADWERK_MIGRATIONRE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONRE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONRE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONRE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONRE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONRE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONRE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONRE_VERSION,
				'requires' => '1.55',
				'short'    => LEADWERK_MIGRATIONRE_PLUGIN_SHORT,
			);
		}

		// Add Mega Extension
		if ( defined( 'LEADWERK_MIGRATIONEE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONEE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONEE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONEE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONEE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONEE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONEE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONEE_VERSION,
				'requires' => '1.64',
				'short'    => LEADWERK_MIGRATIONEE_PLUGIN_SHORT,
			);
		}

		// Add Multisite Extension
		if ( defined( 'LEADWERK_MIGRATIONME_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONME_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONME_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONME_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONME_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONME_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONME_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONME_VERSION,
				'requires' => '4.62',
				'short'    => LEADWERK_MIGRATIONME_PLUGIN_SHORT,
			);
		}

		// Add OneDrive Extension
		if ( defined( 'LEADWERK_MIGRATIONOE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONOE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONOE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONOE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONOE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONOE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONOE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONOE_VERSION,
				'requires' => '1.84',
				'short'    => LEADWERK_MIGRATIONOE_PLUGIN_SHORT,
			);
		}

		// Add pCloud Extension
		if ( defined( 'LEADWERK_MIGRATIONPE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONPE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONPE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONPE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONPE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONPE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONPE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONPE_VERSION,
				'requires' => '1.56',
				'short'    => LEADWERK_MIGRATIONPE_PLUGIN_SHORT,
			);
		}

		// Add Pro Plugin
		if ( defined( 'LEADWERK_MIGRATIONKE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONKE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONKE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONKE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONKE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONKE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONKE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONKE_VERSION,
				'requires' => '1.37',
				'short'    => LEADWERK_MIGRATIONKE_PLUGIN_SHORT,
			);
		}

		// Add S3 Client Extension
		if ( defined( 'LEADWERK_MIGRATIONNE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONNE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONNE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONNE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONNE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONNE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONNE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONNE_VERSION,
				'requires' => '1.56',
				'short'    => LEADWERK_MIGRATIONNE_PLUGIN_SHORT,
			);
		}

		// Add Amazon S3 Extension
		if ( defined( 'LEADWERK_MIGRATIONSE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONSE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONSE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONSE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONSE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONSE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONSE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONSE_VERSION,
				'requires' => '3.95',
				'short'    => LEADWERK_MIGRATIONSE_PLUGIN_SHORT,
			);
		}

		// Add legacy extension when it exposes the compatibility constants.
		if ( defined( 'LEADWERK_MIGRATIONUE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONUE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONUE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONUE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONUE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONUE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONUE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONUE_VERSION,
				'requires' => '2.83',
				'short'    => LEADWERK_MIGRATIONUE_PLUGIN_SHORT,
			);
		}

		// Add URL Extension
		if ( defined( 'LEADWERK_MIGRATIONLE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONLE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONLE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONLE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONLE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONLE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONLE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONLE_VERSION,
				'requires' => '2.79',
				'short'    => LEADWERK_MIGRATIONLE_PLUGIN_SHORT,
			);
		}

		// Add WebDAV Extension
		if ( defined( 'LEADWERK_MIGRATIONWE_PLUGIN_NAME' ) ) {
			$extensions[ LEADWERK_MIGRATIONWE_PLUGIN_NAME ] = array(
				'key'      => LEADWERK_MIGRATIONWE_PURCHASE_ID,
				'title'    => LEADWERK_MIGRATIONWE_PLUGIN_TITLE,
				'about'    => LEADWERK_MIGRATIONWE_PLUGIN_ABOUT,
				'check'    => LEADWERK_MIGRATIONWE_PLUGIN_CHECK,
				'basename' => LEADWERK_MIGRATIONWE_PLUGIN_BASENAME,
				'version'  => LEADWERK_MIGRATIONWE_VERSION,
				'requires' => '1.50',
				'short'    => LEADWERK_MIGRATIONWE_PLUGIN_SHORT,
			);
		}

		return $extensions;
	}
}
