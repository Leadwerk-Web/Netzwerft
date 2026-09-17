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

<?php if ( $backups ) : ?>
	<form action="" method="post" id="leadwerk_migration-backups-form" class="leadwerk_migration-clear">
		<table class="leadwerk_migration-backups">
			<thead>
				<tr>
					<th class="leadwerk_migration-column-checkbox">
						<input type="checkbox" id="leadwerk_migration-backup-select-all" aria-label="<?php esc_attr_e( 'Select all backups', 'leadwerk-migration' ); ?>" />
					</th>
					<th class="leadwerk_migration-column-name"><?php esc_html_e( 'Name', 'leadwerk-migration' ); ?></th>
					<th class="leadwerk_migration-column-date"><?php esc_html_e( 'Date', 'leadwerk-migration' ); ?></th>
					<th class="leadwerk_migration-column-size"><?php esc_html_e( 'Size', 'leadwerk-migration' ); ?></th>
					<th class="leadwerk_migration-column-actions"></th>
				</tr>
			</thead>
			<tbody>
				<tr class="leadwerk_migration-backups-list-spinner-holder leadwerk_migration-hide">
					<td colspan="5" class="leadwerk_migration-backups-list-spinner">
						<span class="spinner"></span>
						<?php esc_html_e( 'Refreshing backup list...', 'leadwerk-migration' ); ?>
					</td>
				</tr>

				<?php foreach ( $backups as $backup ) : ?>
				<tr>
					<td class="leadwerk_migration-column-checkbox">
						<input type="checkbox" class="leadwerk_migration-backup-checkbox" data-archive="<?php echo esc_attr( $backup['filename'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select backup %s', 'leadwerk-migration' ), $backup['filename'] ) ); ?>" />
					</td>
					<td class="leadwerk_migration-column-name">
						<?php if ( ! empty( $backup['path'] ) ) : ?>
							<i class="leadwerk_migration-icon-folder"></i>
							<?php echo esc_html( $backup['path'] ); ?>
							<br />
						<?php endif; ?>
						<i class="leadwerk_migration-icon-file-zip"></i>
						<span class="leadwerk_migration-backup-filename">
							<?php echo esc_html( basename( $backup['filename'] ) ); ?>
						</span>
						<span class="leadwerk_migration-backup-label-description leadwerk_migration-hide <?php echo empty( $labels[ $backup['filename'] ] ) ? null : 'leadwerk_migration-backup-label-selected'; ?>">
							<br />
							<?php esc_html_e( 'Click to label this backup', 'leadwerk-migration' ); ?>
							<i class="leadwerk_migration-icon-edit-pencil leadwerk_migration-hide"></i>
						</span>
						<span class="leadwerk_migration-backup-label-text <?php echo empty( $labels[ $backup['filename'] ] ) ? 'leadwerk_migration-hide' : null; ?>">
							<br />
							<span class="leadwerk_migration-backup-label-colored">
								<?php if ( ! empty( $labels[ $backup['filename'] ] ) ) : ?>
									<?php echo esc_html( $labels[ $backup['filename'] ] ); ?>
								<?php endif; ?>
							</span>
							<i class="leadwerk_migration-icon-edit-pencil leadwerk_migration-hide"></i>
						</span>
						<span class="leadwerk_migration-backup-label-holder leadwerk_migration-hide">
							<br />
							<input type="text" class="leadwerk_migration-backup-label-field" data-archive="<?php echo esc_attr( $backup['filename'] ); ?>" data-value="<?php echo empty( $labels[ $backup['filename'] ] ) ? null : esc_attr( $labels[ $backup['filename'] ] ); ?>" value="<?php echo empty( $labels[ $backup['filename'] ] ) ? null : esc_attr( $labels[ $backup['filename'] ] ); ?>" />
						</span>
					</td>
					<td class="leadwerk_migration-column-date">
						<?php echo esc_html( sprintf( /* translators: Human time diff */ __( '%s ago', 'leadwerk-migration' ), human_time_diff( $backup['mtime'] ) ) ); ?>
					</td>
					<td class="leadwerk_migration-column-size">
						<?php if ( ! is_null( $backup['size'] ) ) : ?>
							<?php echo esc_html( leadwerk_migration_size_format( $backup['size'], 2 ) ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Over 2GB', 'leadwerk-migration' ); ?>
						<?php endif; ?>
					</td>
					<td class="leadwerk_migration-column-actions leadwerk_migration-backup-actions">
						<div>
							<a href="#" role="menu" aria-haspopup="true" class="leadwerk_migration-backup-dots" title="<?php esc_attr_e( 'More', 'leadwerk-migration' ); ?>" aria-label="<?php esc_attr_e( 'More', 'leadwerk-migration' ); ?>">
								<i class="leadwerk_migration-icon-dots-horizontal-triple"></i>
							</a>
							<div class="leadwerk_migration-backup-dots-menu">
								<ul role="menu">
									<li>
										<a tabindex="-1" href="<?php echo esc_url( admin_url( 'admin.php?page=leadwerk_migration_import' ) ); ?>" role="menuitem" aria-label="<?php esc_attr_e( 'Restore through Import', 'leadwerk-migration' ); ?>">
											<i class="leadwerk_migration-icon-cloud-upload"></i>
											<span><?php esc_html_e( 'Restore via Import', 'leadwerk-migration' ); ?></span>
										</a>
									</li>
									<?php if ( $downloadable ) : ?>
										<li>
											<a tabindex="-1" href="<?php echo esc_url( Leadwerk_Migration_Dashboard_Controller::download_url( $backup['filename'] ) ); ?>" role="menuitem" download="<?php echo esc_attr( basename( $backup['filename'] ) ); ?>" aria-label="<?php esc_attr_e( 'Download', 'leadwerk-migration' ); ?>">
												<i class="leadwerk_migration-icon-arrow-down"></i>
												<?php esc_html_e( 'Download', 'leadwerk-migration' ); ?>
											</a>
										</li>
									<?php else : ?>
										<li class="leadwerk_migration-disabled">
											<a tabindex="-1" href="#" role="menuitem" aria-label="<?php esc_attr_e( 'Downloading is not possible because backups directory is not accessible.', 'leadwerk-migration' ); ?>" title="<?php esc_attr_e( 'Downloading is not possible because backups directory is not accessible.', 'leadwerk-migration' ); ?>">
												<i class="leadwerk_migration-icon-arrow-down"></i>
												<?php esc_html_e( 'Download', 'leadwerk-migration' ); ?>
											</a>
										</li>
									<?php endif; ?>
									<li>
										<a tabindex="-1" href="#" class="leadwerk_migration-backup-list-content" data-archive="<?php echo esc_attr( $backup['filename'] ); ?>" role="menuitem" aria-label="<?php esc_attr_e( 'Show backup content', 'leadwerk-migration' ); ?>">
											<i class="leadwerk_migration-icon-file-content"></i>
											<span><?php esc_html_e( 'List', 'leadwerk-migration' ); ?></span>
										</a>
									</li>
									<li class="divider"></li>
									<li>
										<a tabindex="-1" href="#" class="leadwerk_migration-backup-delete" data-archive="<?php echo esc_attr( $backup['filename'] ); ?>" role="menuitem" aria-label="<?php esc_attr_e( 'Delete', 'leadwerk-migration' ); ?>">
											<i class="leadwerk_migration-icon-close"></i>
											<span><?php esc_html_e( 'Delete', 'leadwerk-migration' ); ?></span>
										</a>
									</li>
								</ul>
							</div>
						</div>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<input type="hidden" name="leadwerk_migration_manual_restore" value="1" />
	</form>
<?php endif; ?>
