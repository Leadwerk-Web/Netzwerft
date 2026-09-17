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
?>

<ul id="leadwerk_migration-queries">
	<li class="leadwerk_migration-query leadwerk_migration-expandable">
		<p>
			<span>
				<strong><?php esc_html_e( 'Search for', 'leadwerk-migration' ); ?></strong>
				<small class="leadwerk_migration-query-find-text leadwerk_migration-tooltip" title="<?php esc_attr_e( 'Search the database for this text', 'leadwerk-migration' ); ?>"> <?php echo esc_html( __( '<text>', 'leadwerk-migration' ) ); ?> </small>
				<strong><?php esc_html_e( 'Replace with', 'leadwerk-migration' ); ?></strong>
				<small class="leadwerk_migration-query-replace-text leadwerk_migration-tooltip" title="<?php esc_attr_e( 'Replace the database with this text', 'leadwerk-migration' ); ?>"> <?php echo esc_html( __( '<another-text>', 'leadwerk-migration' ) ); ?> </small>
				<strong><?php esc_html_e( 'in the database', 'leadwerk-migration' ); ?></strong>
			</span>
			<span class="leadwerk_migration-query-arrow leadwerk_migration-icon-chevron-right"></span>
		</p>
		<div>
			<input class="leadwerk_migration-query-find-input" type="text" placeholder="<?php esc_attr_e( 'Search for', 'leadwerk-migration' ); ?>" name="options[replace][old_value][]" />
			<input class="leadwerk_migration-query-replace-input" type="text" placeholder="<?php esc_attr_e( 'Replace with', 'leadwerk-migration' ); ?>" name="options[replace][new_value][]" />
		</div>
	</li>
</ul>

<button type="button" class="leadwerk_migration-button-gray" id="leadwerk_migration-add-new-replace-button">
	<i class="leadwerk_migration-icon-plus2"></i>
	<?php esc_html_e( 'Add', 'leadwerk-migration' ); ?>
</button>
