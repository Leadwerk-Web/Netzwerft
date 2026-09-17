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

if ( $should_reset_permalinks ) {
	echo wp_kses( __( '» WordPress reset the permalink structure to its default during the migration.<br />', 'leadwerk-migration' ), leadwerk_migration_allowed_html_tags() );
} else {
	echo wp_kses(
		/* translators: Url */
		sprintf( __( '» <a class="leadwerk_migration-no-underline" href="%s">Save the permalink structure</a> to refresh rewrite rules.<br />', 'leadwerk-migration' ), admin_url( 'options-permalink.php#submit' ) ),
		leadwerk_migration_allowed_html_tags()
	);
}

if ( leadwerk_migration_validate_plugin_basename( 'oxygen/functions.php' ) ) {
	echo wp_kses( __( '» Re-sign Oxygen Builder shortcodes and clear its generated CSS cache.<br />', 'leadwerk-migration' ), leadwerk_migration_allowed_html_tags() );
}

echo wp_kses( __( '» Clear the Avada Builder caches and regenerate its compiled assets.<br />', 'leadwerk-migration' ), leadwerk_migration_allowed_html_tags() );
echo wp_kses( __( '» Verify critical pages before reopening the site to visitors.', 'leadwerk-migration' ), leadwerk_migration_allowed_html_tags() );
