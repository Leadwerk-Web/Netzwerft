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

class Leadwerk_Migration_Export_Database {

	public static function execute( $params ) {

		// Set exclude database
		if ( isset( $params['options']['no_database'] ) ) {
			return $params;
		}

		// Set query offset
		if ( isset( $params['query_offset'] ) ) {
			$query_offset = (int) $params['query_offset'];
		} else {
			$query_offset = 0;
		}

		// Set table index
		if ( isset( $params['table_index'] ) ) {
			$table_index = (int) $params['table_index'];
		} else {
			$table_index = 0;
		}

		if ( 0 === $query_offset && 0 === $table_index ) {
			Leadwerk_Migration_Mail_Smtp::write_export_sidecar( $params );
		}

		// Set table offset
		if ( isset( $params['table_offset'] ) ) {
			$table_offset = (int) $params['table_offset'];
		} else {
			$table_offset = 0;
		}

		// Set table rows
		if ( isset( $params['table_rows'] ) ) {
			$table_rows = (int) $params['table_rows'];
		} else {
			$table_rows = 0;
		}

		// Set total tables count
		if ( isset( $params['total_tables_count'] ) ) {
			$total_tables_count = (int) $params['total_tables_count'];
		} else {
			$total_tables_count = 1;
		}

		// What percent of tables have we processed?
		$progress = (int) ( ( $table_index / $total_tables_count ) * 100 );

		// Set progress
		/* translators: 1: Progress, 2: Number of records. */
		Leadwerk_Migration_Status::info( sprintf( __( 'Exporting database...<br />%1$d%% complete<br />%2$s records saved', 'leadwerk-migration' ), $progress, number_format_i18n( $table_rows ) ) );

		// Get tables list file
		$tables_list = leadwerk_migration_open( leadwerk_migration_tables_list_path( $params ), 'r' );

		// Loop over tables
		$tables = array();
		while ( ( $row = leadwerk_migration_getcsv( $tables_list ) ) !== false ) {
			list( $table_name ) = $row;
			$tables[] = $table_name; // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning
		}

		// Close the tables list file
		leadwerk_migration_close( $tables_list );

		// Get database client
		$db_client = Leadwerk_Migration_Database_Utility::get_client();

		// Exclude spam comments
		if ( isset( $params['options']['no_spam_comments'] ) ) {
			$db_client->set_table_where_query( leadwerk_migration_table_prefix() . 'comments', "`comment_approved` != 'spam'" )
				->set_table_where_query( leadwerk_migration_table_prefix() . 'commentmeta', sprintf( "`comment_ID` IN ( SELECT `comment_ID` FROM `%s` WHERE `comment_approved` != 'spam' )", leadwerk_migration_table_prefix() . 'comments' ) );
		}

		// Exclude post revisions
		if ( isset( $params['options']['no_post_revisions'] ) ) {
			$db_client->set_table_where_query( leadwerk_migration_table_prefix() . 'posts', "`post_type` != 'revision'" )
				->set_table_where_query( leadwerk_migration_table_prefix() . 'postmeta', sprintf( "`post_id` IN ( SELECT `ID` FROM `%s` WHERE `post_type` != 'revision' )", leadwerk_migration_table_prefix() . 'posts' ) );
		}

		$old_table_prefixes = $old_column_prefixes = array();
		$new_table_prefixes = $new_column_prefixes = array();

		// Set table prefixes
		if ( leadwerk_migration_table_prefix() ) {
			$old_table_prefixes[] = leadwerk_migration_table_prefix();
			$new_table_prefixes[] = leadwerk_migration_servmask_prefix();
		} else {
			foreach ( $tables as $table_name ) {
				$old_table_prefixes[] = $table_name;
				$new_table_prefixes[] = leadwerk_migration_servmask_prefix() . $table_name;
			}
		}

		// Set column prefixes
		if ( strlen( leadwerk_migration_table_prefix() ) > 1 ) {
			$old_column_prefixes[] = leadwerk_migration_table_prefix();
			$new_column_prefixes[] = leadwerk_migration_servmask_prefix();
		} else {
			foreach ( array( 'user_roles', 'capabilities', 'user_level', 'dashboard_quick_press_last_post_id', 'user-settings', 'user-settings-time' ) as $column_prefix ) {
				$old_column_prefixes[] = leadwerk_migration_table_prefix() . $column_prefix;
				$new_column_prefixes[] = leadwerk_migration_servmask_prefix() . $column_prefix;
			}
		}

		$db_client->set_tables( $tables )
			->set_old_table_prefixes( $old_table_prefixes )
			->set_new_table_prefixes( $new_table_prefixes )
			->set_old_column_prefixes( $old_column_prefixes )
			->set_new_column_prefixes( $new_column_prefixes );

		// Exclude column prefixes. WP Mail SMTP option names start with wp_ but
		// are plugin keys, not table-prefix columns; rewriting them or their
		// Sodium encryption key makes settings unreadable after import.
		$db_client->set_reserved_column_prefixes( Leadwerk_Migration_Mail_Smtp::reserved_column_prefixes() );

		// Exclude site options that are private to the installation or represent
		// transient operational state. Import restores the target installation's
		// local values, including when an older archive contains these options.
		$excluded_site_options = array(
			LEADWERK_MIGRATION_STATUS,
			LEADWERK_MIGRATION_SECRET_KEY,
			LEADWERK_MIGRATION_AUTH_USER,
			LEADWERK_MIGRATION_AUTH_PASSWORD,
			LEADWERK_MIGRATION_AUTH_HEADER,
			LEADWERK_MIGRATION_BACKUPS_LABELS,
			LEADWERK_MIGRATION_SITES_LINKS,
			LEADWERK_MIGRATION_PRIVATE_ROOT_OPTION,
			LEADWERK_MIGRATION_BACKUPS_PATH_OPTION,
			LEADWERK_MIGRATION_AUTOMATION_SETTINGS,
			LEADWERK_MIGRATION_AUTOMATION_LOCK,
			LEADWERK_MIGRATION_BACKUP_MANIFESTS,
			LEADWERK_MIGRATION_ACTIVITY_LOG,
			LEADWERK_MIGRATION_HEALTH_CACHE,
		);

		$db_client->set_table_where_query(
			leadwerk_migration_table_prefix() . 'options',
			sprintf(
				"`option_name` NOT IN ('%s') AND `option_name` NOT LIKE 'leadwerk_migration_status_%%' AND `option_name` NOT LIKE '_transient_leadwerk_migration_%%' AND `option_name` NOT LIKE '_transient_timeout_leadwerk_migration_%%' AND `option_name` NOT LIKE 'wp_mail_smtp%%'",
				implode( "', '", $excluded_site_options )
			)
		);

		// Set table select columns
		if ( ( $column_names = $db_client->get_column_names( leadwerk_migration_table_prefix() . 'options' ) ) ) {
			if ( isset( $column_names['option_name'], $column_names['option_value'] ) ) {
				$column_names['option_value'] = sprintf( "(CASE WHEN option_name = '%s' THEN 'a:0:{}' WHEN (option_name = '%s' OR option_name = '%s') THEN '' ELSE option_value END) AS option_value", LEADWERK_MIGRATION_ACTIVE_PLUGINS, LEADWERK_MIGRATION_ACTIVE_TEMPLATE, LEADWERK_MIGRATION_ACTIVE_STYLESHEET );
			}

			$db_client->set_table_select_columns( leadwerk_migration_table_prefix() . 'options', $column_names );
		}

		// Set table prefix columns
		$db_client->set_table_prefix_columns( leadwerk_migration_table_prefix() . 'options', array( 'option_name' ) )
			->set_table_prefix_columns( leadwerk_migration_table_prefix() . 'usermeta', array( 'meta_key' ) );

		// Export database
		if ( $db_client->export( leadwerk_migration_database_path( $params ), $query_offset, $table_index, $table_offset, $table_rows ) ) {

			// Set progress
			Leadwerk_Migration_Status::info( __( 'Database exported.', 'leadwerk-migration' ) );

			// Unset query offset
			unset( $params['query_offset'] );

			// Unset table index
			unset( $params['table_index'] );

			// Unset table offset
			unset( $params['table_offset'] );

			// Unset table rows
			unset( $params['table_rows'] );

			// Unset total tables count
			unset( $params['total_tables_count'] );

			// Unset completed flag
			unset( $params['completed'] );

		} else {

			// What percent of tables have we processed?
			$progress = (int) ( ( $table_index / $total_tables_count ) * 100 );

			// Set progress
			/* translators: 1: Progress, 2: Number of records. */
			Leadwerk_Migration_Status::info( sprintf( __( 'Exporting database...<br />%1$d%% complete<br />%2$s records saved', 'leadwerk-migration' ), $progress, number_format_i18n( $table_rows ) ) );

			// Set query offset
			$params['query_offset'] = $query_offset;

			// Set table index
			$params['table_index'] = $table_index;

			// Set table offset
			$params['table_offset'] = $table_offset;

			// Set table rows
			$params['table_rows'] = $table_rows;

			// Set total tables count
			$params['total_tables_count'] = $total_tables_count;

			// Set completed flag
			$params['completed'] = false;
		}

		return $params;
	}
}
