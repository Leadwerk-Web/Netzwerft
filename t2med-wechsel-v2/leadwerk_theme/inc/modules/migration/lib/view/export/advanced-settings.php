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
	<div class="leadwerk_migration-accordion leadwerk_migration-expandable">
		<h4>
			<i class="leadwerk_migration-icon-arrow-right"></i>
			<?php esc_html_e( 'Advanced options', 'leadwerk-migration' ); ?>
			<small><?php esc_html_e( '(click to expand)', 'leadwerk-migration' ); ?></small>
		</h4>
		<ul>
			<li><strong><?php esc_html_e( 'Security Options', 'leadwerk-migration' ); ?></strong></li>
			<?php if ( leadwerk_migration_can_encrypt() ) : ?>
				<li class="leadwerk_migration-encrypt-backups-container">
					<label for="leadwerk_migration-encrypt-backups">
						<input type="checkbox" id="leadwerk_migration-encrypt-backups" name="options[encrypt_backups]" />
						<?php esc_html_e( 'Encrypt this backup with a password', 'leadwerk-migration' ); ?>
					</label>
					<div class="leadwerk_migration-encrypt-backups-passwords-toggle">
						<div class="leadwerk_migration-encrypt-backups-passwords-container">
							<div class="leadwerk_migration-input-password-container">
								<input type="password" minlength="12" maxlength="4096" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Enter at least 12 characters', 'leadwerk-migration' ); ?>" name="options[encrypt_password]" id="leadwerk_migration-backup-encrypt-password">
								<a href="#leadwerk_migration-backup-encrypt-password" class="leadwerk_migration-toggle-password-visibility leadwerk_migration-icon-eye-blocked"></a>
								<div class="leadwerk_migration-error-message"><?php esc_html_e( 'A password is required', 'leadwerk-migration' ); ?></div>
							</div>
							<div class="leadwerk_migration-input-password-container">
								<input type="password" minlength="12" maxlength="4096" autocomplete="new-password" name="options[encrypt_password_confirmation]" placeholder="<?php esc_attr_e( 'Repeat the password', 'leadwerk-migration' ); ?>" id="leadwerk_migration-backup-encrypt-password-confirmation">
								<a href="#leadwerk_migration-backup-encrypt-password-confirmation" class="leadwerk_migration-toggle-password-visibility leadwerk_migration-icon-eye-blocked"></a>
								<div class="leadwerk_migration-error-message"><?php esc_html_e( 'The passwords do not match', 'leadwerk-migration' ); ?></div>
							</div>
						</div>
					</div>
				</li>
			<?php else : ?>
				<li class="leadwerk_migration-encrypt-backups-container-disabled">
					<input type="checkbox" id="leadwerk_migration-encrypt-backups" name="options[encrypt_backups]" disabled />
					<?php esc_html_e( 'Password-protect and encrypt backups', 'leadwerk-migration' ); ?>
					<span class="leadwerk_migration-icon-help" role="img" aria-label="<?php esc_attr_e( 'Encryption is unavailable because this server does not provide the required OpenSSL functions and cipher.', 'leadwerk-migration' ); ?>" title="<?php esc_attr_e( 'Encryption is unavailable because this server does not provide the required OpenSSL functions and cipher.', 'leadwerk-migration' ); ?>"></span>
				</li>
			<?php endif; ?>

			<?php do_action( 'leadwerk_migration_export_compression_types' ); ?>

			<li><strong><?php esc_html_e( 'Database Options', 'leadwerk-migration' ); ?></strong></li>
			<li>
				<label for="leadwerk_migration-no-spam-comments">
					<input type="checkbox" id="leadwerk_migration-no-spam-comments" name="options[no_spam_comments]" />
					<?php esc_html_e( 'Exclude spam comments', 'leadwerk-migration' ); ?>
				</label>
			</li>
			<li>
				<label for="leadwerk_migration-no-post-revisions">
					<input type="checkbox" id="leadwerk_migration-no-post-revisions" name="options[no_post_revisions]" />
					<?php esc_html_e( 'Exclude post revisions', 'leadwerk-migration' ); ?>
				</label>
			</li>
			<li>
				<label for="leadwerk_migration-no-database">
					<input type="checkbox" id="leadwerk_migration-no-database" name="options[no_database]" />
					<?php esc_html_e( 'Exclude database', 'leadwerk-migration' ); ?>
				</label>
			</li>
			<li>
				<label for="leadwerk_migration-no-email-replace">
					<input type="checkbox" id="leadwerk_migration-no-email-replace" name="options[no_email_replace]" />
					<?php
					echo wp_kses(
						__( 'Do <strong>not</strong> replace email domain', 'leadwerk-migration' ),
						leadwerk_migration_allowed_html_tags()
					);
					?>
				</label>
			</li>

			<?php do_action( 'leadwerk_migration_export_exclude_db_tables' ); ?>

			<?php do_action( 'leadwerk_migration_export_include_db_tables' ); ?>

			<li><strong><?php esc_html_e( 'File Options', 'leadwerk-migration' ); ?></strong></li>
			<li>
				<label for="leadwerk_migration-no-media">
					<input type="checkbox" id="leadwerk_migration-no-media" name="options[no_media]" />
					<?php esc_html_e( 'Exclude media library', 'leadwerk-migration' ); ?>
				</label>
			</li>
			<li>
				<label for="leadwerk_migration-no-themes">
					<input type="checkbox" id="leadwerk_migration-no-themes" name="options[no_themes]" />
					<?php esc_html_e( 'Exclude themes', 'leadwerk-migration' ); ?>
				</label>
			</li>

			<?php do_action( 'leadwerk_migration_export_inactive_themes' ); ?>

			<li>
				<label for="leadwerk_migration-no-muplugins">
					<input type="checkbox" id="leadwerk_migration-no-muplugins" name="options[no_muplugins]" />
					<?php esc_html_e( 'Exclude must-use plugins', 'leadwerk-migration' ); ?>
				</label>
			</li>

			<li>
				<label for="leadwerk_migration-no-plugins">
					<input type="checkbox" id="leadwerk_migration-no-plugins" name="options[no_plugins]" />
					<?php esc_html_e( 'Exclude plugins', 'leadwerk-migration' ); ?>
				</label>
			</li>

			<?php do_action( 'leadwerk_migration_export_inactive_plugins' ); ?>

			<?php do_action( 'leadwerk_migration_export_cache_files' ); ?>

			<?php do_action( 'leadwerk_migration_export_advanced_settings' ); ?>

		</ul>
	</div>
</div>
