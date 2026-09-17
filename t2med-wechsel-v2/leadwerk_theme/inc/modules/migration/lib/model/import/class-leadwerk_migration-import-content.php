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

class Leadwerk_Migration_Import_Content {

	public static function execute( $params ) {

		// Set decryption password
		$decryption_password = null;
		if ( isset( $params['decryption_password'] ) ) {
			$decryption_password = $params['decryption_password'];
		}

		// Set archive bytes offset
		if ( isset( $params['archive_bytes_offset'] ) ) {
			$archive_bytes_offset = (int) $params['archive_bytes_offset'];
		} else {
			$archive_bytes_offset = 0;
		}

		// Set file bytes offset
		if ( isset( $params['file_bytes_offset'] ) ) {
			$file_bytes_offset = (int) $params['file_bytes_offset'];
		} else {
			$file_bytes_offset = 0;
		}

		// Set file bytes written
		if ( isset( $params['file_bytes_written'] ) ) {
			$file_bytes_written = (int) $params['file_bytes_written'];
		} else {
			$file_bytes_written = 0;
		}

		// Get processed files size
		if ( isset( $params['processed_files_size'] ) ) {
			$processed_files_size = (int) $params['processed_files_size'];
		} else {
			$processed_files_size = 0;
		}

		// Get total files size
		if ( isset( $params['total_files_size'] ) ) {
			$total_files_size = (int) $params['total_files_size'];
		} else {
			$total_files_size = 1;
		}

		// Get total files count
		if ( isset( $params['total_files_count'] ) ) {
			$total_files_count = (int) $params['total_files_count'];
		} else {
			$total_files_count = 1;
		}

		// Read blogs.json file
		$handle = leadwerk_migration_open( leadwerk_migration_blogs_path( $params ), 'r' );

		// Parse blogs.json file
		$blogs = leadwerk_migration_read( $handle, filesize( leadwerk_migration_blogs_path( $params ) ) );
		$blogs = json_decode( $blogs, true );

		// Close handle
		leadwerk_migration_close( $handle );

		// Read package.json file
		$handle = leadwerk_migration_open( leadwerk_migration_package_path( $params ), 'r' );

		// Parse package.json file
		$config = leadwerk_migration_read( $handle, filesize( leadwerk_migration_package_path( $params ) ) );
		$config = json_decode( $config, true );

		// Close handle
		leadwerk_migration_close( $handle );

		// What percent of files have we processed?
		$progress = (int) min( ( $processed_files_size / $total_files_size ) * 100, 100 );

		// Set progress
		/* translators: 1: Number of files, 2: Progress. */
		Leadwerk_Migration_Status::info( sprintf( __( 'Restoring %1$d files...<br />%2$d%% complete', 'leadwerk-migration' ), $total_files_count, $progress ) );

		// Flag to hold if file data has been processed
		$completed = true;

		// Start time
		$start = microtime( true );

		// Get compression type
		$compression_type = null;
		if ( ! empty( $config['Compression']['Enabled'] ) ) {
			$compression_type = $config['Compression']['Type'];
		}
		$encryption_format = isset( $config['EncryptionFormat'] ) && is_string( $config['EncryptionFormat'] ) ? $config['EncryptionFormat'] : null;
		$encryption_archive_id = isset( $config['EncryptionArchiveId'] ) && is_string( $config['EncryptionArchiveId'] ) ? $config['EncryptionArchiveId'] : null;

		// Open the archive file for reading
		$archive = new Leadwerk_Migration_Extractor( leadwerk_migration_archive_path( $params ), $decryption_password, $compression_type, $encryption_format, $encryption_archive_id );

		// Set the file pointer to the one that we have saved
		$archive->set_file_pointer( $archive_bytes_offset );

		$old_paths = array( 'plugins', 'themes' );
		$new_paths = array( leadwerk_migration_get_plugins_dir(), get_theme_root() );

		// Set extract paths
		foreach ( $blogs as $blog ) {
			if ( leadwerk_migration_is_mainsite( $blog['Old']['BlogID'] ) === false ) {
				if ( defined( 'UPLOADBLOGSDIR' ) ) {
					// Old files dir style
					$old_paths[] = leadwerk_migration_blog_files_relpath( $blog['Old']['BlogID'] );
					$new_paths[] = leadwerk_migration_blog_files_abspath( $blog['New']['BlogID'] );

					// Old blogs.dir style
					$old_paths[] = leadwerk_migration_blog_blogsdir_relpath( $blog['Old']['BlogID'] );
					$new_paths[] = leadwerk_migration_blog_blogsdir_abspath( $blog['New']['BlogID'] );

					// New sites dir style
					$old_paths[] = leadwerk_migration_blog_sites_relpath( $blog['Old']['BlogID'] );
					$new_paths[] = leadwerk_migration_blog_files_abspath( $blog['New']['BlogID'] );
				} else {
					// Old files dir style
					$old_paths[] = leadwerk_migration_blog_files_relpath( $blog['Old']['BlogID'] );
					$new_paths[] = leadwerk_migration_blog_sites_abspath( $blog['New']['BlogID'] );

					// Old blogs.dir style
					$old_paths[] = leadwerk_migration_blog_blogsdir_relpath( $blog['Old']['BlogID'] );
					$new_paths[] = leadwerk_migration_blog_sites_abspath( $blog['New']['BlogID'] );

					// New sites dir style
					$old_paths[] = leadwerk_migration_blog_sites_relpath( $blog['Old']['BlogID'] );
					$new_paths[] = leadwerk_migration_blog_sites_abspath( $blog['New']['BlogID'] );
				}
			}
		}

		// Set base site extract paths (should be added at the end of arrays)
		foreach ( $blogs as $blog ) {
			if ( leadwerk_migration_is_mainsite( $blog['Old']['BlogID'] ) === true ) {
				if ( defined( 'UPLOADBLOGSDIR' ) ) {
					// Old files dir style
					$old_paths[] = leadwerk_migration_blog_files_relpath( $blog['Old']['BlogID'] );
					$new_paths[] = leadwerk_migration_blog_files_abspath( $blog['New']['BlogID'] );

					// Old blogs.dir style
					$old_paths[] = leadwerk_migration_blog_blogsdir_relpath( $blog['Old']['BlogID'] );
					$new_paths[] = leadwerk_migration_blog_blogsdir_abspath( $blog['New']['BlogID'] );

					// New sites dir style
					$old_paths[] = leadwerk_migration_blog_sites_relpath( $blog['Old']['BlogID'] );
					$new_paths[] = leadwerk_migration_blog_files_abspath( $blog['New']['BlogID'] );
				} else {
					// Old files dir style
					$old_paths[] = leadwerk_migration_blog_files_relpath( $blog['Old']['BlogID'] );
					$new_paths[] = leadwerk_migration_blog_sites_abspath( $blog['New']['BlogID'] );

					// Old blogs.dir style
					$old_paths[] = leadwerk_migration_blog_blogsdir_relpath( $blog['Old']['BlogID'] );
					$new_paths[] = leadwerk_migration_blog_sites_abspath( $blog['New']['BlogID'] );

					// New sites dir style
					$old_paths[] = leadwerk_migration_blog_sites_relpath( $blog['Old']['BlogID'] );
					$new_paths[] = leadwerk_migration_blog_sites_abspath( $blog['New']['BlogID'] );
				}
			}
		}

		$old_paths[] = leadwerk_migration_blog_sites_relpath();
		$new_paths[] = leadwerk_migration_blog_sites_abspath();

		while ( $archive->has_not_reached_eof() ) {
			$file_bytes_read = 0;

			// Exclude WordPress files
			$exclude_files = array_keys( _get_dropins() );

			// Exclude plugin files
			$exclude_files = array_merge(
				$exclude_files,
				array(
					LEADWERK_MIGRATION_PACKAGE_NAME,
					LEADWERK_MIGRATION_MULTISITE_NAME,
					LEADWERK_MIGRATION_DATABASE_NAME,
					Leadwerk_Migration_Mail_Smtp::SNAPSHOT_NAME,
					LEADWERK_MIGRATION_MUPLUGINS_NAME,
				)
			);

			// Exclude theme files
			$exclude_files = array_merge( $exclude_files, array( LEADWERK_MIGRATION_THEMES_FUNCTIONS_NAME ) );

			// Exclude Elementor files
			$exclude_files = array_merge( $exclude_files, array( LEADWERK_MIGRATION_ELEMENTOR_CSS_NAME ) );

			// Exclude CiviCRM environment config, caches and temporary files while
			// retaining real attachments, contact images and extensions.
			$exclude_files = array_merge(
				$exclude_files,
				array(
					LEADWERK_MIGRATION_CIVICRM_SETTINGS_NAME,
					LEADWERK_MIGRATION_CIVICRM_TEMPLATES_C_NAME,
					LEADWERK_MIGRATION_CIVICRM_CONFIG_AND_LOG_NAME,
					LEADWERK_MIGRATION_CIVICRM_UPLOAD_NAME,
					LEADWERK_MIGRATION_CIVICRM_DYNAMIC_NAME,
				)
			);

			// Exclude content extensions
			$exclude_extensions = array( LEADWERK_MIGRATION_LESS_CACHE_EXTENSION, LEADWERK_MIGRATION_SQLITE_DATABASE_EXTENSION );

			// Extract a file from archive to WP_CONTENT_DIR
			if ( ( $completed = $archive->extract_one_file_to( WP_CONTENT_DIR, $exclude_files, $exclude_extensions, $old_paths, $new_paths, $file_bytes_read, $file_bytes_offset, $file_bytes_written ) ) ) {
				$file_bytes_offset = $file_bytes_written = 0;
			}

			// Get archive bytes offset
			$archive_bytes_offset = $archive->get_file_pointer();

			// Increment processed files size
			$processed_files_size += $file_bytes_read;

			// What percent of files have we processed?
			$progress = (int) min( ( $processed_files_size / $total_files_size ) * 100, 100 );

			// Set progress
			/* translators: 1: Number of files, 2: Progress. */
			Leadwerk_Migration_Status::info( sprintf( __( 'Restoring %1$d files...<br />%2$d%% complete', 'leadwerk-migration' ), $total_files_count, $progress ) );

			// More than 10 seconds have passed, break and do another request
			if ( ( $timeout = apply_filters( 'leadwerk_migration_completed_timeout', 10 ) ) ) {
				if ( ( microtime( true ) - $start ) > $timeout ) {
					$completed = false;
					break;
				}
			}
		}

		// End of the archive?
		if ( $archive->has_reached_eof() ) {

			// Unset archive bytes offset
			unset( $params['archive_bytes_offset'] );

			// Unset file bytes offset
			unset( $params['file_bytes_offset'] );

			// Unset file bytes written
			unset( $params['file_bytes_written'] );

			// Unset processed files size
			unset( $params['processed_files_size'] );

			// Unset total files size
			unset( $params['total_files_size'] );

			// Unset total files count
			unset( $params['total_files_count'] );

			// Unset completed flag
			unset( $params['completed'] );

		} else {

			// Set archive bytes offset
			$params['archive_bytes_offset'] = $archive_bytes_offset;

			// Set file bytes offset
			$params['file_bytes_offset'] = $file_bytes_offset;

			// Set file bytes written
			$params['file_bytes_written'] = $file_bytes_written;

			// Set processed files size
			$params['processed_files_size'] = $processed_files_size;

			// Set total files size
			$params['total_files_size'] = $total_files_size;

			// Set total files count
			$params['total_files_count'] = $total_files_count;

			// Set completed flag
			$params['completed'] = $completed;
		}

		// Close the archive file
		$archive->close();

		return $params;
	}
}
