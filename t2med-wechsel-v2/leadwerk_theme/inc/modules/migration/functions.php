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

/**
 * Get storage absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_storage_path( $params ) {
	if ( class_exists( 'Leadwerk_Migration_Security' ) && ! Leadwerk_Migration_Security::path_is_private( LEADWERK_MIGRATION_STORAGE_PATH ) ) {
		throw new Leadwerk_Migration_Storage_Exception(
			esc_html__( 'The working directory is inside a web-served path. Configure LEADWERK_MIGRATION_STORAGE_PATH outside every document root.', 'leadwerk-migration' )
		);
	}

	if ( empty( $params['storage'] ) || ! is_string( $params['storage'] ) || ! preg_match( '/\A[A-Za-z0-9_-]{8,64}\z/D', $params['storage'] ) ) {
		throw new Leadwerk_Migration_Storage_Exception(
			wp_kses(
				__( 'Could not locate the storage path. The process cannot continue. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ),
				leadwerk_migration_allowed_html_tags()
			)
		);
	}

	// Validate storage path
	if ( leadwerk_migration_validate_file( $params['storage'] ) !== 0 || basename( $params['storage'] ) !== $params['storage'] ) {
		throw new Leadwerk_Migration_Storage_Exception(
			wp_kses(
				__( 'Your storage directory name contains invalid characters: < > : " | ? * \0. It must not include these characters. The process cannot continue. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ),
				leadwerk_migration_allowed_html_tags()
			)
		);
	}

	$root = LEADWERK_MIGRATION_STORAGE_PATH;
	if ( ! is_dir( $root ) ) {
		@mkdir( $root, 0700, true );
	}
	$root_real = realpath( $root );
	if ( $root_real === false || ! is_dir( $root_real ) || is_link( $root ) ) {
		throw new Leadwerk_Migration_Storage_Exception(
			esc_html__( 'The private working directory could not be created. Check its filesystem permissions.', 'leadwerk-migration' )
		);
	}

	@chmod( $root_real, 0700 );
	$storage = $root_real . DIRECTORY_SEPARATOR . $params['storage'];
	if ( is_link( $storage ) ) {
		throw new Leadwerk_Migration_Storage_Exception(
			esc_html__( 'The private job directory cannot be a symbolic link.', 'leadwerk-migration' )
		);
	}
	if ( ! is_dir( $storage ) ) {
		@mkdir( $storage, 0700, false );
	}

	$storage_real = realpath( $storage );
	if ( $storage_real === false || ! is_dir( $storage_real ) || is_link( $storage ) || realpath( dirname( $storage_real ) ) !== $root_real ) {
		throw new Leadwerk_Migration_Storage_Exception(
			esc_html__( 'The private job directory could not be resolved safely inside the working root.', 'leadwerk-migration' )
		);
	}

	@chmod( $storage_real, 0700 );

	return $storage_real;
}

/**
 * Resolve the backups path.
 * If the stored option points to a stale path (e.g., from a server migration)
 * where neither the path nor its parent directory exist, the option is deleted
 * and the default path is returned.
 *
 * @return string
 */
function leadwerk_migration_resolve_backups_path() {
	$backups_path = get_option( LEADWERK_MIGRATION_BACKUPS_PATH_OPTION, false );
	if ( ! is_string( $backups_path ) || trim( $backups_path ) === '' ) {
		if ( $backups_path !== false ) {
			delete_option( LEADWERK_MIGRATION_BACKUPS_PATH_OPTION );
		}
		return LEADWERK_MIGRATION_DEFAULT_BACKUPS_PATH;
	}

	$parent_backups_path = dirname( $backups_path );
	if ( ! is_dir( $parent_backups_path ) || ! is_writable( $parent_backups_path ) ) {
		delete_option( LEADWERK_MIGRATION_BACKUPS_PATH_OPTION );
		return LEADWERK_MIGRATION_DEFAULT_BACKUPS_PATH;
	}

	return $backups_path;
}

/**
 * Get backup absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_backup_path( $params ) {
	if ( class_exists( 'Leadwerk_Migration_Security' ) && ! Leadwerk_Migration_Security::path_is_private( LEADWERK_MIGRATION_BACKUPS_PATH ) ) {
		throw new Leadwerk_Migration_Archive_Exception(
			esc_html__( 'The backup directory is inside a web-served path. Configure LEADWERK_MIGRATION_DEFAULT_BACKUPS_PATH outside every document root.', 'leadwerk-migration' )
		);
	}

	if ( empty( $params['archive'] ) ) {
		throw new Leadwerk_Migration_Archive_Exception(
			wp_kses(
				__( 'Could not locate the archive path. The process cannot continue. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ),
				leadwerk_migration_allowed_html_tags()
			)
		);
	}

	// Validate archive path
	if ( leadwerk_migration_validate_file( $params['archive'] ) !== 0 ) {
		throw new Leadwerk_Migration_Archive_Exception(
			wp_kses(
				__( 'Your archive file name contains invalid characters: < > : " | ? * \0. It must not include these characters. The process cannot continue. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ),
				leadwerk_migration_allowed_html_tags()
			)
		);
	}

	// Validate file extension
	if ( ! leadwerk_migration_is_filename_supported( $params['archive'] ) ) {
		throw new Leadwerk_Migration_Archive_Exception(
			wp_kses(
				__( 'Invalid archive file type. Only .wpress files are allowed. The process cannot continue. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ),
				leadwerk_migration_allowed_html_tags()
			)
		);
	}

	if ( ! is_dir( LEADWERK_MIGRATION_BACKUPS_PATH ) ) {
		@mkdir( LEADWERK_MIGRATION_BACKUPS_PATH, 0700, true );
	}
	@chmod( LEADWERK_MIGRATION_BACKUPS_PATH, 0700 );

	if ( class_exists( 'Leadwerk_Migration_Integrity' ) ) {
		$path = Leadwerk_Migration_Integrity::resolve_backup_path( $params['archive'] );
		if ( is_wp_error( $path ) ) {
			throw new Leadwerk_Migration_Archive_Exception( $path->get_error_message() );
		}
		return $path;
	}

	return rtrim( LEADWERK_MIGRATION_BACKUPS_PATH, "/\\" ) . DIRECTORY_SEPARATOR . basename( $params['archive'] );
}

/**
 * Validates a file name and path against an allowed set of rules
 *
 * @param  string  $file          File path
 * @param  array   $allowed_files Array of allowed files
 * @return integer
 */
function leadwerk_migration_validate_file( $file, $allowed_files = array() ) {
	$file = str_replace( '\\', '/', $file );

	// Validates special characters that are illegal in filenames on certain
	// operating systems and special characters requiring special escaping
	// to manipulate at the command line
	$invalid_chars = array( '<', '>', ':', '"', '|', '?', '*', chr( 0 ) );
	foreach ( $invalid_chars as $char ) {
		if ( strpos( $file, $char ) !== false ) {
			return 1;
		}
	}

	return validate_file( $file, $allowed_files );
}

/**
 * Get archive absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_archive_path( $params ) {
	if ( empty( $params['archive'] ) ) {
		throw new Leadwerk_Migration_Archive_Exception(
			wp_kses(
				__( 'Could not locate the archive path. The process cannot continue. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ),
				leadwerk_migration_allowed_html_tags()
			)
		);
	}

	// Validate archive path
	if ( leadwerk_migration_validate_file( $params['archive'] ) !== 0 ) {
		throw new Leadwerk_Migration_Archive_Exception(
			wp_kses(
				__( 'Your archive file name contains invalid characters: < > : " | ? * \0. It must not include these characters. The process cannot continue. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ),
				leadwerk_migration_allowed_html_tags()
			)
		);
	}

	// Validate file extension
	if ( ! leadwerk_migration_is_filename_supported( $params['archive'] ) ) {
		throw new Leadwerk_Migration_Archive_Exception(
			wp_kses(
				__( 'Invalid archive file type. Only .wpress files are allowed. The process cannot continue. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ),
				leadwerk_migration_allowed_html_tags()
			)
		);
	}

	// Get archive path
	if ( empty( $params['leadwerk_migration_manual_restore'] ) || ! empty( $params['leadwerk_migration_verified_local_restore'] ) ) {
		return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . $params['archive'];
	}

	return leadwerk_migration_backup_path( $params );
}

/**
 * Get multipart.list absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_multipart_path( $params ) {
	return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_MULTIPART_NAME;
}

/**
 * Get content.list absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_content_list_path( $params ) {
	return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_CONTENT_LIST_NAME;
}

/**
 * Get media.list absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_media_list_path( $params ) {
	return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_MEDIA_LIST_NAME;
}

/**
 * Get plugins.list absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_plugins_list_path( $params ) {
	return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_PLUGINS_LIST_NAME;
}

/**
 * Get themes.list absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_themes_list_path( $params ) {
	return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_THEMES_LIST_NAME;
}

/**
 * Get tables.list absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_tables_list_path( $params ) {
	return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_TABLES_LIST_NAME;
}

/**
 * Get incremental.content.list absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_incremental_content_list_path( $params ) {
	return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_INCREMENTAL_CONTENT_LIST_NAME;
}

/**
 * Get incremental.media.list absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_incremental_media_list_path( $params ) {
	return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_INCREMENTAL_MEDIA_LIST_NAME;
}

/**
 * Get incremental.plugins.list absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_incremental_plugins_list_path( $params ) {
	return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_INCREMENTAL_PLUGINS_LIST_NAME;
}

/**
 * Get incremental.themes.list absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_incremental_themes_list_path( $params ) {
	return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_INCREMENTAL_THEMES_LIST_NAME;
}

/**
 * Get incremental.backups.list absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_incremental_backups_list_path( $params ) {
	return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_INCREMENTAL_BACKUPS_LIST_NAME;
}

/**
 * Get package.json absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_package_path( $params ) {
	return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_PACKAGE_NAME;
}

/**
 * Get multisite.json absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_multisite_path( $params ) {
	return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_MULTISITE_NAME;
}

/**
 * Get blogs.json absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_blogs_path( $params ) {
	return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_BLOGS_NAME;
}

/**
 * Get settings.json absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_settings_path( $params ) {
	return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_SETTINGS_NAME;
}

/**
 * Get database.sql absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_database_path( $params ) {
	return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_DATABASE_NAME;
}

/**
 * Get cookies.txt absolute path
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_cookies_path( $params ) {
	return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . LEADWERK_MIGRATION_COOKIES_NAME;
}

/**
 * Get error log absolute path
 *
 * @param  mixed  $nonce Log file identifier
 * @return string
 */
function leadwerk_migration_error_path( $nonce ) {
	// Build the file name from the base name of a clean string identifier.
	$nonce = is_scalar( $nonce ) ? str_replace( chr( 0 ), '', (string) $nonce ) : '';

	return LEADWERK_MIGRATION_STORAGE_PATH . DIRECTORY_SEPARATOR . sprintf( LEADWERK_MIGRATION_ERROR_NAME, leadwerk_migration_basename( $nonce ) );
}

/**
 * Get archive name
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_archive_name( $params ) {
	return basename( $params['archive'] );
}

/**
 * Get backup URL address
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_backup_url( $params ) {
	static $backups_base_url = '';
	if ( empty( $backups_base_url ) ) {
		if ( Leadwerk_Migration_Backups::are_in_wp_content_folder() ) {
			$backups_base_url = str_replace( untrailingslashit( WP_CONTENT_DIR ), '', LEADWERK_MIGRATION_BACKUPS_PATH );
			$backups_base_url = content_url(
				leadwerk_migration_replace_directory_separator_with_forward_slash( $backups_base_url )
			);
		} else {
			$backups_base_url = str_replace( untrailingslashit( ABSPATH ), '', LEADWERK_MIGRATION_BACKUPS_PATH );
			$backups_base_url = site_url(
				leadwerk_migration_replace_directory_separator_with_forward_slash( $backups_base_url )
			);
		}
	}

	return $backups_base_url . '/' . leadwerk_migration_replace_directory_separator_with_forward_slash( $params['archive'] );
}

/**
 * Get archive size in bytes
 *
 * @param  array   $params Request parameters
 * @return integer
 */
function leadwerk_migration_archive_bytes( $params ) {
	$archive_path = leadwerk_migration_archive_path( $params );
	clearstatcache( true, $archive_path );

	return filesize( $archive_path );
}

/**
 * Get archive modified time in seconds
 *
 * @param  array   $params Request parameters
 * @return integer
 */
function leadwerk_migration_archive_mtime( $params ) {
	return filemtime( leadwerk_migration_archive_path( $params ) );
}

/**
 * Get backup size in bytes
 *
 * @param  array   $params Request parameters
 * @return integer
 */
function leadwerk_migration_backup_bytes( $params ) {
	return filesize( leadwerk_migration_backup_path( $params ) );
}

/**
 * Get database size in bytes
 *
 * @param  array   $params Request parameters
 * @return integer
 */
function leadwerk_migration_database_bytes( $params ) {
	return filesize( leadwerk_migration_database_path( $params ) );
}

/**
 * Get package size in bytes
 *
 * @param  array   $params Request parameters
 * @return integer
 */
function leadwerk_migration_package_bytes( $params ) {
	return filesize( leadwerk_migration_package_path( $params ) );
}

/**
 * Get multisite size in bytes
 *
 * @param  array   $params Request parameters
 * @return integer
 */
function leadwerk_migration_multisite_bytes( $params ) {
	return filesize( leadwerk_migration_multisite_path( $params ) );
}

/**
 * Get archive size as text
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_archive_size( $params ) {
	return leadwerk_migration_size_format( filesize( leadwerk_migration_archive_path( $params ) ) );
}

/**
 * Get backup size as text
 *
 * @param  array  $params Request parameters
 * @return string
 */
function leadwerk_migration_backup_size( $params ) {
	return leadwerk_migration_size_format( filesize( leadwerk_migration_backup_path( $params ) ) );
}

/**
 * Parse file size
 *
 * @param  string $size    File size
 * @param  string $default Default size
 * @return string
 */
function leadwerk_migration_parse_size( $size, $default = null ) {
	$suffixes = array(
		''  => 1,
		'k' => 1000,
		'm' => 1000000,
		'g' => 1000000000,
	);

	// Parse size format
	if ( preg_match( '/([0-9]+)\s*(k|m|g)?(b?(ytes?)?)/i', $size, $matches ) ) {
		return $matches[1] * $suffixes[ strtolower( $matches[2] ) ];
	}

	return $default;
}

/**
 * Format file size into human-readable string
 *
 * Fixes the WP size_format bug: size_format( '0' ) => false
 *
 * @param  int|string   $bytes            Number of bytes. Note max integer size for integers.
 * @param  int          $decimals         Optional. Precision of number of decimal places. Default 0.
 * @return string|false False on failure. Number string on success.
 */
function leadwerk_migration_size_format( $bytes, $decimals = 0 ) {
	if ( strval( $bytes ) === '0' ) {
		return size_format( 0, $decimals );
	}

	return size_format( $bytes, $decimals );
}

/**
 * Get current site name
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_site_name( $blog_id = null ) {
	return parse_url( get_site_url( $blog_id ), PHP_URL_HOST );
}

/**
 * Get archive file name
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_archive_file( $blog_id = null ) {
	$name = array();

	// Add domain
	if ( defined( 'LEADWERK_MIGRATION_KEEP_DOMAIN_NAME' ) ) {
		$name[] = parse_url( get_site_url( $blog_id ), PHP_URL_HOST );
	} elseif ( ( $domain = explode( '.', parse_url( get_site_url( $blog_id ), PHP_URL_HOST ) ) ) ) {
		foreach ( $domain as $subdomain ) {
			if ( ( $subdomain = strtolower( $subdomain ) ) ) {
				$name[] = $subdomain;
			}
		}
	}

	// Add path
	if ( ( $path = parse_url( get_site_url( $blog_id ), PHP_URL_PATH ) ) ) {
		foreach ( explode( '/', $path ) as $directory ) {
			if ( ( $directory = strtolower( preg_replace( '/[^A-Za-z0-9\-]/', '', $directory ) ) ) ) {
				$name[] = $directory;
			}
		}
	}

	// Add year, month and day
	$name[] = current_time( 'Ymd' );

	// Add hours, minutes and seconds
	$name[] = current_time( 'His' );

	// Add unique identifier
	$name[] = leadwerk_migration_generate_random_string( 12, false );

	return sprintf( '%s.wpress', strtolower( implode( '-', $name ) ) );
}

/**
 * Get archive folder name
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_archive_folder( $blog_id = null ) {
	$name = array();

	// Add domain
	if ( defined( 'LEADWERK_MIGRATION_KEEP_DOMAIN_NAME' ) ) {
		$name[] = parse_url( get_site_url( $blog_id ), PHP_URL_HOST );
	} elseif ( ( $domain = explode( '.', parse_url( get_site_url( $blog_id ), PHP_URL_HOST ) ) ) ) {
		foreach ( $domain as $subdomain ) {
			if ( ( $subdomain = strtolower( $subdomain ) ) ) {
				$name[] = $subdomain;
			}
		}
	}

	// Add path
	if ( ( $path = parse_url( get_site_url( $blog_id ), PHP_URL_PATH ) ) ) {
		foreach ( explode( '/', $path ) as $directory ) {
			if ( ( $directory = strtolower( preg_replace( '/[^A-Za-z0-9\-]/', '', $directory ) ) ) ) {
				$name[] = $directory;
			}
		}
	}

	return strtolower( implode( '-', $name ) );
}

/**
 * Get archive bucket name
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_archive_bucket( $blog_id = null ) {
	$name = array();

	// Add domain
	if ( ( $domain = explode( '.', parse_url( get_site_url( $blog_id ), PHP_URL_HOST ) ) ) ) {
		foreach ( $domain as $subdomain ) {
			if ( ( $subdomain = strtolower( $subdomain ) ) ) {
				$name[] = $subdomain;
			}
		}
	}

	// Add path
	if ( ( $path = parse_url( get_site_url( $blog_id ), PHP_URL_PATH ) ) ) {
		foreach ( explode( '/', $path ) as $directory ) {
			if ( ( $directory = strtolower( preg_replace( '/[^A-Za-z0-9\-]/', '', $directory ) ) ) ) {
				$name[] = $directory;
			}
		}
	}

	return strtolower( implode( '-', $name ) );
}

/**
 * Get archive vault name
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_archive_vault( $blog_id = null ) {
	$name = array();

	// Add domain
	if ( ( $domain = explode( '.', parse_url( get_site_url( $blog_id ), PHP_URL_HOST ) ) ) ) {
		foreach ( $domain as $subdomain ) {
			if ( ( $subdomain = strtolower( $subdomain ) ) ) {
				$name[] = $subdomain;
			}
		}
	}

	// Add path
	if ( ( $path = parse_url( get_site_url( $blog_id ), PHP_URL_PATH ) ) ) {
		foreach ( explode( '/', $path ) as $directory ) {
			if ( ( $directory = strtolower( preg_replace( '/[^A-Za-z0-9\-]/', '', $directory ) ) ) ) {
				$name[] = $directory;
			}
		}
	}

	return strtolower( implode( '-', $name ) );
}

/**
 * Get archive project name
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_archive_project( $blog_id = null ) {
	$name = array();

	// Add domain
	if ( ( $domain = explode( '.', parse_url( get_site_url( $blog_id ), PHP_URL_HOST ) ) ) ) {
		foreach ( $domain as $subdomain ) {
			if ( ( $subdomain = strtolower( $subdomain ) ) ) {
				$name[] = $subdomain;
			}
		}
	}

	// Add path
	if ( ( $path = parse_url( get_site_url( $blog_id ), PHP_URL_PATH ) ) ) {
		foreach ( explode( '/', $path ) as $directory ) {
			if ( ( $directory = strtolower( preg_replace( '/[^A-Za-z0-9\-]/', '', $directory ) ) ) ) {
				$name[] = $directory;
			}
		}
	}

	return strtolower( implode( '-', $name ) );
}

/**
 * Get archive share name
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_archive_share( $blog_id = null ) {
	$name = array();

	// Add domain
	if ( ( $domain = explode( '.', parse_url( get_site_url( $blog_id ), PHP_URL_HOST ) ) ) ) {
		foreach ( $domain as $subdomain ) {
			if ( ( $subdomain = strtolower( $subdomain ) ) ) {
				$name[] = $subdomain;
			}
		}
	}

	// Add path
	if ( ( $path = parse_url( get_site_url( $blog_id ), PHP_URL_PATH ) ) ) {
		foreach ( explode( '/', $path ) as $directory ) {
			if ( ( $directory = strtolower( preg_replace( '/[^A-Za-z0-9\-]/', '', $directory ) ) ) ) {
				$name[] = $directory;
			}
		}
	}

	return strtolower( implode( '-', $name ) );
}

/**
 * Generate random string
 *
 * @param  integer $length              String length
 * @param  boolean $mixed_chars         Whether to include mixed characters
 * @param  boolean $special_chars       Whether to include special characters
 * @param  boolean $extra_special_chars Whether to include extra special characters
 * @return string
 */
function leadwerk_migration_generate_random_string( $length = 12, $mixed_chars = true, $special_chars = false, $extra_special_chars = false ) {
	$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
	if ( $mixed_chars ) {
		$chars .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
	}

	if ( $special_chars ) {
		$chars .= '!@#$%^&*()';
	}

	if ( $extra_special_chars ) {
		$chars .= '-_ []{}<>~`+=,.;:/?|';
	}

	$str = '';
	for ( $i = 0; $i < $length; $i++ ) {
		$str .= substr( $chars, wp_rand( 0, strlen( $chars ) - 1 ), 1 );
	}

	return $str;
}

/**
 * Get storage folder name
 *
 * @return string
 */
function leadwerk_migration_storage_folder() {
	return Leadwerk_Migration_Security::job_id();
}

/**
 * Check whether blog ID is main site
 *
 * @param  integer $blog_id Blog ID
 * @return boolean
 */
function leadwerk_migration_is_mainsite( $blog_id = null ) {
	return $blog_id === null || $blog_id === 0 || $blog_id === 1;
}

/**
 * Get files absolute path by blog ID
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_blog_files_abspath( $blog_id = null ) {
	if ( leadwerk_migration_is_mainsite( $blog_id ) ) {
		return leadwerk_migration_get_uploads_dir();
	}

	return WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'blogs.dir' . DIRECTORY_SEPARATOR . $blog_id . DIRECTORY_SEPARATOR . 'files';
}

/**
 * Get blogs.dir absolute path by blog ID
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_blog_blogsdir_abspath( $blog_id = null ) {
	if ( leadwerk_migration_is_mainsite( $blog_id ) ) {
		return leadwerk_migration_get_uploads_dir();
	}

	return WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'blogs.dir' . DIRECTORY_SEPARATOR . $blog_id;
}

/**
 * Get sites absolute path by blog ID
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_blog_sites_abspath( $blog_id = null ) {
	if ( leadwerk_migration_is_mainsite( $blog_id ) ) {
		return leadwerk_migration_get_uploads_dir();
	}

	return leadwerk_migration_get_uploads_dir() . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . $blog_id;
}

/**
 * Get files relative path by blog ID
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_blog_files_relpath( $blog_id = null ) {
	if ( leadwerk_migration_is_mainsite( $blog_id ) ) {
		return 'uploads';
	}

	return 'blogs.dir' . DIRECTORY_SEPARATOR . $blog_id . DIRECTORY_SEPARATOR . 'files';
}

/**
 * Get blogs.dir relative path by blog ID
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_blog_blogsdir_relpath( $blog_id = null ) {
	if ( leadwerk_migration_is_mainsite( $blog_id ) ) {
		return 'uploads';
	}

	return 'blogs.dir' . DIRECTORY_SEPARATOR . $blog_id;
}

/**
 * Get sites relative path by blog ID
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_blog_sites_relpath( $blog_id = null ) {
	if ( leadwerk_migration_is_mainsite( $blog_id ) ) {
		return 'uploads';
	}

	return 'uploads' . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . $blog_id;
}

/**
 * Get files URL by blog ID
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_blog_files_url( $blog_id = null ) {
	if ( leadwerk_migration_is_mainsite( $blog_id ) ) {
		return '/wp-content/uploads/';
	}

	return sprintf( '/wp-content/blogs.dir/%d/files/', $blog_id );
}

/**
 * Get blogs.dir URL by blog ID
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_blog_blogsdir_url( $blog_id = null ) {
	if ( leadwerk_migration_is_mainsite( $blog_id ) ) {
		return '/wp-content/uploads/';
	}

	return sprintf( '/wp-content/blogs.dir/%d/', $blog_id );
}

/**
 * Get sites URL by blog ID
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_blog_sites_url( $blog_id = null ) {
	if ( leadwerk_migration_is_mainsite( $blog_id ) ) {
		return '/wp-content/uploads/';
	}

	return sprintf( '/wp-content/uploads/sites/%d/', $blog_id );
}

/**
 * Get uploads URL by blog ID
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_blog_uploads_url( $blog_id = null ) {
	if ( leadwerk_migration_is_mainsite( $blog_id ) ) {
		return sprintf( '/%s/', leadwerk_migration_get_uploads_path() );
	}

	return sprintf( '/%s/sites/%d/', leadwerk_migration_get_uploads_path(), $blog_id );
}

/**
 * Get ServMask table prefix by blog ID
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_servmask_prefix( $blog_id = null ) {
	if ( leadwerk_migration_is_mainsite( $blog_id ) ) {
		return LEADWERK_MIGRATION_TABLE_PREFIX;
	}

	return LEADWERK_MIGRATION_TABLE_PREFIX . $blog_id . '_';
}

/**
 * Get WordPress table prefix by blog ID
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function leadwerk_migration_table_prefix( $blog_id = null ) {
	global $wpdb;

	// Set base table prefix
	if ( leadwerk_migration_is_mainsite( $blog_id ) ) {
		return $wpdb->base_prefix;
	}

	return $wpdb->base_prefix . $blog_id . '_';
}

/**
 * Get default config filters
 *
 * @param  array $filters List of files and directories
 * @return array
 */
function leadwerk_migration_config_filters( $filters = array() ) {
	return array_merge(
		$filters,
		array(
			LEADWERK_MIGRATION_PACKAGE_NAME,
			LEADWERK_MIGRATION_MULTISITE_NAME,
			LEADWERK_MIGRATION_AUTH_MANIFEST_NAME,
		)
	);
}

/**
 * Get default content filters
 *
 * @param  array $filters List of files and directories
 * @return array
 */
function leadwerk_migration_content_filters( $filters = array() ) {
	return array_merge(
		$filters,
		array(
			LEADWERK_MIGRATION_BACKUPS_PATH,
			LEADWERK_MIGRATION_BACKUPS_NAME,
			LEADWERK_MIGRATION_PACKAGE_NAME,
			LEADWERK_MIGRATION_MULTISITE_NAME,
			LEADWERK_MIGRATION_AUTH_MANIFEST_NAME,
			LEADWERK_MIGRATION_DATABASE_NAME,
			Leadwerk_Migration_Mail_Smtp::SNAPSHOT_NAME,
			LEADWERK_MIGRATION_W3TC_CONFIG_FILE,
		)
	);
}

/**
 * Get default media filters
 *
 * @param  array $filters List of files and directories
 * @return array
 */
function leadwerk_migration_media_filters( $filters = array() ) {
	return array_merge(
		$filters,
		array(
			LEADWERK_MIGRATION_BACKUPS_PATH,
		)
	);
}

/**
 * Get default plugin filters
 *
 * @param  array $filters List of plugins
 * @return array
 */
function leadwerk_migration_plugin_filters( $filters = array() ) {
	return array_merge(
		$filters,
		array(
			LEADWERK_MIGRATION_BACKUPS_PATH,
			LEADWERK_MIGRATION_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONZE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONAE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONVE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONBE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONIE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONXE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONDE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONTE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONFE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONCE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONGE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONRE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONEE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONME_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONOE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONPE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONKE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONNE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONSE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONUE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONLE_PLUGIN_BASEDIR,
			LEADWERK_MIGRATIONWE_PLUGIN_BASEDIR,
		)
	);
}

/**
 * Get default theme filters
 *
 * @param  array $filters List of files and directories
 * @return array
 */
function leadwerk_migration_theme_filters( $filters = array() ) {
	return array_merge(
		$filters,
		array(
			LEADWERK_MIGRATION_BACKUPS_PATH,
		)
	);
}

/**
 * Get active ServMask plugins
 *
 * @return array
 */
function leadwerk_migration_active_servmask_plugins( $plugins = array() ) {
	// WP Migration Plugin
	if ( defined( 'LEADWERK_MIGRATION_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATION_PLUGIN_BASENAME;
	}

	// Microsoft Azure Extension
	if ( defined( 'LEADWERK_MIGRATIONZE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONZE_PLUGIN_BASENAME;
	}

	// Backblaze B2 Extension
	if ( defined( 'LEADWERK_MIGRATIONAE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONAE_PLUGIN_BASENAME;
	}

	// Backup Plugin
	if ( defined( 'LEADWERK_MIGRATIONVE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONVE_PLUGIN_BASENAME;
	}

	// Box Extension
	if ( defined( 'LEADWERK_MIGRATIONBE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONBE_PLUGIN_BASENAME;
	}

	// DigitalOcean Spaces Extension
	if ( defined( 'LEADWERK_MIGRATIONIE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONIE_PLUGIN_BASENAME;
	}

	// Direct Extension
	if ( defined( 'LEADWERK_MIGRATIONXE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONXE_PLUGIN_BASENAME;
	}

	// Dropbox Extension
	if ( defined( 'LEADWERK_MIGRATIONDE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONDE_PLUGIN_BASENAME;
	}

	// File Extension
	if ( defined( 'LEADWERK_MIGRATIONTE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONTE_PLUGIN_BASENAME;
	}

	// FTP Extension
	if ( defined( 'LEADWERK_MIGRATIONFE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONFE_PLUGIN_BASENAME;
	}

	// Google Cloud Storage Extension
	if ( defined( 'LEADWERK_MIGRATIONCE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONCE_PLUGIN_BASENAME;
	}

	// Google Drive Extension
	if ( defined( 'LEADWERK_MIGRATIONGE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONGE_PLUGIN_BASENAME;
	}

	// Amazon Glacier Extension
	if ( defined( 'LEADWERK_MIGRATIONRE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONRE_PLUGIN_BASENAME;
	}

	// Mega Extension
	if ( defined( 'LEADWERK_MIGRATIONEE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONEE_PLUGIN_BASENAME;
	}

	// Multisite Extension
	if ( defined( 'LEADWERK_MIGRATIONME_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONME_PLUGIN_BASENAME;
	}

	// OneDrive Extension
	if ( defined( 'LEADWERK_MIGRATIONOE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONOE_PLUGIN_BASENAME;
	}

	// pCloud Extension
	if ( defined( 'LEADWERK_MIGRATIONPE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONPE_PLUGIN_BASENAME;
	}

	// Pro Plugin
	if ( defined( 'LEADWERK_MIGRATIONKE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONKE_PLUGIN_BASENAME;
	}

	// S3 Client Extension
	if ( defined( 'LEADWERK_MIGRATIONNE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONNE_PLUGIN_BASENAME;
	}

	// Amazon S3 Extension
	if ( defined( 'LEADWERK_MIGRATIONSE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONSE_PLUGIN_BASENAME;
	}

	// Legacy extension compatibility
	if ( defined( 'LEADWERK_MIGRATIONUE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONUE_PLUGIN_BASENAME;
	}

	// URL Extension
	if ( defined( 'LEADWERK_MIGRATIONLE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONLE_PLUGIN_BASENAME;
	}

	// WebDAV Extension
	if ( defined( 'LEADWERK_MIGRATIONWE_PLUGIN_BASENAME' ) ) {
		$plugins[] = LEADWERK_MIGRATIONWE_PLUGIN_BASENAME;
	}

	return $plugins;
}

/**
 * Get active sitewide plugins
 *
 * @return array
 */
function leadwerk_migration_active_sitewide_plugins() {
	return array_keys( get_site_option( LEADWERK_MIGRATION_ACTIVE_SITEWIDE_PLUGINS, array() ) );
}

/**
 * Get active plugins
 *
 * @return array
 */
function leadwerk_migration_active_plugins() {
	return array_values( get_option( LEADWERK_MIGRATION_ACTIVE_PLUGINS, array() ) );
}

/**
 * Set active sitewide plugins (inspired by WordPress activate_plugins() function)
 *
 * @param  array   $plugins List of plugins
 * @return boolean
 */
function leadwerk_migration_activate_sitewide_plugins( $plugins ) {
	$current = get_site_option( LEADWERK_MIGRATION_ACTIVE_SITEWIDE_PLUGINS, array() );

	// Add plugins
	foreach ( $plugins as $plugin ) {
		if ( ! isset( $current[ $plugin ] ) && ! is_wp_error( validate_plugin( $plugin ) ) ) {
			$current[ $plugin ] = time();
		}
	}

	return update_site_option( LEADWERK_MIGRATION_ACTIVE_SITEWIDE_PLUGINS, $current );
}

/**
 * Set active plugins (inspired by WordPress activate_plugins() function)
 *
 * @param  array   $plugins List of plugins
 * @return boolean
 */
function leadwerk_migration_activate_plugins( $plugins ) {
	$current = get_option( LEADWERK_MIGRATION_ACTIVE_PLUGINS, array() );

	// Add plugins
	foreach ( $plugins as $plugin ) {
		if ( ! in_array( $plugin, $current ) && ! is_wp_error( validate_plugin( $plugin ) ) ) {
			$current[] = $plugin;
		}
	}

	return update_option( LEADWERK_MIGRATION_ACTIVE_PLUGINS, $current );
}

/**
 * Get active template
 *
 * @return string
 */
function leadwerk_migration_active_template() {
	return get_option( LEADWERK_MIGRATION_ACTIVE_TEMPLATE );
}

/**
 * Get active stylesheet
 *
 * @return string
 */
function leadwerk_migration_active_stylesheet() {
	return get_option( LEADWERK_MIGRATION_ACTIVE_STYLESHEET );
}

/**
 * Set active template
 *
 * @param  string  $template Template name
 * @return boolean
 */
function leadwerk_migration_activate_template( $template ) {
	return update_option( LEADWERK_MIGRATION_ACTIVE_TEMPLATE, $template );
}

/**
 * Set active stylesheet
 *
 * @param  string  $stylesheet Stylesheet name
 * @return boolean
 */
function leadwerk_migration_activate_stylesheet( $stylesheet ) {
	return update_option( LEADWERK_MIGRATION_ACTIVE_STYLESHEET, $stylesheet );
}

/**
 * Set inactive sitewide plugins (inspired by WordPress deactivate_plugins() function)
 *
 * @param  array   $plugins List of plugins
 * @return boolean
 */
function leadwerk_migration_deactivate_sitewide_plugins( $plugins ) {
	$current = get_site_option( LEADWERK_MIGRATION_ACTIVE_SITEWIDE_PLUGINS, array() );

	// Add plugins
	foreach ( $plugins as $plugin ) {
		if ( isset( $current[ $plugin ] ) ) {
			unset( $current[ $plugin ] );
		}
	}

	return update_site_option( LEADWERK_MIGRATION_ACTIVE_SITEWIDE_PLUGINS, $current );
}


/**
 * Set inactive plugins (inspired by WordPress deactivate_plugins() function)
 *
 * @param  array   $plugins List of plugins
 * @return boolean
 */
function leadwerk_migration_deactivate_plugins( $plugins ) {
	$current = get_option( LEADWERK_MIGRATION_ACTIVE_PLUGINS, array() );

	// Remove plugins
	foreach ( $plugins as $plugin ) {
		if ( ( $key = array_search( $plugin, $current ) ) !== false ) {
			unset( $current[ $key ] );
		}
	}

	return update_option( LEADWERK_MIGRATION_ACTIVE_PLUGINS, $current );
}

/**
 * Deactivate Jetpack modules
 *
 * @param  array   $modules List of modules
 * @return boolean
 */
function leadwerk_migration_deactivate_jetpack_modules( $modules ) {
	$current = get_option( LEADWERK_MIGRATION_JETPACK_ACTIVE_MODULES, array() );

	// Remove modules
	foreach ( $modules as $module ) {
		if ( ( $key = array_search( $module, $current ) ) !== false ) {
			unset( $current[ $key ] );
		}
	}

	return update_option( LEADWERK_MIGRATION_JETPACK_ACTIVE_MODULES, $current );
}

/**
 * Deactivate Swift Optimizer rules
 *
 * @param  array   $rules List of rules
 * @return boolean
 */
function leadwerk_migration_deactivate_swift_optimizer_rules( $rules ) {
	$current = get_option( LEADWERK_MIGRATION_SWIFT_OPTIMIZER_PLUGIN_ORGANIZER, array() );

	// Remove rules
	foreach ( $rules as $rule ) {
		unset( $current['rules'][ $rule ] );
	}

	return update_option( LEADWERK_MIGRATION_SWIFT_OPTIMIZER_PLUGIN_ORGANIZER, $current );
}

/**
 * Deactivate sitewide Revolution Slider
 *
 * @param  string  $basename Plugin basename
 * @return boolean
 */
function leadwerk_migration_deactivate_sitewide_revolution_slider( $basename ) {
	if ( ( $plugins = get_plugins() ) ) {
		if ( isset( $plugins[ $basename ]['Version'] ) && ( $version = $plugins[ $basename ]['Version'] ) ) {
			if ( version_compare( PHP_VERSION, '7.3', '>=' ) && version_compare( $version, '5.4.8.3', '<' ) ) {
				return leadwerk_migration_deactivate_sitewide_plugins( array( $basename ) );
			}

			if ( version_compare( PHP_VERSION, '7.2', '>=' ) && version_compare( $version, '5.4.6', '<' ) ) {
				return leadwerk_migration_deactivate_sitewide_plugins( array( $basename ) );
			}

			if ( version_compare( PHP_VERSION, '7.1', '>=' ) && version_compare( $version, '5.4.1', '<' ) ) {
				return leadwerk_migration_deactivate_sitewide_plugins( array( $basename ) );
			}

			if ( version_compare( PHP_VERSION, '7.0', '>=' ) && version_compare( $version, '4.6.5', '<' ) ) {
				return leadwerk_migration_deactivate_sitewide_plugins( array( $basename ) );
			}
		}
	}

	return false;
}

/**
 * Deactivate Revolution Slider
 *
 * @param  string  $basename Plugin basename
 * @return boolean
 */
function leadwerk_migration_deactivate_revolution_slider( $basename ) {
	if ( ( $plugins = get_plugins() ) ) {
		if ( isset( $plugins[ $basename ]['Version'] ) && ( $version = $plugins[ $basename ]['Version'] ) ) {
			if ( version_compare( PHP_VERSION, '7.3', '>=' ) && version_compare( $version, '5.4.8.3', '<' ) ) {
				return leadwerk_migration_deactivate_plugins( array( $basename ) );
			}

			if ( version_compare( PHP_VERSION, '7.2', '>=' ) && version_compare( $version, '5.4.6', '<' ) ) {
				return leadwerk_migration_deactivate_plugins( array( $basename ) );
			}

			if ( version_compare( PHP_VERSION, '7.1', '>=' ) && version_compare( $version, '5.4.1', '<' ) ) {
				return leadwerk_migration_deactivate_plugins( array( $basename ) );
			}

			if ( version_compare( PHP_VERSION, '7.0', '>=' ) && version_compare( $version, '4.6.5', '<' ) ) {
				return leadwerk_migration_deactivate_plugins( array( $basename ) );
			}
		}
	}

	return false;
}

/**
 * Initial DB version
 *
 * @return boolean
 */
function leadwerk_migration_initial_db_version() {
	if ( ! get_option( LEADWERK_MIGRATION_DB_VERSION ) ) {
		return update_option( LEADWERK_MIGRATION_DB_VERSION, get_option( LEADWERK_MIGRATION_INITIAL_DB_VERSION ) );
	}

	return false;
}

/**
 * Discover plugin basename
 *
 * @param  string $basename Plugin basename
 * @return string
 */
function leadwerk_migration_discover_plugin_basename( $basename ) {
	if ( ( $plugins = get_plugins() ) ) {
		foreach ( $plugins as $plugin => $info ) {
			if ( strpos( dirname( $plugin ), dirname( $basename ) ) !== false ) {
				if ( basename( $plugin ) === basename( $basename ) ) {
					return $plugin;
				}
			}
		}
	}

	return $basename;
}

/**
 * Validate plugin basename
 *
 * @param  string  $basename Plugin basename
 * @return boolean
 */
function leadwerk_migration_validate_plugin_basename( $basename ) {
	if ( ( $plugins = get_plugins() ) ) {
		foreach ( $plugins as $plugin => $info ) {
			if ( $plugin === $basename ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Validate theme basename
 *
 * @param  string  $basename Theme basename
 * @return boolean
 */
function leadwerk_migration_validate_theme_basename( $basename ) {
	if ( ( $themes = search_theme_directories() ) ) {
		foreach ( $themes as $theme => $info ) {
			if ( $info['theme_file'] === $basename ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Flush WP options cache
 *
 * @return void
 */
function leadwerk_migration_cache_flush() {
	wp_cache_init();
	wp_cache_flush();

	// Reset WP options cache
	wp_cache_set( 'alloptions', array(), 'options' );
	wp_cache_set( 'notoptions', array(), 'options' );

	// Reset WP sitemeta cache
	wp_cache_set( '1:notoptions', array(), 'site-options' );
	wp_cache_set( '1:ms_files_rewriting', false, 'site-options' );
	wp_cache_set( '1:active_sitewide_plugins', false, 'site-options' );

	// Delete WP options cache
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );

	// Delete WP sitemeta cache
	wp_cache_delete( '1:notoptions', 'site-options' );
	wp_cache_delete( '1:ms_files_rewriting', 'site-options' );
	wp_cache_delete( '1:active_sitewide_plugins', 'site-options' );

	// Remove WP options filter
	remove_all_filters( 'sanitize_option_home' );
	remove_all_filters( 'sanitize_option_siteurl' );
	remove_all_filters( 'default_site_option_ms_files_rewriting' );
}

/**
 * Flush Elementor cache
 *
 * @return void
 */
function leadwerk_migration_elementor_cache_flush() {
	delete_post_meta_by_key( '_elementor_css' );
	delete_post_meta_by_key( '_elementor_element_cache' );
	delete_post_meta_by_key( '_elementor_page_assets' );

	delete_option( '_elementor_global_css' );
	delete_option( '_elementor_assets_data' );
	delete_option( 'elementor-custom-breakpoints-files' );
}

/**
 * Set WooCommerce Force SSL checkout
 *
 * @param  boolean $yes Force SSL checkout
 * @return void
 */
function leadwerk_migration_woocommerce_force_ssl( $yes = true ) {
	if ( get_option( 'woocommerce_force_ssl_checkout' ) ) {
		if ( $yes ) {
			update_option( 'woocommerce_force_ssl_checkout', 'yes' );
		} else {
			update_option( 'woocommerce_force_ssl_checkout', 'no' );
		}
	}
}

/**
 * Set URL scheme
 *
 * @param  string $url    URL value
 * @param  string $scheme URL scheme
 * @return string
 */
function leadwerk_migration_url_scheme( $url, $scheme = '' ) {
	if ( empty( $scheme ) ) {
		return preg_replace( '#^\w+://#', '//', $url );
	}

	return preg_replace( '#^\w+://#', $scheme . '://', $url );
}

/**
 * Opens a file in specified mode
 *
 * @param  string   $file Path to the file to open
 * @param  string   $mode Mode in which to open the file
 * @return resource
 * @throws Leadwerk_Migration_Not_Accessible_Exception
 */
function leadwerk_migration_open( $file, $mode ) {
	$file_handle = @fopen( $file, $mode );
	if ( false === $file_handle ) {
		throw new Leadwerk_Migration_Not_Accessible_Exception(
			wp_kses(
				/* translators: 1: File path, 2: mode */
				sprintf( __( 'Could not open %1$s with mode %2$s. The process cannot continue. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ), $file, $mode ),
				leadwerk_migration_allowed_html_tags()
			)
		);
	}

	return $file_handle;
}

/**
 * Opens a gzipped file in specified mode
 *
 * @param  string   $file Path to the file to open
 * @param  string   $mode Mode in which to open the file
 * @return resource
 * @throws Leadwerk_Migration_Not_Accessible_Exception
 */
function leadwerk_migration_gzopen( $file, $mode ) {
	$file_handle = @fopen( "compress.zlib://{$file}", $mode );
	if ( false === $file_handle ) {
		throw new Leadwerk_Migration_Not_Accessible_Exception(
			wp_kses(
				/* translators: 1: File path, 2: mode */
				sprintf( __( 'Could not open %1$s with mode %2$s. The process cannot continue. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ), $file, $mode ),
				leadwerk_migration_allowed_html_tags()
			)
		);
	}

	return $file_handle;
}

/**
 * Write contents to a file
 *
 * @param  resource $handle  File handle to write to
 * @param  string   $content Contents to write to the file
 * @return integer
 * @throws Leadwerk_Migration_Not_Writable_Exception
 * @throws Leadwerk_Migration_Quota_Exceeded_Exception
 */
function leadwerk_migration_write( $handle, $content ) {
	$write_result = @fwrite( $handle, $content );
	if ( false === $write_result ) {
		if ( ( $meta = stream_get_meta_data( $handle ) ) ) {
			throw new Leadwerk_Migration_Not_Writable_Exception(
				wp_kses(
					/* translators: 1: Meta data stream URI. */
					sprintf( __( 'Could not write to: %s. The process cannot continue. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ), $meta['uri'] ),
					leadwerk_migration_allowed_html_tags()
				)
			);
		}
	} elseif ( null === $write_result ) {
		return strlen( $content );
	} elseif ( strlen( $content ) !== $write_result ) {
		if ( ( $meta = stream_get_meta_data( $handle ) ) ) {
			throw new Leadwerk_Migration_Quota_Exceeded_Exception(
				wp_kses(
					/* translators: 1: Meta data stream URI. */
					sprintf( __( 'Out of disk space. Could not write to: %s. The process cannot continue. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ), $meta['uri'] ),
					leadwerk_migration_allowed_html_tags()
				)
			);
		}
	}

	return $write_result;
}

/**
 * Read contents from a file
 *
 * @param  resource $handle File handle to read from
 * @param  integer  $length Up to length number of bytes read
 * @return string
 * @throws Leadwerk_Migration_Not_Readable_Exception
 */
function leadwerk_migration_read( $handle, $length ) {
	if ( $length > 0 ) {
		$read_result = @fread( $handle, $length );
		if ( false === $read_result ) {
			if ( ( $meta = stream_get_meta_data( $handle ) ) ) {
				throw new Leadwerk_Migration_Not_Readable_Exception(
					wp_kses(
						/* translators: 1: Meta data stream URI. */
						sprintf( __( 'Could not read file: %s. The process cannot continue. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ), $meta['uri'] ),
						leadwerk_migration_allowed_html_tags()
					)
				);
			}
		}

		return $read_result;
	}

	return false;
}

/**
 * Seeks on a file pointer
 *
 * @param  resource $handle File handle
 * @param  integer  $offset File offset
 * @param  integer  $mode   Offset mode
 * @return integer
 */
function leadwerk_migration_seek( $handle, $offset, $mode = SEEK_SET ) {
	$seek_result = @fseek( $handle, $offset, $mode );
	if ( -1 === $seek_result ) {
		if ( ( $meta = stream_get_meta_data( $handle ) ) ) {
			throw new Leadwerk_Migration_Not_Seekable_Exception(
				wp_kses(
					/* translators: 1: File offset, 2: Meta data stream URI. */
					sprintf( __( 'Could not seek to offset %1$d on %2$s. The process cannot continue. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ), $offset, $meta['uri'] ),
					leadwerk_migration_allowed_html_tags()
				)
			);
		}
	}

	return $seek_result;
}

/**
 * Returns the current position of the file read/write pointer
 *
 * @param  resource $handle File handle
 * @return integer
 */
function leadwerk_migration_tell( $handle ) {
	$tell_result = @ftell( $handle );
	if ( false === $tell_result ) {
		if ( ( $meta = stream_get_meta_data( $handle ) ) ) {
			throw new Leadwerk_Migration_Not_Tellable_Exception(
				wp_kses(
					/* translators: 1: Meta data stream URI. */
					sprintf( __( 'Could not get current pointer position of %s. The process cannot continue. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ), $meta['uri'] ),
					leadwerk_migration_allowed_html_tags()
				)
			);
		}
	}

	return $tell_result;
}

/**
 * Write fields to a file
 *
 * @param resource  $handle File handle to write to
 * @param array     $fields Fields to write to the file
 * @param string    $separator
 * @param string    $enclosure
 * @param string    $escape
 *
 * @return integer
 * @throws Leadwerk_Migration_Not_Writable_Exception
 */
function leadwerk_migration_putcsv( $handle, $fields, $separator = ',', $enclosure = '"', $escape = '\\' ) {
	if ( PHP_MAJOR_VERSION >= 7 ) {
		// phpcs:ignore PHPCompatibility.FunctionUse.NewFunctionParameters
		$write_result = @fputcsv( $handle, $fields, $separator, $enclosure, $escape );
	} else {
		$write_result = @fputcsv( $handle, $fields, $separator, $enclosure );
	}

	if ( false === $write_result ) {
		if ( ( $meta = stream_get_meta_data( $handle ) ) ) {
			throw new Leadwerk_Migration_Not_Writable_Exception(
				wp_kses(
					/* translators: 1: Meta data stream URI. */
					sprintf( __( 'Could not write to: %s. The process cannot continue. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ), $meta['uri'] ),
					leadwerk_migration_allowed_html_tags()
				)
			);
		}
	}

	return $write_result;
}

/**
 * Read fields from a file
 *
 * @param resource  $handle File handle to read from
 * @param int       $length
 * @param string    $separator
 * @param string    $enclosure
 * @param string    $escape
 *
 * @return array|false|null
 */
function leadwerk_migration_getcsv( $handle, $length = null, $separator = ',', $enclosure = '"', $escape = '\\' ) {
	return fgetcsv( $handle, $length, $separator, $enclosure, $escape );
}

/**
 * Closes a file handle
 *
 * @param  resource $handle File handle to close
 * @return boolean
 */
function leadwerk_migration_close( $handle ) {
	return @fclose( $handle );
}

/**
 * Deletes a file
 *
 * @param  string  $file Path to file to delete
 * @return boolean
 */
function leadwerk_migration_unlink( $file ) {
	return @unlink( $file );
}

/**
 * Sets modification time of a file
 *
 * @param  string  $file Path to file to change modification time
 * @param  integer $time File modification time
 * @return boolean
 */
function leadwerk_migration_touch( $file, $mtime ) {
	return @touch( $file, $mtime );
}

/**
 * Changes file mode
 *
 * @param  string  $file Path to file to change mode
 * @param  integer $time File mode
 * @return boolean
 */
function leadwerk_migration_chmod( $file, $mode ) {
	return @chmod( $file, $mode );
}

/**
 * Copies one file's contents to another
 *
 * @param  string  $target_file        File to copy the contents from
 * @param  string  $output_file        File to copy the contents to
 * @param  integer $output_file_offset Output file offset bytes
 * @return void
 */
function leadwerk_migration_copy( $target_file, $output_file, $output_file_offset = null ) {
	$target_handle = leadwerk_migration_open( $target_file, 'rb' );
	if ( null === $output_file_offset ) {
		$output_handle = leadwerk_migration_open( $output_file, 'ab' );
	} else {
		$output_handle = leadwerk_migration_open( $output_file, 'cb' );
		leadwerk_migration_seek( $output_handle, $output_file_offset, SEEK_SET );
	}

	while ( ( $file_buffer = leadwerk_migration_read( $target_handle, 4096 ) ) ) {
		leadwerk_migration_write( $output_handle, $file_buffer );
	}

	leadwerk_migration_close( $target_handle );
	leadwerk_migration_close( $output_handle );
}

/**
 * Copies gzipped file's contents while uncompressing to another
 *
 * @param  string  $target_file        File to copy the contents from
 * @param  string  $output_file        File to copy the contents to
 * @param  integer $output_file_offset Output file offset bytes
 * @return void
 */
function leadwerk_migration_copy_gz( $target_file, $output_file, $output_file_offset = 0 ) {
	$target_handle = leadwerk_migration_gzopen( $target_file, 'rb' );
	$output_handle = leadwerk_migration_open( $output_file, 'cb' );
	if ( leadwerk_migration_seek( $output_handle, $output_file_offset, SEEK_SET ) !== -1 ) {
		while ( ( $file_buffer = leadwerk_migration_read( $target_handle, 4096 ) ) ) {
			leadwerk_migration_write( $output_handle, $file_buffer );
		}
	}

	leadwerk_migration_close( $target_handle );
	leadwerk_migration_close( $output_handle );
}


/**
 * Check whether file size is supported by current PHP version
 *
 * @param  string  $file         Path to file
 * @param  integer $php_int_size Size of PHP integer
 * @return boolean $php_int_max  Max value of PHP integer
 */
function leadwerk_migration_is_filesize_supported( $file, $php_int_size = PHP_INT_SIZE, $php_int_max = PHP_INT_MAX ) {
	$size_result = true;

	// Check whether file size is less than 2GB in PHP 32bits
	if ( $php_int_size === 4 ) {
		if ( ( $file_handle = @fopen( $file, 'rb' ) ) ) {
			if ( @fseek( $file_handle, $php_int_max, SEEK_SET ) !== -1 ) {
				if ( @fgetc( $file_handle ) !== false ) {
					$size_result = false;
				}
			}

			@fclose( $file_handle );
		}
	}

	return $size_result;
}

/**
 * Check whether file name is supported by Leadwerk Migration
 *
 * @param  string  $file       Path to file
 * @param  array   $extensions File extensions
 * @return boolean
 */
function leadwerk_migration_is_filename_supported( $file, $extensions = array( 'wpress' ) ) {
	if ( in_array( pathinfo( $file, PATHINFO_EXTENSION ), $extensions ) ) {
		return true;
	}

	return false;
}

/**
 * Check whether file data is supported by Leadwerk Migration
 *
 * @param  string  $file Path to file
 * @return boolean
 */
function leadwerk_migration_is_filedata_supported( $file ) {
	if ( ( $file_handle = @fopen( $file, 'rb' ) ) ) {
		if ( ( $file_buffer = @fread( $file_handle, Leadwerk_Migration_Archiver::HEADER_SIZE ) ) ) {
			if ( ( $file_data = @unpack( 'a255filename/a14size/a12mtime/a4088path/a8crc32', $file_buffer ) ) !== false ) {
				if ( LEADWERK_MIGRATION_PACKAGE_NAME === trim( $file_data['filename'] ) ) {
					return true;
				}
			}
		}

		@fclose( $file_handle );
	}

	return false;
}

/**
 * Check whether gzipped file data is supported by Leadwerk Migration
 *
 * @param  string  $file Path to file
 * @return boolean
 */
function leadwerk_migration_is_gzipped_filedata_supported( $file ) {
	if ( ( $file_handle = @gzopen( $file, 'rb' ) ) ) {
		if ( ( $file_buffer = @gzread( $file_handle, Leadwerk_Migration_Archiver::HEADER_SIZE ) ) ) {
			if ( ( $file_data = @unpack( 'a255filename/a14size/a12mtime/a4088path/a8crc32', $file_buffer ) ) !== false ) {
				if ( LEADWERK_MIGRATION_PACKAGE_NAME === trim( $file_data['filename'] ) ) {
					return true;
				}
			}
		}

		@gzclose( $file_handle );
	}

	return false;
}

/**
 * Verify secret key
 *
 * @param  string  $secret_key Secret key
 * @return boolean
 * @throws Leadwerk_Migration_Not_Valid_Secret_Key_Exception
 */
function leadwerk_migration_verify_secret_key( $secret_key ) {
	$valid = Leadwerk_Migration_Security::verify_secret( $secret_key );
	if ( ! $valid ) {
		foreach ( array( 'export', 'import', 'backups', 'status' ) as $scope ) {
			if ( Leadwerk_Migration_Security::verify_scoped_secret( $secret_key, $scope ) ) {
				$valid = true;
				break;
			}
		}
	}

	if ( ! $valid ) {
		throw new Leadwerk_Migration_Not_Valid_Secret_Key_Exception(
			__( 'Could not authenticate the request credential. The process cannot continue.', 'leadwerk-migration' )
		);
	}

	return true;
}

/**
 * Is scheduled backup?
 *
 * @return boolean
 */
function leadwerk_migration_is_scheduled_backup() {
	if ( isset( $_GET['leadwerk_migration_manual_export'] ) || isset( $_POST['leadwerk_migration_manual_export'] ) ) {
		return false;
	}

	if ( isset( $_GET['leadwerk_migration_manual_import'] ) || isset( $_POST['leadwerk_migration_manual_import'] ) ) {
		return false;
	}

	if ( isset( $_GET['leadwerk_migration_manual_restore'] ) || isset( $_POST['leadwerk_migration_manual_restore'] ) ) {
		return false;
	}

	if ( isset( $_GET['leadwerk_migration_manual_reset'] ) || isset( $_POST['leadwerk_migration_manual_reset'] ) ) {
		return false;
	}

	return true;
}

/**
 * PHP setup environment
 *
 * @return void
 */
function leadwerk_migration_setup_environment() {
	// Set whether a client disconnect should abort script execution
	@ignore_user_abort( true );

	// Set maximum execution time
	@set_time_limit( 0 );

	// Set maximum time in seconds a script is allowed to parse input data
	@ini_set( 'max_input_time', '-1' );

	// Set maximum backtracking steps
	@ini_set( 'pcre.backtrack_limit', PHP_INT_MAX );

	// Set binary safe encoding
	// phpcs:ignore PHPCompatibility.IniDirectives.RemovedIniDirectives
	if ( @function_exists( 'mb_internal_encoding' ) && ( @ini_get( 'mbstring.func_overload' ) & 2 ) ) {
		@mb_internal_encoding( 'ISO-8859-1' );
	}

	// Clean (erase) the output buffer and turn off output buffering
	if ( @ob_get_length() ) {
		@ob_end_clean();
	}
}

/**
 * PHP register error handlers
 *
 * @return void
 */
function leadwerk_migration_setup_errors() {
	@set_error_handler( 'Leadwerk_Migration_Handler::error' );
	@register_shutdown_function( 'Leadwerk_Migration_Handler::shutdown' );
}

/**
 * Get WordPress time zone string
 *
 * @return string
 */
function leadwerk_migration_get_timezone_string() {
	if ( ( $timezone_string = get_option( 'timezone_string' ) ) ) {
		return $timezone_string;
	}

	if ( ( $gmt_offset = get_option( 'gmt_offset' ) ) ) {
		if ( $gmt_offset > 0 ) {
			return sprintf( 'UTC+%s', abs( $gmt_offset ) );
		} elseif ( $gmt_offset < 0 ) {
			return sprintf( 'UTC-%s', abs( $gmt_offset ) );
		}
	}

	return 'UTC';
}

/**
 * Get WordPress filter hooks
 *
 * @param  string $tag The name of the filter hook
 * @return array
 */
function leadwerk_migration_get_filters( $tag ) {
	global $wp_filter;

	// Get WordPress filter hooks
	$filters = array();
	if ( isset( $wp_filter[ $tag ] ) ) {
		if ( ( $filters = $wp_filter[ $tag ] ) ) {
			// WordPress 4.7 introduces new class for working with filters/actions called WP_Hook
			// which adds another level of abstraction and we need to address it.
			if ( isset( $filters->callbacks ) ) {
				$filters = $filters->callbacks;
			}
		}

		ksort( $filters );
	}

	return $filters;
}

/**
 * Get WordPress plugins directories
 *
 * @return array
 */
function leadwerk_migration_get_themes_dirs() {
	$theme_dirs = array();
	foreach ( search_theme_directories() as $theme_name => $theme_info ) {
		if ( isset( $theme_info['theme_root'] ) ) {
			if ( ! in_array( $theme_info['theme_root'], $theme_dirs ) ) {
				$theme_dirs[] = untrailingslashit( $theme_info['theme_root'] );
			}
		}
	}

	return $theme_dirs;
}

/**
 * Get WordPress plugins directory
 *
 * @return string
 */
function leadwerk_migration_get_plugins_dir() {
	return untrailingslashit( WP_PLUGIN_DIR );
}

/**
 * Get WordPress uploads directory
 *
 * @return string
 */
function leadwerk_migration_get_uploads_dir() {
	if ( ( $upload_dir = wp_upload_dir() ) ) {
		if ( isset( $upload_dir['basedir'] ) ) {
			return untrailingslashit( $upload_dir['basedir'] );
		}
	}
}

/**
 * Get WordPress uploads URL
 *
 * @return string
 */
function leadwerk_migration_get_uploads_url() {
	if ( ( $upload_dir = wp_upload_dir() ) ) {
		if ( isset( $upload_dir['baseurl'] ) ) {
			return trailingslashit( $upload_dir['baseurl'] );
		}
	}
}

/**
 * Get WordPress uploads path
 *
 * @return string
 */
function leadwerk_migration_get_uploads_path() {
	if ( ( $upload_dir = wp_upload_dir() ) ) {
		if ( isset( $upload_dir['basedir'] ) ) {
			return str_replace( ABSPATH, '', $upload_dir['basedir'] );
		}
	}
}

/**
 * i18n friendly version of basename()
 *
 * @param  string $path   File path
 * @param  string $suffix If the filename ends in suffix this will also be cut off
 * @return string
 */
function leadwerk_migration_basename( $path, $suffix = '' ) {
	return urldecode( basename( str_replace( array( '%2F', '%5C' ), '/', urlencode( $path ) ), $suffix ) );
}

/**
 * i18n friendly version of dirname()
 *
 * @param  string $path File path
 * @return string
 */
function leadwerk_migration_dirname( $path ) {
	return urldecode( dirname( str_replace( array( '%2F', '%5C' ), '/', urlencode( $path ) ) ) );
}

/**
 * Replace forward slash with current directory separator
 *
 * @param  string $path Path
 * @return string
 */
function leadwerk_migration_replace_forward_slash_with_directory_separator( $path ) {
	return str_replace( '/', DIRECTORY_SEPARATOR, $path );
}

/**
 * Replace current directory separator with forward slash
 *
 * @param  string $path Path
 * @return string
 */
function leadwerk_migration_replace_directory_separator_with_forward_slash( $path ) {
	return str_replace( DIRECTORY_SEPARATOR, '/', $path );
}

/**
 * Escape Windows directory separator
 *
 * @param  string $path Path
 * @return string
 */
function leadwerk_migration_escape_windows_directory_separator( $path ) {
	return preg_replace( '/[\\\\]+/', '\\\\\\\\', $path );
}

/**
 * Should reset WordPress permalinks?
 *
 * @param  array   $params Request parameters
 * @return boolean
 */
function leadwerk_migration_should_reset_permalinks( $params ) {
	global $wp_rewrite, $is_apache;

	// Permalinks are not supported
	if ( empty( $params['using_permalinks'] ) ) {
		if ( $wp_rewrite->using_permalinks() ) {
			if ( $is_apache ) {
				if ( ! apache_mod_loaded( 'mod_rewrite', false ) ) {
					return true;
				}
			}
		}
	}

	return false;
}

/**
 * Get .htaccess file content
 *
 * @return string
 */
function leadwerk_migration_get_htaccess() {
	if ( is_file( LEADWERK_MIGRATION_WORDPRESS_HTACCESS ) ) {
		return @file_get_contents( LEADWERK_MIGRATION_WORDPRESS_HTACCESS );
	}

	return '';
}

/**
 * Get web.config file content
 *
 * @return string
 */
function leadwerk_migration_get_webconfig() {
	if ( is_file( LEADWERK_MIGRATION_WORDPRESS_WEBCONFIG ) ) {
		return @file_get_contents( LEADWERK_MIGRATION_WORDPRESS_WEBCONFIG );
	}

	return '';
}

/**
 * Get available space on filesystem or disk partition
 *
 * @param  string $path Directory of the filesystem or disk partition
 * @return mixed
 */
function leadwerk_migration_disk_free_space( $path ) {
	if ( function_exists( 'disk_free_space' ) ) {
		return @disk_free_space( $path );
	}
}

/**
 * Set response header to json end echo data
 *
 * @param array $data
 * @param int $options
 * @param int $depth
 * @return void
 */
function leadwerk_migration_json_response( $data, $options = 0 ) {
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset', 'utf-8' ) );
	}

	echo json_encode( $data, $options );
}

/**
 * Determines if the server can encrypt backups
 *
 * @return boolean
 */
function leadwerk_migration_can_encrypt() {
	if ( ! function_exists( 'openssl_encrypt' ) ) {
		return false;
	}

	if ( ! function_exists( 'random_bytes' ) && ! function_exists( 'openssl_random_pseudo_bytes' ) ) {
		return false;
	}

	if ( ! function_exists( 'openssl_cipher_iv_length' ) ) {
		return false;
	}

	if ( ! function_exists( 'hash_pbkdf2' ) ) {
		return false;
	}

	if ( ! in_array( 'AES-256-GCM', array_map( 'strtoupper', openssl_get_cipher_methods() ), true ) ) {
		return false;
	}

	return true;
}

/**
 * Determines if the server can decrypt backups
 *
 * @return boolean
 */
function leadwerk_migration_can_decrypt() {
	if ( ! function_exists( 'openssl_decrypt' ) ) {
		return false;
	}

	if ( ! function_exists( 'openssl_cipher_iv_length' ) ) {
		return false;
	}

	if ( ! function_exists( 'hash_pbkdf2' ) || ! function_exists( 'sha1' ) ) {
		return false;
	}

	$ciphers = array_map( 'strtoupper', openssl_get_cipher_methods() );
	if ( ! in_array( LEADWERK_MIGRATION_CIPHER_NAME, $ciphers, true ) || ! in_array( 'AES-256-GCM', $ciphers, true ) ) {
		return false;
	}

	return true;
}

/**
 * Encrypts a string with a key
 *
 * @param string $string String to encrypt
 * @param string $key    Key to encrypt the string with
 * @return string
 * @throws Leadwerk_Migration_Not_Encryptable_Exception
 */
function leadwerk_migration_encrypt_string( $string, $key, $aad_context = 'standalone' ) {
	$magic    = 'LWM3';
	$material = leadwerk_migration_encryption_material( $key );
	$iv       = leadwerk_migration_random_bytes( 12 );
	$tag      = '';
	$aad      = $magic . "\0" . (string) $aad_context;

	// AES-GCM authenticates every encrypted archive chunk before it is parsed.
	$encrypted_string = openssl_encrypt( $string, 'aes-256-gcm', $material['key'], OPENSSL_RAW_DATA, $iv, $tag, $aad, 16 );
	if ( $encrypted_string === false ) {
		throw new Leadwerk_Migration_Not_Encryptable_Exception( esc_html__( 'Could not encrypt data. The process cannot continue.', 'leadwerk-migration' ) );
	}

	return $magic . $material['salt'] . $iv . $tag . $encrypted_string;
}

/**
 * Build the authenticated context for one archive data chunk.
 *
 * @param string $archive_id      Random per-export identifier.
 * @param string $file_name       Normalized archive-relative filename.
 * @param int    $total_size      Total plaintext file size.
 * @param int    $plain_offset    Plaintext chunk offset.
 * @param int    $plain_length    Plaintext chunk length.
 * @return string
 */
function leadwerk_migration_encryption_chunk_aad( $archive_id, $file_name, $total_size, $plain_offset, $plain_length ) {
	$file_name = str_replace( '\\', '/', (string) $file_name );

	return implode(
		"\n",
		array(
			'leadwerk-migration-archive-chunk-v3',
			(string) $archive_id,
			$file_name,
			(string) (int) $total_size,
			(string) (int) $plain_offset,
			(string) (int) $plain_length,
		)
	);
}

/**
 * Encode a bounded, portable frame header for an authenticated chunk.
 * Numeric metadata is covered by the chunk's GCM AAD.
 *
 * @return string
 */
function leadwerk_migration_encryption_frame_header( $cipher_length, $total_size, $plain_offset, $plain_length ) {
	return pack( 'N', (int) $cipher_length ) . sprintf( '%020d%020d%010d', (int) $total_size, (int) $plain_offset, (int) $plain_length );
}

/**
 * Create the password-authenticated manifest appended to a v3 archive.
 * The digest covers every preceding header and payload byte, including
 * package.json and the complete ordered file set.
 *
 * @param string $archive_path Archive before the manifest and EOF block.
 * @param string $password     Archive password.
 * @param string $archive_id   Random per-export identifier.
 * @return string Base64 encoded authenticated manifest.
 */
function leadwerk_migration_create_encrypted_archive_manifest( $archive_path, $password, $archive_id ) {
	$covered_bytes = @filesize( $archive_path );
	$digest         = @hash_file( 'sha256', $archive_path );
	if ( $covered_bytes === false || ! is_string( $digest ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $digest ) ) {
		throw new Leadwerk_Migration_Not_Encryptable_Exception( esc_html__( 'Could not calculate the authenticated archive manifest.', 'leadwerk-migration' ) );
	}

	$manifest = array(
		'version'       => 3,
		'format'        => LEADWERK_MIGRATION_ENCRYPTION_FORMAT,
		'archive_id'    => $archive_id,
		'covered_bytes' => (int) $covered_bytes,
		'sha256'        => strtolower( $digest ),
	);
	$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $manifest ) : json_encode( $manifest );
	if ( ! is_string( $json ) ) {
		throw new Leadwerk_Migration_Not_Encryptable_Exception( esc_html__( 'Could not encode the authenticated archive manifest.', 'leadwerk-migration' ) );
	}

	return base64_encode( leadwerk_migration_encrypt_string( $json, $password, 'archive-manifest|' . $archive_id ) );
}

/**
 * Hash an exact prefix without trusting archive-controlled lengths.
 *
 * @param string $path   File path.
 * @param int    $length Prefix byte count.
 * @return string|false
 */
function leadwerk_migration_hash_file_prefix( $path, $length ) {
	$length = (int) $length;
	$handle = @fopen( $path, 'rb' );
	if ( $handle === false || $length < 0 ) {
		return false;
	}

	$context   = hash_init( 'sha256' );
	$remaining = $length;
	while ( $remaining > 0 ) {
		$chunk = @fread( $handle, min( 1024 * 1024, $remaining ) );
		if ( $chunk === false || $chunk === '' ) {
			@fclose( $handle );
			return false;
		}
		hash_update( $context, $chunk );
		$remaining -= strlen( $chunk );
	}
	@fclose( $handle );

	return hash_final( $context );
}

/**
 * Verify the keyed whole-archive manifest before any destructive extraction.
 *
 * @param string $archive_path Archive path.
 * @param array  $package      Parsed package.json.
 * @param string $password     Archive password.
 * @return true
 * @throws Leadwerk_Migration_Not_Decryptable_Exception
 */
function leadwerk_migration_verify_encrypted_archive_manifest( $archive_path, $package, $password ) {
	$format     = isset( $package['EncryptionFormat'] ) ? $package['EncryptionFormat'] : null;
	$archive_id = isset( $package['EncryptionArchiveId'] ) ? $package['EncryptionArchiveId'] : '';
	if ( $format !== LEADWERK_MIGRATION_ENCRYPTION_FORMAT ) {
		return true;
	}
	if ( ! is_string( $archive_id ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $archive_id ) ) {
		throw new Leadwerk_Migration_Not_Decryptable_Exception( esc_html__( 'The authenticated archive identifier is invalid.', 'leadwerk-migration' ) );
	}

	$archive = new Leadwerk_Migration_Extractor( $archive_path );
	$files   = $archive->list_files();
	$archive->close();
	if ( empty( $files ) ) {
		throw new Leadwerk_Migration_Not_Decryptable_Exception( esc_html__( 'The authenticated archive manifest is missing.', 'leadwerk-migration' ) );
	}

	$manifest_file = end( $files );
	if ( ! is_array( $manifest_file ) || $manifest_file['filename'] !== LEADWERK_MIGRATION_AUTH_MANIFEST_NAME ) {
		throw new Leadwerk_Migration_Not_Decryptable_Exception( esc_html__( 'The authenticated archive manifest must be the final archive entry.', 'leadwerk-migration' ) );
	}

	$manifest_size = isset( $manifest_file['size'] ) ? (int) $manifest_file['size'] : 0;
	$manifest_at   = isset( $manifest_file['offset'] ) ? (int) $manifest_file['offset'] : -1;
	$archive_size  = @filesize( $archive_path );
	if ( $manifest_size < 1 || $manifest_size > 65536 || $manifest_at < 0 || $archive_size === false || $archive_size !== $manifest_at + Leadwerk_Migration_Archiver::HEADER_SIZE + $manifest_size + Leadwerk_Migration_Archiver::HEADER_SIZE ) {
		throw new Leadwerk_Migration_Not_Decryptable_Exception( esc_html__( 'The authenticated archive manifest framing is invalid.', 'leadwerk-migration' ) );
	}

	$handle = @fopen( $archive_path, 'rb' );
	if ( $handle === false || @fseek( $handle, $manifest_at + Leadwerk_Migration_Archiver::HEADER_SIZE, SEEK_SET ) === -1 ) {
		throw new Leadwerk_Migration_Not_Decryptable_Exception( esc_html__( 'The authenticated archive manifest could not be read.', 'leadwerk-migration' ) );
	}
	$encoded = '';
	while ( strlen( $encoded ) < $manifest_size ) {
		$chunk = @fread( $handle, $manifest_size - strlen( $encoded ) );
		if ( $chunk === false || $chunk === '' ) {
			@fclose( $handle );
			throw new Leadwerk_Migration_Not_Decryptable_Exception( esc_html__( 'The authenticated archive manifest is truncated.', 'leadwerk-migration' ) );
		}
		$encoded .= $chunk;
	}
	@fclose( $handle );

	$encrypted = base64_decode( $encoded, true );
	if ( $encrypted === false ) {
		throw new Leadwerk_Migration_Not_Decryptable_Exception( esc_html__( 'The authenticated archive manifest encoding is invalid.', 'leadwerk-migration' ) );
	}
	$json     = leadwerk_migration_decrypt_string( $encrypted, $password, 'archive-manifest|' . $archive_id );
	$manifest = json_decode( $json, true );
	if (
		! is_array( $manifest ) ||
		! isset( $manifest['version'], $manifest['format'], $manifest['archive_id'], $manifest['covered_bytes'], $manifest['sha256'] ) ||
		(int) $manifest['version'] !== 3 ||
		$manifest['format'] !== LEADWERK_MIGRATION_ENCRYPTION_FORMAT ||
		$manifest['archive_id'] !== $archive_id ||
		(int) $manifest['covered_bytes'] !== $manifest_at ||
		! is_string( $manifest['sha256'] ) ||
		! preg_match( '/\A[a-f0-9]{64}\z/D', $manifest['sha256'] )
	) {
		throw new Leadwerk_Migration_Not_Decryptable_Exception( esc_html__( 'The authenticated archive manifest is invalid.', 'leadwerk-migration' ) );
	}

	$actual = leadwerk_migration_hash_file_prefix( $archive_path, $manifest_at );
	if ( ! is_string( $actual ) || ! hash_equals( strtolower( $manifest['sha256'] ), strtolower( $actual ) ) ) {
		throw new Leadwerk_Migration_Not_Decryptable_Exception( esc_html__( 'Archive authentication failed. The archive headers or contents have changed.', 'leadwerk-migration' ) );
	}

	return true;
}

/**
 * Detect the v3 final manifest without trusting package.json flags.
 *
 * @param string $archive_path Archive path.
 * @return boolean
 */
function leadwerk_migration_archive_has_auth_manifest( $archive_path ) {
	try {
		$archive = new Leadwerk_Migration_Extractor( $archive_path );
		$files   = $archive->list_files();
		$archive->close();
	} catch ( Exception $error ) {
		return false;
	}

	if ( empty( $files ) ) {
		return false;
	}
	$last = end( $files );

	return is_array( $last ) && isset( $last['filename'] ) && $last['filename'] === LEADWERK_MIGRATION_AUTH_MANIFEST_NAME;
}

/**
 * Build request-cached PBKDF2 material for authenticated archive encryption.
 *
 * A random salt is reused only for chunks encrypted in this PHP request. Every
 * chunk still receives a unique 96-bit GCM nonce. Imports derive keys from the
 * salt embedded in each chunk.
 *
 * @param string $password Archive password.
 * @return array{salt:string,key:string}
 * @throws Leadwerk_Migration_Not_Encryptable_Exception
 */
function leadwerk_migration_encryption_material( $password ) {
	static $cache = array();

	$cache_key = hash( 'sha256', (string) $password );
	if ( ! isset( $cache[ $cache_key ] ) ) {
		$salt = leadwerk_migration_random_bytes( 16 );
		$key  = hash_pbkdf2( 'sha256', (string) $password, $salt, 120000, 32, true );
		if ( ! is_string( $key ) || strlen( $key ) !== 32 ) {
			throw new Leadwerk_Migration_Not_Encryptable_Exception( esc_html__( 'Could not derive the archive encryption key.', 'leadwerk-migration' ) );
		}

		$cache[ $cache_key ] = array( 'salt' => $salt, 'key' => $key );
	}

	return $cache[ $cache_key ];
}

/**
 * Generate cryptographically secure bytes with a compatibility fallback.
 *
 * @param int $length Byte count.
 * @return string
 * @throws Leadwerk_Migration_Not_Encryptable_Exception
 */
function leadwerk_migration_random_bytes( $length ) {
	try {
		if ( function_exists( 'random_bytes' ) ) {
			return random_bytes( $length );
		}
	} catch ( Exception $error ) {
		// Try OpenSSL below.
	}

	$bytes = function_exists( 'openssl_random_pseudo_bytes' ) ? openssl_random_pseudo_bytes( $length ) : false;
	if ( ! is_string( $bytes ) || strlen( $bytes ) !== $length ) {
		throw new Leadwerk_Migration_Not_Encryptable_Exception( esc_html__( 'Could not generate secure random bytes.', 'leadwerk-migration' ) );
	}

	return $bytes;
}

/**
 * Returns encrypt/decrypt iv length
 *
 * @return int
 * @throws Leadwerk_Migration_Not_Encryptable_Exception
 */
function leadwerk_migration_crypt_iv_length() {
	$iv_length = openssl_cipher_iv_length( LEADWERK_MIGRATION_CIPHER_NAME );
	if ( $iv_length === false ) {
		throw new Leadwerk_Migration_Not_Encryptable_Exception( esc_html__( 'Could not obtain cipher length. The process cannot continue.', 'leadwerk-migration' ) );
	}

	return $iv_length;
}

/**
 * Decrypts a string with a key
 *
 * @param string $encrypted_string String to decrypt
 * @param string $key              Key to decrypt the string with
 * @return string
 * @throws Leadwerk_Migration_Not_Encryptable_Exception
 * @throws Leadwerk_Migration_Not_Decryptable_Exception
 */
function leadwerk_migration_decrypt_string( $encrypted_string, $key, $aad_context = 'standalone' ) {
	$magic_prefix = is_string( $encrypted_string ) ? substr( $encrypted_string, 0, 4 ) : '';
	if ( $magic_prefix === 'LWM2' || $magic_prefix === 'LWM3' ) {
		if ( strlen( $encrypted_string ) < 48 ) {
			throw new Leadwerk_Migration_Not_Decryptable_Exception( esc_html__( 'The encrypted data is truncated.', 'leadwerk-migration' ) );
		}

		$magic      = substr( $encrypted_string, 0, 4 );
		$salt       = substr( $encrypted_string, 4, 16 );
		$iv         = substr( $encrypted_string, 20, 12 );
		$tag        = substr( $encrypted_string, 32, 16 );
		$ciphertext = substr( $encrypted_string, 48 );
		$derived    = leadwerk_migration_decryption_key( $key, $salt );
		$aad        = $magic === 'LWM3' ? $magic . "\0" . (string) $aad_context : $magic;

		$decrypted_string = openssl_decrypt( $ciphertext, 'aes-256-gcm', $derived, OPENSSL_RAW_DATA, $iv, $tag, $aad );
		if ( $decrypted_string === false ) {
			throw new Leadwerk_Migration_Not_Decryptable_Exception( esc_html__( 'Encrypted data authentication failed. The archive may be damaged or the password is incorrect.', 'leadwerk-migration' ) );
		}

		return $decrypted_string;
	}

	// Backward-compatible reader for legacy upstream AES-CBC archives. New
	// Leadwerk exports never write this unauthenticated format.
	$iv_length = leadwerk_migration_crypt_iv_length();
	$key       = substr( sha1( $key, true ), 0, $iv_length );
	$iv        = substr( $encrypted_string, 0, $iv_length );

	// phpcs:ignore PHPCompatibility.Constants.NewConstants, PHPCompatibility.FunctionUse.NewFunctionParameters
	$decrypted_string = openssl_decrypt( substr( $encrypted_string, $iv_length ), LEADWERK_MIGRATION_CIPHER_NAME, $key, OPENSSL_RAW_DATA, $iv );
	if ( $decrypted_string === false ) {
		throw new Leadwerk_Migration_Not_Decryptable_Exception( esc_html__( 'Could not decrypt data. The process cannot continue.', 'leadwerk-migration' ) );
	}

	return $decrypted_string;
}

/**
 * Derive and cache a GCM key for chunks that share an export-request salt.
 *
 * @param string $password Archive password.
 * @param string $salt     Sixteen-byte salt embedded in the chunk.
 * @return string
 * @throws Leadwerk_Migration_Not_Decryptable_Exception
 */
function leadwerk_migration_decryption_key( $password, $salt ) {
	static $cache = array();

	$cache_key = hash( 'sha256', $salt . "\0" . (string) $password );
	if ( ! isset( $cache[ $cache_key ] ) ) {
		$derived = hash_pbkdf2( 'sha256', (string) $password, $salt, 120000, 32, true );
		if ( ! is_string( $derived ) || strlen( $derived ) !== 32 ) {
			throw new Leadwerk_Migration_Not_Decryptable_Exception( esc_html__( 'Could not derive the archive decryption key.', 'leadwerk-migration' ) );
		}

		$cache[ $cache_key ] = $derived;
	}

	return $cache[ $cache_key ];
}

/**
 * Checks if decryption password is valid
 *
 * @param string $encrypted_signature
 * @param string $password
 * @return bool
 */
function leadwerk_migration_is_decryption_password_valid( $encrypted_signature, $password, $encryption_format = null, $archive_id = '' ) {
	try {
		$encrypted_signature = base64_decode( $encrypted_signature, true );
		if ( $encrypted_signature === false ) {
			return false;
		}

		if ( $encryption_format === LEADWERK_MIGRATION_ENCRYPTION_FORMAT ) {
			if ( ! is_string( $archive_id ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $archive_id ) ) {
				return false;
			}

			$expected = LEADWERK_MIGRATION_SIGN_TEXT . "\0" . $archive_id;
			return leadwerk_migration_decrypt_string( $encrypted_signature, $password, 'signature|' . $archive_id ) === $expected;
		}

		return leadwerk_migration_decrypt_string( $encrypted_signature, $password ) === LEADWERK_MIGRATION_SIGN_TEXT;
	} catch ( Leadwerk_Migration_Not_Decryptable_Exception $exception ) {
		return false;
	}
}

function leadwerk_migration_populate_roles() {
	if ( ! function_exists( 'populate_roles' ) && ! function_exists( 'populate_options' ) && ! function_exists( 'populate_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/schema.php';
	}

	if ( function_exists( 'populate_roles' ) ) {
		populate_roles();
	}
}

/**
 * Store a validated Basic Auth header for this PHP request only.
 *
 * @param string $header Authorization header.
 * @return void
 */
function leadwerk_migration_set_auth_header( $header ) {
	if ( is_string( $header ) && preg_match( '/\ABasic [A-Za-z0-9+\/=]+\z/D', $header ) ) {
		$GLOBALS['leadwerk_migration_request_auth_header'] = $header;
	}
}

/**
 * Set the in-memory Basic Auth header on a same-site loopback request.
 *
 * @param array $headers Request headers.
 * @return array
 */
function leadwerk_migration_auth_headers( $headers = array() ) {
	if ( ! isset( $headers['Authorization'] ) && ! empty( $GLOBALS['leadwerk_migration_request_auth_header'] ) ) {
		$headers['Authorization'] = $GLOBALS['leadwerk_migration_request_auth_header'];
	}

	return $headers;
}

/**
 * Check if direct download of backup supported
 *
 * @return bool
 */
function leadwerk_migration_direct_download_supported() {
	return true;
}

/**
 * Get allowed HTML tags when output with `wp_kses()`
 *
 * @return array
 */
function leadwerk_migration_allowed_html_tags() {
	return array(
		'a'      => array(
			'href'       => array(),
			'title'      => array(),
			'target'     => array(),
			'id'         => array(),
			'name'       => array(),
			'aria-label' => array(),
			'class'      => array(),
			'style'      => array(),
			'disabled'   => array(),
			'download'   => array(),
		),
		'p'      => array(
			'class' => array(),
			'style' => array(),
		),
		'br'     => array(),
		'em'     => array(),
		'h3'     => array(),
		'i'      => array(
			'class'       => array(),
			'aria-hidden' => array(),
			'aria-label'  => array(),
		),
		'small'  => array(),
		'strong' => array(),
		'input'  => array(
			'type'       => array(),
			'name'       => array(),
			'aria-label' => array(),
			'style'      => array(),
			'id'         => array(),
			'value'      => array(),
			'class'      => array(),
			'disabled'   => array(),
		),
	);
}

/**
 * Wrapper for wp_register_style function
 *
 * @param string $handle Name of the stylesheet
 * @param string $src    Path of the stylesheet
 * @param array  $deps   An array of registered stylesheet handles this stylesheet depends on
 * @param mixed  $ver    String specifying stylesheet version number
 * @param string $media  The media for which this stylesheet has been defined
 *
 * @return bool
 */
function leadwerk_migration_register_style( $handle, $src, $deps = array(), $ver = false, $media = 'all' ) {
	if ( is_rtl() ) {
		$src = str_replace( '.min.css', '.min.rtl.css', $src );
	}

	return wp_register_style( $handle, $src, $deps, $ver, $media );
}

/**
 * Wrapper for wp_enqueue_style function
 *
 * @param string $handle Name of the stylesheet
 * @param string $src    Path of the stylesheet
 * @param array  $deps   An array of registered stylesheet handles this stylesheet depends on
 * @param mixed  $ver    String specifying stylesheet version number
 * @param string $media  The media for which this stylesheet has been defined
 *
 * @return void
 */
function leadwerk_migration_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	if ( is_rtl() ) {
		$src = str_replace( '.min.css', '.min.rtl.css', $src );
	}

	wp_enqueue_style( $handle, $src, $deps, $ver, $media );
}

/**
 * Wrapper for wp_register_script function
 *
 * @param string $handle Name of the script
 * @param string $src    Path of the script
 * @param array  $deps   An array of registered script handles this script depends on
 * @param mixed  $ver    String specifying script version number
 * @param mixed  $args   An array of additional script loading strategies
 *
 * @return bool
 */
function leadwerk_migration_register_script( $handle, $src, $deps = array(), $ver = false, $args = array() ) {
	return wp_register_script( $handle, $src, $deps, $ver, $args );
}

/**
 * Wrapper for wp_enqueue_script function
 *
 * @param string $handle Name of the script
 * @param string $src    Path of the script
 * @param array  $deps   An array of registered script handles this script depends on
 * @param mixed  $ver    String specifying script version number
 * @param mixed  $args   An array of additional script loading strategies
 *
 * @return void
 */
function leadwerk_migration_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
	wp_enqueue_script( $handle, $src, $deps, $ver, $args );
}

/**
 * Check for compression type availability
 *
 * @param  string  $name Compression type
 * @return boolean
 */
function leadwerk_migration_has_compression_type( $name ) {
	switch ( strtolower( $name ) ) {
		case 'gzip':
			return function_exists( 'gzcompress' );

		case 'bzip2':
			return function_exists( 'bzcompress' );

		default:
			return false;
	}
}
