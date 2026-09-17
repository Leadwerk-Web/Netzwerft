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

<div class="leadwerk_migration-import-messages"></div>

<div class="leadwerk_migration-import-form">
	<div class="hide-if-no-js">
		<div class="leadwerk_migration-drag-drop-area" id="leadwerk_migration-drag-drop-area">
			<div id="leadwerk_migration-import-init">
				<p>
					<i class="leadwerk_migration-icon-cloud-upload"></i><br />
					<?php esc_html_e( 'Drag & Drop a backup to import it', 'leadwerk-migration' ); ?>
				</p>
				<div class="leadwerk_migration-button-group leadwerk_migration-button-import leadwerk_migration-expandable">
					<div class="leadwerk_migration-button-main">
						<span role="list" aria-label="<?php esc_attr_e( 'Import From', 'leadwerk-migration' ); ?>"><?php esc_html_e( 'Import From', 'leadwerk-migration' ); ?></span>
						<span class="leadwerk_migration-lines">
							<span class="leadwerk_migration-line leadwerk_migration-line-first"></span>
							<span class="leadwerk_migration-line leadwerk_migration-line-second"></span>
							<span class="leadwerk_migration-line leadwerk_migration-line-third"></span>
						</span>
					</div>
					<ul class="leadwerk_migration-dropdown-menu leadwerk_migration-import-providers">
						<?php foreach ( apply_filters( 'leadwerk_migration_import_buttons', array() ) as $button ) : ?>
							<li>
								<?php echo wp_kses( $button, leadwerk_migration_allowed_html_tags() ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
		</div>
	</div>
</div>

<p>
	<?php esc_html_e( 'Maximum upload file size:', 'leadwerk-migration' ); ?>
	<?php if ( ( $max_file_size = apply_filters( 'leadwerk_migration_max_file_size', LEADWERK_MIGRATION_MAX_FILE_SIZE ) ) ) : ?>
		<span class="leadwerk_migration-max-upload-size"><?php echo esc_html( leadwerk_migration_size_format( $max_file_size ) ); ?></span>
	<?php else : ?>
		<span class="leadwerk_migration-max-upload-size"><?php esc_html_e( 'Unlimited', 'leadwerk-migration' ); ?></span>
	<?php endif; ?>
</p>
