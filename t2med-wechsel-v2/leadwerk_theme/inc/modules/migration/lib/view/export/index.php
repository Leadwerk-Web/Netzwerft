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

<div class="leadwerk_migration-container">
	<div class="leadwerk_migration-row">
		<div class="leadwerk_migration-left">
			<div class="leadwerk_migration-holder">
				<h1>
					<i class="leadwerk_migration-icon-export"></i>
					<?php esc_html_e( 'Export Site', 'leadwerk-migration' ); ?>
				</h1>

				<?php if ( is_readable( LEADWERK_MIGRATION_STORAGE_PATH ) && is_writable( LEADWERK_MIGRATION_STORAGE_PATH ) ) : ?>

					<form action="" method="post" id="leadwerk_migration-export-form" class="leadwerk_migration-clear">
						<?php wp_nonce_field( 'leadwerk_migration_export', '_leadwerk_migration_nonce', false ); ?>
						<input type="hidden" name="priority" value="5" />

						<?php require_once LEADWERK_MIGRATION_TEMPLATES_PATH . '/export/find-replace.php'; ?>

						<?php do_action( 'leadwerk_migration_export_left_options' ); ?>

						<?php require_once LEADWERK_MIGRATION_TEMPLATES_PATH . '/export/advanced-settings.php'; ?>

						<?php require_once LEADWERK_MIGRATION_TEMPLATES_PATH . '/export/export-buttons.php'; ?>

						<input type="hidden" name="leadwerk_migration_manual_export" value="1" />

					</form>

					<?php do_action( 'leadwerk_migration_export_left_end' ); ?>

				<?php else : ?>

					<?php require_once LEADWERK_MIGRATION_TEMPLATES_PATH . '/export/export-permissions.php'; ?>

				<?php endif; ?>
			</div>
		</div>

		<?php require_once LEADWERK_MIGRATION_TEMPLATES_PATH . '/common/sidebar-right.php'; ?>

	</div>
</div>
