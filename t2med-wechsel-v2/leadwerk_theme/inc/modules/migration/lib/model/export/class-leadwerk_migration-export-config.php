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

class Leadwerk_Migration_Export_Config {

	public static function execute( $params ) {
		global $table_prefix, $wp_version;

		// Backups preserve an existing explicit identity but never create one as a
		// side effect. Family creation belongs only to the Site Sync admin button.
		$sync_identity = Leadwerk_Migration_Site_Identity::current_family();

		// Set progress
		Leadwerk_Migration_Status::info( __( 'Preparing configuration...', 'leadwerk-migration' ) );

		// Get options
		$options = wp_load_alloptions();

		// Get database client
		$db_client = Leadwerk_Migration_Database_Utility::get_client();

		$config = array();

		// Set site URL
		$config['SiteURL'] = site_url();

		// Set home URL
		$config['HomeURL'] = home_url();

		// Set internal site URL
		if ( isset( $options['siteurl'] ) ) {
			$config['InternalSiteURL'] = $options['siteurl'];
		}

		// Set internal home URL
		if ( isset( $options['home'] ) ) {
			$config['InternalHomeURL'] = $options['home'];
		}

		// Set replace old and new values
		if ( isset( $params['options']['replace'] ) && ( $replace = $params['options']['replace'] ) ) {
			for ( $i = 0; $i < count( $replace['old_value'] ); $i++ ) {
				if ( ! empty( $replace['old_value'][ $i ] ) && ! empty( $replace['new_value'][ $i ] ) ) {
					$config['Replace']['OldValues'][] = $replace['old_value'][ $i ];
					$config['Replace']['NewValues'][] = $replace['new_value'][ $i ];
				}
			}
		}

		// Set no spam comments
		if ( isset( $params['options']['no_spam_comments'] ) ) {
			$config['NoSpamComments'] = true;
		}

		// Set no post revisions
		if ( isset( $params['options']['no_post_revisions'] ) ) {
			$config['NoPostRevisions'] = true;
		}

		// Set no media
		if ( isset( $params['options']['no_media'] ) ) {
			$config['NoMedia'] = true;
		}

		// Set no themes
		if ( isset( $params['options']['no_themes'] ) ) {
			$config['NoThemes'] = true;
		}

		// Set no inactive themes
		if ( isset( $params['options']['no_inactive_themes'] ) ) {
			$config['NoInactiveThemes'] = true;
		}

		// Set no must-use plugins
		if ( isset( $params['options']['no_muplugins'] ) ) {
			$config['NoMustUsePlugins'] = true;
		}

		// Set no plugins
		if ( isset( $params['options']['no_plugins'] ) ) {
			$config['NoPlugins'] = true;
		}

		// Set no inactive plugins
		if ( isset( $params['options']['no_inactive_plugins'] ) ) {
			$config['NoInactivePlugins'] = true;
		}

		// Set no cache
		if ( isset( $params['options']['no_cache'] ) ) {
			$config['NoCache'] = true;
		}

		// Set no database
		if ( isset( $params['options']['no_database'] ) ) {
			$config['NoDatabase'] = true;
		}

		// Set no email replace
		if ( isset( $params['options']['no_email_replace'] ) ) {
			$config['NoEmailReplace'] = true;
		}

		// Set plugin version
		$config['Plugin'] = array( 'Version' => LEADWERK_MIGRATION_VERSION );

		// Set non-secret project/environment metadata for pre-destructive checks.
		$config['LeadwerkSync'] = Leadwerk_Migration_Site_Identity::archive_metadata( $sync_identity );

		// Set WordPress version and content
		$config['WordPress'] = array( 'Version' => $wp_version, 'Absolute' => ABSPATH, 'Content' => WP_CONTENT_DIR, 'Plugins' => leadwerk_migration_get_plugins_dir(), 'Themes' => leadwerk_migration_get_themes_dirs(), 'Uploads' => leadwerk_migration_get_uploads_dir(), 'UploadsURL' => leadwerk_migration_get_uploads_url() );

		// Set database version
		$config['Database'] = array(
			'Version' => $db_client->server_info(),
			'Charset' => defined( 'DB_CHARSET' ) ? DB_CHARSET : 'undefined',
			'Collate' => defined( 'DB_COLLATE' ) ? DB_COLLATE : 'undefined',
			'Prefix'  => $table_prefix,
		);

		// Exclude selected db tables
		if ( isset( $params['options']['exclude_db_tables'], $params['excluded_db_tables'] ) ) {
			if ( ( $excluded_db_tables = explode( ',', $params['excluded_db_tables'] ) ) ) {
				$config['Database']['ExcludedTables'] = $excluded_db_tables;
			}
		}

		// Include selected db tables
		if ( isset( $params['options']['include_db_tables'], $params['included_db_tables'] ) ) {
			if ( ( $included_db_tables = explode( ',', $params['included_db_tables'] ) ) ) {
				$config['Database']['IncludedTables'] = $included_db_tables;
			}
		}

		// Set PHP version
		$config['PHP'] = array( 'Version' => PHP_VERSION, 'System' => PHP_OS, 'Integer' => PHP_INT_SIZE );

		// Set active plugins
		$config['Plugins'] = array_values( array_diff( leadwerk_migration_active_plugins(), leadwerk_migration_active_servmask_plugins() ) );

		// Set active template
		$config['Template'] = leadwerk_migration_active_template();

		// Set active stylesheet
		$config['Stylesheet'] = leadwerk_migration_active_stylesheet();

		// Set upload path
		$config['Uploads'] = get_option( 'upload_path' );

		// Set upload URL path
		$config['UploadsURL'] = get_option( 'upload_url_path' );

		// Set server info
		$config['Server'] = array( '.htaccess' => base64_encode( leadwerk_migration_get_htaccess() ), 'web.config' => base64_encode( leadwerk_migration_get_webconfig() ) );

		// Set encrypt backups only if password is provided
		if ( ! empty( $params['options']['encrypt_backups'] ) && ! empty( $params['options']['encrypt_password'] ) ) {
			if ( empty( $params['encryption_archive_id'] ) || ! is_string( $params['encryption_archive_id'] ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $params['encryption_archive_id'] ) ) {
				$params['encryption_archive_id'] = Leadwerk_Migration_Security::job_id();
			}

			$config['Encrypted']           = true;
			$config['EncryptionFormat']    = LEADWERK_MIGRATION_ENCRYPTION_FORMAT;
			$config['EncryptionArchiveId'] = $params['encryption_archive_id'];

			$archive_id                   = $params['encryption_archive_id'];
			$config['EncryptedSignature'] = base64_encode(
				leadwerk_migration_encrypt_string(
					LEADWERK_MIGRATION_SIGN_TEXT . "\0" . $archive_id,
					$params['options']['encrypt_password'],
					'signature|' . $archive_id
				)
			);
		}

		// Set compression type
		if ( ! empty( $params['options']['compression_type'] ) ) {
			$config['Compression'] = array( 'Enabled' => true, 'Type' => $params['options']['compression_type'] );
		}

		// Save package.json file
		$handle = leadwerk_migration_open( leadwerk_migration_package_path( $params ), 'w' );
		leadwerk_migration_write( $handle, json_encode( $config ) );
		leadwerk_migration_close( $handle );

		// Set progress
		Leadwerk_Migration_Status::info( __( 'Configuration prepared.', 'leadwerk-migration' ) );

		return $params;
	}
}
