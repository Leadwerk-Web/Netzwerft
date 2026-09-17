<?php
/**
 * Leadwerk Migration contextual sidebar.
 *
 * Copyright (C) 2026 Leadwerk.
 * Licensed under GPLv3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access is not allowed.' );
}
?>

<div class="leadwerk_migration-right">
	<div class="leadwerk_migration-sidebar">
		<div class="leadwerk_migration-segment">
			<h2><?php esc_html_e( 'Safe migration checklist', 'leadwerk-migration' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'Create a fresh backup before importing.', 'leadwerk-migration' ); ?></li>
				<li><?php esc_html_e( 'Keep the source site available until verification finishes.', 'leadwerk-migration' ); ?></li>
				<li><?php esc_html_e( 'Test login, permalinks, forms, and scheduled jobs afterward.', 'leadwerk-migration' ); ?></li>
			</ul>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=leadwerk_migration_dashboard' ) ); ?>"><?php esc_html_e( 'Open migration health →', 'leadwerk-migration' ); ?></a></p>

			<hr />
			<h2><?php esc_html_e( 'Private by default', 'leadwerk-migration' ); ?></h2>
			<p><?php esc_html_e( 'Backup files are stored behind deny rules and delivered only through an authenticated, nonce-protected stream.', 'leadwerk-migration' ); ?></p>

			<?php do_action( 'leadwerk_migration_sidebar_right_end' ); ?>
		</div>
	</div>
</div>
