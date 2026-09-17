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

<div class="leadwerk_migration-field-set">
	<div class="leadwerk_migration-buttons">
		<div class="leadwerk_migration-button-group leadwerk_migration-button-export leadwerk_migration-expandable">
			<div class="leadwerk_migration-button-main">
				<span role="list" aria-label="<?php esc_attr_e( 'Export Site To', 'leadwerk-migration' ); ?>"><?php esc_html_e( 'Export Site To', 'leadwerk-migration' ); ?></span>
				<span class="leadwerk_migration-lines">
					<span class="leadwerk_migration-line leadwerk_migration-line-first"></span>
					<span class="leadwerk_migration-line leadwerk_migration-line-second"></span>
					<span class="leadwerk_migration-line leadwerk_migration-line-third"></span>
				</span>
			</div>
			<ul class="leadwerk_migration-dropdown-menu leadwerk_migration-export-providers">
				<?php foreach ( apply_filters( 'leadwerk_migration_export_buttons', array() ) as $button ) : ?>
					<li>
						<?php echo wp_kses( $button, leadwerk_migration_allowed_html_tags() ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
</div>
